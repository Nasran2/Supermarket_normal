<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnitPreset extends Model
{
    protected $fillable = ['name', 'unit_id'];

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function conversions()
    {
        return $this->hasMany(UnitPresetConversion::class);
    }
}
