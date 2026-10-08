<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseCharge extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['amount' => 'decimal:2'];

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }
}
