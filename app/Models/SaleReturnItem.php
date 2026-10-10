<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleReturnItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['purchased_on' => 'date', 'suggested_credit_price' => 'decimal:2', 'credit_price' => 'decimal:2', 'cost_basis' => 'decimal:2', 'stock_selling_price' => 'decimal:2', 'fee_refund' => 'decimal:2', 'quantity' => 'decimal:3', 'base_quantity' => 'decimal:3', 'amount' => 'decimal:2', 'cost_total' => 'decimal:2'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function getDisplayNameAttribute()
    {
        return $this->name ?? $this->item?->name;
    }

    public function getDisplayUnitAttribute()
    {
        return $this->unit ?? $this->item?->unit;
    }

    public function allocations()
    {
        return $this->hasMany(ReturnStockAllocation::class);
    }

    public function return()
    {
        return $this->belongsTo(SaleReturn::class, 'sale_return_id');
    }

    public function item()
    {
        return $this->belongsTo(SaleItem::class, 'sale_item_id');
    }
}
