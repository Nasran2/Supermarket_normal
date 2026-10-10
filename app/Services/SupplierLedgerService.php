<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Support\Money;
use Carbon\Carbon;

class SupplierLedgerService
{
    public function build(Supplier $supplier, array $filters = []): array
    {
        $purchases = $supplier->purchases()->with('payments.user')->orderBy('purchase_date')->orderBy('id')->get();
        $audits = AuditLog::where('subject_type', Purchase::class)->whereIn('subject_id', $purchases->modelKeys())->whereIn('action', ['purchase.save', 'purchase.void'])->orderBy('created_at')->orderBy('id')->get()->groupBy('subject_id');
        $entries = collect();
        foreach ($purchases as $purchase) {
            $changes = ($audits[$purchase->id] ?? collect())->filter(fn ($a) => $a->action === 'purchase.save' && isset($a->before['total'], $a->after['total']));
            $delta = Money::sum($changes->map(fn ($a) => Money::sub($a->after['total'], $a->before['total'])));
            $initial = Money::sub($purchase->total, $delta);
            $add = function ($date, $type, $description, $debit, $credit, $amount, $order) use (&$entries, $purchase) {
                $entries->push(['date' => Carbon::parse($date), 'type' => $type, 'reference' => $purchase->reference, 'description' => $description, 'purchase_id' => $purchase->id, 'debit' => $debit, 'credit' => $credit, 'amount' => $amount, 'order' => $order]);
            };
            $add($purchase->purchase_date, $purchase->payment_tracking ? 'Purchase' : 'Unrecorded', $purchase->payment_tracking ? 'Purchase invoice' : 'Payment balance not recorded', $purchase->payment_tracking ? $initial : '0.00', '0.00', $initial, '0-'.$purchase->id);
            foreach ($changes as $change) {
                $amount = Money::sub($change->after['total'], $change->before['total']);
                $add($change->created_at, 'Revision', 'Purchase total correction', $purchase->payment_tracking && Money::compare($amount, 0) > 0 ? $amount : '0.00', $purchase->payment_tracking && Money::compare($amount, 0) < 0 ? Money::sub('0', $amount) : '0.00', $amount, '1-'.$change->id);
            }
            foreach ($purchase->payments as $payment) {
                $refund = $payment->kind === 'REFUND';
                $add($payment->paid_at, $refund ? 'Refund' : ($payment->kind === 'OPENING' ? 'Opening payment' : 'Payment'), $payment->method_name.($payment->reference ? ' · '.$payment->reference : '').($payment->notes ? ' · '.$payment->notes : ''), $refund ? $payment->amount : '0.00', $refund ? '0.00' : $payment->amount, $payment->amount, '2-'.$payment->id);
            }
            if ($purchase->status !== 'ACTIVE' && $purchase->status !== 'RETURN_CANCELLED') {
                $void = ($audits[$purchase->id] ?? collect())->firstWhere('action', 'purchase.void');
                $add($void?->created_at ?? $purchase->updated_at, 'Void', $void?->after['reason'] ?? 'Purchase voided', '0.00', $purchase->payment_tracking ? $purchase->total : '0.00', $purchase->total, '3-'.$purchase->id);
            }
        }
        $returns = PurchaseReturn::where('supplier_id', $supplier->id)->with('purchase', 'settlements')->get();
        $addReturn = function ($date, $type, $reference, $purchaseId, $debit, $credit, $order) use ($entries) {
            $entries->push(['date' => Carbon::parse($date), 'type' => $type, 'reference' => $reference, 'description' => $type.' · '.$reference, 'purchase_id' => $purchaseId, 'debit' => $debit, 'credit' => $credit, 'amount' => Money::add($debit, $credit), 'order' => $order]);
        };
        foreach ($returns as $r) {
            $addReturn($r->returned_at, 'Purchase Return', $r->reference, $r->purchase_id, '0.00', $r->amount, '4-'.$r->id);
            foreach ($r->settlements as $event) {
                $addReturn($event->created_at, str_replace('_', ' ', $event->kind), $r->reference, $r->purchase_id, Money::compare($event->amount, 0) > 0 ? $event->amount : '0.00', Money::compare($event->amount, 0) < 0 ? Money::sub('0', $event->amount) : '0.00', '5-'.$event->id);
            }
            if ($r->status === 'CANCELLED') {
                $addReturn($r->cancelled_at, 'Return Cancellation', $r->reference, $r->purchase_id, $r->amount, '0.00', '6-'.$r->id);
                if ($r->replacement) {
                    $addReturn($r->cancelled_at, 'Replacement Cancellation', $r->replacement->reference, $r->replacement_purchase_id, '0.00', $r->replacement->total, '7-'.$r->id);
                }
            }
        }
        foreach (SupplierReturn::where('supplier_id', $supplier->id)->where('status', 'SETTLED')->with('settlements')->get() as $r) {
            $purchaseId = $supplier->purchases()->value('id');
            $addReturn($r->settled_at, 'Supplier Return Credit', $r->reference, $purchaseId, '0.00', $r->amount, '8-'.$r->id);
            foreach ($r->settlements as $event) {
                $addReturn($event->created_at, 'Supplier Return Refund', $r->reference, $purchaseId, $event->amount, '0.00', '9-'.$event->id);
            }
        }
        $entries = $entries->sort(fn ($a, $b) => $a['date']->getTimestamp() <=> $b['date']->getTimestamp() ?: strnatcmp($a['order'], $b['order']))->values();
        $balance = '0.00';
        $opening = '0.00';
        $debits = '0.00';
        $credits = '0.00';
        $period = collect();
        foreach ($entries as $entry) {
            $balance = Money::add($balance, Money::sub($entry['debit'], $entry['credit']));
            $entry['balance'] = $balance;
            $day = $entry['date']->toDateString();
            if (! empty($filters['from']) && $day < $filters['from']) {
                $opening = $balance;

                continue;
            }
            if (! empty($filters['to']) && $day > $filters['to']) {
                continue;
            }
            $debits = Money::add($debits, $entry['debit']);
            $credits = Money::add($credits, $entry['credit']);
            $period->push($entry);
        }
        $closing = Money::add($opening, Money::sub($debits, $credits));

        return ['entries' => $period, 'opening' => $opening, 'debits' => $debits, 'credits' => $credits, 'closing' => $closing];
    }
}
