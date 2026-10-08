<?php

namespace App\Services;

use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\Register;
use App\Models\RegisterMovement;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchasePaymentService
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['payment' => $message]);
    }

    public function registerFor(?int $methodId, int $userId, bool $takeFromRegister = true): ?Register
    {
        $method = PaymentMethod::whereKey($methodId)->where('active', true)->first();
        if (! $method) {
            $this->fail('Choose an active payment method.');
        }
        if ($method->type !== 'CASH' || ! $takeFromRegister) {
            return null;
        }
        $register = app(RegisterService::class)->current($userId, true);
        if (! $register) {
            $this->fail('Open your register before recording a cash supplier payment (Or uncheck "Take from register").');
        }

        return $register;
    }

    public function record(Purchase $original, array $data, int $userId, bool $refund = false): PurchasePayment
    {
        return DB::transaction(function () use ($original, $data, $userId, $refund) {
            // Retrying a completed request must work even if the register has since closed.
            $existing = PurchasePayment::where('token', $data['token'])->first();
            if ($existing) {
                abort_unless($existing->purchase_id === $original->id && $existing->user_id === $userId && $existing->kind === ($refund ? 'REFUND' : 'PAYMENT'), 403);

                return $existing;
            }
            $take = ! isset($data['take_from_register']) || $data['take_from_register'];
            $register = $this->registerFor($data['payment_method_id'] ?? null, $userId, $take);
            $purchase = Purchase::whereKey($original->id)->lockForUpdate()->firstOrFail();
            $existing = PurchasePayment::where('token', $data['token'])->first();
            if ($existing) {
                abort_unless($existing->purchase_id === $purchase->id && $existing->user_id === $userId && $existing->kind === ($refund ? 'REFUND' : 'PAYMENT'), 403);

                return $existing;
            }
            if ($purchase->status !== 'ACTIVE') {
                $this->fail('Voided purchases cannot receive payments or refunds.');
            }
            if (! $purchase->payment_tracking) {
                $this->fail('Set the previous payment balance before recording a new payment.');
            }
            $amount = Money::round((string) $data['amount']);
            $given = $amount;
            $limit = $refund ? $purchase->paid_amount : $purchase->due_amount;
            if (Money::compare($amount, 0) <= 0 || (Money::compare($amount, $limit) > 0 && ($refund || empty($data['allow_change'])))) {
                $this->fail($refund ? 'Refund exceeds the amount paid to this supplier.' : 'Payment exceeds the outstanding purchase balance. Refresh and check the due amount.');
            }
            if (! $refund && ! empty($data['allow_change']) && Money::compare($amount, $limit) > 0) {
                $amount = $limit;
            }
            if (Money::compare($amount, 0) <= 0) {
                $this->fail('This purchase has no remaining balance to pay.');
            }
            $method = PaymentMethod::whereKey($data['payment_method_id'])->where('active', true)->lockForUpdate()->first();
            if (! $method) {
                $this->fail('This payment method is no longer active.');
            }
            if (($method->type === 'CASH') !== ($register !== null)) {
                $this->fail('The payment method changed while this payment was being recorded. Refresh and select it again.');
            }
            $movement = null;
            if ($register) {
                $movement = RegisterMovement::create(['register_id' => $register->id, 'user_id' => $userId, 'type' => $refund ? 'IN' : 'OUT', 'amount' => $amount, 'description' => ($refund ? 'Supplier refund · ' : 'Supplier payment · ').Str::limit($purchase->reference, 180, '').' · purchase #'.$purchase->id]);
            }
            $payment = $purchase->payments()->create(['user_id' => $userId, 'payment_method_id' => $method->id, 'register_id' => $register?->id, 'register_movement_id' => $movement?->id, 'token' => $data['token'], 'kind' => $refund ? 'REFUND' : 'PAYMENT', 'method_name' => $method->name, 'method_type' => $method->type, 'amount' => $amount, 'amount_paid' => $given, 'change' => Money::sub($given, $amount), 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null, 'paid_at' => now()]);
            Audit::record($refund ? 'purchase.refund' : 'purchase.payment', $payment, [], $payment->toArray());

            return $payment;
        }, 3);
    }

    public function startTracking(Purchase $original, string $previouslyPaid, int $userId): void
    {
        DB::transaction(function () use ($original, $previouslyPaid, $userId) {
            $purchase = Purchase::whereKey($original->id)->lockForUpdate()->firstOrFail();
            if ($purchase->status !== 'ACTIVE' || $purchase->payment_tracking) {
                $this->fail('Payment tracking is already set or this purchase is voided.');
            }
            $paid = Money::round($previouslyPaid);
            if (Money::compare($paid, 0) < 0 || Money::compare($paid, $purchase->total) > 0) {
                $this->fail('Previous payments must be between zero and the purchase total.');
            }
            $purchase->update(['payment_tracking' => true]);
            if (Money::compare($paid, 0) > 0) {
                $purchase->payments()->create(['user_id' => $userId, 'token' => (string) Str::uuid(), 'kind' => 'OPENING', 'method_name' => 'Previously paid', 'method_type' => 'OTHER', 'amount' => $paid, 'notes' => 'Opening payment balance only. No cash was moved when this balance was recorded.', 'paid_at' => now()]);
            }
            Audit::record('purchase.payment-balance', $purchase, ['payment_tracking' => false], ['previously_paid' => $paid, 'due' => Money::sub($purchase->total, $paid)]);
        }, 3);
    }
}
