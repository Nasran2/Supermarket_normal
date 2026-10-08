<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleRevision extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['before' => 'array', 'after' => 'array'];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
