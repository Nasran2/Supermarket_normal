<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalePayment extends Model
{
    protected $fillable = ['sale_id', 'payment_method_id', 'payment_charge_rule_id', 'method_name', 'method_type', 'rule_name', 'charge_type', 'charge_value', 'charge_bearer', 'sale_amount', 'processing_charge', 'customer_payable', 'amount_paid', 'change', 'reference'];

    protected $casts = ['sale_amount' => 'decimal:2', 'processing_charge' => 'decimal:2', 'customer_payable' => 'decimal:2', 'amount_paid' => 'decimal:2', 'change' => 'decimal:2', 'charge_value' => 'decimal:4'];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function method()
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function rule()
    {
        return $this->belongsTo(PaymentChargeRule::class, 'payment_charge_rule_id');
    }

    public function expense()
    {
        return $this->hasOne(Expense::class);
    }
}
