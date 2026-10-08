<?php

namespace App\Http\Controllers;

use App\Models\ProductStockLayer;
use App\Models\Unit;
use App\Services\DefaultUnitService;
use App\Services\StockLayerService;
use Illuminate\Http\Request;

class StockPriceController extends Controller
{
    public function reprice(Request $request, ProductStockLayer $layer, StockLayerService $service)
    {
        $data = $request->validate(['selling_price' => ['required', 'numeric', 'min:0', 'max:999999999', 'decimal:0,2'], 'confirmed' => ['accepted']]);
        $service->reprice($layer, (string) $data['selling_price']);

        return back()->with('success', 'Available stock repriced. Historical invoices retain their original prices and costs.');
    }

    public function defaultUnit(Request $request, Unit $unit, DefaultUnitService $service)
    {
        $request->validate(['confirmed' => ['accepted']]);
        $service->set($unit->id);

        return back()->with('success', $unit->name.' is the default for new products.');
    }
}
