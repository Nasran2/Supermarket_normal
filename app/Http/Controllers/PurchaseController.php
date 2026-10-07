<?php

namespace App\Http\Controllers;

use App\Http\Requests\PurchaseRequest;
use App\Http\Requests\VoidRequest;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\PurchaseService;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['q' => 'nullable|string|max:100', 'from' => 'nullable|date', 'to' => 'nullable|date']);
        $query = Purchase::with('supplier', 'user');
        if ($request->filled('q')) {
            $query->where('reference', 'like', '%'.$request->input('q').'%');
        }
        if ($request->filled('from')) {
            $query->whereDate('purchase_date', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('purchase_date', '<=', $request->input('to'));
        }
        $purchases = $query->latest('purchase_date')->paginate(20)->withQueryString();

        return view('purchases.index', compact('purchases'));
    }

    public function create()
    {
        return $this->form(new Purchase);
    }

    public function edit(Purchase $purchase)
    {
        abort_unless($purchase->status === 'ACTIVE', 409);
        $purchase->load('items');

        return $this->form($purchase);
    }

    private function form(Purchase $purchase)
    {
        $suppliers = Supplier::where('active', true)->orderBy('name')->get();
        $products = Product::with('unit')->where('active', true)->orderBy('name')->get();

        return view('purchases.form', compact('purchase', 'suppliers', 'products'));
    }

    public function store(PurchaseRequest $request, PurchaseService $service)
    {
        $purchase = $service->save($request->validated(), auth()->id());

        return redirect()->route('purchases.show', $purchase)->with('success', 'Purchase saved and stock updated.');
    }

    public function update(PurchaseRequest $request, Purchase $purchase, PurchaseService $service)
    {
        $service->save($request->validated(), auth()->id(), $purchase);

        return redirect()->route('purchases.show', $purchase)->with('success', 'Purchase updated.');
    }

    public function show(Purchase $purchase)
    {
        $purchase->load(['items', 'supplier', 'user']);

        return view('purchases.show', compact('purchase'));
    }

    public function void(VoidRequest $request, Purchase $purchase, PurchaseService $service)
    {
        $service->void($purchase, auth()->id(), $request->validated('reason'));

        return back()->with('success', 'Purchase voided and stock reversed.');
    }
}
