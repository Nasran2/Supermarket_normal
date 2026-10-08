<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $fillable = ['product_id', 'user_id', 'quantity', 'balance', 'reason', 'reference'];

    protected $casts = ['quantity' => 'decimal:3', 'balance' => 'decimal:3'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class, 'reference', 'invoice');
    }

    public function saleReturn()
    {
        return $this->belongsTo(SaleReturn::class, 'reference', 'reference');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
