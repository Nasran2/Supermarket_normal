<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RepairPart extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['quantity' => 'decimal:3', 'price' => 'decimal:2', 'cost' => 'decimal:2', 'allocations' => 'array', 'consumed_at' => 'datetime'];
}
