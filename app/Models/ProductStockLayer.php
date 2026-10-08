<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

class ProductStockLayer extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['remaining_cost_total' => 'decimal:2', 'inventory_cost_total' => 'decimal:2', 'cost_price' => 'decimal:2', 'selling_price' => 'decimal:2', 'original_quantity' => 'decimal:3', 'remaining_quantity' => 'decimal:3', 'received_at' => 'datetime'];

    public function getStockValueAttribute(): string
    {
        return $this->remaining_cost_total ?? Money::mul($this->remaining_quantity, $this->cost_price);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function purchaseItem()
    {
        return $this->belongsTo(PurchaseItem::class);
    }

    public function allocations()
    {
        return $this->hasMany(SaleStockAllocation::class, 'stock_layer_id');
    }

    public function movements()
    {
        return $this->hasMany(StockLayerMovement::class, 'stock_layer_id');
    }

    public function scopeAvailable($q)
    {
        return $q->where('status', 'ACTIVE')->where('remaining_quantity', '>', 0);
    }
}
