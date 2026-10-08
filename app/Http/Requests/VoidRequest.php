<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VoidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission($this->routeIs('sales.void', 'sales.destroy') ? 'sales.void' : 'purchases.delete');
    }

    public function rules(): array
    {
        return ['reason' => 'required|string|min:3|max:1000'];
    }
}
