<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleReturn extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['amount' => 'decimal:2', 'cost_total' => 'decimal:2', 'due_reduction' => 'decimal:2', 'refund_amount' => 'decimal:2', 'returned_at' => 'datetime'];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function register()
    {
        return $this->belongsTo(Register::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(SaleReturnItem::class);
    }
}
