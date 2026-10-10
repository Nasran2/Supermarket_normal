<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaleReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('sales_returns.create') ?? false;
    }

    public function rules(): array
    {
        return ['token' => 'required|uuid', 'reason' => 'required|string|min:3|max:1000', 'payment_method_id' => 'nullable|integer|exists:payment_methods,id', 'items' => 'required|array|min:1|max:100', 'items.*' => 'array:sale_item_id,quantity', 'items.*.sale_item_id' => 'required|integer|distinct', 'items.*.quantity' => ['required', 'numeric', 'min:0', 'max:999999999999.999', 'regex:/^\d+(?:\.\d{1,3})?$/']];
    }
}
