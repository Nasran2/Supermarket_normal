<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission($this->routeIs('register.open') ? 'register.open' : 'register.close');
    }

    public function rules(): array
    {
        return match ($this->route()->getName()) {
            'register.open' => ['opening_cash' => 'required|numeric|min:0|max:999999999|decimal:0,2'],'register.close' => ['actual_cash' => 'required|numeric|min:0|max:999999999|decimal:0,2', 'notes' => 'nullable|string|max:2000'],default => ['type' => 'required|in:IN,OUT', 'amount' => 'required|numeric|gt:0|max:999999999|decimal:0,2', 'description' => 'required|string|max:255']
        };
    }
}
