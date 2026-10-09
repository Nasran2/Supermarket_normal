<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchasePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission($this->routeIs('purchases.refunds.store') ? 'purchases.refund' : 'purchases.pay');
    }

    public function rules(): array
    {
        return ['token' => ['required', 'uuid'], 'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99', 'decimal:0,2'], 'payment_method_id' => ['required', 'integer', Rule::exists('payment_methods', 'id')->where('active', true)], 'reference' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:1000'], 'take_from_register' => ['nullable', 'boolean']];
    }
}
