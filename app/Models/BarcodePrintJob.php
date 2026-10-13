<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BarcodePrintJob extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['configuration' => 'array', 'labels' => 'array'];
}
