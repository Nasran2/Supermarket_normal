<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReturnStockAllocation extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['returned_at' => 'datetime', 'cancelled_at' => 'datetime', 'sent_at' => 'datetime', 'settled_at' => 'datetime', 'reversed_at' => 'datetime', 'amount' => 'decimal:2', 'cost_total' => 'decimal:2', 'quantity' => 'decimal:3', 'base_quantity' => 'decimal:3'];

    public function layer()
    {
        return $this->belongsTo(ProductStockLayer::class, 'stock_layer_id');
    }

    public function originalAllocation()
    {
        return $this->belongsTo(SaleStockAllocation::class, 'sale_stock_allocation_id');
    }
}
