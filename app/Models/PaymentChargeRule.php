<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentChargeRule extends Model
{
    protected $fillable = ['payment_method_id', 'name', 'minimum_amount', 'maximum_amount', 'comparison_operator', 'charge_type', 'charge_value', 'charge_bearer', 'priority', 'active'];

    protected $casts = ['active' => 'boolean', 'minimum_amount' => 'decimal:2', 'maximum_amount' => 'decimal:2', 'charge_value' => 'decimal:4'];

    public function method()
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function payments()
    {
        return $this->hasMany(SalePayment::class);
    }
}
