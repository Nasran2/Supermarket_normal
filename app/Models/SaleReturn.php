<?php

namespace App\Models;

use App\Support\SalesVisibility;
use Illuminate\Database\Eloquent\Model;

class SaleReturn extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['approved_at' => 'datetime', 'verified_amount' => 'decimal:2', 'historical_cost_total' => 'decimal:2', 'estimated_cost_total' => 'decimal:2', 'unverified_amount' => 'decimal:2', 'draft_payload' => 'array', 'amount' => 'decimal:2', 'cost_total' => 'decimal:2', 'due_reduction' => 'decimal:2', 'refund_amount' => 'decimal:2', 'replacement_value' => 'decimal:2', 'additional_payment' => 'decimal:2', 'customer_due_applied' => 'decimal:2', 'fee_refund' => 'decimal:2', 'returned_at' => 'datetime', 'cancelled_at' => 'datetime'];

    public function scopeVisibleTo($query, ?User $user = null)
    {
        return $query->where(function ($q) use ($user) {
            $q->whereHas('sale', fn ($s) => $s->visibleTo($user))
                ->orWhere(function ($q) use ($user) {
                    $q->where('return_type', 'NO_RECEIPT');
                    SalesVisibility::apply($q, 'sale_returns.user_id', $user);
                    $q->whereDoesntHave('items.item.sale', fn ($s) => $s->whereNotIn('sales.id', Sale::visibleTo($user)->select('id')));
                });
        });
    }

    public function getVerificationLabelAttribute(): string
    {
        return match ($this->verification_status) {
            'VERIFIED' => 'Verified Return', 'PARTIALLY_VERIFIED' => 'Partially Verified', default => 'No Receipt'
        };
    }

    public function replacement()
    {
        return $this->belongsTo(Sale::class, 'replacement_sale_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function allocations()
    {
        return $this->hasMany(ReturnAccountAllocation::class);
    }

    public function settlements()
    {
        return $this->hasMany(ReturnSettlement::class);
    }

    public function supplierReturns()
    {
        return $this->hasMany(SupplierReturn::class);
    }

    public function scopeCompleted($q)
    {
        return $q->where('status', 'COMPLETED');
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function register()
    {
        return $this->belongsTo(Register::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(SaleReturnItem::class);
    }
}
