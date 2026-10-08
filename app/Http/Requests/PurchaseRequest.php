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
        return ['auto_reference' => ['sometimes', 'boolean'], 'charge_treatment' => ['nullable', 'in:EXPENSE,COST'], 'charges' => ['nullable', 'array', 'max:30'], 'charges.*.label' => ['required', 'string', 'max:150'], 'charges.*.amount' => ['required', 'numeric', 'gt:0', 'max:999999999', 'decimal:0,2'], 'reference' => [$this->boolean('auto_reference') && ! $this->route('purchase') ? 'nullable' : 'required', 'string', 'max:255', $this->boolean('auto_reference') && ! $this->route('purchase') ? 'nullable' : Rule::unique('purchases')->ignore($this->route('purchase')?->id)], 'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('active', true)], 'purchase_date' => ['required', 'date', 'before_or_equal:today'], 'notes' => ['nullable', 'string', 'max:2000'], 'items' => ['required', 'array', 'min:1', 'max:300'], 'items.*.product_id' => ['required', 'exists:products,id'], 'items.*.unit_id' => ['nullable', 'integer', 'exists:units,id'], 'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999', 'decimal:0,3'], 'items.*.selling_price' => ['nullable', 'numeric', 'min:0', 'max:999999999', 'decimal:0,2'], 'items.*.cost' => ['required', 'numeric', 'min:0', 'max:999999999', 'decimal:0,2'],
            'payment_mode' => [$this->route('purchase') ? 'prohibited' : 'nullable', 'in:AUTO,FULL,PARTIAL,UNPAID'],
            'amount_paid' => [$this->route('purchase') ? 'prohibited' : 'nullable', 'required_if:payment_mode,PARTIAL', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
            'payment_method_id' => [$this->route('purchase') ? 'prohibited' : 'nullable', 'required_if:payment_mode,FULL,PARTIAL', Rule::requiredIf(! $this->route('purchase') && (float) $this->input('amount_paid', 0) > 0), 'integer', Rule::exists('payment_methods', 'id')->where('active', true)],
            'payment_reference' => [$this->route('purchase') ? 'prohibited' : 'nullable', 'string', 'max:255']];
    }
}
