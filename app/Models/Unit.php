<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    protected $fillable = ['default_slot', 'name', 'short_name', 'allow_decimal', 'active'];

    protected $casts = ['default_slot' => 'integer', 'allow_decimal' => 'boolean', 'active' => 'boolean'];

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
