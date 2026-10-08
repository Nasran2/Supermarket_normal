<?php

namespace App\Http\Controllers;

use App\Http\Requests\StockBatchRequest;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Services\StockAdjustmentService;
use Illuminate\Http\Request;

class StockBatchController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['q' => 'nullable|string|max:150', 'from' => 'nullable|date_format:Y-m-d', 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])], 'status' => 'nullable|in:ACTIVE,VOID']);
        $query = StockAdjustment::with('user')->withCount('items')->latest('id');
        if (! empty($filters['q'])) {
            $query->where(fn ($q) => $q->where('reference', 'like', '%'.$filters['q'].'%')->orWhere('reason', 'like', '%'.$filters['q'].'%'));
        }
        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        $records = $query->paginate(20)->withQueryString();

        return view('stock-adjustments.index', compact('records'));
    }

    public function products(Request $request)
    {
        $input = $request->validate(['q' => 'nullable|string|max:255']);
        $query = Product::with('unit')->where('active', true)->whereHas('unit', fn ($q) => $q->where('active', true));
        if ($q = trim($input['q'] ?? '')) {
            $query->where(fn ($query) => $query->where('name', 'like', '%'.$q.'%')->orWhere('sku', 'like', '%'.$q.'%')->orWhere('barcode', 'like', '%'.$q.'%'));
        }

        return response()->json($query->orderBy('name')->limit(60)->get()->map(fn ($p) => $this->productRow($p)));
    }

    private function productRow(Product $p): array
    {
        return ['product_id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'unit_id' => $p->unit_id, 'unit' => $p->unit->short_name, 'decimal' => $p->unit->allow_decimal, 'expected_stock' => $p->stock, 'expected_price' => $p->price, 'expected_cost' => $p->cost];
    }

    public function create()
    {
        return $this->form(new StockAdjustment);
    }

    public function edit(StockAdjustment $adjustment)
    {
        abort_unless($adjustment->status === 'ACTIVE', 409, 'Reversed adjustments cannot be edited.');

        return $this->form($adjustment);
    }

    private function form(StockAdjustment $adjustment)
    {
        $inputs = old('items');
        $ids = $inputs ? array_column($inputs, 'product_id') : $adjustment->items()->pluck('product_id')->all();
        $products = Product::with('unit')->whereIn('id', $ids)->get()->keyBy('id');
        $originalIds = $adjustment->exists ? $adjustment->items()->pluck('product_id')->all() : [];
        $rows = collect($inputs ?: array_map(fn ($id) => ['product_id' => $id, 'mode' => 'ADD', 'quantity' => '0', 'locked' => true], $ids))->map(function ($input) use ($products, $originalIds) {
            $p = $products->get($input['product_id']);

            return $p ? array_merge($this->productRow($p), $input, ['locked' => in_array($p->id, $originalIds)]) : null;
        })->filter()->values()->all();

        return view('stock-adjustments.form', compact('adjustment', 'rows'));
    }

    public function store(StockBatchRequest $request, StockAdjustmentService $service)
    {
        $batch = $service->save($request->validated(), $request->user());

        return redirect()->route('adjustments.show', $batch)->with('success', 'Stock adjustment applied.');
    }

    public function update(StockBatchRequest $request, StockAdjustment $adjustment, StockAdjustmentService $service)
    {
        $service->save($request->validated(), $request->user(), $adjustment->id);

        return redirect()->route('adjustments.show', $adjustment)->with('success', 'Adjustment updated. Revision history is recorded in the audit log.');
    }

    public function show(StockAdjustment $adjustment)
    {
        $adjustment->load(['items.product', 'user', 'voider']);

        return view('stock-adjustments.show', compact('adjustment'));
    }

    public function destroy(Request $request, StockAdjustment $adjustment, StockAdjustmentService $service)
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:255']);
        $service->reverse($adjustment->id, $request->user(), (int) $data['revision'], $data['reason']);

        return redirect()->route('adjustments.show', $adjustment)->with('success', 'Adjustment reversed. Stock and prices were restored; history remains available.');
    }
}
