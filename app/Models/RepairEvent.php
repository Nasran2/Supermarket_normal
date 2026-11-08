<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RepairEvent extends Model
{
    protected $fillable = ['user_id', 'kind', 'note', 'public_status', 'details'];

    protected $casts = ['details' => 'array'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
