<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockAdjustmentItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['stock_before' => 'decimal:3', 'stock_after' => 'decimal:3', 'quantity_change' => 'decimal:3', 'price_before' => 'decimal:2', 'price_after' => 'decimal:2', 'cost_before' => 'decimal:2', 'cost_after' => 'decimal:2', 'price_changed' => 'boolean', 'cost_changed' => 'boolean'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
