<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\Product;
use App\Models\ProductStockLayer;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Register;
use App\Models\ReturnSettlement;
use App\Models\Sale;
use App\Models\SaleCollection;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\SaleRevision;
use App\Support\Money;
use App\Support\SalesVisibility;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReportService
{
    public const TITLES = ['sales' => 'Sales', 'purchases' => 'Purchases', 'expenses' => 'Expenses', 'profit' => 'Profit & loss', 'stock' => 'Stock', 'product-sales' => 'Product sales', 'payments' => 'Payment methods', 'register' => 'Register', 'cash' => 'Cash summary', 'payment-charges' => 'Payment processing charges', 'card-charges' => 'Card charges', 'qr-charges' => 'QR charges', 'bank-charges' => 'Bank charges', 'purchase-returns' => 'Purchase returns', 'returns' => 'Sales returns', 'collections' => 'Due collections', 'audit' => 'Audit log'];

    public static function permission(string $kind): string
    {
        return 'reports.'.$kind;
    }

    public function build(string $kind, array $filters): array
    {
        $from = $filters['from'];
        $to = $filters['to'];
        $cards = [];
        $start = $from.' 00:00:00';
        $end = $to.' 23:59:59';
        $pay = SalePayment::with(['sale.user', 'expense'])->whereHas('sale', fn ($q) => $q->visibleTo()->whereIn('status', ['ACTIVE', 'RETURN_CANCELLED'])->whereBetween('sold_at', [$start, $end]));
        foreach (['payment_method_id' => 'payment_method_id', 'rule_id' => 'payment_charge_rule_id', 'charge_bearer' => 'charge_bearer'] as $input => $col) {
            if (! empty($filters[$input])) {
                $pay->where($col, $filters[$input]);
            }
        }
        if (! empty($filters['user_id'])) {
            $pay->whereHas('sale', fn ($q) => $q->where('user_id', $filters['user_id']));
        }
        if (in_array($kind, ['payment-charges', 'card-charges', 'qr-charges', 'bank-charges'])) {
            if ($kind !== 'payment-charges') {
                $pay->where('method_type', match ($kind) {
                    'card-charges' => 'CARD','qr-charges' => 'QR',default => 'BANK_TRANSFER'
                });
            }
            $query = $pay->where('processing_charge', '>', 0)->latest('id');
            $headers = ['Date', 'Invoice', 'Payment method', 'Payment share', 'Rule', 'Charge', 'Fee', 'Paid by', 'Expense reference', 'Cashier'];
            $map = fn ($p) => [$p->sale->sold_at->format('Y-m-d H:i'), $p->sale->invoice, $p->method_name, Money::display($p->sale_amount), $p->rule_name, $p->charge_type === 'PERCENTAGE' ? rtrim(rtrim($p->charge_value, '0'), '.').'%' : Money::display($p->charge_value).' fixed', Money::display($p->processing_charge), ucfirst(strtolower($p->charge_bearer)), $p->expense ? 'EXP-'.$p->expense->id : '—', $p->sale->user->name];
            $cards = ['Total charges' => (string) (clone $query)->sum('processing_charge'), 'Customer paid' => (string) (clone $query)->where('charge_bearer', 'CUSTOMER')->sum('processing_charge'), 'Business paid' => (string) (clone $query)->where('charge_bearer', 'BUSINESS')->sum('processing_charge'), 'Card charges' => (string) (clone $query)->where('method_type', 'CARD')->sum('processing_charge'), 'QR charges' => (string) (clone $query)->where('method_type', 'QR')->sum('processing_charge')];
        } elseif ($kind === 'payments') {
            $base = (clone $pay)->selectRaw("payment_method_id, method_name, method_type, sale_amount as base, CASE WHEN charge_bearer = 'CUSTOMER' THEN processing_charge ELSE 0 END as customer_fees, CASE WHEN charge_bearer = 'BUSINESS' THEN processing_charge ELSE 0 END as business_fees, amount_paid - sale_payments.change as collections");
            if (empty($filters['rule_id']) && empty($filters['charge_bearer'])) {
                $collections = SaleCollection::whereHas('sale', fn ($q) => $q->visibleTo())->whereBetween('collected_at', [$start, $end])->selectRaw('payment_method_id, method_name, method_type, 0 as base, 0 as customer_fees, 0 as business_fees, amount as collections');
                $refunds = SaleReturn::whereHas('sale', fn ($q) => $q->visibleTo())->whereBetween('returned_at', [$start, $end])->where('refund_amount', '>', 0)->selectRaw('payment_method_id, method_name, method_type, 0 as base, 0 as customer_fees, 0 as business_fees, -refund_amount as collections');
                foreach ([$collections, $refunds] as $activity) {
                    if (! empty($filters['payment_method_id'])) {
                        $activity->where('payment_method_id', $filters['payment_method_id']);
                    }
                    if (! empty($filters['user_id'])) {
                        $activity->where('user_id', $filters['user_id']);
                    }
                }
                $events = ReturnSettlement::whereBetween('created_at', [$start, $end])->whereIn('kind', ['PURCHASE_RETURN_REFUND', 'PURCHASE_RETURN_PAYMENT', 'SUPPLIER_RETURN_REFUND', 'REVERSAL'])->where(fn ($q) => $q->whereNull('sale_return_id')->orWhereHas('saleReturn.sale', fn ($q) => $q->visibleTo()))->selectRaw('payment_method_id, method_name, method_type, 0 as base, 0 as customer_fees, 0 as business_fees, amount as collections');
                foreach (['user_id', 'payment_method_id'] as $key) {
                    if (! empty($filters[$key])) {
                        $events->where($key, $filters[$key]);
                    }
                }
                $base->unionAll($collections)->unionAll($refunds)->unionAll($events);
            }
            $query = DB::query()->fromSub($base->toBase(), 'tenders')->selectRaw('payment_method_id, method_name, method_type, COUNT(*) as transactions, SUM(base) as base, SUM(customer_fees) as customer_fees, SUM(business_fees) as business_fees, SUM(collections) as collections')->groupBy('payment_method_id', 'method_name', 'method_type')->orderBy('method_name');
            $headers = ['Payment method', 'Type', 'Entries including collections / refunds', 'Original allocated sales', 'Customer-paid charges', 'Business-paid charges', 'Net collections'];
            $map = fn ($p) => [$p->method_name, $p->method_type, $p->transactions, Money::display($p->base), Money::display($p->customer_fees), Money::display($p->business_fees), Money::display($p->collections)];
            $cards = ['Net collections' => (string) DB::query()->fromSub(clone $query, 'totals')->sum('collections'), 'Original allocated sales' => (string) (clone $pay)->sum('sale_amount')];
        } elseif ($kind === 'purchase-returns') {
            $query = PurchaseReturn::with('purchase', 'supplier', 'user')->whereBetween('returned_at', [$start, $end])->latest('id');
            foreach (['status', 'reason', 'resolution', 'supplier_id', 'user_id'] as $key) {
                if (! empty($filters[$key])) {
                    $query->where($key, $filters[$key]);
                }
            }
            if (! empty($filters['q'])) {
                $query->where(fn ($q) => $q->where('reference', 'like', '%'.$filters['q'].'%')->orWhereHas('purchase', fn ($q) => $q->where('reference', 'like', '%'.$filters['q'].'%')));
            }
            if (! empty($filters['product_id'])) {
                $query->whereHas('items.item', fn ($q) => $q->where('product_id', $filters['product_id']));
            }
            if (! empty($filters['payment_method_id'])) {
                $query->whereHas('settlements', fn ($q) => $q->where('payment_method_id', $filters['payment_method_id']));
            }
            $headers = ['Date', 'Return', 'Purchase', 'Supplier', 'Value', 'Due reduced', 'Replacement', 'Received', 'Paid', 'Supplier credit', 'Status', 'Cashier'];
            $map = fn ($r) => [$r->returned_at->format('Y-m-d H:i'), $r->reference, $r->purchase->reference, $r->supplier->name, Money::display($r->amount), Money::display($r->due_reduction), Money::display($r->replacement_value), Money::display($r->refund_amount), Money::display($r->additional_payment), Money::display($r->supplier_credit), $r->status, $r->user->name];
            $cards = ['Completed returns' => (string) (clone $query)->completed()->sum('amount'), 'Supplier refunds received' => (string) (clone $query)->completed()->sum('refund_amount')];
        } elseif (in_array($kind, ['returns', 'collections'])) {
            $isReturn = $kind === 'returns';
            $query = ($isReturn ? SaleReturn::query() : SaleCollection::query())->whereHas('sale', fn ($q) => $q->visibleTo())->with('sale', 'user')->whereBetween($isReturn ? 'returned_at' : 'collected_at', [$start, $end])->latest('id');
            if (! empty($filters['user_id'])) {
                $query->where('user_id', $filters['user_id']);
            }
            if (! empty($filters['payment_method_id'])) {
                $query->where('payment_method_id', $filters['payment_method_id']);
            }
            if (! empty($filters['q'])) {
                $query->whereHas('sale', fn ($q) => $q->where('invoice', 'like', '%'.$filters['q'].'%'));
            }
            if ($isReturn) {
                foreach (['status', 'reason', 'resolution', 'customer_id'] as $key) {
                    if (! empty($filters[$key])) {
                        $query->where($key, $filters[$key]);
                    }
                }
                if (! empty($filters['stock_action'])) {
                    $query->whereHas('items', fn ($q) => $q->where('stock_action', $filters['stock_action']));
                }
                if (! empty($filters['product_id'])) {
                    $query->whereHas('items.item', fn ($q) => $q->where('product_id', $filters['product_id']));
                }
            }
            $headers = $isReturn ? ['Date', 'Return', 'Invoice', 'Return value', 'Due reduction', 'Refund paid', 'Method', 'Register', 'Cashier', 'Reason', 'Replacement', 'Additional payment', 'Status'] : ['Date', 'Invoice', 'Collected', 'Received', 'Change', 'Method', 'Register', 'Cashier', 'Reference'];
            $map = $isReturn ? fn ($r) => [$r->returned_at->format('Y-m-d H:i'), $r->reference, $r->sale->invoice, Money::display($r->amount), Money::display($r->due_reduction), Money::display($r->refund_amount), $r->method_name ?? 'No refund', 'REG-'.$r->register_id, $r->user->name, $r->reason, Money::display($r->replacement_value), Money::display($r->additional_payment), $r->status] : fn ($c) => [$c->collected_at->format('Y-m-d H:i'), $c->sale->invoice, Money::display($c->amount), Money::display($c->amount_paid), Money::display($c->change), $c->method_name, 'REG-'.$c->register_id, $c->user->name, $c->reference ?? '—'];
            $cards = $isReturn ? ['Returns' => (string) (clone $query)->completed()->sum('amount'), 'Refunds paid' => (string) (clone $query)->completed()->sum('refund_amount'), 'Due reduced' => (string) (clone $query)->completed()->sum('due_reduction')] : ['Dues collected' => (string) (clone $query)->sum('amount')];
        } elseif ($kind === 'sales') {
            $query = Sale::visibleTo()->with(['user', 'customer', 'payments', 'returns', 'collections'])->whereBetween('sold_at', [$start, $end])->latest('sold_at');
            if (! empty($filters['user_id'])) {
                $query->where('user_id', $filters['user_id']);
            }
            if (! empty($filters['payment_method_id'])) {
                $query->whereHas('payments', fn ($q) => $q->where('payment_method_id', $filters['payment_method_id']));
            }
            if (! empty($filters['q'])) {
                $query->where('invoice', 'like', '%'.$filters['q'].'%');
            }
            $headers = ['Date', 'Invoice', 'Customer', 'Subtotal', 'Discount', 'Base sales', 'Method', 'Returned', 'Balance due', 'Status'];
            $map = fn ($s) => [$s->sold_at->format('Y-m-d H:i'), $s->invoice, $s->customer?->name ?? 'Walk-in', Money::display($s->subtotal), Money::display($s->discount), Money::display($s->sale_amount), $s->payment_names, Money::display($s->returned_total), Money::display($s->due_balance), $s->status];
            if (auth()->user()?->hasPermission('products.view_cost')) {
                $headers = array_merge($headers, ['Invoice COGS after returns', 'Invoice gross profit after returns', 'Margin %']);
                $baseMap = $map;
                $map = function ($s) use ($baseMap) {
                    $cogs = Money::sub($s->cost_total, Money::sum($s->returns->pluck('cost_total')));
                    $revenue = Money::sub($s->sale_amount, $s->returned_total);
                    $profit = Money::sub($revenue, $cogs);
                    $margin = Money::compare($revenue, 0) > 0 ? (string) BigDecimal::of($profit)->multipliedBy(100)->dividedBy($revenue, 2, RoundingMode::HALF_UP) : '0.00';

                    return array_merge($baseMap($s), [Money::display($cogs), Money::display($profit), $margin]);
                };
            }
            $periodReturns = SaleReturn::whereHas('sale', fn ($q) => $q->visibleTo())->whereBetween('returned_at', [$start, $end]);
            $periodReturns->whereHas('sale', function ($q) use ($filters) {
                if (! empty($filters['user_id'])) {
                    $q->where('user_id', $filters['user_id']);
                }
                if (! empty($filters['payment_method_id'])) {
                    $q->whereHas('payments', fn ($p) => $p->where('payment_method_id', $filters['payment_method_id']));
                }
                if (! empty($filters['q'])) {
                    $q->where('invoice', 'like', '%'.$filters['q'].'%');
                }
            });
            $original = (string) (clone $query)->whereIn('status', ['ACTIVE', 'RETURN_CANCELLED'])->sum('sale_amount');
            $exchangeReversals = Sale::visibleTo()->where('status', 'RETURN_CANCELLED')->whereBetween('voided_at', [$start, $end]);
            foreach (['user_id'] as $key) {
                if (! empty($filters[$key])) {
                    $exchangeReversals->where($key, $filters[$key]);
                }
            }
            if (! empty($filters['q'])) {
                $exchangeReversals->where('invoice', 'like', '%'.$filters['q'].'%');
            }
            if (! empty($filters['payment_method_id'])) {
                $exchangeReversals->whereHas('payments', fn ($q) => $q->where('payment_method_id', $filters['payment_method_id']));
            }
            $original = Money::sub($original, (string) $exchangeReversals->sum('sale_amount'));
            $returned = Money::sub((string) $periodReturns->sum('amount'), (string) SaleReturn::whereHas('sale', fn ($q) => $q->visibleTo())->where('status', 'CANCELLED')->whereBetween('cancelled_at', [$start, $end])->sum('amount'));
            $cards = ['Sales before returns' => $original, 'Returns in period' => $returned, 'Net revenue in period' => Money::sub($original, $returned), 'Original invoice payable' => (string) (clone $query)->where('status', 'ACTIVE')->sum('customer_payable')];
        } elseif ($kind === 'purchases') {
            $query = Purchase::with('supplier', 'user')->withPaymentTotals()->whereBetween('purchase_date', [$from, $to])->latest('purchase_date');
            $headers = ['Date', 'Reference', 'Supplier', 'Amount', 'Paid less refunds', 'Due', 'Payment status', 'Recorded by', 'Status'];
            $map = fn ($p) => [$p->purchase_date->format('Y-m-d'), $p->reference, $p->supplier->name, Money::display($p->total), $p->payment_tracking ? Money::display($p->paid_amount) : 'Unrecorded', $p->due_amount !== null ? Money::display($p->due_amount) : 'Unrecorded', $p->payment_status, $p->user->name, $p->status];
            $grossPurchases = (string) (clone $query)->whereIn('status', ['ACTIVE', 'RETURN_CANCELLED'])->sum('total');
            $reversals = PurchaseReturn::where('status', 'CANCELLED')->whereBetween('cancelled_at', [$start, $end]);
            $grossPurchases = Money::sub($grossPurchases, Money::sum((clone $reversals)->with('replacement')->get()->map(fn ($r) => $r->replacement?->total ?? '0')));
            $returned = Money::sub((string) PurchaseReturn::whereBetween('returned_at', [$start, $end])->sum('amount'), (string) $reversals->sum('amount'));
            $cards = ['Gross purchases' => $grossPurchases, 'Purchase returns' => $returned, 'Net purchases' => Money::sub($grossPurchases, $returned)];
        } elseif ($kind === 'expenses') {
            $query = Expense::visibleSales()->with('category', 'user', 'method')->whereBetween('expense_date', [$from, $to])->latest('expense_date');
            if (! empty($filters['user_id'])) {
                $query->where('user_id', $filters['user_id']);
            }
            if (! empty($filters['payment_method_id'])) {
                $query->where('payment_method_id', $filters['payment_method_id']);
            }
            $headers = ['Date', 'Reference', 'Category', 'Description', 'Amount', 'Type', 'Status', 'Method', 'Recorded by'];
            $map = fn ($e) => [$e->expense_date->format('Y-m-d'), $e->reference ?? 'EXP-'.$e->id, $e->category->name, $e->description, Money::display($e->amount), $e->type, $e->status, $e->method?->name ?? '—', $e->user->name];
            $cards = ['Active expenses' => (string) (clone $query)->where('status', 'ACTIVE')->sum('amount')];
        } elseif ($kind === 'stock') {
            $canCost = auth()->user()?->hasPermission('products.view_cost') ?? false;
            $lotQuery = ProductStockLayer::where('status', 'ACTIVE')->with('product.unit', 'product.categories');
            if (! empty($filters['q'])) {
                $lotQuery->whereHas('product', fn ($q) => $q->where('name', 'like', '%'.$filters['q'].'%')->orWhere('sku', 'like', '%'.$filters['q'].'%'));
            }
            if (! empty($filters['product_id'])) {
                $lotQuery->where('product_id', $filters['product_id']);
            }
            if (! empty($filters['category_id'])) {
                $lotQuery->whereHas('product.categories', fn ($q) => $q->where('categories.id', $filters['category_id']));
            }
            if (isset($filters['selling_price'])) {
                $lotQuery->where('selling_price', $filters['selling_price']);
            }
            if ($canCost && isset($filters['cost_price'])) {
                $lotQuery->where('cost_price', $filters['cost_price']);
            }
            if (! empty($filters['source_reference'])) {
                $lotQuery->where('source_reference', 'like', '%'.$filters['source_reference'].'%');
            }
            if (! empty($filters['received_from'])) {
                $lotQuery->whereDate('received_at', '>=', $filters['received_from']);
            }
            if (! empty($filters['received_to'])) {
                $lotQuery->whereDate('received_at', '<=', $filters['received_to']);
            }
            $status = $filters['stock_status'] ?? 'available';
            if ($status === 'available') {
                $lotQuery->where('remaining_quantity', '>', 0);
            } elseif ($status === 'depleted') {
                $lotQuery->where('remaining_quantity', '<=', 0);
            }
            $values = (clone $lotQuery)->selectRaw('SUM(COALESCE(remaining_cost_total, remaining_quantity * cost_price)) as cost_value, SUM(remaining_quantity * selling_price) as selling_value')->first();
            $cards = ['Potential sales value' => (string) ($values->selling_value ?? 0)];
            if ($canCost) {
                $cards = ['Stock at cost' => (string) ($values->cost_value ?? 0)] + $cards;
            }
            if (($filters['stock_view'] ?? '') === 'layers') {
                $query = $lotQuery->orderBy('product_id')->orderBy('received_at')->orderBy('id');
                $headers = ['Product', 'SKU', 'Primary unit', 'Selling price', 'Available', 'Original quantity', 'Source', 'Received'];
                if ($canCost) {
                    $headers = array_merge($headers, ['Cost / unit', 'Stock at cost']);
                }
                $map = fn ($l) => array_merge([$l->product->name, $l->product->sku, $l->product->unit->short_name, Money::display($l->selling_price), $l->remaining_quantity, $l->original_quantity, $l->source_reference ?? $l->source_type, $l->received_at->format('Y-m-d')], $canCost ? [Money::display($l->cost_price), Money::display($l->stock_value)] : []);
            } else {
                $query = Product::with('unit', 'categories')->with(['stockLayers' => fn ($q) => $q->available()])->orderBy('name');
                if (! empty($filters['q'])) {
                    $query->where(fn ($q) => $q->where('name', 'like', '%'.$filters['q'].'%')->orWhere('sku', 'like', '%'.$filters['q'].'%'));
                }
                if (! empty($filters['product_id'])) {
                    $query->whereKey($filters['product_id']);
                }
                if (! empty($filters['category_id'])) {
                    $query->whereHas('categories', fn ($q) => $q->where('categories.id', $filters['category_id']));
                }
                if ($status === 'depleted') {
                    $query->where('stock', '<=', 0);
                }
                if (array_intersect(array_keys($filters), ['selling_price', 'cost_price', 'source_reference', 'received_from', 'received_to'])) {
                    $query->whereIn('id', (clone $lotQuery)->select('product_id'));
                }
                $headers = ['Product', 'SKU', 'Categories', 'Unit', 'Stock', 'Low stock level', 'Available selling prices', 'Potential sales value'];
                if ($canCost) {
                    $headers[] = 'Stock at cost';
                }
                $map = fn ($p) => array_merge([$p->name, $p->sku, $p->categories->pluck('name')->join(', '), $p->unit->short_name, $p->stock, $p->low_stock, $p->stockLayers->pluck('selling_price')->unique()->sort()->map(fn ($v) => Money::display($v))->join(' / '), Money::display(Money::sum($p->stockLayers->map(fn ($l) => Money::mul($l->remaining_quantity, $l->selling_price))))], $canCost ? [Money::display(Money::sum($p->stockLayers->map(fn ($l) => $l->stock_value)))] : []);
            }
        } elseif ($kind === 'product-sales') {
            $query = SaleItem::whereHas('sale', fn ($q) => $q->visibleTo()->where('status', 'ACTIVE')->whereBetween('sold_at', [$start, $end]))->selectRaw('product_id, name, unit, SUM(quantity) as quantity, SUM(total) as total, SUM(COALESCE(cogs_total, COALESCE(base_quantity, quantity) * COALESCE(base_cost, cost))) as cogs')->groupBy('product_id', 'name', 'unit')->orderByDesc('total');
            $headers = ['Product', 'Unit', 'Original quantity before returns', 'Original line sales before invoice discount / returns'];
            $canCost = auth()->user()?->hasPermission('products.view_cost') ?? false;
            if ($canCost) {
                $headers = array_merge($headers, ['Actual COGS before returns', 'Gross profit before invoice discount / returns', 'Margin % before invoice discount / returns']);
            }
            $map = fn ($i) => array_merge([$i->name, $i->unit, $i->quantity, Money::display($i->total)], $canCost ? [Money::display($i->cogs), Money::display(Money::sub($i->total, $i->cogs)), Money::compare($i->total, 0) > 0 ? (string) BigDecimal::of(Money::sub($i->total, $i->cogs))->multipliedBy(100)->dividedBy($i->total, 2, RoundingMode::HALF_UP) : '0.00'] : []);
        } elseif ($kind === 'register') {
            $query = app(RegisterService::class)->withSummary(SalesVisibility::apply(Register::with('user'), 'registers.user_id'))->whereBetween('opened_at', [$start, $end])->latest('opened_at');
            if (! empty($filters['user_id'])) {
                $query->where('user_id', $filters['user_id']);
            }
            $headers = ['Register', 'Cashier', 'Opened', 'Closed', 'Opening cash', 'Cash sales', 'Cash in', 'Cash out', 'Cash expenses', 'Expected', 'Actual', 'Difference'];
            $map = function ($r) {
                $s = app(RegisterService::class)->summary($r);

                return ['REG-'.$r->id, $r->user->name, $r->opened_at->format('Y-m-d H:i'), $r->closed_at?->format('Y-m-d H:i') ?? 'Open', Money::display($r->opening_cash), Money::display($s['cash']), Money::display($s['in']), Money::display($s['out']), Money::display($s['expenses']), Money::display($r->closed_at ? $r->expected_cash : $s['expected']), $r->closed_at ? Money::display($r->actual_cash) : '—', $r->closed_at ? Money::display($r->difference) : '—'];
            };
        } elseif ($kind === 'cash') {
            $sp = DB::table('sale_payments')->whereIn('sale_id', Sale::visibleTo()->select('id'))->selectRaw("'Sale' as type, sale_id as reference_id, reference, created_at as date, payment_method_id, amount_paid - `change` as in_amount, 0 as out_amount");
            $cp = SalesVisibility::apply(DB::table('customer_payments'), 'customer_payments.user_id')->selectRaw("'Customer payment' as type, id as reference_id, notes as reference, payment_date as date, payment_method_id, amount as in_amount, 0 as out_amount");
            $sc = DB::table('sale_collections')->whereIn('sale_id', Sale::visibleTo()->select('id'))->selectRaw("'Due collection' as type, sale_id as reference_id, reference, collected_at as date, payment_method_id, amount as in_amount, 0 as out_amount");
            $sr = DB::table('sale_returns')->whereIn('sale_id', Sale::visibleTo()->select('id'))->selectRaw("'Refund' as type, id as reference_id, reference, returned_at as date, payment_method_id, 0 as in_amount, refund_amount as out_amount")->whereNotNull('payment_method_id');
            $pp = SalesVisibility::apply(DB::table('purchase_payments'), 'purchase_payments.user_id')->selectRaw("CASE WHEN kind = 'REFUND' THEN 'Supplier refund' ELSE 'Supplier payment' END as type, purchase_id as reference_id, reference, created_at as date, payment_method_id, CASE WHEN kind = 'REFUND' THEN amount ELSE 0 END as in_amount, CASE WHEN kind = 'PAYMENT' THEN amount ELSE 0 END as out_amount");
            $xp = SalesVisibility::apply(DB::table('expenses'), 'expenses.user_id')->where(fn ($q) => $q->whereNull('sale_id')->orWhereIn('sale_id', Sale::visibleTo()->select('id')))->selectRaw("'Expense' as type, id as reference_id, reference, expense_date as date, payment_method_id, 0 as in_amount, amount as out_amount")->whereNotNull('payment_method_id')->where('status', 'ACTIVE');

            $returnEvents = DB::table('return_settlements as r')->leftJoin('sale_returns as sr', 'sr.id', '=', 'r.sale_return_id')->leftJoin('purchase_returns as pr', 'pr.id', '=', 'r.purchase_return_id')->leftJoin('supplier_returns as sup', 'sup.id', '=', 'r.supplier_return_id')->whereIn('r.kind', ['PURCHASE_RETURN_REFUND', 'PURCHASE_RETURN_PAYMENT', 'SUPPLIER_RETURN_REFUND', 'REVERSAL'])->where(fn ($q) => $q->whereNull('sr.id')->orWhereIn('sr.sale_id', Sale::visibleTo()->select('id')))->selectRaw('r.kind as type, r.id as reference_id, COALESCE(sr.reference,pr.reference,sup.reference) as reference,r.created_at as date,r.payment_method_id,CASE WHEN r.amount>0 THEN r.amount ELSE 0 END as in_amount,CASE WHEN r.amount<0 THEN -r.amount ELSE 0 END as out_amount');
            $unionQuery = $sp->unionAll($cp)->unionAll($sc)->unionAll($sr)->unionAll($pp)->unionAll($xp)->unionAll($returnEvents);
            if (Schema::hasTable('hr_payments')) {
                $hrDate = DB::getDriverName() === 'sqlite' ? "date || ' 00:00:00'" : "CONCAT(date, ' 00:00:00')";
                $hp = SalesVisibility::apply(DB::table('hr_payments'), 'hr_payments.user_id')->selectRaw("'Staff payment' as type, id as reference_id, reference, $hrDate as date, payment_method_id, 0 as in_amount, amount as out_amount")->where('status', 'ACTIVE');
                $unionQuery->unionAll($hp);
            }

            $query = DB::table($unionQuery, 'transactions')
                ->whereBetween('date', [$start, $end])
                ->orderByDesc('date')
                ->join('payment_methods as pm', 'transactions.payment_method_id', '=', 'pm.id')
                ->select('transactions.*', 'pm.name as method_name', 'pm.type as method_type');

            if (! empty($filters['payment_method_id'])) {
                $query->where('transactions.payment_method_id', $filters['payment_method_id']);
            }

            $headers = ['Date', 'Type', 'Reference', 'Payment method', 'Money In', 'Money Out'];
            $map = function ($t) {
                return [
                    Carbon::parse($t->date)->format('Y-m-d H:i'),
                    $t->type,
                    $t->reference ?: ($t->type === 'Expense' ? 'EXP-'.$t->reference_id : ($t->type === 'Sale' ? 'SALE-'.$t->reference_id : ($t->type === 'Supplier payment' || $t->type === 'Supplier refund' ? 'PUR-'.$t->reference_id : '—'))),
                    $t->method_name,
                    Money::display($t->in_amount),
                    Money::display($t->out_amount),
                ];
            };

            $openingQuery = DB::table($unionQuery, 'transactions')
                ->where('date', '<', $start);
            if (! empty($filters['payment_method_id'])) {
                $openingQuery->where('payment_method_id', $filters['payment_method_id']);
            }

            $openingBalances = (clone $openingQuery)->reorder()->select(DB::raw('SUM(in_amount) as total_in, SUM(out_amount) as total_out'))->first();
            $opening = Money::sub($openingBalances->total_in ?? 0, $openingBalances->total_out ?? 0);

            $periodTotals = (clone $query)->reorder()->select(DB::raw('SUM(in_amount) as total_in, SUM(out_amount) as total_out'))->first();
            $periodIn = $periodTotals->total_in ?? 0;
            $periodOut = $periodTotals->total_out ?? 0;
            $closing = Money::add(Money::sub($opening, $periodOut), $periodIn);

            $cards = [
                'Opening balance' => (string) $opening,
                'Period Money In' => (string) $periodIn,
                'Period Money Out' => (string) $periodOut,
                'Closing balance' => (string) $closing,
            ];
        } elseif ($kind === 'audit') {
            $query = SalesVisibility::apply(AuditLog::with('user')->where('action', 'not like', 'hr.%'), 'audit_logs.user_id')->whereBetween('created_at', [$start, $end])->latest();
            if (SalesVisibility::mode() !== 'ALL') {
                $subjects = [Sale::class => Sale::visibleTo()->select('id')];
                foreach ([SaleReturn::class, SaleCollection::class, SaleRevision::class] as $subject) {
                    $subjects[$subject] = $subject::whereHas('sale', fn ($q) => $q->visibleTo())->select('id');
                }
                $query->where(function ($q) use ($subjects) {
                    $q->whereNotIn('subject_type', array_keys($subjects));
                    foreach ($subjects as $type => $ids) {
                        $q->orWhere(fn ($q) => $q->where('subject_type', $type)->whereIn('subject_id', $ids));
                    }
                });
            }
            $headers = ['Time', 'User', 'Action', 'Record', 'Before', 'After'];
            $map = fn ($a) => [$a->created_at->format('Y-m-d H:i:s'), $a->user?->name ?? 'System', $a->action, class_basename($a->subject_type).' #'.$a->subject_id, json_encode($a->before), json_encode($a->after)];
        } else {
            abort(404);
        }

        return compact('query', 'headers', 'map', 'cards');
    }
}
