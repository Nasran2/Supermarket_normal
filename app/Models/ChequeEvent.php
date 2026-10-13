<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChequeEvent extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['metadata' => 'array'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
