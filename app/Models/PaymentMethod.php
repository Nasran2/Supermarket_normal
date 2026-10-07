<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    protected $fillable = ['name', 'code', 'type', 'charge_bearer', 'active', 'display_order'];

    protected $casts = ['active' => 'boolean'];

    public function rules()
    {
        return $this->hasMany(PaymentChargeRule::class);
    }

    public function payments()
    {
        return $this->hasMany(SalePayment::class);
    }
}
