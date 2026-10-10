<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseReturnItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['returned_at' => 'datetime', 'cancelled_at' => 'datetime', 'sent_at' => 'datetime', 'settled_at' => 'datetime', 'reversed_at' => 'datetime', 'amount' => 'decimal:2', 'cost_total' => 'decimal:2', 'quantity' => 'decimal:3', 'base_quantity' => 'decimal:3'];

    public function return()
    {
        return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id');
    }

    public function item()
    {
        return $this->belongsTo(PurchaseItem::class, 'purchase_item_id');
    }

    public function allocations()
    {
        return $this->hasMany(ReturnStockAllocation::class);
    }
}
