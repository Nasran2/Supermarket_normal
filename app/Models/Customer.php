<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = ['name', 'phone', 'email', 'address', 'active'];

    protected $casts = ['active' => 'boolean'];

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }
}
