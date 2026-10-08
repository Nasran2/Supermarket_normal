<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseItem extends Model
{
    protected $fillable = ['allocated_charge', 'selling_price', 'base_selling_price', 'purchase_id', 'product_id', 'unit_id', 'base_quantity', 'base_cost', 'name', 'unit', 'quantity', 'cost', 'total', 'previous_cost'];

    protected $casts = ['allocated_charge' => 'decimal:2', 'base_quantity' => 'decimal:3', 'base_cost' => 'decimal:2', 'quantity' => 'decimal:3', 'cost' => 'decimal:2', 'total' => 'decimal:2', 'previous_cost' => 'decimal:2'];

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function stockLayers()
    {
        return $this->hasMany(ProductStockLayer::class);
    }
}
