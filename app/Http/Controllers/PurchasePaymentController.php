<?php

namespace App\Http\Controllers;

use App\Http\Requests\PurchasePaymentRequest;
use App\Models\Purchase;
use App\Services\PurchasePaymentService;
use Illuminate\Http\Request;

class PurchasePaymentController extends Controller
{
    public function store(PurchasePaymentRequest $request, Purchase $purchase, PurchasePaymentService $service)
    {
        $service->record($purchase, $request->validated() + ['allow_change' => true], $request->user()->id);

        return redirect()->route('purchases.show', $purchase)->with('success', 'Supplier payment recorded and purchase due updated.');
    }

    public function refund(PurchasePaymentRequest $request, Purchase $purchase, PurchasePaymentService $service)
    {
        $service->record($purchase, $request->validated(), $request->user()->id, true);

        return redirect()->route('purchases.show', $purchase)->with('success', 'Supplier refund recorded. Cash refunds were added to your open register.');
    }

    public function balance(Request $request, Purchase $purchase, PurchasePaymentService $service)
    {
        abort_unless($request->user()->hasPermission('purchases.edit'), 403);
        $data = $request->validate(['previously_paid' => ['required', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'], 'confirmed' => ['accepted']]);
        $service->startTracking($purchase, (string) $data['previously_paid'], $request->user()->id);

        return redirect()->route('purchases.show', $purchase)->with('success', 'Previous payment balance recorded. You can now pay the remaining due.');
    }
}
