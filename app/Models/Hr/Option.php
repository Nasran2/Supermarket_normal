<?php

namespace App\Models\Hr;

use Illuminate\Database\Eloquent\Model;

class Option extends Model
{
    protected $table = 'hr_options';

    protected $guarded = ['id'];

    protected $casts = ['settings' => 'array', 'active' => 'boolean'];
}
