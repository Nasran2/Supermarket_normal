<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaleCollectionRequest;
use App\Http\Requests\SaleEditRequest;
use App\Http\Requests\SaleReturnRequest;
use App\Http\Requests\SaleRevisionRequest;
use App\Http\Requests\VoidRequest;
use App\Models\Category;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Register;
use App\Models\Sale;
use App\Services\ProductUnitService;
use App\Services\RegisterService;
use App\Services\SaleAftercareService;
use App\Services\SaleRevisionService;
use App\Services\SaleService;
use App\Services\SettingsService;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['q' => 'nullable|string|max:100', 'from' => 'nullable|date', 'to' => 'nullable|date', 'status' => 'nullable|in:ACTIVE,VOIDED']);
        $query = Sale::visibleTo()->with(['user', 'customer', 'payments', 'register', 'returns', 'collections', 'items.returns']);
        if ($request->filled('q')) {
            $query->where(fn ($q) => $q->where('invoice', 'like', '%'.$data['q'].'%')->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', '%'.$data['q'].'%')->orWhere('phone', 'like', '%'.$data['q'].'%')));
        }
        if ($request->filled('from')) {
            $query->whereDate('sold_at', '>=', $data['from']);
        }
        if ($request->filled('to')) {
            $query->whereDate('sold_at', '<=', $data['to']);
        }
        if ($request->filled('status')) {
            $query->where('status', $data['status']);
        }
        $totals = (clone $query)->where('status', 'ACTIVE')->selectRaw('COUNT(*) as invoices, SUM(sale_amount) as amount, SUM(customer_payable) as payable')->first();
        $sales = $query->latest('sold_at')->latest('id')->paginate(20)->withQueryString();

        return view('sales.index', compact('sales', 'totals'));
    }

    public function show(Sale $sale)
    {
        $sale->load(['items.returns', 'user', 'customer', 'register', 'payments.expense', 'voider', 'returns.items.item', 'returns.user', 'collections.user']);
        $customers = Customer::withDueBalances()->where('active', true)->orderBy('name')->get();
        $methods = PaymentMethod::where('active', true)->orderBy('display_order')->get();
        $returnable = app(SaleAftercareService::class)->returnableLines($sale);

        return view('sales.show', compact('sale', 'customers', 'methods', 'returnable'));
    }

    public function edit(Sale $sale, SaleRevisionService $service)
    {
        $service->assertEditable($sale, auth()->id());
        $sale->load('items.product.unit', 'items.product.conversions.unit', 'items.allocations.layer', 'customer', 'payments');
        $register = $sale->register;
        $methods = PaymentMethod::where('active', true)->orderBy('display_order')->get();
        $customers = Customer::withDueBalances()->where('active', true)->orWhere('id', $sale->customer_id)->orderBy('name')->get();
        $categories = Category::orderBy('name')->get();
        $editingSale = $sale;
        $editSeed = ['invoice' => $sale->invoice, 'version' => $service->version($sale), 'customer' => (string) ($sale->customer_id ?? ''), 'notes' => $sale->notes ?? '',
            'billDiscount' => ['type' => 'AMOUNT', 'value' => Money::sub($sale->discount, $sale->line_discount_total)],
            'payments' => $sale->payments->map(fn ($p) => ['id' => $p->payment_method_id, 'name' => $p->method_name, 'amount' => $p->sale_amount, 'collected' => Money::sub($p->amount_paid, $p->change), 'reference' => $p->reference ?? ''])->all(),
            'items' => $sale->items->map(function ($i) {
                $stockPrice = $i->stock_price ?? app(ProductUnitService::class)->rate($i->catalog_price ?? $i->price, $i->quantity, $i->base_quantity ?? $i->quantity);
                $options = array_map(fn ($o) => array_diff_key($o, ['cost' => true]), app(ProductUnitService::class)->options($i->product, $stockPrice));
                $id = $i->unit_id ?? $i->product->unit_id;
                $selected = collect($options)->firstWhere('id', $id);

                $allocation = $i->allocations->count() === 1 ? $i->allocations->first() : null;

                return ['stock_layer_id' => $allocation?->stock_layer_id, 'stock_reference' => $allocation?->layer?->source_reference ?? '', 'stock_price' => $stockPrice, 'line_key' => (string) Str::uuid(), 'id' => $i->product_id, 'name' => $i->name, 'units' => $options, 'unit_id' => $id, 'unit' => $i->unit, 'decimal' => $selected['decimal'] ?? false, 'price' => $i->catalog_price ?? $i->price, 'unit_price' => $i->price, 'quantity' => $i->quantity, 'discount_type' => $i->discount_type ?? 'AMOUNT', 'discount_value' => $i->discount_value ?? '0', 'unavailable' => ! $selected || ! $i->product->active || ! $i->product->unit->active];
            })->all()];

        return view('pos.index', compact('register', 'methods', 'customers', 'categories', 'editingSale', 'editSeed'));
    }

    public function editProducts(Request $request, Sale $sale, SaleRevisionService $service, PosController $pos, SettingsService $settings, RegisterService $registers)
    {
        $service->assertEditable($sale, auth()->id());
        $products = $pos->products($request, $settings, $registers);

        return $products->map(function ($p) use ($sale) {
            foreach ($sale->items->where('product_id', $p['id']) as $original) {
                $p['stock'] = Money::quantity($p['stock'], $original->base_quantity ?? $original->quantity);
            }

            return $p;
        });
    }

    public function editQuote(SaleRevisionRequest $request, Sale $sale, SaleRevisionService $service)
    {
        return response()->json(app(SaleService::class)->publicQuote($service->quote($sale, $request->validated(), $request->user()), $request->user()));
    }

    public function revise(SaleRevisionRequest $request, Sale $sale, SaleRevisionService $service)
    {
        $sale = $service->revise($sale, $request->validated(), $request->user());

        return response()->json(['invoice' => $sale->invoice, 'sale_url' => route('sales.show', $sale), 'receipt_url' => route('sales.receipt', $sale)]);
    }

    public function destroy(VoidRequest $request, Sale $sale, SaleService $service)
    {
        $service->void($sale, $request->validated('reason'), auth()->id());

        return redirect()->route('sales.index')->with('success', 'Sale deleted by reversal. Stock restored; invoice history retained.');
    }

    public function returnItems(SaleReturnRequest $request, Sale $sale, SaleAftercareService $service)
    {
        $return = $service->returnItems($sale, $request->validated(), auth()->id());

        return redirect()->route('sales.show', $sale)->with('success', $return->reference.' recorded. Stock restored and the refund / due reduction saved.');
    }

    public function collect(SaleCollectionRequest $request, Sale $sale, SaleAftercareService $service)
    {
        $service->collect($sale, $request->validated(), auth()->id());

        return redirect()->route('sales.show', $sale)->with('success', 'Due payment received. Invoice balance updated.');
    }

    public function receipt(Sale $sale)
    {
        abort_unless(auth()->user()->hasPermission('sales.view') || ($sale->user_id === auth()->id() && auth()->user()->hasPermission('sales.create')), 403);
        $sale->load(['items', 'user', 'customer', 'payments', 'returns', 'collections']);

        return view('sales.receipt', compact('sale'));
    }

    public function update(SaleEditRequest $request, Sale $sale)
    {
        DB::transaction(function () use ($request, $sale) {
            $register = Register::whereKey($sale->register_id)->lockForUpdate()->firstOrFail();
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($sale->status !== 'ACTIVE' || $register->closed_at) {
                throw ValidationException::withMessages(['sale' => 'Only active sales in open registers can be edited.']);
            }
            $before = $sale->load('payments')->toArray();
            $data = $request->validated();
            $sale->update(['customer_id' => $data['customer_id'] ?? null, 'notes' => $data['notes'] ?? null]);
            if (isset($data['payment_references'])) {
                foreach ($data['payment_references'] as $paymentId => $reference) {
                    if (! ctype_digit((string) $paymentId)) {
                        throw ValidationException::withMessages(['payment_references' => 'Invalid payment record.']);
                    }
                    $sale->payments()->whereKey($paymentId)->firstOrFail()->update(['reference' => $reference]);
                }
            } elseif (array_key_exists('reference', $data)) {
                $sale->payment()->update(['reference' => $data['reference']]);
            }
            Audit::record('sale.edit', $sale, $before, $data);
        });

        return redirect()->route('sales.show', $sale)->with('success', 'Sale details updated.');
    }

    public function void(VoidRequest $request, Sale $sale, SaleService $service)
    {
        $service->void($sale, $request->validated('reason'), auth()->id());

        return back()->with('success', 'Sale voided. Stock restored and processing expense reversed.');
    }
}
