<?php

namespace App\Services\Repair;

use App\Models\BankAccount;
use App\Models\Cheque;
use App\Models\PaymentMethod;
use App\Models\RepairJob;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ChequeService;
use App\Services\RegisterService;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RepairAccounting
{
    public function __construct(private RegisterService $registers, private RepairSms $sms) {}

    public function post(RepairJob $job, array $data, User $user, string $kind, ?int $paymentId = null): void
    {
        DB::transaction(function () use ($job, $data, $user, $kind, $paymentId) {
            $job = RepairJob::lockForUpdate()->findOrFail($job->id);
            if ($cheque = Cheque::withoutGlobalScopes()->where('repair_submission', $data['submission'])->first()) {
                abort_unless($cheque->repair_job_id === $job->id && $cheque->created_by === $user->id, 403);
                if ($kind !== 'COLLECT' || (int) $data['payment_method_id'] !== (int) $cheque->repair_method_id) {
                    throw ValidationException::withMessages(['payment' => 'This submission already recorded a cheque. Reopen the payment form for a new entry.']);
                }

                return;
            }
            if ($existing = $job->payments()->where('submission', $data['submission'])->first()) {
                abort_unless($existing->user_id === $user->id, 403);

                return;
            }
            if (in_array($job->status, ['CANCELLED', 'UNREPAIRABLE'], true)) {
                throw ValidationException::withMessages(['payment' => 'The repair is closed.']);
            }
            $amount = Money::round((string) $data['amount']);
            $method = PaymentMethod::where('active', true)->findOrFail($data['payment_method_id']);
            if ($method->type === 'CHEQUE') {
                $this->receiveCheque($job, $data, $user, $method, $kind, $amount);

                return;
            }
            if (! empty($data['cheque'])) {
                throw ValidationException::withMessages(['payment' => 'Choose Cheque to record cheque details.']);
            }
            $account = $this->bankAccount($data['bank_account_id'] ?? null, $method);
            $original = null;
            $credit = $taxCredit = '0.00';
            $paid = $job->paid;
            $register = $this->registers->current($user->id, true);
            if ($kind === 'CORRECTION') {
                $original = $job->payments()->whereKey($paymentId)->whereIn('kind', ['ADVANCE', 'PAYMENT', 'CORRECTION'])->firstOrFail();
                if ($original->method_type === 'CHEQUE') {
                    throw ValidationException::withMessages(['payment' => 'Use a refund to reverse a cleared cheque collection.']);
                }
                if ($original->reversal()->exists()) {
                    throw ValidationException::withMessages(['payment' => 'This payment already has a correction.']);
                }
                $paid = Money::sub($paid, $original->amount);
            } elseif ($kind === 'REFUND') {
                if (Money::compare($amount, $paid) > 0) {
                    throw ValidationException::withMessages(['amount' => 'The refund exceeds the net amount collected.']);
                }
                if ($job->invoiced_at) {
                    $credit = $amount;
                    $remainingTax = Money::sub($job->tax, $job->tax_credited);
                    $remainingTotal = Money::sub($job->total, $job->credited);
                    $taxCredit = (string) BigDecimal::of($remainingTax)->multipliedBy($credit)->dividedBy($remainingTotal, 2, RoundingMode::HALF_UP);
                }
                $amount = '-'.$amount;
            }
            if (! $register && ($method->type === 'CASH' || $original?->method_type === 'CASH')) {
                throw ValidationException::withMessages(['payment' => 'Open a register before recording a cash movement.']);
            }
            $newPaid = Money::add($paid, $amount);
            $remaining = Money::sub($job->total, Money::add($job->credited, $credit));
            $committed = Money::add($newPaid, $job->chequeHold());
            if (Money::compare($newPaid, 0) < 0 || ($job->invoiced_at && Money::compare($committed, $remaining) > 0) || Money::compare($committed, '999999999999.99') > 0) {
                throw ValidationException::withMessages(['amount' => 'The resulting balance is outside the amount that can be collected.']);
            }
            if ($original) {
                $job->payments()->create([
                    'kind' => 'REVERSAL', 'reverses_id' => $original->id, 'amount' => '-'.$original->amount,
                    'user_id' => $user->id, 'register_id' => $register?->id,
                    ...$original->only(['payment_method_id', 'method_type', 'method_name', 'bank_account_id', 'reference']),
                    'reason' => $data['reason'],
                ]);
            }
            $payment = $job->payments()->create([
                'submission' => $data['submission'], 'user_id' => $user->id, 'register_id' => $register?->id,
                'payment_method_id' => $method->id, 'method_type' => $method->type, 'method_name' => $method->name,
                'bank_account_id' => $account, 'kind' => $kind === 'COLLECT' ? ($job->invoiced_at ? 'PAYMENT' : 'ADVANCE') : $kind,
                'amount' => $amount, 'credit' => $credit, 'tax_credit' => $taxCredit,
                'reference' => $data['reference'] ?? null, 'reason' => $data['reason'] ?? null,
            ]);
            $job->update(['paid' => $newPaid, 'credited' => Money::add($job->credited, $credit), 'tax_credited' => Money::add($job->tax_credited, $taxCredit)]);
            $snapshot = ['id', 'amount', 'payment_method_id', 'method_name', 'bank_account_id', 'reference'];
            $job->events()->create([
                'kind' => 'PAYMENT', 'user_id' => $user->id, 'note' => $data['reason'] ?? null,
                'details' => ['kind' => $payment->kind, 'before' => $original?->only($snapshot), 'after' => $payment->only($snapshot)],
            ]);
            if ($kind === 'COLLECT') {
                $this->sms->queue($job, 'REPAIR_PAYMENT', $user->id, $payment->id, ['amount' => Money::display($amount)]);
            }
        }, 3);
    }

    private function receiveCheque(RepairJob $job, array $data, User $user, PaymentMethod $method, string $kind, string $amount): void
    {
        if ($kind !== 'COLLECT' || ! empty($data['bank_account_id'])) {
            throw ValidationException::withMessages(['payment' => 'Use the Cheque method for a new collection only.']);
        }
        $cheques = app(ChequeService::class);
        $cheques->authorize($user->id, 'receive');
        $held = Money::add($job->chequeHold(), $amount);
        if (($job->invoiced_at && Money::compare($held, $job->due()) > 0)
            || Money::compare(Money::add($job->paid, $held), '999999999999.99') > 0) {
            $cheques->fail('The cheque exceeds the balance available after pending cheques.');
        }
        $entry = $cheques->entries([array_merge($data['cheque'] ?? [], ['amount' => $amount, 'reference' => $data['reference'] ?? null])], $amount)[0];
        $cheque = $cheques->create($entry, 'CUSTOMER_RECEIVED', $job->customer_id, $user->id);
        $cheque->update(['repair_job_id' => $job->id, 'repair_method_id' => $method->id, 'repair_submission' => $data['submission']]);
        $job->events()->create(['kind' => 'CHEQUE', 'user_id' => $user->id, 'note' => 'Cheque received and awaiting clearance.']);
    }

    // Called inside the existing cheque transition transaction after the instrument is locked.
    public function settleCheque(Cheque $cheque, User $user, ?string $reason): void
    {
        $job = RepairJob::visibleTo($user)->lockForUpdate()->findOrFail($cheque->repair_job_id);
        $job->events()->create(['kind' => 'CHEQUE', 'user_id' => $user->id, 'note' => 'Cheque '.strtolower($cheque->status).($reason ? ' · '.$reason : '')]);
        if ($cheque->status !== 'PASSED') {
            return;
        }
        $amount = Money::round($cheque->getRawOriginal('amount'));
        $method = PaymentMethod::findOrFail($cheque->repair_method_id);
        $payment = $job->payments()->create([
            'submission' => $cheque->repair_submission, 'user_id' => $user->id, 'payment_method_id' => $method->id,
            'method_type' => $method->type, 'method_name' => $method->name, 'kind' => $job->invoiced_at ? 'PAYMENT' : 'ADVANCE',
            'amount' => $amount, 'reference' => $cheque->cheque_number,
        ]);
        $job->update(['paid' => Money::add($job->paid, $amount)]);
        $this->sms->queue($job, 'REPAIR_PAYMENT', $user->id, $payment->id, ['amount' => Money::display($amount)]);
    }

    private function bankAccount(?int $account, ?PaymentMethod $method): ?int
    {
        if ($account) {
            if (! in_array($method?->type, ['BANK_TRANSFER', 'CARD', 'QR'], true)) {
                throw ValidationException::withMessages(['bank_account_id' => 'Choose a bank settlement method for this account.']);
            }
            BankAccount::where('active', true)->findOrFail($account);
        }

        return $account;
    }

    public function cost(RepairJob $job, array $data, User $user): void
    {
        DB::transaction(function () use ($job, $data, $user) {
            $job = RepairJob::lockForUpdate()->findOrFail($job->id);
            if ($job->costs()->where('submission', $data['submission'])->exists()) {
                return;
            }
            abort_unless($job->editable(), 409);
            $method = isset($data['payment_method_id'])
                ? PaymentMethod::where('active', true)->where('type', '!=', 'CHEQUE')->findOrFail($data['payment_method_id'])
                : null;
            $account = $this->bankAccount($data['bank_account_id'] ?? null, $method);
            $cash = $method?->type === 'CASH';
            $register = $cash ? $this->registers->current($user->id, true) : null;
            if ($cash && ! $register) {
                throw ValidationException::withMessages(['cost' => 'Open a register before recording a cash expense.']);
            }
            if (! empty($data['supplier_id'])) {
                Supplier::where('active', true)->findOrFail($data['supplier_id']);
            }
            $job->costs()->create([
                'submission' => $data['submission'], 'user_id' => $user->id, 'register_id' => $register?->id,
                'supplier_id' => $data['supplier_id'] ?? null, 'payment_method_id' => $method?->id,
                'bank_account_id' => $account, 'description' => $data['description'], 'amount' => $data['amount'],
            ]);
            $job->events()->create(['kind' => 'COST', 'user_id' => $user->id, 'note' => 'Direct cost recorded']);
        }, 3);
    }
}
