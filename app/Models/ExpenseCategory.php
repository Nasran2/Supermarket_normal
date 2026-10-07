<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseCategory extends Model
{
    protected $fillable = ['name', 'system'];

    protected $casts = ['system' => 'boolean'];

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }
}
