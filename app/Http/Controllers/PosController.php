<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckoutRequest;
use App\Models\Category;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Services\RegisterService;
use App\Services\SaleService;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosController extends Controller
{
    public function index(RegisterService $registers)
    {
        $register = $registers->current(auth()->id());
        $methods = PaymentMethod::where('active', true)->orderBy('display_order')->get();
        $customers = Customer::where('active', true)->orderBy('name')->get();
        $categories = Category::orderBy('name')->get();

        return view('pos.index', compact('register', 'methods', 'customers', 'categories'));
    }

    public function products(Request $request, SettingsService $settings)
    {
        $data = $request->validate(['q' => 'nullable|string|max:255', 'scan' => 'nullable|boolean', 'category_id' => 'nullable|integer|exists:categories,id']);
        $q = trim($data['q'] ?? '');
        $products = Product::with(['unit', 'category'])->where('active', true)->whereHas('unit', fn ($u) => $u->where('active', true));
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
                $exactQuery->where('category_id', $data['category_id']);
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
            $products->where('category_id', $data['category_id']);
        }

        $found = $products->orderByRaw('CASE WHEN barcode = ? THEN 0 WHEN sku = ? THEN 1 ELSE 2 END', [$q, $q])->orderBy('name')->limit(60)->get();
        if ($q !== '' && $found->isEmpty() && in_array($settings->get('search_mode', 'all'), ['all', 'name'])) {
            $fallback = Product::with(['unit', 'category'])->where('active', true)->whereHas('unit', fn ($u) => $u->where('active', true))->where('name', 'like', $q.'%');
            if (! empty($data['category_id'])) {
                $fallback->where('category_id', $data['category_id']);
            }
            $found = $fallback->orderBy('name')->limit(60)->get();
        }

        return $this->productPayload($found);
    }

    private function productPayload($products)
    {
        return $products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'barcode' => $p->barcode, 'price' => $p->price, 'stock' => $p->stock, 'unit' => $p->unit->short_name, 'decimal' => $p->unit->allow_decimal, 'category' => $p->category->name, 'image' => $p->image ? asset('storage/'.$p->image) : null]);
    }

    public function quote(CheckoutRequest $request, SaleService $service)
    {
        return response()->json($service->quote($request->validated(), $request->user()));
    }

    public function complete(CheckoutRequest $request, SaleService $service, SettingsService $settings)
    {
        $sale = $service->complete($request->validated(), $request->user());

        return response()->json(['invoice' => $sale->invoice, 'receipt_url' => route('sales.receipt', $sale), 'sale_url' => route('sales.show', $sale), 'auto_print' => $settings->get('auto_print', false), 'show_receipt' => $settings->get('show_receipt', true)]);
    }
}
