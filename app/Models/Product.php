<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = ['track_stock', 'default_discount_type', 'default_discount_value', 'name', 'sku', 'barcode', 'unit_id', 'supplier_id', 'cost', 'price', 'stock', 'low_stock', 'active', 'image', 'created_by', 'updated_by'];

    protected $attributes = ['track_stock' => true, 'default_discount_type' => 'NONE', 'default_discount_value' => '0.00'];

    protected $casts = ['track_stock' => 'boolean', 'default_discount_value' => 'decimal:2', 'active' => 'boolean', 'cost' => 'decimal:2', 'price' => 'decimal:2', 'stock' => 'decimal:3', 'low_stock' => 'decimal:3'];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function conversions()
    {
        return $this->hasMany(ProductUnit::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class);
    }

    public function saleItems()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function purchaseItems()
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function stockLayers()
    {
        return $this->hasMany(ProductStockLayer::class);
    }
}
