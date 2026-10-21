<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsMessage extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['sent_at' => 'datetime'];
}
