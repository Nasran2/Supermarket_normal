<?php

namespace App\Models;

use App\Support\Money;
use App\Support\SalesVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    protected $fillable = ['invoice', 'checkout_token', 'user_id', 'customer_id', 'register_id', 'subtotal', 'discount', 'sale_amount', 'processing_charge', 'customer_payable', 'cost_total', 'status', 'sold_at', 'voided_by', 'voided_at', 'void_reason', 'notes'];

    protected $casts = ['sold_at' => 'datetime', 'voided_at' => 'datetime', 'subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'sale_amount' => 'decimal:2', 'processing_charge' => 'decimal:2', 'customer_payable' => 'decimal:2', 'cost_total' => 'decimal:2'];

    public function scopeVisibleTo(Builder $query, ?User $user = null): Builder
    {
        return SalesVisibility::apply($query, 'sales.user_id', $user);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function register()
    {
        return $this->belongsTo(Register::class);
    }

    public function items()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments()
    {
        return $this->hasMany(SalePayment::class)->orderBy('id');
    }

    public function returns()
    {
        return $this->hasMany(SaleReturn::class)->where('status', 'COMPLETED')->orderBy('id');
    }

    public function collections()
    {
        return $this->hasMany(SaleCollection::class)->orderBy('id');
    }

    public function revisions()
    {
        return $this->hasMany(SaleRevision::class)->orderByDesc('id');
    }

    public function getReturnedTotalAttribute(): string
    {
        $linked = (string) SaleReturnItem::whereIn('sale_item_id', $this->items()->select('id'))->whereHas('return', fn ($q) => $q->completed()->where('return_type', 'NO_RECEIPT'))->sum('amount');

        return Money::add(Money::sum($this->returns->pluck('amount')), $linked);
    }

    public function getRefundedTotalAttribute(): string
    {
        return Money::sum($this->returns->pluck('refund_amount'));
    }

    public function getReturnedCostAttribute(): string
    {
        $linked = (string) SaleReturnItem::whereIn('sale_item_id', $this->items()->select('id'))->whereHas('return', fn ($q) => $q->completed()->where('return_type', 'NO_RECEIPT'))->sum('cost_total');

        return Money::add(Money::sum($this->returns->pluck('cost_total')), $linked);
    }

    public function getCollectedTotalAttribute(): string
    {
        return Money::add(Money::sub(Money::sum($this->payments->where('is_effective', true)->pluck('amount_paid')), Money::sum($this->payments->where('is_effective', true)->pluck('change'))), Money::sum($this->collections->where('is_effective', true)->pluck('amount')));
    }

    public function getChequeHoldAttribute(): string
    {
        return Money::add((string) $this->payments()->where('settlement_status', 'PENDING')->sum('amount_paid'), (string) $this->collections()->where('settlement_status', 'PENDING')->sum('amount'));
    }

    public function getSettledTotalAttribute(): string
    {
        return Money::add(Money::sum($this->payments->where('settlement_status', 'PASSED')->map(fn ($p) => Money::sub($p->amount_paid, $p->change))), Money::sum($this->collections->where('settlement_status', 'PASSED')->pluck('amount')));
    }

    public function getDueBalanceAttribute(): string
    {
        if ($this->status !== 'ACTIVE') {
            return '0.00';
        }
        $credits = (string) ReturnAccountAllocation::where('sale_id', $this->id)->where('status', 'ACTIVE')->sum('amount');
        $due = Money::sub(Money::sub(Money::sub($this->customer_payable, $this->collected_total), Money::sum($this->returns->pluck('due_reduction'))), $credits);

        return Money::compare($due, 0) > 0 ? $due : '0.00';
    }

    public function getPaymentStatusAttribute(): string
    {
        return $this->status !== 'ACTIVE' ? 'Voided' : (config('features.cheque') && Money::compare($this->cheque_hold, 0) > 0 && Money::compare($this->due_balance, 0) <= 0 ? 'Cheque Hold' : (Money::compare($this->due_balance, 0) > 0 ? 'Due' : 'Paid'));
    }

    public function getHasReturnableItemsAttribute(): bool
    {
        if ($this->status !== 'ACTIVE') {
            return false;
        }
        $this->loadMissing('items.returns');
        foreach ($this->items as $item) {
            $returned = '0.000';
            foreach ($item->returns as $line) {
                $returned = Money::quantity($returned, $line->quantity);
            }
            if (Money::compare($returned, $item->quantity) < 0) {
                return true;
            }
        }

        return false;
    }

    public function getLineDiscountTotalAttribute(): string
    {
        return Money::sum($this->items->pluck('line_discount'));
    }

    public function getReturnCreditTotalAttribute(): string
    {
        return Money::round((string) ReturnAccountAllocation::where('sale_id', $this->id)->where('status', 'ACTIVE')->sum('amount'));
    }

    public function getPaymentNamesAttribute(): string
    {
        $names = $this->payments->filter(fn ($p) => config('features.cheque') || ! $p->cheque_id)->pluck('method_name');
        if (Money::compare($this->return_credit_total, 0) > 0) {
            $names->push('Return credit');
        }

        if (config('features.cheque') && $this->payments->contains('method_type', 'CHEQUE')) {
            $names = $names->unique();
        }

        return $names->join(' + ');
    }

    public function getCustomerFeesAttribute(): string
    {
        return Money::sum($this->payments->where('charge_bearer', 'CUSTOMER')->pluck('processing_charge'));
    }

    public function getPaidTotalAttribute(): string
    {
        return Money::add(Money::sum($this->payments->where('is_effective', true)->pluck('amount_paid')), Money::sum($this->collections->where('is_effective', true)->pluck('amount_paid')));
    }

    public function getChangeTotalAttribute(): string
    {
        return Money::add(Money::sum($this->payments->where('is_effective', true)->pluck('change')), Money::sum($this->collections->where('is_effective', true)->pluck('change')));
    }

    // Compatibility for callers displaying a historical single-payment sale.
    public function payment()
    {
        return $this->hasOne(SalePayment::class);
    }

    public function voider()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
