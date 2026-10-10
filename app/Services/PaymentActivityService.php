<?php

namespace App\Services;

use App\Models\PaymentMethod;
use App\Models\SaleReturn;
use App\Support\Money;
use App\Support\SalesVisibility;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PaymentActivityService
{
    /** Saved payment snapshots, not the method's current fee settings. */
    public function query(array $filters, ?int $methodId = null): Builder
    {
        $checkouts = DB::table('sale_payments as p')->join('sales as s', 's.id', '=', 'p.sale_id')
            ->leftJoin('customers as c', 'c.id', '=', 's.customer_id')->join('users as u', 'u.id', '=', 's.user_id')
            ->whereIn('s.status', ['ACTIVE', 'RETURN_CANCELLED'])->selectRaw("p.id as entry_id, 'Checkout' as kind, s.sold_at as occurred_at, p.payment_method_id, p.method_name, s.id as sale_id, s.invoice as bill, COALESCE(c.name, 'Walk-in customer') as customer, c.id as customer_id, u.name as cashier, p.sale_amount as allocated, p.amount_paid as received, p.change as change_amount, p.amount_paid - p.change as collected, 0 as refunded, p.processing_charge as charges, CASE WHEN p.charge_bearer = 'CUSTOMER' THEN p.processing_charge ELSE 0 END as customer_charges, CASE WHEN p.charge_bearer = 'BUSINESS' THEN p.processing_charge ELSE 0 END as business_charges, p.reference");
        $collections = DB::table('sale_collections as p')->join('sales as s', 's.id', '=', 'p.sale_id')
            ->leftJoin('customers as c', 'c.id', '=', 's.customer_id')->join('users as u', 'u.id', '=', 'p.user_id')
            ->whereIn('s.status', ['ACTIVE', 'RETURN_CANCELLED'])->selectRaw("p.id as entry_id, 'Due collection' as kind, p.collected_at as occurred_at, p.payment_method_id, p.method_name, s.id as sale_id, s.invoice as bill, COALESCE(c.name, 'Walk-in customer') as customer, c.id as customer_id, u.name as cashier, 0 as allocated, p.amount_paid as received, p.change as change_amount, p.amount as collected, 0 as refunded, 0 as charges, 0 as customer_charges, 0 as business_charges, p.reference");
        $refunds = DB::table('sale_returns as p')->leftJoin('sales as s', 's.id', '=', 'p.sale_id')
            ->leftJoin('customers as c', 'c.id', '=', DB::raw('COALESCE(p.customer_id,s.customer_id)'))->join('users as u', 'u.id', '=', 'p.user_id')
            ->whereIn('p.id', SaleReturn::visibleTo()->select('id'))->where('p.refund_amount', '>', 0)->selectRaw("p.id as entry_id, CASE WHEN p.return_type='NO_RECEIPT' THEN 'No receipt refund' ELSE 'Refund' END as kind, p.returned_at as occurred_at, p.payment_method_id, p.method_name, s.id as sale_id, COALESCE(s.invoice,p.reference) as bill, COALESCE(c.name, 'Walk-in customer') as customer, c.id as customer_id, u.name as cashier, 0 as allocated, 0 as received, 0 as change_amount, 0 as collected, p.refund_amount as refunded, 0 as charges, 0 as customer_charges, 0 as business_charges, p.reference");
        // Invoice allocations already appear in sale_collections; include only the remainder here.
        $allocations = DB::table('customer_payment_allocations')->where('type', 'INVOICE')
            ->selectRaw('customer_payment_id, SUM(amount) as invoice_amount')->groupBy('customer_payment_id');
        $accounts = DB::table('customer_payments as p')->join('customers as c', 'c.id', '=', 'p.customer_id')
            ->join('users as u', 'u.id', '=', 'p.user_id')->join('payment_methods as m', 'm.id', '=', 'p.payment_method_id')
            ->leftJoinSub($allocations, 'a', 'a.customer_payment_id', '=', 'p.id')
            ->whereRaw('p.amount - COALESCE(a.invoice_amount, 0) > 0')
            ->selectRaw("p.id as entry_id, 'Account payment' as kind, p.payment_date as occurred_at, p.payment_method_id, m.name as method_name, NULL as sale_id, NULL as bill, c.name as customer, c.id as customer_id, u.name as cashier, 0 as allocated, p.amount - COALESCE(a.invoice_amount, 0) as received, 0 as change_amount, p.amount - COALESCE(a.invoice_amount, 0) as collected, 0 as refunded, 0 as charges, 0 as customer_charges, 0 as business_charges, p.notes as reference");
        foreach ([$checkouts, $collections] as $entryQuery) {
            SalesVisibility::apply($entryQuery, 's.user_id');
        }
        SalesVisibility::apply($accounts, 'p.user_id');
        $returnEvents = DB::table('return_settlements as p')->leftJoin('sale_returns as r', 'r.id', '=', 'p.sale_return_id')->leftJoin('sales as s', 's.id', '=', 'r.sale_id')->leftJoin('purchase_returns as pr', 'pr.id', '=', 'p.purchase_return_id')->leftJoin('supplier_returns as sr', 'sr.id', '=', 'p.supplier_return_id')->leftJoin('customers as c', 'c.id', '=', 'r.customer_id')->join('users as u', 'u.id', '=', 'p.user_id')
            ->where(fn ($q) => $q->whereIn('p.kind', ['PURCHASE_RETURN_REFUND', 'PURCHASE_RETURN_PAYMENT', 'SUPPLIER_RETURN_REFUND', 'REVERSAL']))
            ->where(fn ($q) => $q->whereNull('p.sale_return_id')->orWhereIn('r.id', SaleReturn::visibleTo()->select('id')))
            ->selectRaw("p.id as entry_id, p.kind as kind, p.created_at as occurred_at, p.payment_method_id, p.method_name, s.id as sale_id, COALESCE(r.reference,pr.reference,sr.reference) as bill, COALESCE(c.name,'Supplier') as customer,c.id as customer_id,u.name as cashier,0 as allocated,CASE WHEN p.amount>0 THEN p.amount ELSE 0 END as received,0 as change_amount,CASE WHEN p.amount>0 THEN p.amount ELSE 0 END as collected,CASE WHEN p.amount<0 THEN -p.amount ELSE 0 END as refunded,0 as charges,0 as customer_charges,0 as business_charges,COALESCE(r.reference,pr.reference,sr.reference) as reference");
        $events = $checkouts->unionAll($collections)->unionAll($refunds)->unionAll($accounts)->unionAll($returnEvents);

        return DB::query()->fromSub($events, 'activity')
            ->where('occurred_at', '>=', $filters['from'].' 00:00:00')
            ->where('occurred_at', '<', Carbon::parse($filters['to'])->addDay()->toDateString().' 00:00:00')
            ->when($methodId !== null, fn ($q) => $q->where('payment_method_id', $methodId));
    }

    private function sums(Builder $query): Builder
    {
        return $query->selectRaw('COUNT(*) as entries, COUNT(DISTINCT sale_id) as bills, COALESCE(SUM(collected), 0) as collected, COALESCE(SUM(refunded), 0) as refunded, COALESCE(SUM(charges), 0) as charges, COALESCE(SUM(customer_charges), 0) as customer_charges, COALESCE(SUM(business_charges), 0) as business_charges');
    }

    public function totals(Builder $query): array
    {
        $totals = (array) $this->sums(clone $query)->first();
        foreach (['collected', 'refunded', 'charges', 'customer_charges', 'business_charges'] as $key) {
            $totals[$key] = Money::round((string) $totals[$key]);
        }
        $totals['net'] = Money::sub($totals['collected'], $totals['refunded']);

        return $totals;
    }

    public function overview(array $filters): array
    {
        $methods = PaymentMethod::query()->when($filters['q'] ?? null, fn ($q, $text) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$text.'%')->orWhere('code', 'like', '%'.$text.'%')))
            ->when(isset($filters['active']), fn ($q) => $q->where('active', $filters['active']));
        $query = $this->query($filters)->whereIn('payment_method_id', (clone $methods)->select('id'));
        $totals = $this->totals($query);
        $byMethod = $this->sums(clone $query)->addSelect('payment_method_id')->groupBy('payment_method_id')->get()->keyBy('payment_method_id');
        $rows = $methods->orderBy('display_order')->orderBy('name')->paginate(20)->appends($filters);

        return compact('rows', 'totals', 'byMethod');
    }
}
