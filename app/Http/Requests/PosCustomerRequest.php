<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PosCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('customers.create') && $this->user()?->hasPermission($this->routeIs('repairs.customers.store') ? 'repairs.create' : 'pos.access');
    }

    public function rules(): array
    {
        return [
            'tax_number' => [config('features.customer_tax') ? 'nullable' : 'exclude', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'opening_due' => ['nullable', 'numeric', 'min:0', 'max:999999999', 'decimal:0,2'],
        ];
    }
}
