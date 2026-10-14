<?php

namespace App\Services;

use App\Models\Cheque;
use App\Models\ChequeResolution;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\ReturnAccountAllocation;
use App\Models\Sale;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/** Preserve the returned instrument; record each recovery as a separate payment. */
class ChequeRecoveryService
{
    public function __construct(private ChequeService $cheques) {}

    public function record(Cheque $original, array $data, int $userId, bool $returning = true): ChequeResolution
    {
        $user = $this->cheques->authorize($userId, $returning ? 'return' : 'resolve_return');
        $resolution = $data['resolution'] ?? 'DUE';
        $side = $data['recovery_side'] ?? ($original->type === 'OWN_CHEQUE' ? 'SUPPLIER' : 'CUSTOMER');
        $amount = $resolution === 'DUE' ? '0.00' : Money::round((string) ($data['amount'] ?? 0));
        $token = $data['token'] ?? (string) Str::uuid();
        $hash = hash('sha256', json_encode([
            'returning' => $returning, 'resolution' => $resolution, 'side' => $side, 'amount' => $amount,
            'reason' => $data['reason'] ?? null, 'notes' => $data['notes'] ?? null,
            'method' => $data['payment_method_id'] ?? null, 'reference' => $data['reference'] ?? null,
            'register' => $data['take_from_register'] ?? false, 'cheques' => $data['cheques'] ?? [],
        ]));

        try {
            return DB::transaction(function () use ($original, $data, $userId, $user, $returning, $resolution, $side, $amount, $token, $hash) {
                $c = Cheque::visibleTo()->whereKey($original->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($user)->authorize($returning ? 'return' : 'resolveReturn', $c);
                if ($existing = ChequeResolution::where('token', $token)->first()) {
                    abort_unless($existing->cheque_id === $c->id && $existing->user_id === $userId, 403);
                    if (! hash_equals($existing->request_hash, $hash)) {
                        $this->cheques->fail('This request was already saved with different details. Reload the cheque.');
                    }

                    return $existing;
                }
                if ($c->origin_customer_id) {
                    Customer::whereKey($c->origin_customer_id)->lockForUpdate()->firstOrFail();
                }
                if ($c->purchase_id) {
                    Purchase::whereKey($c->purchase_id)->lockForUpdate()->firstOrFail();
                }
                if ($returning) {
                    $c = $this->cheques->transition($c, 'return', $userId, $data);
                } elseif ($c->status !== 'RETURNED') {
                    $this->cheques->fail('Only a returned cheque can receive a replacement or payment.');
                }
                if (! in_array($resolution, ['DUE', 'REPLACE', 'PAYMENT']) || ! in_array($side, ['CUSTOMER', 'SUPPLIER'])) {
                    $this->cheques->fail('Choose a valid return resolution and account.');
                }
                if (($side === 'CUSTOMER' && ! $c->origin_customer_id) || ($side === 'SUPPLIER' && ! $c->destination_supplier_id)) {
                    $this->cheques->fail('This cheque has no balance for the selected account.');
                }
                $metadata = ['reason' => $data['reason'] ?? null, 'notes' => $data['notes'] ?? null, 'reference' => $data['reference'] ?? null];
                $method = null;
                $entries = [];
                if ($resolution !== 'DUE') {
                    $this->cheques->authorize($userId, 'resolve_return');
                    if (Money::compare($amount, 0) <= 0 || Money::compare($amount, $c->recoveryRemaining($side)) > 0) {
                        $this->cheques->fail('The replacement or payment exceeds the amount remaining against this returned cheque.');
                    }
                    if ($side === 'CUSTOMER') {
                        abort_unless($user->hasPermission('customers.collect_payment') || ($c->sale_id && $user->hasPermission('sales.collect_payment')), 403);
                    } else {
                        abort_unless($user->hasPermission('purchases.pay'), 403);
                    }
                    $method = PaymentMethod::where('active', true)->when($resolution === 'REPLACE', fn ($q) => $q->where('type', 'CHEQUE'), fn ($q) => $q->whereKey($data['payment_method_id'] ?? null)->where('type', '!=', 'CHEQUE'))->lockForUpdate()->first();
                    if (! $method) {
                        $this->cheques->fail('Choose an active payment method for this resolution.');
                    }
                    if ($resolution === 'REPLACE') {
                        $entries = $this->cheques->entries($data['cheques'] ?? [], $amount, $side === 'SUPPLIER');
                    } elseif (! empty($data['cheques'])) {
                        $this->cheques->fail('Use replacement cheques to enter cheque details.');
                    }
                } elseif (! empty($data['cheques']) || ! empty($data['amount'])) {
                    $this->cheques->fail('Leave due does not record a new payment or cheque.');
                }
                $record = $c->resolutions()->create(['user_id' => $userId, 'token' => $token, 'request_hash' => $hash, 'resolution' => $resolution, 'side' => $side, 'amount' => $amount, 'payment_method_id' => $method?->id, 'metadata' => $metadata]);
                if ($resolution !== 'DUE') {
                    if ($side === 'CUSTOMER') {
                        $metadata += $this->customerPayment($c, $user, $data, $amount, $method, $entries, $token);
                    } else {
                        $purchase = Purchase::findOrFail($c->purchase_id);
                        $payment = app(PurchasePaymentService::class)->record($purchase, [
                            'token' => $token, 'amount' => $amount, 'payment_method_id' => $method->id,
                            'cheques' => $entries, 'reference' => $data['reference'] ?? 'Returned cheque #'.$c->cheque_number,
                            'notes' => $data['notes'] ?? null, 'take_from_register' => $data['take_from_register'] ?? false,
                        ], $userId);
                        $metadata['purchase_payment_id'] = $payment->id;
                    }
                    if ($entries) {
                        $ids = array_map(fn ($entry) => ($entry['source'] ?? '') === 'THIRD_PARTY' ? $entry['cheque_id'] : Cheque::where('instrument_key', $this->cheques->instrumentKey($entry))->firstOrFail()->id, $entries);
                        $record->replacements()->attach($ids);
                    }
                }
                $record->update(['metadata' => $metadata]);
                $c->events()->create(['user_id' => $userId, 'event' => $resolution === 'DUE' ? 'BALANCE_DUE' : 'RETURN_RESOLVED', 'notes' => $data['notes'] ?? null, 'metadata' => ['resolution_id' => $record->id, 'resolution' => $resolution, 'side' => $side, 'amount' => $amount, 'replacement_ids' => $record->replacements()->pluck('cheques.id')->all()]]);
                Audit::record('cheque.resolution', $c, [], $record->toArray());

                return $record;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            $this->cheques->fail('This request has already been recorded. Reload the cheque before trying again.');
        }
    }

    private function customerPayment(Cheque $c, User $user, array $data, string $amount, PaymentMethod $method, array $entries, string $token): array
    {
        if ($c->sale_id) {
            $sale = Sale::visibleTo($user)->whereKey($c->sale_id)->lockForUpdate()->firstOrFail();
            $collection = app(SaleAftercareService::class)->collect($sale, ['token' => $token, 'amount' => $amount, 'amount_paid' => $amount, 'payment_method_id' => $method->id, 'cheques' => $entries, 'reference' => $data['reference'] ?? 'Returned cheque #'.$c->cheque_number], $user->id);

            return ['sale_collection_id' => $collection->id];
        }
        $customer = Customer::whereKey($c->origin_customer_id)->lockForUpdate()->firstOrFail();
        $allocations = $c->customerPayments()->with('allocations')->get()->flatMap->allocations;
        $previous = $c->resolutions->where('side', 'CUSTOMER');
        $remaining = $amount;
        $plan = ['opening_balance' => '0.00', 'sales' => []];
        if ($allocations->contains('type', 'OPENING_BALANCE')) {
            $credits = (string) ReturnAccountAllocation::where('customer_id', $customer->id)->whereNull('sale_id')->where('status', 'ACTIVE')->sum('amount');
            $opening = Money::sub(Money::sub($customer->opening_due, $customer->opening_due_paid), $credits);
            $limit = Money::sub(Money::sum($allocations->where('type', 'OPENING_BALANCE')->pluck('amount')), Money::sum($previous->map(fn ($r) => $r->metadata['allocations']['opening_balance'] ?? '0')));
            if (Money::compare($opening, 0) > 0 && Money::compare($limit, 0) > 0) {
                $plan['opening_balance'] = $this->minimum($remaining, $this->minimum($opening, $limit));
                $remaining = Money::sub($remaining, $plan['opening_balance']);
            }
        }
        $invoices = $allocations->where('type', 'INVOICE')->groupBy('sale_id');
        foreach (Sale::visibleTo($user)->whereIn('id', $invoices->keys())->where('status', 'ACTIVE')->orderBy('id')->lockForUpdate()->get() as $sale) {
            $limit = Money::sub(Money::sum($invoices[$sale->id]->pluck('amount')), Money::sum($previous->map(fn ($r) => $r->metadata['allocations']['sales'][$sale->id] ?? '0')));
            if (Money::compare($limit, 0) <= 0) {
                continue;
            }
            $take = $this->minimum($remaining, $this->minimum($sale->due_balance, $limit));
            if (Money::compare($take, 0) > 0) {
                $plan['sales'][$sale->id] = $take;
                $remaining = Money::sub($remaining, $take);
            }
        }
        if (Money::compare($remaining, 0) > 0) {
            $this->cheques->fail('The original balances have already been paid or are unavailable. Review the customer account before recording this payment.');
        }
        $paymentData = ['amount' => $amount, 'payment_method_id' => $method->id, 'payment_date' => now()->toDateString(), 'mode' => 'manual', 'allocations' => $plan, 'notes' => $data['notes'] ?? 'Resolution for returned cheque #'.$c->cheque_number];
        if ($entries) {
            $paymentData['cheques'] = $entries;
        }
        app(CustomerPaymentService::class)->record($customer, $paymentData, $user->id);

        return ['allocations' => $plan];
    }

    private function minimum(string $a, string $b): string
    {
        return Money::compare($a, $b) <= 0 ? $a : $b;
    }
}
