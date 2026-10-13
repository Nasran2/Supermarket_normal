<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BarcodeLabelPreset extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['is_default' => 'boolean', 'configuration' => 'array'];
}
