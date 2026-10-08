<?php

namespace App\Http\Requests;

class SaleRevisionRequest extends CheckoutRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('sales.edit') && $this->user()?->hasPermission('pos.access');
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules['payments'] = ['required', 'array', 'list', 'min:1', 'max:10'];
        $rules['payment_method_id'] = ['prohibited'];
        $rules['allow_due'] = ['prohibited'];
        $rules['version'] = ['required', 'string', 'size:64'];
        if ($this->routeIs('sales.revise')) {
            $rules['checkout_token'] = ['required', 'uuid'];
            $rules['quote_hash'] = ['required', 'string', 'size:64'];
            $rules['payments.*.amount_paid'] = ['required', 'numeric', 'min:0', 'max:999999999999', 'decimal:0,2'];
        }

        return $rules;
    }
}
