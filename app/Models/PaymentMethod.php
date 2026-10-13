<?php

namespace App\Models;

use App\Support\Cheques;
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

    protected static function booted(): void
    {
        static::addGlobalScope('cheque-feature', function ($q) {
            if (! config('features.cheque')) {
                $q->where($q->getModel()->qualifyColumn('type'), '!=', 'CHEQUE');
            }
        });
    }

    public function scopeForContext($q, string $context)
    {
        $source = ['receive' => 'customer', 'issue' => 'own', 'transfer' => 'third_party'][$context] ?? null;

        return $source && auth()->user()?->hasPermission('cheques.'.$context) && Cheques::setting('cheque_allow_'.$source) ? $q : $q->where('type', '!=', 'CHEQUE');
    }

    public function scopeForSupplier($q)
    {
        $allowed = (auth()->user()?->hasPermission('cheques.issue') && Cheques::setting('cheque_allow_own')) || (auth()->user()?->hasPermission('cheques.transfer') && Cheques::setting('cheque_allow_third_party'));

        return $allowed ? $q : $q->where('type', '!=', 'CHEQUE');
    }

    public function rules()
    {
        return $this->hasMany(PaymentChargeRule::class);
    }

    public function payments()
    {
        return $this->hasMany(SalePayment::class);
    }
}
