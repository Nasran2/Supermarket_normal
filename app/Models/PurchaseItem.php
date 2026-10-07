<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseItem extends Model
{
    protected $fillable = ['purchase_id', 'product_id', 'name', 'unit', 'quantity', 'cost', 'total', 'previous_cost'];

    protected $casts = ['quantity' => 'decimal:3', 'cost' => 'decimal:2', 'total' => 'decimal:2', 'previous_cost' => 'decimal:2'];

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
