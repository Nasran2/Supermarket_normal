<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockLayerMovement extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['quantity' => 'decimal:3', 'cost_price' => 'decimal:2', 'selling_price' => 'decimal:2'];

    public function layer()
    {
        return $this->belongsTo(ProductStockLayer::class, 'stock_layer_id');
    }

    public function movement()
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }
}
