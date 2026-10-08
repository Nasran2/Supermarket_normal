<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleCollection extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['amount' => 'decimal:2', 'amount_paid' => 'decimal:2', 'change' => 'decimal:2', 'collected_at' => 'datetime'];

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
}
