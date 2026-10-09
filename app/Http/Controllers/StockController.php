<?php

namespace App\Http\Controllers;

use App\Http\Requests\StockAdjustmentRequest;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\StockLayerService;
use App\Services\StockService;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StockController extends Controller
{
    public function edit(Product $product)
    {
        $product->load('unit');
        app(StockLayerService::class)->ensureLegacy($product);
        $layers = $product->stockLayers()->available()->get();
        $movements = $product->hasMany(StockMovement::class)->when(! auth()->user()->hasPermission('products.view_history'), fn ($q) => $q->whereRaw('1 = 0'))->with('user', 'sale', 'saleReturn.sale', 'layers.layer')->latest('created_at')->latest('id')->paginate(20, ['*'], 'movements');

        return view('crud.stock', compact('product', 'movements', 'layers'));
    }

    public function update(StockAdjustmentRequest $request, Product $product, StockService $stock)
    {
        DB::transaction(function () use ($request, $product, $stock) {
            $p = Product::with('unit')->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $quantity = $request->validated('quantity');
            $stock->validateQuantity($p, ltrim($quantity, '-'));
            if (Money::compare(Money::quantity($p->stock, $quantity), 0) < 0) {
                throw ValidationException::withMessages(['quantity' => 'An adjustment cannot make stock negative.']);
            }
            app(StockLayerService::class)->adjust($p, $quantity, $request->validated('stock_layer_id'), $p->cost, $p->price, $p->sku.'-'.Str::uuid(), 'ADJUSTMENT: '.$request->validated('reason'), auth()->id());
            Audit::record('stock.adjust', $p, [], $request->validated());
        });

        return back()->with('success', 'Stock adjusted.');
    }
}
