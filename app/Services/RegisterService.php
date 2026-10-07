<?php

namespace App\Services;

use App\Models\Register;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegisterService
{
    public function current(int $userId, bool $lock = false): ?Register
    {
        $query = Register::where('open_user_id', $userId);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function withSummary(Builder $query): Builder
    {
        return $query->withSum(['payments as cash_collections' => fn ($q) => $q->where('method_type', 'CASH')], 'customer_payable')
            ->withSum('payments as total_collections', 'customer_payable')->withCount('payments as transactions_count')
            ->withSum(['movements as movement_in' => fn ($q) => $q->where('type', 'IN')], 'amount')
            ->withSum(['movements as movement_out' => fn ($q) => $q->where('type', 'OUT')], 'amount')
            ->withSum(['expenses as cash_expenses' => fn ($q) => $q->where('status', 'ACTIVE')], 'amount');
    }

    public function summary(Register $register): array
    {
        if (! array_key_exists('cash_collections', $register->getAttributes())) {
            $register = $this->withSummary(Register::whereKey($register->id))->firstOrFail();
        }
        $cash = Money::round((string) ($register->cash_collections ?? 0));
        $in = Money::round((string) ($register->movement_in ?? 0));
        $out = Money::round((string) ($register->movement_out ?? 0));
        $expenses = Money::round((string) ($register->cash_expenses ?? 0));
        $expected = Money::sub(Money::sub(Money::add(Money::add($register->opening_cash, $cash), $in), $out), $expenses);

        return compact('cash', 'in', 'out', 'expenses', 'expected') + ['collections' => Money::round((string) ($register->total_collections ?? 0)), 'transactions' => $register->transactions_count];
    }

    public function open(int $userId, string $cash): Register
    {
        return DB::transaction(function () use ($userId, $cash) {
            User::whereKey($userId)->lockForUpdate()->firstOrFail();
            if ($this->current($userId, true)) {
                throw ValidationException::withMessages(['opening_cash' => 'You already have an open register.']);
            }
            $r = Register::create(['user_id' => $userId, 'open_user_id' => $userId, 'opening_cash' => $cash, 'opened_at' => now()]);
            Audit::record('register.open', $r, [], $r->toArray());

            return $r;
        }, 3);
    }

    public function close(Register $register, string $actual, ?string $notes): void
    {
        DB::transaction(function () use ($register, $actual, $notes) {
            $r = Register::whereKey($register->id)->lockForUpdate()->firstOrFail();
            if ($r->closed_at) {
                throw ValidationException::withMessages(['actual_cash' => 'Register is already closed.']);
            }
            $expected = $this->summary($r)['expected'];
            $r->update(['closed_at' => now(), 'open_user_id' => null, 'actual_cash' => $actual, 'expected_cash' => $expected, 'difference' => Money::sub($actual, $expected), 'notes' => $notes]);
            Audit::record('register.close', $r, [], $r->toArray());
        }, 3);
    }
}
