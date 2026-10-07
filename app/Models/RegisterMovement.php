<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegisterMovement extends Model
{
    protected $fillable = ['register_id', 'user_id', 'type', 'amount', 'description'];

    protected $casts = ['amount' => 'decimal:2'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
