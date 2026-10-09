<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VoidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(match ($this->route()->getName()) {
            'sales.void' => 'sales.void', 'sales.destroy' => 'sales.delete', 'purchases.destroy' => 'purchases.delete', default => 'purchases.void'
        });
    }

    public function rules(): array
    {
        return ['reason' => 'required|string|min:3|max:1000'];
    }
}
