<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = ['name', 'sku', 'barcode', 'category_id', 'unit_id', 'cost', 'price', 'stock', 'low_stock', 'active', 'image', 'created_by', 'updated_by'];

    protected $casts = ['active' => 'boolean', 'cost' => 'decimal:2', 'price' => 'decimal:2', 'stock' => 'decimal:3', 'low_stock' => 'decimal:3'];

    public function conversions()
    {
        return $this->hasMany(ProductUnit::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function saleItems()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function purchaseItems()
    {
        return $this->hasMany(PurchaseItem::class);
    }
}
