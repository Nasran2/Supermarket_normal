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
        $r = ['items' => ['required', 'array', 'min:1', 'max:300'], 'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'], 'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999', 'decimal:0,3'], 'discount' => ['nullable', 'numeric', 'min:0', 'max:999999999', 'decimal:0,2'], 'payment_method_id' => ['required', 'integer', Rule::exists('payment_methods', 'id')->where('active', true)], 'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('active', true)], 'reference' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:1000']];
        if ($this->routeIs('pos.complete')) {
            $r += ['amount_paid' => ['required', 'numeric', 'min:0', 'max:999999999999', 'decimal:0,2'], 'checkout_token' => ['required', 'uuid'], 'quote_hash' => ['required', 'string', 'size:64']];
        }

        return $r;
    }
}
