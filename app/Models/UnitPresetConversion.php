<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnitPresetConversion extends Model
{
    protected $fillable = ['unit_preset_id', 'unit_id', 'base_quantity', 'converted_quantity'];

    protected $casts = ['base_quantity' => 'decimal:6', 'converted_quantity' => 'decimal:6'];

    public function preset()
    {
        return $this->belongsTo(UnitPreset::class, 'unit_preset_id');
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }
}
