<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductUnit extends Model
{
    protected $fillable = ['product_id', 'unit_id', 'base_quantity', 'converted_quantity', 'price'];

    protected $casts = ['base_quantity' => 'decimal:6', 'converted_quantity' => 'decimal:6', 'price' => 'decimal:2'];

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }
}
