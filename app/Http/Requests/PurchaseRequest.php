<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission($this->route('purchase') ? 'purchases.edit' : 'purchases.create');
    }

    public function rules(): array
    {
        return ['reference' => ['required', 'string', 'max:255', Rule::unique('purchases')->ignore($this->route('purchase')?->id)], 'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('active', true)], 'purchase_date' => ['required', 'date', 'before_or_equal:today'], 'notes' => ['nullable', 'string', 'max:2000'], 'items' => ['required', 'array', 'min:1', 'max:300'], 'items.*.product_id' => ['required', 'distinct', 'exists:products,id'], 'items.*.unit_id' => ['nullable', 'integer', 'exists:units,id'], 'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999', 'decimal:0,3'], 'items.*.cost' => ['required', 'numeric', 'min:0', 'max:999999999', 'decimal:0,2']];
    }
}
