<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    protected $fillable = ['name', 'code', 'type', 'charge_bearer', 'has_charge', 'charge_type', 'charge_value', 'active', 'display_order'];

    protected $casts = ['active' => 'boolean', 'has_charge' => 'boolean', 'charge_value' => 'decimal:4'];

    public function rules()
    {
        return $this->hasMany(PaymentChargeRule::class);
    }

    public function payments()
    {
        return $this->hasMany(SalePayment::class);
    }
}
