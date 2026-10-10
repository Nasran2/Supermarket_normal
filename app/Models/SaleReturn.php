<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleReturn extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['draft_payload' => 'array', 'amount' => 'decimal:2', 'cost_total' => 'decimal:2', 'due_reduction' => 'decimal:2', 'refund_amount' => 'decimal:2', 'replacement_value' => 'decimal:2', 'additional_payment' => 'decimal:2', 'customer_due_applied' => 'decimal:2', 'fee_refund' => 'decimal:2', 'returned_at' => 'datetime', 'cancelled_at' => 'datetime'];

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
