<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\Sale;
use App\Models\SaleCollection;
use App\Models\SalePayment;
use App\Models\User;
use App\Support\Audit;
use App\Support\Cheques;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChequeService
{
    public function authorize(int $userId, string $action): User
    {
        abort_unless(Cheques::enabled(), 404);
        $user = User::findOrFail($userId);
        abort_unless($user->hasPermission('cheques.'.$action), 403);

        return $user;
    }

    public function fail(string $message): never
    {
        throw ValidationException::withMessages(['cheques' => $message]);
    }

    public function entries(array $entries, string $expected, bool $supplier = false): array
    {
        if (! $entries) {
            $this->fail('Enter at least one cheque with its number, bank, date and amount.');
        }
        if (count($entries) > 30 || (count($entries) > 1 && ! Cheques::setting('cheque_allow_multiple'))) {
            $this->fail('Multiple cheques are disabled or the 30-cheque limit was exceeded.');
        }
        $sum = '0.00';
        $validated = [];
        foreach ($entries as $entry) {
            if ($supplier && ($entry['source'] ?? 'OWN') === 'THIRD_PARTY') {
                Validator::make($entry, ['cheque_id' => 'required|integer|exists:cheques,id'])->validate();
                $cheque = Cheque::visibleTo()->available()->whereKey($entry['cheque_id'])->lockForUpdate()->first();
                if (! $cheque) {
                    $this->fail('This customer cheque is no longer available for transfer.');
                }
                $entry = ['source' => 'THIRD_PARTY', 'cheque_id' => $cheque->id, 'amount' => $cheque->amount];
            } else {
                $entry = Validator::make($entry, [
                    'cheque_number' => 'required|string|max:80', 'amount' => 'required|numeric|gt:0|max:9999999999999.99|decimal:0,2',
                    'cheque_date' => 'required|date_format:Y-m-d', 'bank_name' => 'required|string|max:150',
                    'branch' => [Cheques::setting('cheque_require_branch') ? 'required' : 'nullable', 'string', 'max:150'],
                    'drawer_name' => 'nullable|string|max:150', 'account_number' => 'nullable|string|max:80', 'bank_account_id' => 'nullable|integer|exists:bank_accounts,id',
                    'reference' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:2000',
                ])->validate();
                $entry['amount'] = Money::round((string) $entry['amount']);
            }
            $sum = Money::add($sum, $entry['amount']);
            $validated[] = $entry;
        }
        if (Money::compare($sum, $expected) !== 0) {
            $this->fail(Money::compare($sum, $expected) > 0 ? 'Cheque total exceeds the amount being paid.' : 'Cheque total must equal the amount being paid.');
        }

        return $validated;
    }

    private function event(Cheque $c, string $event, int $userId, ?string $notes = null, array $metadata = []): void
    {
        $c->events()->create(['user_id' => $userId, 'event' => $event, 'notes' => $notes, 'metadata' => $metadata]);
        Audit::record('cheque.'.strtolower($event), $c, [], ['status' => $c->status, 'amount' => $c->amount, 'customer_id' => $c->origin_customer_id, 'supplier_id' => $c->destination_supplier_id] + $metadata);
    }

    public function create(array $entry, string $type, int $partyId, int $userId, ?int $documentId = null): Cheque
    {
        $this->authorize($userId, $type === 'OWN_CHEQUE' ? 'issue' : 'receive');
        if (! Cheques::setting($type === 'OWN_CHEQUE' ? 'cheque_allow_own' : 'cheque_allow_customer')) {
            $this->fail('This cheque source is disabled in Cheque Settings.');
        }
        if ($type === 'CUSTOMER_RECEIVED' && ! empty($entry['bank_account_id'])) {
            $this->fail('Business bank accounts can only be linked to own cheques.');
        }
        if ($type === 'OWN_CHEQUE' && ! empty($entry['bank_account_id'])) {
            $account = BankAccount::whereKey($entry['bank_account_id'])->where('active', true)->lockForUpdate()->firstOrFail();
            $entry['bank_name'] = $account->bank_name;
            $entry['account_number'] = $account->account_number;
            $entry['drawer_name'] = $account->account_name;
            $entry['branch'] = $account->branch ?? ($entry['branch'] ?? null);
        }
        $key = hash('sha256', mb_strtolower(trim($entry['bank_name'])).'|'.trim($entry['account_number'] ?? '').'|'.trim($entry['cheque_number']));
        if (Cheque::where('instrument_key', $key)->exists()) {
            $this->fail('A cheque with this bank, account and cheque number already exists.');
        }
        // A database unique key also guards two concurrent attempts to create one instrument.
        try {
            $c = Cheque::create($entry + ['instrument_key' => $key, 'type' => $type, 'status' => 'PENDING', 'origin_customer_id' => $type === 'CUSTOMER_RECEIVED' ? $partyId : null, 'destination_supplier_id' => $type === 'OWN_CHEQUE' ? $partyId : null, 'sale_id' => $type === 'CUSTOMER_RECEIVED' ? $documentId : null, 'purchase_id' => $type === 'OWN_CHEQUE' ? $documentId : null, 'created_by' => $userId]);
        } catch (UniqueConstraintViolationException $e) {
            $this->fail('This cheque has already been recorded.');
        }
        $this->event($c, $type === 'CUSTOMER_RECEIVED' ? 'RECEIVED' : 'ISSUED', $userId);

        return $c;
    }

    public function receiveForSale($sale, array $entries, int $userId): void
    {
        $this->authorize($userId, 'receive');
        if (! $sale->customer_id) {
            $this->fail('Select a named customer to receive cheques.');
        }
        foreach ($entries as $entry) {
            $c = $this->create($entry, 'CUSTOMER_RECEIVED', $sale->customer_id, $userId, $sale->id);
            $sale->payments()->create(['cheque_id' => $c->id, 'settlement_status' => 'PENDING', 'payment_method_id' => PaymentMethod::where('type', 'CHEQUE')->value('id'), 'method_name' => 'Cheque', 'method_type' => 'CHEQUE', 'sale_amount' => $c->amount, 'customer_payable' => $c->amount, 'amount_paid' => $c->amount, 'change' => '0.00', 'processing_charge' => '0.00', 'charge_bearer' => 'BUSINESS', 'reference' => $entry['reference'] ?? $c->cheque_number]);
        }
    }

    public function supplierEntries(Purchase $purchase, array $entries, int $userId, string $token): PurchasePayment
    {
        $first = null;
        foreach ($entries as $i => $entry) {
            if (($entry['source'] ?? 'OWN') === 'THIRD_PARTY') {
                $c = $this->transfer(Cheque::findOrFail($entry['cheque_id']), $purchase, $userId);
            } else {
                $c = $this->create($entry, 'OWN_CHEQUE', $purchase->supplier_id, $userId, $purchase->id);
            }
            $p = $purchase->payments()->create(['cheque_id' => $c->id, 'settlement_status' => 'PENDING', 'user_id' => $userId, 'payment_method_id' => PaymentMethod::where('type', 'CHEQUE')->value('id'), 'token' => $i === 0 ? $token : (string) Str::uuid(), 'kind' => 'PAYMENT', 'method_name' => $c->type === 'THIRD_PARTY' ? 'Third-party cheque' : 'Own cheque', 'method_type' => 'CHEQUE', 'amount' => $c->amount, 'amount_paid' => $c->amount, 'change' => '0.00', 'reference' => $c->cheque_number, 'paid_at' => now()]);
            $first ??= $p;
        }

        return $first;
    }

    public function transfer(Cheque $original, Purchase $purchase, int $userId): Cheque
    {
        $this->authorize($userId, 'transfer');
        if (! Cheques::setting('cheque_allow_third_party')) {
            $this->fail('Third-party transfers are disabled.');
        }
        $c = Cheque::available()->visibleTo()->whereKey($original->id)->lockForUpdate()->first();
        if (! $c) {
            $this->fail('This customer cheque is unavailable or already transferred.');
        }
        if ($purchase->status !== 'ACTIVE' || ! $purchase->payment_tracking || Money::compare($c->amount, $purchase->fresh()->due_amount) > 0) {
            $this->fail('The full cheque amount exceeds this purchase balance. Cheques cannot be split between suppliers.');
        }
        $c->update(['type' => 'THIRD_PARTY', 'status' => 'TRANSFERRED', 'destination_supplier_id' => $purchase->supplier_id, 'purchase_id' => $purchase->id]);
        $this->event($c, 'TRANSFERRED', $userId, null, ['purchase_id' => $purchase->id, 'supplier_id' => $purchase->supplier_id]);

        return $c;
    }

    public function transition(Cheque $original, string $action, int $userId, array $data = []): Cheque
    {
        $user = $this->authorize($userId, $action);

        return DB::transaction(function () use ($original, $action, $userId, $data, $user) {
            $c = Cheque::visibleTo()->whereKey($original->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize($action, $c);
            if (! in_array($c->status, ['PENDING', 'TRANSFERRED'])) {
                $this->fail('This cheque is already '.$c->status.'. Its settlement cannot be repeated.');
            }
            if (in_array($action, ['pass', 'return']) && ! $c->action_available && ! $user->hasPermission('cheques.override_action_date')) {
                $this->fail('Pass and Return become available '.$c->cheque_date->copy()->subDays((int) Cheques::setting('cheque_alert_days'))->format('d/m/Y').'.');
            }
            if ($action === 'cancel' && $c->status === 'TRANSFERRED') {
                $this->fail('Reverse the transfer first, or mark a bounced transferred cheque Returned to restore both accounts.');
            }
            if ($action === 'return' && empty(trim($data['reason'] ?? ''))) {
                $this->fail('Enter a return reason.');
            }
            $status = ['pass' => 'PASSED', 'return' => 'RETURNED', 'cancel' => 'CANCELLED'][$action] ?? null;
            abort_unless($status, 422);
            if ($c->origin_customer_id) {
                Customer::whereKey($c->origin_customer_id)->lockForUpdate()->firstOrFail();
            }
            $customerPayments = $c->customerPayments()->lockForUpdate()->with('allocations')->get();
            if ($status !== 'PASSED') {
                foreach ($customerPayments as $p) {
                    $opening = Money::sum($p->allocations->where('type', 'OPENING_BALANCE')->pluck('amount'));
                    if (Money::compare($opening, 0) > 0) {
                        Customer::whereKey($c->origin_customer_id)->decrement('opening_due_paid', $opening);
                    }
                }
            }
            foreach ([SalePayment::class, SaleCollection::class, CustomerPayment::class, PurchasePayment::class] as $model) {
                $model::where('cheque_id', $c->id)->where('settlement_status', 'PENDING')->update(['settlement_status' => $status]);
            }
            $prefix = ['pass' => 'passed', 'return' => 'returned', 'cancel' => 'cancelled'][$action];
            $c->update(['status' => $status, $prefix.'_by' => $userId, $prefix.'_at' => now(), 'return_reason' => $action === 'return' ? $data['reason'] : $c->return_reason]);
            $this->event($c, $status, $userId, $data['notes'] ?? null, ['reason' => $data['reason'] ?? null]);

            return $c->fresh();
        }, 3);
    }

    public function reverseTransfer(Cheque $original, int $userId, string $reason): Cheque
    {
        $this->authorize($userId, 'reverse_transfer');

        return DB::transaction(function () use ($original, $userId, $reason) {
            $c = Cheque::visibleTo()->whereKey($original->id)->lockForUpdate()->firstOrFail();
            if ($c->status !== 'TRANSFERRED') {
                $this->fail('Only a pending transferred cheque can have its transfer reversed.');
            }
            Purchase::whereKey($c->purchase_id)->lockForUpdate()->firstOrFail();
            $old = $c->only('destination_supplier_id', 'purchase_id');
            PurchasePayment::where('cheque_id', $c->id)->where('settlement_status', 'PENDING')->update(['settlement_status' => 'CANCELLED']);
            $c->update(['type' => 'CUSTOMER_RECEIVED', 'status' => 'PENDING', 'destination_supplier_id' => null, 'purchase_id' => null]);
            $this->event($c, 'TRANSFER_REVERSED', $userId, $reason, $old);

            return $c;
        }, 3);
    }

    public function assertDocumentSafe($document, bool $editing = false): void
    {
        $customer = $document instanceof Sale;
        $ids = $document->payments()->whereNotNull('cheque_id')->when(! $editing, fn ($q) => $q->where('settlement_status', 'PENDING'))->pluck('cheque_id');
        if ($customer) {
            $ids = $ids->merge($document->collections()->whereNotNull('cheque_id')->when(! $editing, fn ($q) => $q->where('settlement_status', 'PENDING'))->pluck('cheque_id'));
        }
        if ($ids->isNotEmpty()) {
            $this->fail($editing ? 'Cheque-backed invoices cannot be edited. Use audited returns after resolving pending cheques.' : 'Resolve pending cheques before returning, refunding or voiding this document. No cash refund can be made against an uncleared cheque.');
        }
    }
}
