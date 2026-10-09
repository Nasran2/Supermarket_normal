<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    protected $fillable = ['name', 'code', 'type', 'charge_bearer', 'has_charge', 'charge_type', 'charge_value', 'charge_minimum_amount', 'active', 'display_order'];

    protected $casts = ['active' => 'boolean', 'has_charge' => 'boolean', 'charge_value' => 'decimal:4', 'charge_minimum_amount' => 'decimal:2'];

    protected $attributes = [
        'charge_bearer' => 'BUSINESS',
        'charge_type' => 'PERCENTAGE',
        'charge_minimum_amount' => 0,
        'charge_value' => 0,
        'has_charge' => false,
    ];

    public function rules()
    {
        return $this->hasMany(PaymentChargeRule::class);
    }

    public function payments()
    {
        return $this->hasMany(SalePayment::class);
    }
}
