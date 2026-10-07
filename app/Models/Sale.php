<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    protected $fillable = ['invoice', 'checkout_token', 'user_id', 'customer_id', 'register_id', 'subtotal', 'discount', 'sale_amount', 'processing_charge', 'customer_payable', 'cost_total', 'status', 'sold_at', 'voided_by', 'voided_at', 'void_reason', 'notes'];

    protected $casts = ['sold_at' => 'datetime', 'voided_at' => 'datetime', 'subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'sale_amount' => 'decimal:2', 'processing_charge' => 'decimal:2', 'customer_payable' => 'decimal:2', 'cost_total' => 'decimal:2'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function register()
    {
        return $this->belongsTo(Register::class);
    }

    public function items()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payment()
    {
        return $this->hasOne(SalePayment::class);
    }

    public function voider()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
