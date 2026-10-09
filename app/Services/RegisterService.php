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
        return $query->withSum(['payments as cash_collections' => fn ($q) => $q->where('method_type', 'CASH')], 'amount_paid')
            ->withSum(['payments as cash_change' => fn ($q) => $q->where('method_type', 'CASH')], 'change')
            ->withSum('payments as total_collections', 'amount_paid')->withSum('payments as total_change', 'change')
            ->withSum(['collections as due_cash' => fn ($q) => $q->where('method_type', 'CASH')], 'amount')
            ->withSum('collections as due_collections', 'amount')
            ->withSum(['returns as cash_refunds' => fn ($q) => $q->where('method_type', 'CASH')], 'refund_amount')
            ->withSum('returns as total_refunds', 'refund_amount')->withSum('returns as returned_amount', 'amount')
            ->withCount(['sales as transactions_count' => fn ($q) => $q->where('status', 'ACTIVE')])
            ->withSum(['movements as movement_in' => fn ($q) => $q->where('type', 'IN')], 'amount')
            ->withSum(['movements as movement_out' => fn ($q) => $q->where('type', 'OUT')], 'amount')
            ->withSum(['expenses as cash_expenses' => fn ($q) => $q->where('status', 'ACTIVE')], 'amount');
    }

    public function summary(Register $register, bool $detailed = false): array
    {
        if (! array_key_exists('cash_collections', $register->getAttributes())) {
            $register = $this->withSummary(Register::whereKey($register->id))->firstOrFail();
        }
        $pos_cash = Money::sub((string) ($register->cash_collections ?? 0), (string) ($register->cash_change ?? 0));
        $due_cash = (string) ($register->due_cash ?? 0);
        $cash = Money::sub(Money::add($pos_cash, $due_cash), (string) ($register->cash_refunds ?? 0));
        $in = Money::round((string) ($register->movement_in ?? 0));
        $out = Money::round((string) ($register->movement_out ?? 0));
        $expenses = Money::round((string) ($register->cash_expenses ?? 0));
        $expected = Money::sub(Money::sub(Money::add(Money::add($register->opening_cash, $cash), $in), $out), $expenses);

        $summary = compact('cash', 'pos_cash', 'due_cash', 'in', 'out', 'expenses', 'expected') + ['collections' => Money::sub(Money::add(Money::sub((string) ($register->total_collections ?? 0), (string) ($register->total_change ?? 0)), (string) ($register->due_collections ?? 0)), (string) ($register->total_refunds ?? 0)), 'transactions' => $register->transactions_count,
            'due_collections' => Money::round((string) ($register->due_collections ?? 0)), 'refunds' => Money::round((string) ($register->total_refunds ?? 0)), 'returns' => Money::round((string) ($register->returned_amount ?? 0))];
        if (! $detailed) {
            return $summary;
        }
        $totals = $register->payments()->select('method_type')
            ->selectRaw('COUNT(*) as entries, SUM(sale_payments.sale_amount) as base_amount, SUM(sale_payments.amount_paid - sale_payments.change) as collected')
            ->selectRaw("SUM(CASE WHEN charge_bearer = 'CUSTOMER' THEN sale_payments.processing_charge ELSE 0 END) as customer_fees")
            ->selectRaw("SUM(CASE WHEN charge_bearer = 'BUSINESS' THEN sale_payments.processing_charge ELSE 0 END) as business_fees")
            ->groupBy('method_type')->toBase()->get()->keyBy('method_type');
        $paymentTotals = [];
        $dueTotals = $register->collections()->selectRaw('method_type, COUNT(*) as entries, SUM(amount) as amount')->groupBy('method_type')->get()->keyBy('method_type');
        $refundTotals = $register->returns()->where('refund_amount', '>', 0)->selectRaw('method_type, COUNT(*) as entries, SUM(refund_amount) as amount')->groupBy('method_type')->get()->keyBy('method_type');
        foreach (['CASH' => 'Cash', 'CARD' => 'Card', 'QR' => 'QR payment', 'BANK_TRANSFER' => 'Online transfer', 'OTHER' => 'Other payments'] as $type => $name) {
            $row = $totals->get($type);
            if ($type === 'OTHER' && ! $row && ! $dueTotals->has($type) && ! $refundTotals->has($type)) {
                continue;
            }
            $paymentTotals[] = ['type' => $type, 'name' => $name, 'entries' => (int) ($row?->entries ?? 0) + (int) ($dueTotals->get($type)?->entries ?? 0) + (int) ($refundTotals->get($type)?->entries ?? 0),
                'base_amount' => Money::round((string) ($row?->base_amount ?? 0)), 'collected' => Money::sub(Money::add((string) ($row?->collected ?? 0), (string) ($dueTotals->get($type)?->amount ?? 0)), (string) ($refundTotals->get($type)?->amount ?? 0)),
                'customer_fees' => Money::round((string) ($row?->customer_fees ?? 0)), 'business_fees' => Money::round((string) ($row?->business_fees ?? 0))];
        }
        $sales = $register->sales()->visibleTo()->where('status', 'ACTIVE')->selectRaw('SUM(subtotal) as subtotal, SUM(discount) as discounts, SUM(sale_amount) as amount')->first();

        return $summary + ['payment_totals' => $paymentTotals, 'sales_subtotal' => Money::round((string) ($sales->subtotal ?? 0)), 'discounts' => Money::round((string) ($sales->discounts ?? 0)), 'sales_amount' => Money::round((string) ($sales->amount ?? 0)),
            'return_records' => $register->returns()->whereHas('sale', fn ($q) => $q->visibleTo())->with('sale', 'user')->get(), 'collection_records' => $register->collections()->whereHas('sale', fn ($q) => $q->visibleTo())->with('sale', 'user')->get(),
            'customer_fees' => Money::sum(array_column($paymentTotals, 'customer_fees')), 'business_fees' => Money::sum(array_column($paymentTotals, 'business_fees')),
            'voided_transactions' => $register->sales()->visibleTo()->where('status', 'VOIDED')->count()];
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
