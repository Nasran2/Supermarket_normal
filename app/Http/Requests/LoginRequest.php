<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Keep existing clients that submit the original email field working.
        $login = $this->input('login', $this->input('email'));
        $this->merge(['login' => is_string($login) ? trim($login) : $login]);
    }

    public function rules(): array
    {
        return ['login' => 'required|string|max:255', 'password' => 'required|string|max:128'];
    }
}
