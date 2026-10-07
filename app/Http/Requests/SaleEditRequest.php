<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaleEditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('sales.edit');
    }

    public function rules(): array
    {
        return ['customer_id' => 'nullable|integer|exists:customers,id', 'reference' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:1000'];
    }
}
