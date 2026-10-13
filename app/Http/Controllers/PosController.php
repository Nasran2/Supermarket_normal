<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckoutRequest;
use App\Http\Requests\PosCustomerRequest;
use App\Models\Category;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Services\ProductUnitService;
use App\Services\RegisterService;
use App\Services\SaleService;
use App\Services\SettingsService;
use App\Services\StockLayerService;
use App\Support\Audit;
use App\Support\Expiry;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosController extends Controller
{
    private const PRODUCTS_PER_PAGE = 100;

    public function index(RegisterService $registers)
    {
        $register = $registers->current(auth()->id());
        $methods = PaymentMethod::where('active', true)->orderBy('display_order')->get();
        $customers = Customer::withDueBalances()->where('active', true)->orderBy('name')->get();
        $categories = Category::orderBy('name')->get();

        return view('pos.index', compact('register', 'methods', 'customers', 'categories'));
    }

    public function products(Request $request, SettingsService $settings, RegisterService $registers)
    {
        $this->requireRegister($registers);
        $data = $request->validate(['q' => 'nullable|string|max:255', 'scan' => 'nullable|boolean', 'auto_scan' => 'nullable|boolean', 'page' => 'sometimes|integer|min:1', 'category_id' => 'nullable|integer|exists:categories,id', 'ids' => 'nullable|array|max:300', 'ids.*' => 'integer|min:1']);
        $page = isset($data['page']) ? (int) $data['page'] : null;
        $q = trim($data['q'] ?? '');
        $products = Product::with(['unit', 'categories', 'conversions.unit', 'stockLayers' => fn ($q) => $q->available()])->where('active', true)->whereHas('unit', fn ($u) => $u->where('active', true));
        if (array_key_exists('ids', $data)) {
            return $this->productPayload($products->whereIn('id', $data['ids'] ?? [])->get());
        }
        $scanning = ($data['scan'] ?? false) && $settings->get('barcode_enabled', true);
        $autoScanning = ($data['auto_scan'] ?? false) && $settings->get('barcode_enabled', true);
        $mode = $scanning ? 'all' : $settings->get('search_mode', 'all');
        $exactMode = $autoScanning ? 'all' : $mode;
        $exact = null;
        if ($q !== '' && $exactMode !== 'name') {
            $exactQuery = (clone $products)->where(function ($query) use ($exactMode, $q) {
                foreach ($exactMode === 'all' ? ['barcode', 'sku'] : [$exactMode] as $column) {
                    $query->orWhere($column, $q);
                }
            });
            if (! empty($data['category_id']) && ! $scanning && ! $autoScanning) {
                $exactQuery->whereHas('categories', fn ($q) => $q->where('categories.id', $data['category_id']));
            }
            $exact = $exactQuery->orderByRaw('CASE WHEN barcode = ? THEN 0 ELSE 1 END', [$q])->first();
        }
        if ($exact) {
            $found = collect([$exact]);

            return $this->productPayload($page === null ? $found : new LengthAwarePaginator($page === 1 ? $found : collect(), 1, self::PRODUCTS_PER_PAGE, $page));
        }
        if ($q !== '') {
            $cols = $mode === 'all' ? ['name', 'sku', 'barcode'] : [$mode];
            $products->where(function ($query) use ($q, $cols) {
                foreach ($cols as $col) {
                    if ($col === 'name' && DB::getDriverName() === 'mysql' && mb_strlen($q) >= 3) {
                        $terms = preg_split('/[^\p{L}\p{N}]+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY);
                        $search = implode(' ', array_map(fn ($term) => '+'.$term.'*', $terms));
                        if ($search !== '') {
                            $query->orWhereRaw('MATCH(name) AGAINST(? IN BOOLEAN MODE)', [$search]);
                        }
                    } else {
                        $query->orWhere($col, 'like', ($col === 'name' && DB::getDriverName() !== 'mysql' ? '%' : '').$q.'%');
                    }
                }
            });
        }
        if (! empty($data['category_id'])) {
            $products->whereHas('categories', fn ($q) => $q->where('categories.id', $data['category_id']));
        }

        $products->orderByRaw('CASE WHEN barcode = ? THEN 0 WHEN sku = ? THEN 1 ELSE 2 END', [$q, $q])->orderBy('name')->orderBy('id');
        $found = $page === null ? $products->limit(self::PRODUCTS_PER_PAGE)->get() : $products->paginate(self::PRODUCTS_PER_PAGE, ['*'], 'page', $page);
        $noMatches = $found instanceof LengthAwarePaginator ? $found->total() === 0 : $found->isEmpty();
        if ($q !== '' && $noMatches && in_array($settings->get('search_mode', 'all'), ['all', 'name'])) {
            $fallback = Product::with(['unit', 'categories', 'conversions.unit', 'stockLayers' => fn ($q) => $q->available()])->where('active', true)->whereHas('unit', fn ($u) => $u->where('active', true))->where('name', 'like', $q.'%');
            if (! empty($data['category_id'])) {
                $fallback->whereHas('categories', fn ($q) => $q->where('categories.id', $data['category_id']));
            }
            $fallback->orderBy('name')->orderBy('id');
            $found = $page === null ? $fallback->limit(self::PRODUCTS_PER_PAGE)->get() : $fallback->paginate(self::PRODUCTS_PER_PAGE, ['*'], 'page', $page);
        }

        return $this->productPayload($found);
    }

    private function productPayload($products)
    {
        $transform = function ($p) {
            $groups = app(StockLayerService::class)->choices($p, request()->route('sale'));

            return ['track_expiry' => Expiry::tracks($p), 'show_expiry' => Expiry::enabled() && Expiry::setting('expiry_show_pos'), 'track_stock' => $p->track_stock, 'default_discount_type' => $p->default_discount_type, 'default_discount_value' => $p->default_discount_value, 'price_options' => $groups, 'units' => array_map(fn ($o) => array_diff_key($o, ['cost' => true]), app(ProductUnitService::class)->options($p)), 'unit_id' => $p->unit_id, 'id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'barcode' => $p->barcode, 'price' => $p->price, 'stock' => Expiry::tracks($p) ? Money::quantity('0', (string) collect($groups)->sum('quantity')) : $p->stock, 'unit' => $p->unit->short_name, 'decimal' => $p->unit->allow_decimal, 'category' => $p->categories->pluck('name')->join(', '), 'image' => $p->image ? asset('storage/'.$p->image) : null];
        };

        return $products instanceof LengthAwarePaginator ? $products->through($transform) : $products->map($transform);
    }

    public function storeCustomer(PosCustomerRequest $request, RegisterService $registers)
    {
        $this->requireRegister($registers);
        $customer = DB::transaction(function () use ($request) {
            $data = $request->validated();
            $data['opening_due'] = Money::round((string) ($data['opening_due'] ?? 0));
            $customer = Customer::create($data + ['active' => true]);
            Audit::record('customers.save', $customer, [], $customer->getAttributes());

            return $customer;
        });

        return response()->json(['customer' => ['id' => $customer->id, 'name' => $customer->name, 'due_balance' => $customer->due_balance]], 201);
    }

    public function checkoutStatus(Request $request)
    {
        abort_unless($request->user()->hasPermission('sales.create'), 403);
        $data = $request->validate(['checkout_token' => 'required|uuid']);
        $sale = Sale::where('user_id', $request->user()->id)->where('checkout_token', $data['checkout_token'])->first();

        return response()->json(['completed' => (bool) $sale, 'invoice' => $sale?->invoice, 'receipt_url' => $sale && $request->user()->hasPermission('sales.receipt') ? route('sales.receipt', $sale) : null]);
    }

    public function quote(CheckoutRequest $request, SaleService $service, RegisterService $registers)
    {
        $this->requireRegister($registers);

        return response()->json($service->publicQuote($service->quote($request->validated(), $request->user()), $request->user()));
    }

    private function requireRegister(RegisterService $registers): void
    {
        if (! $registers->current(auth()->id())) {
            throw ValidationException::withMessages(['register' => 'Open your register before accessing the POS.']);
        }
    }

    public function complete(CheckoutRequest $request, SaleService $service, SettingsService $settings)
    {
        $sale = $service->complete($request->validated(), $request->user());

        $canReceipt = $request->user()->hasPermission('sales.receipt');

        return response()->json(['invoice' => $sale->invoice, 'receipt_url' => $canReceipt ? route('sales.receipt', $sale) : null, 'sale_url' => route('sales.show', $sale), 'due_balance' => $sale->due_balance, 'customer_id' => $sale->customer_id, 'customer_balance' => $sale->customer?->due_balance, 'auto_print' => $canReceipt && $settings->get('auto_print', false), 'show_receipt' => $canReceipt && $settings->get('show_receipt', true)]);
    }
}
