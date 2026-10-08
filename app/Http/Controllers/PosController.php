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
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosController extends Controller
{
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
        $data = $request->validate(['q' => 'nullable|string|max:255', 'scan' => 'nullable|boolean', 'category_id' => 'nullable|integer|exists:categories,id', 'ids' => 'nullable|array|max:300', 'ids.*' => 'integer|min:1']);
        $q = trim($data['q'] ?? '');
        $products = Product::with(['unit', 'categories', 'conversions.unit'])->where('active', true)->whereHas('unit', fn ($u) => $u->where('active', true));
        if (array_key_exists('ids', $data)) {
            return $this->productPayload($products->whereIn('id', $data['ids'] ?? [])->get());
        }
        $scanning = ($data['scan'] ?? false) && $settings->get('barcode_enabled', true);
        $mode = $scanning ? 'all' : $settings->get('search_mode', 'all');
        $exact = null;
        if ($q !== '' && $mode !== 'name') {
            $exactQuery = (clone $products)->where(function ($query) use ($mode, $q) {
                foreach ($mode === 'all' ? ['barcode', 'sku'] : [$mode] as $column) {
                    $query->orWhere($column, $q);
                }
            });
            if (! empty($data['category_id']) && ! $scanning) {
                $exactQuery->whereHas('categories', fn($q) => $q->where('categories.id', $data['category_id']));
            }
            $exact = $exactQuery->first();
        }
        if ($exact) {
            return $this->productPayload(collect([$exact]));
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
            $products->whereHas('categories', fn($q) => $q->where('categories.id', $data['category_id']));
        }

        $found = $products->orderByRaw('CASE WHEN barcode = ? THEN 0 WHEN sku = ? THEN 1 ELSE 2 END', [$q, $q])->orderBy('name')->limit(60)->get();
        if ($q !== '' && $found->isEmpty() && in_array($settings->get('search_mode', 'all'), ['all', 'name'])) {
            $fallback = Product::with(['unit', 'categories', 'conversions.unit'])->where('active', true)->whereHas('unit', fn ($u) => $u->where('active', true))->where('name', 'like', $q.'%');
            if (! empty($data['category_id'])) {
                $fallback->whereHas('categories', fn($q) => $q->where('categories.id', $data['category_id']));
            }
            $found = $fallback->orderBy('name')->limit(60)->get();
        }

        return $this->productPayload($found);
    }

    private function productPayload($products)
    {
        return $products->map(fn ($p) => ['units' => app(ProductUnitService::class)->options($p), 'unit_id' => $p->unit_id, 'id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'barcode' => $p->barcode, 'price' => $p->price, 'stock' => $p->stock, 'unit' => $p->unit->short_name, 'decimal' => $p->unit->allow_decimal, 'category' => $p->categories->pluck('name')->join(', '), 'image' => $p->image ? asset('storage/'.$p->image) : null]);
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

        return response()->json(['completed' => (bool) $sale, 'invoice' => $sale?->invoice, 'receipt_url' => $sale ? route('sales.receipt', $sale) : null]);
    }

    public function quote(CheckoutRequest $request, SaleService $service, RegisterService $registers)
    {
        $this->requireRegister($registers);

        return response()->json($service->quote($request->validated(), $request->user()));
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

        return response()->json(['invoice' => $sale->invoice, 'receipt_url' => route('sales.receipt', $sale), 'sale_url' => route('sales.show', $sale), 'due_balance' => $sale->due_balance, 'customer_id' => $sale->customer_id, 'customer_balance' => $sale->customer?->due_balance, 'auto_print' => $settings->get('auto_print', false), 'show_receipt' => $settings->get('show_receipt', true)]);
    }
}
