<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Register extends Model
{
    protected $fillable = ['user_id', 'open_user_id', 'opening_cash', 'opened_at', 'closed_at', 'expected_cash', 'actual_cash', 'difference', 'notes'];

    protected $casts = ['opened_at' => 'datetime', 'closed_at' => 'datetime', 'opening_cash' => 'decimal:2', 'expected_cash' => 'decimal:2', 'actual_cash' => 'decimal:2', 'difference' => 'decimal:2'];

    public function payments()
    {
        return $this->hasManyThrough(SalePayment::class, Sale::class, 'register_id', 'sale_id')->where('sales.status', 'ACTIVE');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function movements()
    {
        return $this->hasMany(RegisterMovement::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }

    public function returns()
    {
        return $this->hasMany(SaleReturn::class);
    }

    public function collections()
    {
        return $this->hasMany(SaleCollection::class);
    }
}
