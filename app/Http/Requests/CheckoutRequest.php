<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('sales.create') && $this->user()?->hasPermission('pos.access');
    }

    public function rules(): array
    {
        $activeMethod = Rule::exists('payment_methods', 'id')->where('active', true);
        $r = [
            'items' => ['required', 'array', 'min:1', 'max:300'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.stock_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999999', 'decimal:0,2'],
            'items.*.stock_layer_id' => ['nullable', 'integer', 'min:1', 'exists:product_stock_layers,id'],
            'items.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:999999999', 'decimal:0,2'],
            'items.*.discount_type' => ['nullable', 'in:AMOUNT,PERCENT'],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0', 'max:999999999', 'decimal:0,2'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999', 'decimal:0,3'],
            'bill_discount_type' => ['nullable', 'required_with:bill_discount_value', 'in:AMOUNT,PERCENT'],
            'bill_discount_value' => ['nullable', 'required_with:bill_discount_type', 'numeric', 'min:0', 'max:999999999', 'decimal:0,2'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:999999999', 'decimal:0,2'],
            'payment_method_id' => ['required_without:payments', Rule::prohibitedIf($this->has('payments')), 'integer', $activeMethod],
            'payments' => ['sometimes', 'required', 'array', 'list', 'min:1', 'max:10'],
            'payments.*' => ['array:payment_method_id,amount,amount_paid,reference'],
            'payments.*.payment_method_id' => ['required', 'integer', $activeMethod],
            'payments.*.amount' => ['required', 'numeric', 'min:0', 'max:999999999999', 'decimal:0,2'],
            'payments.*.reference' => ['nullable', 'string', 'max:255'],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('active', true)],
            'reference' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:1000'],
        ];
        if ($this->routeIs('pos.complete')) {
            $money = ['required', 'numeric', 'min:0', 'max:999999999999', 'decimal:0,2'];
            $r += ['allow_due' => ['sometimes', 'boolean'], 'amount_paid' => ['required_without:payments', 'numeric', 'min:0', 'max:999999999999', 'decimal:0,2'], 'payments.*.amount_paid' => $money,
                'checkout_token' => ['required', 'uuid'], 'quote_hash' => ['required', 'string', 'size:64']];
        }

        return $r;
    }
}
