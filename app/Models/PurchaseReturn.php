<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseReturn extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['draft_payload' => 'array', 'amount' => 'decimal:2', 'cost_total' => 'decimal:2', 'due_reduction' => 'decimal:2', 'refund_amount' => 'decimal:2', 'replacement_value' => 'decimal:2', 'additional_payment' => 'decimal:2', 'supplier_due_applied' => 'decimal:2', 'supplier_credit' => 'decimal:2', 'returned_at' => 'datetime', 'cancelled_at' => 'datetime'];

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function replacement()
    {
        return $this->belongsTo(Purchase::class, 'replacement_purchase_id');
    }

    public function items()
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    public function allocations()
    {
        return $this->hasMany(ReturnAccountAllocation::class);
    }

    public function settlements()
    {
        return $this->hasMany(ReturnSettlement::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeCompleted($q)
    {
        return $q->where('status', 'COMPLETED');
    }
}
