<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\ReturnAccountAllocation;
use App\Models\SaleReturn;
use App\Support\Money;
use App\Support\SalesVisibility;
use Carbon\Carbon;

class CustomerLedgerService
{
    public function build(Customer $customer, ?string $from = null, ?string $to = null)
    {
        $entries = collect();
        $add = function ($date, $type, $description, $debit, $credit) use ($entries) {
            $entries->push(['date' => Carbon::parse($date), 'type' => $type, 'description' => $description, 'debit' => $debit, 'credit' => $credit]);
        };
        $chequeEntry = function ($payment, $amount) use ($add) {
            if (! $payment->cheque_id) {
                return;
            }
            $label = config('features.cheque') ? 'Cheque #'.$payment->cheque->cheque_number : 'Account payment';
            if (in_array($payment->settlement_status, ['RETURNED', 'CANCELLED'])) {
                $add($payment->updated_at, config('features.cheque') ? 'CHEQUE '.$payment->settlement_status : 'Payment reversal', $label, $amount, '0.00');
            }
            if (config('features.cheque')) {
                $add($payment->created_at, 'CHEQUE HOLD', $label.' · '.Money::display($amount).' awaiting clearance', '0.00', '0.00');
                if ($payment->settlement_status === 'PASSED') {
                    $add($payment->cheque->passed_at, 'CHEQUE PASSED', $label.' · '.Money::display($amount).' settled', '0.00', '0.00');
                }
            }
        };
        $add($customer->created_at, 'Opening Balance', 'Old balance brought forward', $customer->opening_due, '0.00');
        foreach ($customer->sales()->visibleTo()->whereIn('status', ['ACTIVE', 'RETURN_CANCELLED'])->with('payments', 'collections')->get() as $sale) {
            $add($sale->sold_at, 'Invoice', 'Invoice '.$sale->invoice, $sale->customer_payable, '0.00');
            foreach ($sale->payments as $p) {
                $add($sale->sold_at, $p->cheque_id && config('features.cheque') ? 'CHEQUE RECEIVED' : 'POS Payment', $sale->invoice.' · '.($p->cheque_id && ! config('features.cheque') ? 'Account payment' : $p->method_name), '0.00', Money::sub($p->amount_paid, $p->change));
                $chequeEntry($p, Money::sub($p->amount_paid, $p->change));
            }
            foreach ($sale->collections as $p) {
                $add($p->collected_at, $p->cheque_id && config('features.cheque') ? 'CHEQUE RECEIVED' : 'Due Collection', $sale->invoice.' · '.($p->cheque_id && ! config('features.cheque') ? 'Account payment' : $p->method_name), '0.00', $p->amount);
                $chequeEntry($p, $p->amount);
            }
            if ($sale->status === 'RETURN_CANCELLED') {
                $cash = Money::sum($sale->payments->map(fn ($p) => Money::sub($p->amount_paid, $p->change)));
                $add($sale->voided_at, 'Exchange Cancellation', $sale->invoice, '0.00', Money::sub($sale->customer_payable, $cash));
            }
        }
        foreach (SalesVisibility::apply($customer->customerPayments()->getQuery(), 'customer_payments.user_id')->with('allocations')->get() as $p) {
            $opening = Money::sum($p->allocations->where('type', 'OPENING_BALANCE')->pluck('amount'));
            if (Money::compare($opening, 0) > 0) {
                $add($p->payment_date, $p->cheque_id && config('features.cheque') ? 'CHEQUE RECEIVED' : 'Payment', 'Opening due payment #'.$p->id, '0.00', $opening);
                $chequeEntry($p, $opening);
            }
        }
        foreach (SaleReturn::whereHas('sale', fn ($q) => $q->visibleTo()->where('customer_id', $customer->id))->get() as $r) {
            $add($r->returned_at, 'Sales Return Credit', $r->reference.' · original bill '.$r->sale->invoice, '0.00', $r->due_reduction);
            if ($r->status === 'CANCELLED') {
                $add($r->cancelled_at, 'Return Cancellation', $r->reference, $r->due_reduction, '0.00');
            }
        }
        foreach (ReturnAccountAllocation::where('customer_id', $customer->id)->where(fn ($q) => $q->whereNull('sale_id')->orWhereHas('sale', fn ($q) => $q->visibleTo()))->with('saleReturn')->get() as $a) {
            $add($a->created_at, $a->saleReturn?->return_type === 'NO_RECEIPT' ? 'No-receipt return credit' : 'Sales Return Credit', ($a->saleReturn?->reference ?? 'Return').' · '.str_replace('_', ' ', $a->kind).' · Customer #'.$customer->id, '0.00', $a->amount);
            if ($a->status === 'REVERSED') {
                $add($a->reversed_at, 'Return Credit Reversal', $a->saleReturn?->reference ?? 'Return', $a->amount, '0.00');
            }
        }
        $entries = $entries->sortBy('date')->values();
        $balance = '0.00';
        $opening = '0.00';
        $period = collect();
        foreach ($entries as $row) {
            $balance = Money::add($balance, Money::sub($row['debit'], $row['credit']));
            $row['balance'] = $balance;
            if ($from && $row['date']->toDateString() < $from) {
                $opening = $balance;

                continue;
            }
            if ($to && $row['date']->toDateString() > $to) {
                continue;
            }
            $period->push($row);
        }
        if ($from) {
            $period->prepend(['date' => Carbon::parse($from), 'type' => 'Opening Balance', 'description' => 'Balance before selected period', 'debit' => $opening, 'credit' => '0.00', 'balance' => $opening]);
        }

        return $period;
    }
}
