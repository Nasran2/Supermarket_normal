<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaleCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('sales.edit') ?? false;
    }

    public function rules(): array
    {
        $amount = ['required', 'numeric', 'min:0.01', 'max:9999999999999.99', 'regex:/^\d+(?:\.\d{1,2})?$/'];

        return ['token' => 'required|uuid', 'payment_method_id' => 'required|integer|exists:payment_methods,id', 'amount' => $amount, 'amount_paid' => $amount, 'reference' => 'nullable|string|max:255'];
    }
}
