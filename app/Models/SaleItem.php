<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleItem extends Model
{
    protected $fillable = ['stock_price', 'cogs_total', 'catalog_price', 'line_subtotal', 'line_discount', 'discount_type', 'discount_value', 'sale_id', 'product_id', 'unit_id', 'base_quantity', 'base_cost', 'name', 'sku', 'unit', 'quantity', 'price', 'cost', 'total'];

    protected $casts = ['catalog_price' => 'decimal:2', 'line_subtotal' => 'decimal:2', 'line_discount' => 'decimal:2', 'discount_value' => 'decimal:2', 'base_quantity' => 'decimal:3', 'base_cost' => 'decimal:2', 'quantity' => 'decimal:3', 'price' => 'decimal:2', 'cost' => 'decimal:2', 'total' => 'decimal:2'];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function returns()
    {
        return $this->hasMany(SaleReturnItem::class)->whereHas('return', fn ($q) => $q->where('status', 'COMPLETED'));
    }

    public function unitRecord()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function allocations()
    {
        return $this->hasMany(SaleStockAllocation::class);
    }
}
