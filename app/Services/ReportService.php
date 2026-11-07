<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Cheque;
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
use App\Models\SaleReturnItem;
use App\Models\SaleRevision;
use App\Support\Money;
use App\Support\SalesPrivacy;
use App\Support\SalesVisibility;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReportService
{
    public const RANKED_REPORTS = ['sales', 'product-sales', 'profit', 'payments', 'payment-charges', 'card-charges', 'qr-charges', 'bank-charges', 'collections', 'returns'];
    public const TITLES = ['sales' => 'Sales', 'purchases' => 'Purchases', 'expenses' => 'Expenses', 'profit' => 'Profit & loss', 'stock' => 'Stock', 'product-sales' => 'Product sales', 'payments' => 'Payment methods', 'register' => 'Register', 'cash' => 'Cash summary', 'payment-charges' => 'Payment processing charges', 'card-charges' => 'Card charges', 'qr-charges' => 'QR charges', 'bank-charges' => 'Bank charges', 'purchase-returns' => 'Purchase returns', 'returns' => 'Sales returns', 'collections' => 'Due collections', 'audit' => 'Audit log'];

    public static function titles(): array
    {
        if (class_exists(\App\Support\SecretControl::class) && \App\Support\SecretControl::isHidden('disable_reports')) {
            return [];
        }

        $titles = static::TITLES;

        if (class_exists(\App\Support\SecretControl::class) && \App\Support\SecretControl::isHidden('hide_profit')) {
            unset($titles['profit']);
        }

        return $titles;
    }

    public static function permission(string $kind): string
    {
        return 'reports.'.$kind;
    }

    public function build(string $kind, array $filters, ?array $privacy = null): array
    {
        $from = $filters['from'];
        $to = $filters['to'];
        $cards = [];
        $start = $from.' 00:00:00';
        $end = $to.' 23:59:59';
        $ids = null;
        if ($privacy && $privacy['mode'] !== 'all' && in_array($kind, self::RANKED_REPORTS, true) && $kind !== 'sales') {
            $candidates = Sale::visibleTo()->whereBetween('sold_at', [$start, $end])
                ->when(! empty($filters['user_id']) && ! in_array($kind, ['collections', 'returns'], true), fn ($q) => $q->where('user_id', $filters['user_id']))
                ->when(! empty($filters['q']) && $kind !== 'returns', fn ($q) => $q->where('invoice', 'like', '%'.$filters['q'].'%'));
            $tenderFilters = array_filter(array_intersect_key($filters, array_flip(['payment_method_id', 'rule_id', 'charge_bearer'])));
            if (in_array($kind, ['payment-charges', 'card-charges', 'qr-charges', 'bank-charges'], true) || ($kind === 'payments' && $tenderFilters)) {
                $candidates->whereHas('payments', function ($query) use ($filters, $kind) {
                    foreach (['payment_method_id' => 'payment_method_id', 'rule_id' => 'payment_charge_rule_id', 'charge_bearer' => 'charge_bearer'] as $input => $column) {
                        if (! empty($filters[$input])) {
                            $query->where($column, $filters[$input]);
                        }
                    }
                    if ($kind !== 'payments') {
                        $query->where('processing_charge', '>', 0);
                    }
                    if ($type = ['card-charges' => 'CARD', 'qr-charges' => 'QR', 'bank-charges' => 'BANK_TRANSFER'][$kind] ?? null) {
                        $query->where('method_type', $type);
                    }
                });
            }
            $ids = SalesPrivacy::selectedIds($candidates, $privacy);
        }
        $pay = SalePayment::featureVisible()->with(['sale.user', 'expense'])->whereHas('sale', fn ($q) => $q->visibleTo()->whereIn('status', ['ACTIVE', 'RETURN_CANCELLED'])->whereBetween('sold_at', [$start, $end]));
        if ($ids) {
            $pay->whereIn('sale_id', $ids);
        }
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
            $base = (clone $pay)->selectRaw("payment_method_id, method_name, method_type, sale_amount as base, CASE WHEN charge_bearer = 'CUSTOMER' THEN processing_charge ELSE 0 END as customer_fees, CASE WHEN charge_bearer = 'BUSINESS' THEN processing_charge ELSE 0 END as business_fees, CASE WHEN method_type='CHEQUE' THEN 0 ELSE amount_paid - sale_payments.change END as collections");
            if (empty($filters['rule_id']) && empty($filters['charge_bearer'])) {
                $collections = SaleCollection::settled()->featureVisible()->whereHas('sale', fn ($q) => $q->visibleTo())->whereBetween('collected_at', [$start, $end])->selectRaw("payment_method_id, method_name, method_type, 0 as base, 0 as customer_fees, 0 as business_fees, CASE WHEN method_type='CHEQUE' THEN 0 ELSE amount END as collections");
                $refunds = SaleReturn::visibleTo()->whereBetween('returned_at', [$start, $end])->where('refund_amount', '>', 0)->selectRaw('payment_method_id, method_name, method_type, 0 as base, 0 as customer_fees, 0 as business_fees, -refund_amount as collections');
                foreach ([$collections, $refunds] as $activity) {
                    if (! empty($filters['payment_method_id'])) {
                        $activity->where('payment_method_id', $filters['payment_method_id']);
                    }
                    if (! empty($filters['user_id'])) {
                        $activity->where('user_id', $filters['user_id']);
                    }
                }
                $events = ReturnSettlement::whereBetween('created_at', [$start, $end])->whereIn('kind', ['PURCHASE_RETURN_REFUND', 'PURCHASE_RETURN_PAYMENT', 'SUPPLIER_RETURN_REFUND', 'REVERSAL'])->where(fn ($q) => $q->whereNull('sale_return_id')->orWhereHas('saleReturn', fn ($q) => $q->visibleTo()))->selectRaw("payment_method_id, method_name, method_type, 0 as base, 0 as customer_fees, 0 as business_fees, CASE WHEN method_type='CHEQUE' THEN 0 ELSE amount END as collections");
                if ($ids) {
                    $collections->whereIn('sale_id', $ids);
                    $refunds->whereIn('sale_id', $ids);
                    $events->whereHas('saleReturn', fn ($q) => $q->whereIn('sale_id', $ids));
                }
                foreach (['user_id', 'payment_method_id'] as $key) {
                    if (! empty($filters[$key])) {
                        $events->where($key, $filters[$key]);
                    }
                }
                if (config('features.cheque')) {
                    $cleared = Cheque::visibleTo()->where('type', 'CUSTOMER_RECEIVED')->where('status', 'PASSED')->whereBetween('passed_at', [$start, $end])->selectRaw("(SELECT id FROM payment_methods WHERE type='CHEQUE' ORDER BY id LIMIT 1) as payment_method_id, 'Cheque' as method_name, 'CHEQUE' as method_type, 0 as base, 0 as customer_fees, 0 as business_fees, amount as collections");
                    if ($ids) {
                        $cleared->whereIn('sale_id', $ids);
                    }
                    if (! empty($filters['payment_method_id'])) {
                        $cleared->whereRaw("(SELECT id FROM payment_methods WHERE type='CHEQUE' ORDER BY id LIMIT 1) = ?", [$filters['payment_method_id']]);
                    }
                    if (! empty($filters['user_id'])) {
                        $cleared->where('created_by', $filters['user_id']);
                    }
                    $base->unionAll($cleared);
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
                $query->whereHas($kind === 'purchase-returns' ? 'items.item' : 'items', fn ($q) => $q->where('product_id', $filters['product_id']));
            }
            if (! empty($filters['payment_method_id'])) {
                $query->whereHas('settlements', fn ($q) => $q->where('payment_method_id', $filters['payment_method_id']));
            }
            $headers = ['Date', 'Return', 'Purchase', 'Supplier', 'Value', 'Due reduced', 'Replacement', 'Received', 'Paid', 'Supplier credit', 'Status', 'Cashier'];
            $map = fn ($r) => [$r->returned_at->format('Y-m-d H:i'), $r->reference, $r->purchase->reference, $r->supplier?->name ?? 'Direct supplier', Money::display($r->amount), Money::display($r->due_reduction), Money::display($r->replacement_value), Money::display($r->refund_amount), Money::display($r->additional_payment), Money::display($r->supplier_credit), $r->status, $r->user->name];
            $cards = ['Completed returns' => (string) (clone $query)->completed()->sum('amount'), 'Supplier refunds received' => (string) (clone $query)->completed()->sum('refund_amount')];
        } elseif (in_array($kind, ['returns', 'collections'])) {
            $isReturn = $kind === 'returns';
            $query = ($isReturn ? SaleReturn::visibleTo() : SaleCollection::settled()->featureVisible()->whereHas('sale', fn ($q) => $q->visibleTo()))->with('sale', 'user')->whereBetween($isReturn ? 'returned_at' : 'collected_at', [$start, $end])->latest('id');
            if ($ids) {
                if ($isReturn) {
                    $query->where(fn ($q) => $q->whereIn('sale_id', $ids)->orWhere(fn ($q) => $q
                        ->where('return_type', 'NO_RECEIPT')->whereHas('items')
                        ->whereDoesntHave('items', fn ($line) => $line->whereDoesntHave('item', fn ($item) => $item->whereIn('sale_id', $ids)))));
                } else {
                    $query->whereIn('sale_id', $ids);
                }
            }
            if (! empty($filters['user_id'])) {
                $query->where('user_id', $filters['user_id']);
            }
            if (! empty($filters['payment_method_id'])) {
                $query->where('payment_method_id', $filters['payment_method_id']);
            }
            if (! empty($filters['q'])) {
                if ($isReturn) {
                    $query->where(fn ($q) => $q->where('reference', 'like', '%'.$filters['q'].'%')->orWhereHas('sale', fn ($q) => $q->where('invoice', 'like', '%'.$filters['q'].'%'))->orWhereHas('items.item.sale', fn ($q) => $q->where('invoice', 'like', '%'.$filters['q'].'%')));
                } else {
                    $query->whereHas('sale', fn ($q) => $q->where('invoice', 'like', '%'.$filters['q'].'%'));
                }
            }
            if ($isReturn) {
                foreach (['status', 'reason', 'resolution', 'customer_id', 'return_type', 'verification_status', 'approved_by'] as $key) {
                    if (! empty($filters[$key])) {
                        $query->where($key, $filters[$key]);
                    }
                }
                if (! empty($filters['stock_action'])) {
                    $query->whereHas('items', fn ($q) => $q->where('stock_action', $filters['stock_action']));
                }
                if (! empty($filters['product_id'])) {
                    $query->whereHas($kind === 'purchase-returns' ? 'items.item' : 'items', fn ($q) => $q->where('product_id', $filters['product_id']));
                }
            }
            if ($isReturn) {
                $query->with('items.item.sale', 'customer');
            }
            $headers = $isReturn ? ['Date', 'Return', 'Invoice', 'Return value', 'Due reduction', 'Refund paid', 'Method', 'Register', 'Cashier', 'Reason', 'Replacement', 'Additional payment', 'Status', 'Return type', 'Verification', 'Estimated cost source', 'Customer', 'Approved by', 'Approved at', 'Other customer due credit'] : ['Date', 'Invoice', 'Collected', 'Received', 'Change', 'Method', 'Register', 'Cashier', 'Reference'];
            $map = $isReturn ? fn ($r) => [$r->returned_at->format('Y-m-d H:i'), $r->reference, $r->sale?->invoice ?? ($r->items->filter(fn ($i) => $i->sale_item_id)->map(fn ($i) => $i->item->sale->invoice)->unique()->join(', ') ?: 'No original invoice linked'), Money::display($r->amount), Money::display($r->due_reduction), Money::display($r->refund_amount), $r->method_name ?? 'No refund', 'REG-'.$r->register_id, $r->user->name, $r->reason, Money::display($r->replacement_value), Money::display($r->additional_payment), $r->status, $r->return_type, $r->verification_status, $r->items->where('cost_basis_type', 'ESTIMATED')->pluck('cost_basis_source')->unique()->join(', '), $r->customer?->name ?? 'Walk-in', $r->approved_by ?? '—', $r->approved_at?->format('Y-m-d H:i') ?? '—', Money::display($r->customer_due_applied)] : fn ($c) => [$c->collected_at->format('Y-m-d H:i'), $c->sale->invoice, Money::display($c->amount), Money::display($c->amount_paid), Money::display($c->change), $c->method_name, 'REG-'.$c->register_id, $c->user->name, $c->reference ?? '—'];
            $cards = $isReturn ? ['Returns' => (string) (clone $query)->completed()->sum('amount'), 'Refunds paid' => (string) (clone $query)->completed()->sum('refund_amount'), 'Due reduced' => Money::add((string) (clone $query)->completed()->sum('due_reduction'), (string) (clone $query)->completed()->sum('customer_due_applied')), 'With bill' => (string) (clone $query)->completed()->where('return_type', 'INVOICE')->sum('amount'), 'Without bill' => (string) (clone $query)->completed()->where('return_type', 'NO_RECEIPT')->sum('amount'), 'Cash refunds' => (string) (clone $query)->completed()->where('method_type', 'CASH')->sum('refund_amount')] : ['Dues collected' => (string) (clone $query)->sum('amount')];
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
            $ids = SalesPrivacy::selectedIds($query, $privacy);
            if ($ids) {
                $query->whereIn('sales.id', $ids);
            }
            $headers = ['Date', 'Invoice', 'Customer', 'Subtotal', 'Discount', 'Base sales', 'Method', 'Returned', 'Balance due', 'Status'];
            $map = fn ($s) => [$s->sold_at->format('Y-m-d H:i'), $s->invoice, $s->customer?->name ?? 'Walk-in', Money::displayPrice($s->subtotal), Money::displayPrice($s->discount), Money::displayPrice($s->sale_amount), $s->payment_names, Money::displayPrice($s->returned_total), Money::displayPrice($s->due_balance), $s->status];
            if (config('features.cheque')) {
                $headers = array_merge($headers, ['Cheque Hold', 'Settled cheque']);
                $baseMap = $map;
                $map = fn ($s) => array_merge($baseMap($s), [Money::displayPrice($s->cheque_hold), Money::displayPrice(Money::sum($s->payments->where('method_type', 'CHEQUE')->where('settlement_status', 'PASSED')->pluck('amount_paid')))]);
            }
            $canCost = (auth()->user()?->hasPermission('products.view_cost') ?? false) && ! (class_exists(\App\Support\SecretControl::class) && \App\Support\SecretControl::isHidden('hide_stock_money'));
            $canProfit = (auth()->user()?->hasPermission('products.view_cost') ?? false) && ! (class_exists(\App\Support\SecretControl::class) && \App\Support\SecretControl::isHidden('hide_profit'));

            $sellMult = class_exists(\App\Support\SecretControl::class) ? \App\Support\SecretControl::sellingPriceMultiplier() : 1.0;
            $costMult = class_exists(\App\Support\SecretControl::class) ? \App\Support\SecretControl::costMultiplier() : 1.0;

            $extraHeaders = [];
            if ($canCost) {
                $extraHeaders[] = 'Invoice COGS after returns';
            }
            if ($canProfit) {
                $extraHeaders[] = 'Invoice gross profit after returns';
                $extraHeaders[] = 'Margin %';
            }

            if (! empty($extraHeaders)) {
                $headers = array_merge($headers, $extraHeaders);
                $baseMap = $map;
                $map = function ($s) use ($baseMap, $canCost, $canProfit, $sellMult, $costMult) {
                    $rawCogs = (float) Money::sub($s->cost_total, $s->returned_cost);
                    $rawRevenue = (float) Money::sub($s->sale_amount, $s->returned_total);
                    $cogs = $rawCogs * $costMult;
                    $revenue = $rawRevenue * $sellMult;
                    $profit = $revenue - $cogs;
                    $margin = $revenue > 0 ? (string) round(($profit / $revenue) * 100, 2) : '0.00';

                    $extras = [];
                    if ($canCost) {
                        $extras[] = Money::formatRaw($cogs);
                    }
                    if ($canProfit) {
                        $extras[] = Money::formatRaw($profit);
                        $extras[] = $margin;
                    }

                    return array_merge($baseMap($s), $extras);
                };
            }
            $periodReturns = SaleReturn::visibleTo()->whereBetween('returned_at', [$start, $end]);
            $saleFilter = function ($q) use ($filters, $ids) {
                if ($ids) {
                    $q->whereIn('sales.id', $ids);
                }
                if (! empty($filters['user_id'])) {
                    $q->where('user_id', $filters['user_id']);
                }
                if (! empty($filters['payment_method_id'])) {
                    $q->whereHas('payments', fn ($p) => $p->where('payment_method_id', $filters['payment_method_id']));
                }
                if (! empty($filters['q'])) {
                    $q->where('invoice', 'like', '%'.$filters['q'].'%');
                }
            };
            $periodReturns->where(fn ($q) => $q->whereHas('sale', $saleFilter)->orWhereHas('items.item.sale', $saleFilter));
            $original = (string) (clone $query)->whereIn('status', ['ACTIVE', 'RETURN_CANCELLED'])->sum('sale_amount');
            $exchangeReversals = Sale::visibleTo()->where('status', 'RETURN_CANCELLED')->whereBetween('voided_at', [$start, $end]);
            if ($ids) {
                $exchangeReversals->whereIn('sales.id', $ids);
            }
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
            $normalCancelled = SaleReturn::visibleTo()->where('return_type', 'INVOICE')->whereHas('sale', $saleFilter)->where('status', 'CANCELLED')->whereBetween('cancelled_at', [$start, $end]);
            $linkedReturned = SaleReturnItem::whereHas('item.sale', $saleFilter)->whereHas('return', fn ($q) => $q->visibleTo()->where('return_type', 'NO_RECEIPT')->whereBetween('returned_at', [$start, $end]));
            $linkedCancelled = SaleReturnItem::whereHas('item.sale', $saleFilter)->whereHas('return', fn ($q) => $q->visibleTo()->where('return_type', 'NO_RECEIPT')->where('status', 'CANCELLED')->whereBetween('cancelled_at', [$start, $end]));
            $returned = Money::sub(Money::add((string) $periodReturns->where('return_type', 'INVOICE')->sum('amount'), (string) $linkedReturned->sum('amount')), Money::add((string) $normalCancelled->sum('amount'), (string) $linkedCancelled->sum('amount')));
            $cards = [
                'Sales before returns'     => (string) round((float) $original * $sellMult, 2),
                'Returns in period'        => (string) round((float) $returned * $sellMult, 2),
                'Net revenue in period'    => (string) round(((float) $original - (float) $returned) * $sellMult, 2),
                'Original invoice payable' => (string) round((float) ((clone $query)->where('status', 'ACTIVE')->sum('customer_payable')) * $sellMult, 2),
            ];
            if (class_exists(\App\Support\SecretControl::class) && \App\Support\SecretControl::isHidden('hide_sales_value')) {
                $cards = [];
            }
            if ($ids) {
                $query->reorder('customer_payable', SalesPrivacy::direction($privacy))->orderBy('sales.id');
                $headers[] = 'Bill total';
                $baseMap = $map;
                $map = fn ($sale) => [...$baseMap($sale), Money::displayPrice($sale->customer_payable)];
            }
        } elseif ($kind === 'purchases') {
            $costMult = class_exists(\App\Support\SecretControl::class) ? \App\Support\SecretControl::costMultiplier() : 1.0;
            $query = Purchase::with('supplier', 'user')->withPaymentTotals()->whereBetween('purchase_date', [$from, $to])->latest('purchase_date');
            $headers = ['Date', 'Reference', 'Supplier', 'Amount', 'Paid less refunds', 'Due', 'Payment status', 'Recorded by', 'Status'];
            $map = fn ($p) => [$p->purchase_date->format('Y-m-d'), $p->reference, $p->supplier?->name ?? 'Direct supplier', Money::displayCost($p->total), $p->payment_tracking ? Money::displayCost(config('features.cheque') ? $p->settled_amount : $p->paid_amount) : 'Unrecorded', $p->due_amount !== null ? Money::displayCost($p->due_amount) : 'Unrecorded', $p->payment_status, $p->user->name, $p->status];
            if (config('features.cheque')) {
                $headers = array_merge($headers, ['Cheque Hold', 'Payment methods']);
                $baseMap = $map;
                $map = fn ($p) => array_merge($baseMap($p), [Money::displayCost($p->cheque_hold), $p->payments->filter(fn ($payment) => $payment->is_effective)->pluck('method_name')->unique()->join(' + ')]);
            }
            $grossPurchases = (string) (clone $query)->whereIn('status', ['ACTIVE', 'RETURN_CANCELLED'])->sum('total');
            $reversals = PurchaseReturn::where('status', 'CANCELLED')->whereBetween('cancelled_at', [$start, $end]);
            $grossPurchases = Money::sub($grossPurchases, Money::sum((clone $reversals)->with('replacement')->get()->map(fn ($r) => $r->replacement?->total ?? '0')));
            $returned = Money::sub((string) PurchaseReturn::whereBetween('returned_at', [$start, $end])->sum('amount'), (string) $reversals->sum('amount'));
            $cards = [
                'Gross purchases'  => (string) round((float) $grossPurchases * $costMult, 2),
                'Purchase returns' => (string) round((float) $returned * $costMult, 2),
                'Net purchases'    => (string) round(((float) $grossPurchases - (float) $returned) * $costMult, 2),
            ];
            if (class_exists(\App\Support\SecretControl::class)) {
                $daysDiff = \Carbon\Carbon::parse($from)->diffInDays(\Carbon\Carbon::parse($to));
                if (\App\Support\SecretControl::isHidden('hide_purchases_value')
                    || (\App\Support\SecretControl::isHidden('hide_weekly_purchase_info') && $daysDiff <= 7)
                    || (\App\Support\SecretControl::isHidden('hide_monthly_purchase_info') && $daysDiff > 7)) {
                    $cards = [];
                }
            }
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
            $canCost = (auth()->user()?->hasPermission('products.view_cost') ?? false) 
                && ! (class_exists(\App\Support\SecretControl::class) && (\App\Support\SecretControl::isHidden('hide_stock_money') || \App\Support\SecretControl::isHidden('hide_exact_purchase_cost') || \App\Support\SecretControl::isHidden('hide_all_price_data')));
            $canSellingPrice = ! (class_exists(\App\Support\SecretControl::class) && (\App\Support\SecretControl::isHidden('hide_exact_selling_price') || \App\Support\SecretControl::isHidden('hide_all_price_data')));
            $canSalesValue = ! (class_exists(\App\Support\SecretControl::class) && (\App\Support\SecretControl::isHidden('hide_sales_value') || \App\Support\SecretControl::isHidden('hide_exact_selling_price') || \App\Support\SecretControl::isHidden('hide_all_price_data')));

            $lotQuery = ProductStockLayer::where('status', 'ACTIVE')->whereHas('product', fn ($q) => $q->where('track_stock', true))->with('product.unit', 'product.categories');
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
            $stockMult = class_exists(\App\Support\SecretControl::class) ? \App\Support\SecretControl::stockMultiplier() : 1.0;
            $priceMult = class_exists(\App\Support\SecretControl::class) ? \App\Support\SecretControl::sellingPriceMultiplier() : 1.0;
            $costMult = class_exists(\App\Support\SecretControl::class) ? \App\Support\SecretControl::costMultiplier() : 1.0;
            $scaledCostVal = (float) ($values->cost_value ?? 0) * $stockMult * $costMult;
            $scaledSellVal = (float) ($values->selling_value ?? 0) * $stockMult * $priceMult;

            $cards = [];
            if ($canSalesValue) {
                $cards['Potential sales value'] = (string) $scaledSellVal;
            }
            if ($canCost) {
                $cards['Stock at cost'] = (string) $scaledCostVal;
            }
            if (($filters['stock_view'] ?? '') === 'layers') {
                $query = $lotQuery->orderBy('product_id')->orderBy('received_at')->orderBy('id');
                $headers = ['Product', 'SKU', 'Primary unit'];
                if ($canSellingPrice) {
                    $headers[] = 'Selling price';
                }
                $headers = array_merge($headers, ['Available', 'Original quantity', 'Source', 'Received']);
                if ($canCost) {
                    $headers = array_merge($headers, ['Cost / unit', 'Stock at cost']);
                }
                $map = function ($l) use ($canCost, $canSellingPrice, $stockMult) {
                    $scaledRem = (float) $l->remaining_quantity * $stockMult;
                    $scaledOrig = (float) $l->original_quantity * $stockMult;

                    if (class_exists(\App\Support\SecretControl::class) && ! \App\Support\SecretControl::isSuperAdminArea()) {
                        if (\App\Support\SecretControl::isHidden('hide_all_quantity_data')) {
                            $remDisplay = '—';
                            $origDisplay = '—';
                        } elseif (\App\Support\SecretControl::isHidden('hide_exact_stock_quantity')) {
                            $remDisplay = $scaledRem > 0 ? 'In stock' : 'Out of stock';
                            $origDisplay = '—';
                        } else {
                            $remDisplay = number_format($scaledRem, 3, '.', '');
                            $origDisplay = number_format($scaledOrig, 3, '.', '');
                        }
                    } else {
                        $remDisplay = $l->remaining_quantity;
                        $origDisplay = $l->original_quantity;
                    }

                    $prodName = class_exists(\App\Support\SecretControl::class) ? \App\Support\SecretControl::productName($l->product) : $l->product->name;
                    $prodSku = class_exists(\App\Support\SecretControl::class) && \App\Support\SecretControl::isHidden('hide_product_level_data') ? 'SKU-'.$l->product->id : $l->product->sku;

                    $row = [
                        $prodName,
                        $prodSku,
                        $l->product->unit->short_name,
                    ];

                    if ($canSellingPrice) {
                        $sellingPriceDisplay = Money::displayPrice($l->selling_price);
                        if (class_exists(\App\Support\SecretControl::class) && \App\Support\SecretControl::isSellingPriceHiddenFor($l->selling_price)) {
                            $sellingPriceDisplay = '—';
                        }
                        $row[] = $sellingPriceDisplay;
                    }

                    $row = array_merge($row, [
                        $remDisplay,
                        $origDisplay,
                        $l->source_reference ?? $l->source_type,
                        $l->received_at->format('Y-m-d'),
                    ]);

                    if ($canCost) {
                        $costPriceDisplay = Money::displayCost($l->cost_price);
                        $stockValDisplay = Money::displayCost((float) $l->stock_value * $stockMult);
                        if (class_exists(\App\Support\SecretControl::class) && \App\Support\SecretControl::isCostHiddenFor($l->cost_price)) {
                            $costPriceDisplay = '—';
                            $stockValDisplay = '—';
                        }
                        $row[] = $costPriceDisplay;
                        $row[] = $stockValDisplay;
                    }

                    return $row;
                };
            } else {
                $query = Product::where('track_stock', true)->with('unit', 'categories')->with(['stockLayers' => fn ($q) => $q->available()])->orderBy('name');
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
                $headers = ['Product', 'SKU', 'Categories', 'Unit', 'Stock', 'Low stock level'];
                if ($canSellingPrice) {
                    $headers[] = 'Available selling prices';
                }
                if ($canSalesValue) {
                    $headers[] = 'Potential sales value';
                }
                if ($canCost) {
                    $headers[] = 'Stock at cost';
                }
                $map = function ($p) use ($canCost, $canSellingPrice, $canSalesValue, $stockMult) {
                    $scaledStock = (float) $p->stock * $stockMult;
                    $scaledLow = (float) $p->low_stock * $stockMult;

                    if (class_exists(\App\Support\SecretControl::class) && ! \App\Support\SecretControl::isSuperAdminArea()) {
                        if (\App\Support\SecretControl::isHidden('hide_all_quantity_data')) {
                            $stockDisplay = '—';
                            $lowDisplay = '—';
                        } elseif (\App\Support\SecretControl::isHidden('hide_exact_stock_quantity')) {
                            $stockDisplay = $scaledStock > 0 ? 'In stock' : 'Out of stock';
                            $lowDisplay = '—';
                        } else {
                            $stockDisplay = number_format($scaledStock, 3, '.', '');
                            $lowDisplay = number_format($scaledLow, 3, '.', '');
                        }
                    } else {
                        $stockDisplay = $p->stock;
                        $lowDisplay = $p->low_stock;
                    }

                    $prodName = class_exists(\App\Support\SecretControl::class) ? \App\Support\SecretControl::productName($p) : $p->name;
                    $prodSku = class_exists(\App\Support\SecretControl::class) && \App\Support\SecretControl::isHidden('hide_product_level_data') ? 'SKU-'.$p->id : $p->sku;

                    $row = [
                        $prodName,
                        $prodSku,
                        $p->categories->pluck('name')->join(', '),
                        $p->unit->short_name,
                        $stockDisplay,
                        $lowDisplay,
                    ];

                    if ($canSellingPrice) {
                        $prices = $p->stockLayers->pluck('selling_price')->unique()->sort()->map(function ($v) {
                            if (class_exists(\App\Support\SecretControl::class) && \App\Support\SecretControl::isSellingPriceHiddenFor($v)) {
                                return null;
                            }
                            return Money::displayPrice($v);
                        })->filter()->join(' / ');
                        $row[] = $prices ?: '—';
                    }

                    if ($canSalesValue) {
                        $rawSalesVal = Money::sum($p->stockLayers->map(fn ($l) => Money::mul($l->remaining_quantity, $l->selling_price)));
                        $priceMult = class_exists(\App\Support\SecretControl::class) ? \App\Support\SecretControl::sellingPriceMultiplier() : 1.0;
                        $scaledSalesVal = (float) $rawSalesVal * $stockMult * $priceMult;

                        if (class_exists(\App\Support\SecretControl::class) && $p->stockLayers->contains(fn ($l) => \App\Support\SecretControl::isSellingPriceHiddenFor($l->selling_price))) {
                            $salesValDisplay = '—';
                        } else {
                            $salesValDisplay = Money::formatRaw($scaledSalesVal);
                        }
                        $row[] = $salesValDisplay;
                    }

                    if ($canCost) {
                        $rawCostVal = Money::sum($p->stockLayers->map(fn ($l) => $l->stock_value));
                        $costMult = class_exists(\App\Support\SecretControl::class) ? \App\Support\SecretControl::costMultiplier() : 1.0;
                        $scaledCostVal = (float) $rawCostVal * $stockMult * $costMult;

                        if (class_exists(\App\Support\SecretControl::class) && $p->stockLayers->contains(fn ($l) => \App\Support\SecretControl::isCostHiddenFor($l->cost_price))) {
                            $costValDisplay = '—';
                        } else {
                            $costValDisplay = Money::formatRaw($scaledCostVal);
                        }
                        $row[] = $costValDisplay;
                    }

                    return $row;
                };
            }
        } elseif ($kind === 'product-sales') {
            $query = SaleItem::whereHas('sale', fn ($q) => $q->visibleTo()->where('status', 'ACTIVE')->whereBetween('sold_at', [$start, $end]))->selectRaw('product_id, name, unit, SUM(quantity) as quantity, SUM(total) as total, SUM(COALESCE(cogs_total, COALESCE(base_quantity, quantity) * COALESCE(base_cost, cost))) as cogs')->groupBy('product_id', 'name', 'unit')->orderByDesc('total');
            if ($ids) {
                $query->whereIn('sale_id', $ids);
            }
            $headers = ['Product', 'Unit', 'Original quantity before returns', 'Original line sales before invoice discount / returns'];
            $stockMult = class_exists(\App\Support\SecretControl::class) ? \App\Support\SecretControl::stockMultiplier() : 1.0;
            $sellMult = class_exists(\App\Support\SecretControl::class) ? \App\Support\SecretControl::sellingPriceMultiplier() : 1.0;
            $costMult = class_exists(\App\Support\SecretControl::class) ? \App\Support\SecretControl::costMultiplier() : 1.0;

            $canCost = (auth()->user()?->hasPermission('products.view_cost') ?? false) && ! (class_exists(\App\Support\SecretControl::class) && \App\Support\SecretControl::isHidden('hide_stock_money')) && ! (class_exists(\App\Support\SecretControl::class) && \App\Support\SecretControl::isHidden('hide_exact_purchase_cost'));
            $canProfit = (auth()->user()?->hasPermission('products.view_cost') ?? false) && ! (class_exists(\App\Support\SecretControl::class) && \App\Support\SecretControl::isHidden('hide_profit'));

            $extraHeaders = [];
            if ($canCost) {
                $extraHeaders[] = 'Actual COGS before returns';
            }
            if ($canProfit) {
                $extraHeaders[] = 'Gross profit before invoice discount / returns';
                $extraHeaders[] = 'Margin % before invoice discount / returns';
            }
            $headers = array_merge($headers, $extraHeaders);
            $map = function ($i) use ($canCost, $canProfit, $stockMult, $sellMult, $costMult) {
                $scaledQty = (float) $i->quantity * $stockMult;
                if (class_exists(\App\Support\SecretControl::class) && \App\Support\SecretControl::isHidden('hide_all_quantity_data')) {
                    $qtyDisplay = '—';
                } elseif (class_exists(\App\Support\SecretControl::class) && \App\Support\SecretControl::isHidden('hide_exact_stock_quantity')) {
                    $qtyDisplay = $scaledQty > 0 ? 'Sold' : '0';
                } else {
                    $qtyDisplay = number_format($scaledQty, 3, '.', '');
                }

                $scaledTotal = (float) $i->total * $stockMult * $sellMult;
                $scaledCogs = (float) $i->cogs * $stockMult * $costMult;

                $row = [
                    class_exists(\App\Support\SecretControl::class) ? \App\Support\SecretControl::productName($i) : $i->name,
                    $i->unit,
                    $qtyDisplay,
                    Money::formatRaw($scaledTotal),
                ];
                if ($canCost) {
                    $row[] = Money::formatRaw($scaledCogs);
                }
                if ($canProfit) {
                    $profitVal = $scaledTotal - $scaledCogs;
                    $row[] = Money::formatRaw($profitVal);
                    $row[] = $scaledTotal > 0 ? (string) round(($profitVal / $scaledTotal) * 100, 2) : '0.00';
                }
                return $row;
            };
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
            $sp = DB::table('sale_payments')->whereNull('cheque_id')->whereIn('sale_id', Sale::visibleTo()->select('id'))->selectRaw("'Sale' as type, sale_id as reference_id, reference, created_at as date, payment_method_id, amount_paid - `change` as in_amount, 0 as out_amount");
            $cp = SalesVisibility::apply(DB::table('customer_payments')->whereNull('cheque_id'), 'customer_payments.user_id')->selectRaw("'Customer payment' as type, id as reference_id, notes as reference, payment_date as date, payment_method_id, amount as in_amount, 0 as out_amount");
            $sc = DB::table('sale_collections')->whereNull('cheque_id')->whereIn('sale_id', Sale::visibleTo()->select('id'))->selectRaw("'Due collection' as type, sale_id as reference_id, reference, collected_at as date, payment_method_id, amount as in_amount, 0 as out_amount");
            $sr = DB::table('sale_returns')->whereIn('id', SaleReturn::visibleTo()->select('id'))->selectRaw("CASE WHEN return_type='NO_RECEIPT' THEN 'NO_RECEIPT_SALES_RETURN_REFUND' ELSE 'Refund' END as type, id as reference_id, reference, returned_at as date, payment_method_id, 0 as in_amount, refund_amount as out_amount")->whereNotNull('payment_method_id');
            $pp = SalesVisibility::apply(DB::table('purchase_payments')->whereNull('cheque_id'), 'purchase_payments.user_id')->selectRaw("CASE WHEN kind = 'REFUND' THEN 'Supplier refund' ELSE 'Supplier payment' END as type, purchase_id as reference_id, reference, created_at as date, payment_method_id, CASE WHEN kind = 'REFUND' THEN amount ELSE 0 END as in_amount, CASE WHEN kind = 'PAYMENT' THEN amount ELSE 0 END as out_amount");
            $xp = SalesVisibility::apply(DB::table('expenses'), 'expenses.user_id')->where(fn ($q) => $q->whereNull('sale_id')->orWhereIn('sale_id', Sale::visibleTo()->select('id')))->selectRaw("'Expense' as type, id as reference_id, reference, expense_date as date, payment_method_id, 0 as in_amount, amount as out_amount")->whereNotNull('payment_method_id')->where('status', 'ACTIVE');

            $returnEvents = DB::table('return_settlements as r')->leftJoin('sale_returns as sr', 'sr.id', '=', 'r.sale_return_id')->leftJoin('purchase_returns as pr', 'pr.id', '=', 'r.purchase_return_id')->leftJoin('supplier_returns as sup', 'sup.id', '=', 'r.supplier_return_id')->whereIn('r.kind', ['PURCHASE_RETURN_REFUND', 'PURCHASE_RETURN_PAYMENT', 'SUPPLIER_RETURN_REFUND', 'REVERSAL'])->where(fn ($q) => $q->whereNull('sr.id')->orWhereIn('sr.id', SaleReturn::visibleTo()->select('id')))->selectRaw('r.kind as type, r.id as reference_id, COALESCE(sr.reference,pr.reference,sup.reference) as reference,r.created_at as date,r.payment_method_id,CASE WHEN r.amount>0 THEN r.amount ELSE 0 END as in_amount,CASE WHEN r.amount<0 THEN -r.amount ELSE 0 END as out_amount');
            $unionQuery = $sp->unionAll($cp)->unionAll($sc)->unionAll($sr)->unionAll($pp)->unionAll($xp)->unionAll($returnEvents);
            if (config('features.cheque')) {
                $cleared = Cheque::visibleTo()->where('status', 'PASSED')->whereIn('type', ['CUSTOMER_RECEIVED', 'OWN_CHEQUE'])->selectRaw("'Cheque passed' as type,id as reference_id,cheque_number as reference,passed_at as date,(SELECT id FROM payment_methods WHERE type='CHEQUE' ORDER BY id LIMIT 1) as payment_method_id,CASE WHEN type='CUSTOMER_RECEIVED' THEN amount ELSE 0 END as in_amount,CASE WHEN type='OWN_CHEQUE' THEN amount ELSE 0 END as out_amount")->toBase();
                $unionQuery->unionAll($cleared);
            }
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
            $query = SalesVisibility::apply(AuditLog::with('user')->when(! \App\Support\Units::enabled(), fn ($q) => $q->where('action', 'not like', 'units.%')->where('action', 'not like', 'unit-presets.%')->where('action', '!=', 'unit.default'))->where('action', 'not like', 'hr.%')->when(! config('features.barcode'), fn ($q) => $q->where('action', 'not like', 'barcode.%')->where('action', '!=', 'settings.barcode')), 'audit_logs.user_id')->whereBetween('created_at', [$start, $end])->latest();
            if (SalesVisibility::mode() !== 'ALL') {
                $subjects = [Sale::class => Sale::visibleTo()->select('id')];
                foreach ([SaleReturn::class, SaleCollection::class, SaleRevision::class] as $subject) {
                    $subjects[$subject] = ($subject === SaleReturn::class ? $subject::visibleTo() : $subject::whereHas('sale', fn ($q) => $q->visibleTo()))->select('id');
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

        if (! \App\Support\Units::enabled()) {
            $hidden = array_keys(array_filter($headers, fn ($header) => in_array($header, ['Unit', 'Primary unit', 'Conversion'])));
            $headers = array_values(array_diff_key($headers, array_flip($hidden)));
            $headers = array_map(fn ($header) => str_replace(' / unit', '', $header), $headers);
            $originalMap = $map;
            $map = fn ($row) => array_values(array_diff_key($originalMap($row), array_flip($hidden)));
        }

        return compact('query', 'headers', 'map', 'cards');
    }
}
