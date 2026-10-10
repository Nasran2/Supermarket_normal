<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\RegisterMovement;
use App\Models\ReturnAccountAllocation;
use App\Models\ReturnSettlement;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReturnSettlementService
{
    public static function minimum(string $a, string $b): string
    {
        return Money::compare($a, $b) < 0 ? $a : $b;
    }

    public static function fail(string $message): never
    {
        throw ValidationException::withMessages(['return' => $message]);
    }

    public function difference(string $value, string $due, string $replacement): array
    {
        $reduction = self::minimum($value, $due);
        $credit = Money::sub($value, $reduction);
        $difference = Money::sub($replacement, $credit);

        return ['return_value' => $value, 'due_reduction' => $reduction, 'available_credit' => $credit, 'replacement_value' => $replacement, 'must_pay' => Money::compare($difference, 0) > 0 ? $difference : '0.00', 'owed' => Money::compare($difference, 0) < 0 ? Money::sub('0', $difference) : '0.00'];
    }

    public function guard(string $kind, array $data, User $user, string $amount): void
    {
        abort_unless($user->hasPermission($kind.'_returns.create'), 403);
        $s = app(SettingsService::class);
        if (! $s->get($kind.'_returns_enabled', true)) {
            self::fail('Returns are disabled in settings.');
        }
        if ($s->get('return_require_reason', true) && empty(trim($data['reason'] ?? ''))) {
            self::fail('Select a return reason.');
        }
        if (($data['reason'] ?? '') === 'Other' && empty(trim($data['notes'] ?? ''))) {
            self::fail('Enter notes for Other.');
        }
        $threshold = (string) $s->get('return_approval_above', '0');
        if (Money::compare($threshold, 0) > 0 && Money::compare($amount, $threshold) > 0) {
            abort_unless($user->hasPermission($kind.'_returns.approve'), 403, 'Manager approval is required for this return amount.');
        }
    }

    public function money($document, string $parent, string $kind, string $amount, ?int $methodId, User $user, bool $movement = true): ?ReturnSettlement
    {
        if (Money::compare($amount, 0) === 0) {
            return null;
        }
        $method = PaymentMethod::whereKey($methodId)->where('active', true)->lockForUpdate()->first();
        if (! $method) {
            self::fail('Choose an active payment method.');
        }
        $register = app(RegisterService::class)->current($user->id, true);
        if ($method->type === 'CASH' && ! $register) {
            self::fail('Open a daily register before making a cash return settlement.');
        }
        $move = null;
        if ($movement && $method->type === 'CASH') {
            $move = RegisterMovement::create(['register_id' => $register->id, 'user_id' => $user->id, 'type' => Money::compare($amount, 0) > 0 ? 'IN' : 'OUT', 'amount' => Money::compare($amount, 0) > 0 ? $amount : Money::sub('0', $amount), 'description' => $kind.' · '.$document->reference]);
        }

        return ReturnSettlement::create([$parent.'_id' => $document->id, 'user_id' => $user->id, 'register_id' => $register?->id, 'payment_method_id' => $method->id, 'register_movement_id' => $move?->id, 'kind' => $kind, 'amount' => $amount, 'method_name' => $method->name, 'method_type' => $method->type]);
    }

    public function applyCustomer($document, int $customerId, string $amount, User $user): void
    {
        if (Money::compare($amount, 0) <= 0) {
            return;
        }
        abort_unless($user->hasPermission('sales_returns.apply_customer_due'), 403);
        $s = app(SettingsService::class);
        if (! $s->get('return_apply_customer_due', true)) {
            self::fail('Applying return credit to customer due is disabled.');
        }
        if (($document->sale?->customer_id ?? $document->customer_id) !== $customerId) {
            abort_unless($user->hasPermission('sales_returns.allocate_other_customer'), 403);
            if (! $s->get('return_customer_search', true)) {
                self::fail('Customer search for return credit is disabled.');
            }
        }
        $customer = Customer::whereKey($customerId)->where('active', true)->lockForUpdate()->firstOrFail();
        $left = $amount;
        $openingCredits = (string) ReturnAccountAllocation::where('customer_id', $customerId)->whereNull('sale_id')->where('status', 'ACTIVE')->sum('amount');
        $opening = Money::sub(Money::sub($customer->opening_due, $customer->opening_due_paid), $openingCredits);
        $take = self::minimum($left, $opening);
        if (Money::compare($take, 0) > 0) {
            ReturnAccountAllocation::create(['sale_return_id' => $document->id, 'customer_id' => $customerId, 'kind' => 'OPENING_DUE', 'amount' => $take]);
            $left = Money::sub($left, $take);
        }
        foreach ($customer->sales()->visibleTo($user)->where('status', 'ACTIVE')->where('id', '!=', $document->sale_id)->oldest('sold_at')->orderBy('id')->lockForUpdate()->get() as $sale) {
            $take = self::minimum($left, $sale->due_balance);
            if (Money::compare($take, 0) > 0) {
                ReturnAccountAllocation::create(['sale_return_id' => $document->id, 'customer_id' => $customerId, 'sale_id' => $sale->id, 'kind' => 'CUSTOMER_DUE', 'amount' => $take]);
            }
            $left = Money::sub($left, $take);
        }
        if (Money::compare($left, 0) > 0) {
            self::fail('The customer has insufficient eligible outstanding due for this allocation.');
        }
    }

    public function applySupplier($document, string $parent, int $supplierId, string $amount, ?int $except, User $user): void
    {
        if (Money::compare($amount, 0) <= 0) {
            return;
        }
        abort_unless($user->hasPermission('purchase_returns.apply_supplier_credit'), 403);
        Supplier::whereKey($supplierId)->lockForUpdate()->firstOrFail();
        $left = $amount;
        foreach (Purchase::where('supplier_id', $supplierId)->where('status', 'ACTIVE')->where('payment_tracking', true)->when($except, fn ($q) => $q->where('id', '!=', $except))->oldest('purchase_date')->orderBy('id')->lockForUpdate()->get() as $purchase) {
            $take = self::minimum($left, $purchase->due_amount);
            if (Money::compare($take, 0) > 0) {
                ReturnAccountAllocation::create([$parent.'_id' => $document->id, 'supplier_id' => $supplierId, 'purchase_id' => $purchase->id, 'kind' => 'SUPPLIER_DUE', 'amount' => $take]);
            }
            $left = Money::sub($left, $take);
        }
        if (Money::compare($left, 0) > 0) {
            self::fail('The supplier has insufficient outstanding due for this credit allocation.');
        }
    }

    public function useSupplierCredit(Supplier $supplier, string $amount, User $user): void
    {
        abort_unless($user->hasPermission('purchase_returns.apply_supplier_credit'), 403);
        DB::transaction(function () use ($supplier, $amount, $user) {
            $supplier = Supplier::whereKey($supplier->id)->lockForUpdate()->firstOrFail();
            $amount = Money::round($amount);
            if (Money::compare($amount, 0) <= 0 || Money::compare($amount, $supplier->credit_balance) > 0 || Money::compare($amount, $supplier->due_balance) > 0) {
                self::fail('Enter an amount within the retained supplier credit and outstanding due.');
            }
            $left = $amount;
            foreach ($supplier->purchaseReturns()->completed()->where('supplier_credit', '>', 0)->oldest('id')->lockForUpdate()->get() as $r) {
                $used = (string) $r->allocations()->where('kind', 'STORED_SUPPLIER_CREDIT')->where('status', 'ACTIVE')->sum('amount');
                $take = self::minimum($left, Money::sub($r->supplier_credit, $used));
                if (Money::compare($take, 0) <= 0) {
                    continue;
                }
                $before = $r->allocations()->pluck('id');
                $this->applySupplier($r, 'purchase_return', $supplier->id, $take, null, $user);
                $r->allocations()->whereNotIn('id', $before)->update(['kind' => 'STORED_SUPPLIER_CREDIT']);
                $left = Money::sub($left, $take);
            }
            if (Money::compare($left, 0) > 0) {
                self::fail('Supplier credit changed. Refresh the account.');
            }
            Audit::record('supplier_return.credit_used', $supplier, [], ['amount' => $amount]);
        }, 3);
    }

    public function reverseMoney($document, string $parent, User $user): void
    {
        foreach ($document->settlements()->where('kind', '!=', 'REVERSAL')->get() as $row) {
            $amount = Money::sub('0', $row->amount);
            $register = app(RegisterService::class)->current($user->id, true);
            $movement = null;
            if ($row->method_type === 'CASH') {
                if (! $register) {
                    self::fail('Open a daily register before reversing a cash settlement.');
                }
                $movement = RegisterMovement::create(['register_id' => $register->id, 'user_id' => $user->id, 'type' => Money::compare($amount, 0) > 0 ? 'IN' : 'OUT', 'amount' => Money::compare($amount, 0) > 0 ? $amount : Money::sub('0', $amount), 'description' => 'RETURN_REVERSAL · '.$document->reference]);
            }
            ReturnSettlement::create([$parent.'_id' => $document->id, 'user_id' => $user->id, 'register_id' => $register?->id, 'payment_method_id' => $row->payment_method_id, 'register_movement_id' => $movement?->id, 'kind' => 'REVERSAL', 'amount' => $amount, 'method_name' => $row->method_name, 'method_type' => $row->method_type]);
        }
        $document->allocations()->where('status', 'ACTIVE')->update(['status' => 'REVERSED', 'reversed_at' => now()]);
    }
}
