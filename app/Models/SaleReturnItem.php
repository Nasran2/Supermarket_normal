<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleReturnItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['quantity' => 'decimal:3', 'base_quantity' => 'decimal:3', 'amount' => 'decimal:2', 'cost_total' => 'decimal:2'];

    public function return()
    {
        return $this->belongsTo(SaleReturn::class, 'sale_return_id');
    }

    public function item()
    {
        return $this->belongsTo(SaleItem::class, 'sale_item_id');
    }
}
