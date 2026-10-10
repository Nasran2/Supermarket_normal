<?php

namespace App\Http\Controllers;

use App\Http\Requests\TransactionReturnRequest;
use App\Models\Category;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\Unit;
use App\Models\User;
use App\Services\DocumentNumberService;
use App\Services\PaymentChargeService;
use App\Services\ProductUnitService;
use App\Services\PurchaseReturnService;
use App\Services\ReturnSettlementService;
use App\Services\SaleAftercareService;
use App\Services\SalesReturnService;
use App\Services\SettingsService;
use App\Services\StockLayerService;
use App\Services\StockReturnService;
use App\Services\SupplierReturnService;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class TransactionReturnController extends Controller
{
    private function permission(string $kind, string $action): void
    {
        abort_unless(auth()->user()->hasPermission($kind.'_returns.'.$action), 403);
    }

    private function original(string $kind, int $id)
    {
        return $kind === 'sales' ? Sale::visibleTo()->findOrFail($id) : Purchase::findOrFail($id);
    }

    private function service(string $kind)
    {
        return app($kind === 'sales' ? SalesReturnService::class : PurchaseReturnService::class);
    }

    private function document(string $kind, int $id)
    {
        return ($kind === 'sales' ? SaleReturn::class : PurchaseReturn::class)::findOrFail($id);
    }

    public function index(Request $request, string $kind)
    {
        $this->permission($kind, 'view');
        $request->merge(['from' => $request->input('from', today()->startOfMonth()->toDateString()), 'to' => $request->input('to', today()->toDateString())]);
        $filters = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'q' => 'nullable|string|max:150', 'status' => 'nullable|in:DRAFT,COMPLETED,CANCELLED', 'reason' => 'nullable|string|max:1000', 'stock_action' => 'nullable|in:RESTOCK,WRITEOFF,SUPPLIER', 'resolution' => 'nullable|in:MONEY,SAME,OTHER', 'customer_id' => 'nullable|integer', 'supplier_id' => 'nullable|integer', 'user_id' => 'nullable|integer', 'product_id' => 'nullable|integer', 'payment_method_id' => 'nullable|integer']);
        $query = ($kind === 'sales' ? SaleReturn::query()->whereHas('sale', fn ($q) => $q->visibleTo())->with('sale.customer') : PurchaseReturn::query()->with('purchase.supplier'))->with('user', 'replacement');
        foreach (['from', 'to'] as $key) {
            if (! empty($filters[$key])) {
                $query->whereDate('returned_at', $key === 'from' ? '>=' : '<=', $filters[$key]);
            }
        }
        foreach (['status', 'reason', 'resolution', 'customer_id', 'supplier_id', 'user_id'] as $key) {
            if (! empty($filters[$key]) && ! ($kind === 'sales' && $key === 'supplier_id') && ! ($kind === 'purchase' && $key === 'customer_id')) {
                $query->where($key, $filters[$key]);
            }
        }
        if (! empty($filters['payment_method_id'])) {
            $query->whereHas('settlements', fn ($q) => $q->where('payment_method_id', $filters['payment_method_id']));
        }
        if (! empty($filters['stock_action']) && $kind === 'sales') {
            $query->whereHas('items', fn ($q) => $q->where('stock_action', $filters['stock_action']));
        }
        if (! empty($filters['product_id'])) {
            $query->whereHas('items.item', fn ($q) => $q->where('product_id', $filters['product_id']));
        }
        if (! empty($filters['q'])) {
            $query->where(fn ($q) => $q->where('reference', 'like', '%'.$filters['q'].'%')->orWhereHas($kind === 'sales' ? 'sale' : 'purchase', fn ($q) => $q->where($kind === 'sales' ? 'invoice' : 'reference', 'like', '%'.$filters['q'].'%')));
        }
        $totals = (clone $query)->where('status', 'COMPLETED')->selectRaw('SUM(amount) as amount,SUM(due_reduction) as due,SUM(refund_amount) as refund,SUM(additional_payment) as payment')->first();
        $rows = $query->latest('id')->paginate(20)->withQueryString();
        $methods = PaymentMethod::orderBy('name')->get();

        $customers = Customer::orderBy('name')->get(['id', 'name']);
        $suppliers = Supplier::orderBy('name')->get(['id', 'name']);
        $users = User::orderBy('name')->get(['id', 'name']);
        $products = Product::orderBy('name')->get(['id', 'name']);

        return view('returns.index', compact('kind', 'rows', 'totals', 'methods', 'customers', 'suppliers', 'users', 'products'));
    }

    public function create(Request $request, string $kind)
    {
        $this->permission($kind, 'create');
        $request->validate(['original_id' => 'nullable|integer', 'draft_id' => 'nullable|integer', 'q' => 'nullable|string|max:150']);
        $draft = null;
        if ($request->filled('draft_id')) {
            $draft = $this->document($kind, (int) $request->draft_id);
            abort_unless($draft->status === 'DRAFT' && $draft->user_id === $request->user()->id, 403);
        }
        $original = $draft ? $this->original($kind, $kind === 'sales' ? $draft->sale_id : $draft->purchase_id) : ($request->filled('original_id') ? $this->original($kind, (int) $request->original_id) : null);
        $query = $kind === 'sales' ? Sale::visibleTo()->with('customer', 'user', 'payments', 'returns', 'collections')->where('status', 'ACTIVE') : Purchase::with('supplier', 'user')->where('status', 'ACTIVE');
        if ($request->filled('q')) {
            $query->where(function ($q) use ($kind, $request) {
                $q->where($kind === 'sales' ? 'invoice' : 'reference', 'like', '%'.$request->q.'%')->orWhereHas($kind === 'sales' ? 'customer' : 'supplier', fn ($q) => $q->where('name', 'like', '%'.$request->q.'%')->orWhere('phone', 'like', '%'.$request->q.'%'));
                if ($kind === 'purchase') {
                    $q->orWhereHas('items', fn ($q) => $q->where('name', 'like', '%'.$request->q.'%'))->orWhere('notes', 'like', '%'.$request->q.'%');
                }
            });
        }
        $bills = $query->latest('id')->limit(20)->get();
        $lines = [];
        if ($original) {
            if ($kind === 'sales') {
                foreach (app(SaleAftercareService::class)->returnableLines($original) as $line) {
                    $item = $line['item'];
                    $lines[] = ['id' => $item->id, 'product_id' => $item->product_id, 'unit_id' => $item->unit_id, 'name' => $item->name, 'unit' => $item->unit, 'sold' => $item->quantity, 'returned' => $line['returned'], 'remaining' => $line['remaining'], 'decimal' => ($item->unitRecord ?? $item->product->unit)->allow_decimal, 'base_quantity' => $item->base_quantity ?? $item->quantity, 'restock_prices' => $item->allocations()->orderByDesc('id')->with('layer')->get()->map(fn ($a) => ['stock_price' => $a->layer->selling_price, 'remaining' => Money::quantity($a->quantity, '-'.$a->returned_quantity), 'units' => array_map(fn ($u) => array_diff_key($u, ['cost' => true]), app(ProductUnitService::class)->options($item->product, $a->layer->selling_price))])->values()->all(), 'price' => $item->price, 'net' => $line['net'], 'stock_action' => app(SettingsService::class)->get('return_default_stock_action', 'RESTOCK'), 'unknown_supplier' => $item->allocations()->whereHas('layer', fn ($q) => $q->whereNull('purchase_item_id'))->exists() || ! $item->allocations()->exists()];
                }
            } else {
                foreach ($original->items()->with('product.unit', 'stockLayers')->get() as $item) {
                    $prior = PurchaseReturnItem::where('purchase_item_id', $item->id)->whereHas('return', fn ($q) => $q->completed())->sum('quantity');
                    $remaining = Money::quantity($item->quantity, '-'.Money::quantity('0', (string) $prior));
                    $physical = Money::quantity('0', (string) $item->stockLayers->where('status', 'ACTIVE')->sum('remaining_quantity'));
                    $primary = $item->base_quantity ?? $item->quantity;
                    $available = app(StockReturnService::class)->portion($item->quantity, $physical, $primary, 3);
                    $lines[] = ['id' => $item->id, 'product_id' => $item->product_id, 'unit_id' => $item->unit_id, 'name' => $item->name, 'unit' => $item->unit, 'sold' => $item->quantity, 'returned' => (string) $prior, 'remaining' => ReturnSettlementService::minimum($remaining, $available), 'physical' => $physical, 'decimal' => (Unit::find($item->unit_id) ?? $item->product->unit)->allow_decimal, 'price' => $item->cost, 'net' => $item->total];
                }
            }
        }
        $methods = PaymentMethod::where('active', true)->orderBy('display_order')->get();
        $suppliers = Supplier::where('active', true)->orderBy('name')->get();
        $customers = Customer::withDueBalances()->where('active', true)->orderBy('name')->get();

        $categories = Category::orderBy('name')->get();

        return view('returns.create', compact('draft', 'categories', 'kind', 'original', 'bills', 'lines', 'methods', 'suppliers', 'customers'));
    }

    public function products(Request $request, string $kind)
    {
        $this->permission($kind, 'create');
        $request->validate(['q' => 'nullable|string|max:150', 'ids' => 'nullable|array|max:100', 'ids.*' => 'integer|min:1', 'category_id' => 'nullable|integer']);
        $q = Product::with(['unit', 'conversions.unit', 'stockLayers' => fn ($q) => $q->available()])->where('active', true)->whereHas('unit', fn ($q) => $q->where('active', true));
        if ($request->has('ids')) {
            $q->whereIn('id', $request->ids);
        } elseif ($request->filled('q')) {
            $q->where(fn ($q) => $q->where('name', 'like', '%'.$request->q.'%')->orWhere('barcode', $request->q)->orWhere('sku', 'like', '%'.$request->q.'%'));
        }
        if ($request->filled('category_id')) {
            $q->whereHas('categories', fn ($q) => $q->where('categories.id', $request->category_id));
        }

        return $q->orderBy('name')->limit(60)->get()->map(fn ($p) => ['id' => $p->id, 'sku' => $p->sku, 'barcode' => $p->barcode, 'name' => $p->name, 'unit_id' => $p->unit_id, 'unit' => $p->unit->short_name, 'price' => $p->price, 'cost' => $kind === 'purchase' && auth()->user()->hasPermission('returns.view_cost') ? $p->cost : null, 'units' => array_map(fn ($u) => array_diff_key($u, ['cost' => true]), app(ProductUnitService::class)->options($p)), 'price_options' => app(StockLayerService::class)->choices($p)]);
    }

    public function quote(TransactionReturnRequest $request, string $kind)
    {
        $data = $request->validated();
        $q = $this->service($kind)->quote($this->original($kind, (int) $data['original_id']), $data, $request->user());
        unset($q['replacement_quote'],$q['replacements']);
        foreach ($q['lines'] as &$line) {
            unset($line['allocations']);
            if (! $request->user()->hasPermission('returns.view_cost')) {
                unset($line['cost_total']);
            }
        }
        if (! $request->user()->hasPermission('returns.view_cost')) {
            unset($q['cost_total']);
        }
        $q['payment_charge'] = '0.00';
        if ($kind === 'sales' && Money::compare($q['must_pay'], 0) > 0 && ! ($data['add_to_due'] ?? false) && ! empty($data['payment_method_id'])) {
            $method = PaymentMethod::whereKey($data['payment_method_id'])->where('active', true)->firstOrFail();
            $q['payment_charge'] = app(PaymentChargeService::class)->calculateCharge($method, $q['must_pay'], false, $q['replacement_value'])['customer_payable'];
            $q['payment_charge'] = Money::sub($q['payment_charge'], $q['must_pay']);
        }

        return response()->json($q);
    }

    public function store(TransactionReturnRequest $request, string $kind)
    {
        $data = $request->validated();
        $r = $this->service($kind)->complete($this->original($kind, (int) $data['original_id']), $data, $request->user());

        return response()->json([
            'reference' => $r->reference,
            'url' => route('returns.show', [$kind, $r->id]),
            'auto_print' => (bool) app(SettingsService::class)->get('return_auto_print', false),
            'receipt_url' => route('returns.receipt', [$kind, $r->id, 'print' => '1']),
        ]);
    }

    public function draft(TransactionReturnRequest $request, string $kind)
    {
        $data = $request->validated();
        $original = $this->original($kind, (int) $data['original_id']);
        $class = $kind === 'sales' ? SaleReturn::class : PurchaseReturn::class;
        $r = DB::transaction(function () use ($kind, $class, $data, $original, $request) {
            $existing = $class::where('token', $data['token'])->lockForUpdate()->first();
            if ($existing) {
                abort_unless($existing->user_id === $request->user()->id && ($kind === 'sales' ? $existing->sale_id : $existing->purchase_id) === $original->id, 403);
                if ($existing->status !== 'DRAFT') {
                    return $existing;
                }
                $existing->update(['draft_payload' => $data, 'reason' => $data['reason'] ?? '', 'notes' => $data['notes'] ?? null]);

                return $existing;
            }
            $fields = ['token' => $data['token'], 'user_id' => $request->user()->id, 'reference' => app(DocumentNumberService::class)->next($kind === 'sales' ? 'SALES_RETURN' : 'PURCHASE_RETURN', now()), 'status' => 'DRAFT', 'reason' => $data['reason'] ?? '', 'resolution' => $data['resolution'], 'notes' => $data['notes'] ?? null, 'amount' => '0.00', 'cost_total' => '0.00', 'due_reduction' => '0.00', 'refund_amount' => '0.00', 'returned_at' => now(), 'draft_payload' => $data];
            $fields += $kind === 'sales' ? ['sale_id' => $original->id, 'customer_id' => $original->customer_id] : ['purchase_id' => $original->id, 'supplier_id' => $original->supplier_id];
            $r = $class::create($fields);
            Audit::record('return.draft', $r, [], ['original_id' => $original->id]);

            return $r;
        }, 3);

        return response()->json(['reference' => $r->reference, 'url' => route('returns.show', [$kind, $r->id])]);
    }

    public function show(string $kind, int $id)
    {
        $document = $this->document($kind, $id);
        Gate::authorize('view', $document);
        $document->load('items.item', 'items.allocations.layer', 'settlements', 'allocations', 'replacement', 'user');

        return view('returns.show', compact('kind', 'document'));
    }

    public function receipt(string $kind, int $id)
    {
        $document = $this->document($kind, $id);
        Gate::authorize('view', $document);
        $document->load('items.item', 'settlements', 'replacement.items', 'user');

        return view('returns.receipt', compact('kind', 'document'));
    }

    public function cancel(Request $request, string $kind, int $id)
    {
        $document = $this->document($kind, $id);
        Gate::authorize('cancel', $document);
        $data = $request->validate(['reason' => 'required|string|min:3|max:1000']);
        $this->service($kind)->cancel($document, $data['reason'], $request->user());

        return back()->with('success', 'Return cancelled. Reversals and history retained.');
    }

    public function supplierIndex(Request $request)
    {
        abort_unless($request->user()->hasPermission('supplier_returns.view'), 403);
        $request->validate(['supplier_id' => 'nullable|integer', 'status' => 'nullable|in:PENDING,SENT,SETTLED,CANCELLED']);
        $rows = SupplierReturn::whereHas('saleReturn.sale', fn ($q) => $q->visibleTo())->with('supplier', 'saleReturn.sale', 'items.layer.product')->when($request->supplier_id, fn ($q, $id) => $q->where('supplier_id', $id))->when($request->status, fn ($q, $status) => $q->where('status', $status))->latest('id')->paginate(20)->withQueryString();
        $methods = PaymentMethod::where('active', true)->orderBy('display_order')->get();

        return view('returns.suppliers', compact('rows', 'methods'));
    }

    public function supplierCredit(Request $request, Supplier $supplier, ReturnSettlementService $service)
    {
        $data = $request->validate(['amount' => 'required|numeric|gt:0|decimal:0,2']);
        $service->useSupplierCredit($supplier, (string) $data['amount'], $request->user());

        return back()->with('success', 'Retained supplier credit applied to outstanding bills.');
    }

    public function supplierTransition(Request $request, SupplierReturn $supplierReturn, SupplierReturnService $service)
    {
        $data = $request->validate(['action' => 'required|in:SEND,SETTLE', 'apply_due' => 'nullable|numeric|min:0|decimal:0,2', 'payment_method_id' => 'nullable|integer|exists:payment_methods,id', 'notes' => 'nullable|string|max:1000']);
        $service->transition($supplierReturn, $data, $request->user());

        return back()->with('success', 'Supplier return updated.');
    }
}
