<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierReturn extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['returned_at' => 'datetime', 'cancelled_at' => 'datetime', 'sent_at' => 'datetime', 'settled_at' => 'datetime', 'reversed_at' => 'datetime', 'amount' => 'decimal:2', 'cost_total' => 'decimal:2', 'quantity' => 'decimal:3', 'base_quantity' => 'decimal:3'];

    public function saleReturn()
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items()
    {
        return $this->hasMany(ReturnStockAllocation::class);
    }

    public function settlements()
    {
        return $this->hasMany(ReturnSettlement::class);
    }

    public function allocations()
    {
        return $this->hasMany(ReturnAccountAllocation::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
