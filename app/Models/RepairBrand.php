<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RepairBrand extends Model
{
    protected $fillable = ['name', 'name_key', 'active'];

    protected $casts = ['active' => 'boolean'];

    public function models(): HasMany
    {
        return $this->hasMany(RepairModel::class, 'brand_id');
    }
}
