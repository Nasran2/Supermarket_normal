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
        return $query->withSum(['payments as paid_total' => fn ($q) => $q->whereIn('kind', ['PAYMENT', 'OPENING'])], 'amount')
            ->withSum(['payments as refunded_total' => fn ($q) => $q->where('kind', 'REFUND')], 'amount');
    }

    public function getPaidAmountAttribute(): string
    {
        if ($this->relationLoaded('payments')) {
            return Money::sub(Money::sum($this->payments->whereIn('kind', ['PAYMENT', 'OPENING'])->pluck('amount')), Money::sum($this->payments->where('kind', 'REFUND')->pluck('amount')));
        }
        if (array_key_exists('paid_total', $this->getAttributes())) {
            return Money::sub((string) ($this->paid_total ?? 0), (string) ($this->refunded_total ?? 0));
        }

        return Money::round((string) ($this->payments()->selectRaw("SUM(CASE WHEN kind = 'REFUND' THEN -amount ELSE amount END) as net_paid")->value('net_paid') ?? 0));
    }

    public function getDueAmountAttribute(): ?string
    {
        return $this->payment_tracking ? ($this->status === 'ACTIVE' ? Money::sub($this->total, $this->paid_amount) : '0.00') : null;
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
            return 'Paid';
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
