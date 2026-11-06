<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseItem extends Model
{
    protected $attributes = ['track_stock' => true];

    protected $fillable = ['expires_at', 'batch_number', 'track_stock', 'allocated_charge', 'selling_price', 'base_selling_price', 'purchase_id', 'product_id', 'unit_id', 'base_quantity', 'base_cost', 'name', 'unit', 'quantity', 'cost', 'total', 'previous_cost'];

    protected $casts = ['track_stock' => 'boolean', 'allocated_charge' => 'decimal:2', 'base_quantity' => 'decimal:3', 'base_cost' => 'decimal:2', 'quantity' => 'decimal:3', 'cost' => 'decimal:2', 'total' => 'decimal:2', 'previous_cost' => 'decimal:2'];

    protected static function booted()
    {
        static::addGlobalScope('secret_hidden_purchase_items', function ($builder) {
            if (class_exists(\App\Support\SecretControl::class) && ! \App\Support\SecretControl::isSuperAdminArea()) {
                $hiddenIds = \App\Support\SecretControl::hiddenProductIds();
                if (! empty($hiddenIds)) {
                    $builder->whereNotIn('purchase_items.product_id', $hiddenIds);
                }
            }
        });
    }

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
