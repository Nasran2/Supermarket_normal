<?php

namespace App\Http\Controllers;

use App\Http\Requests\PurchaseRequest;
use App\Http\Requests\VoidRequest;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\Supplier;
use App\Services\DocumentNumberService;
use App\Services\PurchaseService;
use App\Support\Money;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['q' => 'nullable|string|max:100', 'from' => 'nullable|date', 'to' => $request->filled('from') ? 'nullable|date|after_or_equal:from' : 'nullable|date', 'supplier_id' => 'nullable|exists:suppliers,id']);
        $query = Purchase::with('supplier', 'user')->withPaymentTotals();
        if ($request->filled('q')) {
            $query->where('reference', 'like', '%'.$request->input('q').'%');
        }
        if ($request->filled('from')) {
            $query->whereDate('purchase_date', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('purchase_date', '<=', $request->input('to'));
        }
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->input('supplier_id'));
        }
        $tracked = (clone $query)->where('status', 'ACTIVE')->where('payment_tracking', true);
        $paid = PurchasePayment::whereIn('purchase_id', (clone $tracked)->select('id')->reorder())->selectRaw("SUM(CASE WHEN kind = 'REFUND' THEN -amount ELSE amount END) as net_paid")->value('net_paid') ?? '0';
        $stats = ['total' => (string) (clone $query)->where('status', 'ACTIVE')->sum('total'), 'paid' => Money::round((string) $paid), 'due' => Money::sum((clone $tracked)->get()->map(fn ($p) => $p->due_amount)), 'unrecorded' => (clone $query)->where('status', 'ACTIVE')->where('payment_tracking', false)->count()];
        $suppliers = Supplier::orderBy('name')->get(['id', 'name']);
        $purchases = $query->latest('purchase_date')->paginate(20)->withQueryString();

        return view('purchases.index', compact('purchases', 'suppliers', 'stats'));
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
        $products = Product::with(['unit', 'conversions.unit', 'stockLayers' => fn ($query) => $query->available()])->where('active', true)->orderBy('name')->get();

        $methods = PaymentMethod::where('active', true)->orderBy('display_order')->get();

        $nextReference = app(DocumentNumberService::class)->preview('PURCHASE', today());
        $purchase->load('charges');

        return view('purchases.form', compact('purchase', 'suppliers', 'products', 'methods', 'nextReference'));
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
        $purchase->load(['items', 'supplier', 'user', 'payments.user', 'charges']);
        $methods = PaymentMethod::where('active', true)->orderBy('display_order')->get();

        return view('purchases.show', compact('purchase', 'methods'));
    }

    public function void(VoidRequest $request, Purchase $purchase, PurchaseService $service)
    {
        $service->void($purchase, auth()->id(), $request->validated('reason'));

        return back()->with('success', 'Purchase voided and stock reversed.');
    }
}
