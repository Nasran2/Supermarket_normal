<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PosCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('pos.access') && $this->user()?->hasPermission('sales.create');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'opening_due' => ['nullable', 'numeric', 'min:0', 'max:999999999', 'decimal:0,2'],
        ];
    }
}
