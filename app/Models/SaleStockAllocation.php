<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleStockAllocation extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['quantity' => 'decimal:3', 'returned_quantity' => 'decimal:3', 'cost_price' => 'decimal:2', 'cost_total' => 'decimal:2'];

    public function layer()
    {
        return $this->belongsTo(ProductStockLayer::class, 'stock_layer_id');
    }

    public function saleItem()
    {
        return $this->belongsTo(SaleItem::class);
    }
}
