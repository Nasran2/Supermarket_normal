<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Purchase extends Model
{
    protected $attributes = ['status' => 'ACTIVE', 'payment_tracking' => false];

    protected $fillable = ['reference', 'supplier_id', 'user_id', 'purchase_date', 'total', 'status', 'notes', 'payment_tracking', 'subtotal', 'charges_total', 'charge_treatment'];

    protected $casts = ['purchase_date' => 'date', 'total' => 'decimal:2', 'payment_tracking' => 'boolean', 'subtotal' => 'decimal:2', 'charges_total' => 'decimal:2'];

    protected static function booted()
    {
        static::addGlobalScope('secret_hidden_purchases', function ($builder) {
            if (class_exists(\App\Support\SecretControl::class) && ! \App\Support\SecretControl::isSuperAdminArea() && \App\Support\SecretControl::isSuperAdminFeatureActive()) {
                $hiddenIds = \App\Support\SecretControl::hiddenProductIds();
                if (! empty($hiddenIds)) {
                    $builder->whereDoesntHave('items', function ($q) use ($hiddenIds) {
                        $q->withoutGlobalScopes()->whereIn('purchase_items.product_id', $hiddenIds);
                    });
                }

                $hiddenSuppIds = \App\Support\SecretControl::hiddenSupplierIds();
                if (! empty($hiddenSuppIds)) {
                    $builder->whereNotIn('purchases.supplier_id', $hiddenSuppIds);
                }

                $purchaseRanges = \App\Support\SecretControl::parseRanges(\App\Support\SecretControl::get('hide_purchase_amount_ranges'));
                if (! empty($purchaseRanges)) {
                    $builder->where(function ($q) use ($purchaseRanges) {
                        foreach ($purchaseRanges as $r) {
                            $q->whereNotBetween('purchases.total', [$r['min'], $r['max']]);
                        }
                    });
                }
            }
        });
    }

    public function returns()
    {
        return $this->hasMany(PurchaseReturn::class)->where('status', 'COMPLETED');
    }

    public function charges()
    {
        return $this->hasMany(PurchaseCharge::class)->where('status', 'ACTIVE');
    }

    public function payments()
    {
        return $this->hasMany(PurchasePayment::class);
    }

    public function scopeWithPaymentTotals($query)
    {
        return $query->withSum(['payments as paid_total' => fn ($q) => $q->effective()->whereIn('kind', ['PAYMENT', 'OPENING'])], 'amount')
            ->withSum(['payments as refunded_total' => fn ($q) => $q->effective()->where('kind', 'REFUND')], 'amount');
    }

    public function getPaidAmountAttribute(): string
    {
        if ($this->relationLoaded('payments')) {
            return Money::sub(Money::sum($this->payments->where('is_effective', true)->whereIn('kind', ['PAYMENT', 'OPENING'])->pluck('amount')), Money::sum($this->payments->where('is_effective', true)->where('kind', 'REFUND')->pluck('amount')));
        }
        if (array_key_exists('paid_total', $this->getAttributes())) {
            return Money::sub((string) ($this->paid_total ?? 0), (string) ($this->refunded_total ?? 0));
        }

        return Money::round((string) ($this->payments()->effective()->selectRaw("SUM(CASE WHEN kind = 'REFUND' THEN -amount ELSE amount END) as net_paid")->value('net_paid') ?? 0));
    }

    public function getSettledAmountAttribute(): string
    {
        return Money::round((string) ($this->payments()->settled()->selectRaw("SUM(CASE WHEN kind='REFUND' THEN -amount ELSE amount END) as amount")->value('amount') ?? 0));
    }

    public function getDueAmountAttribute(): ?string
    {
        return $this->payment_tracking ? ($this->status === 'ACTIVE' ? Money::sub(Money::sub(Money::sub($this->total, $this->paid_amount), (string) $this->returns()->sum('due_reduction')), (string) ReturnAccountAllocation::where('purchase_id', $this->id)->where('status', 'ACTIVE')->sum('amount')) : '0.00') : null;
    }

    public function getChequeHoldAttribute(): string
    {
        return Money::round((string) $this->payments()->where('settlement_status', 'PENDING')->sum('amount'));
    }

    public function getPaymentStatusAttribute(): string
    {
        if ($this->status !== 'ACTIVE') {
            return 'Voided';
        }
        if (! $this->payment_tracking) {
            return 'Unrecorded';
        }
        if (Money::compare($this->due_amount, 0) <= 0) {
            return config('features.cheque') && Money::compare($this->cheque_hold, 0) > 0 ? 'Cheque Hold' : 'Paid';
        }

        return Money::compare($this->paid_amount, 0) > 0 ? 'Partial' : 'Unpaid';
    }

    protected function purchaseDate(): Attribute
    {
        return Attribute::make(set: fn ($value) => Carbon::parse($value)->toDateString());
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(PurchaseItem::class);
    }
}
