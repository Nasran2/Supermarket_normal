<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RepairDevice extends Model
{
    protected $fillable = ['customer_id', 'brand', 'model', 'serial', 'color'];

    public function jobs()
    {
        return $this->hasMany(RepairJob::class, 'device_id');
    }
}
