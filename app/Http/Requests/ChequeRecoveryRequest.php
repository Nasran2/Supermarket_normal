<?php

namespace App\Http\Requests;

use App\Support\Cheques;
use Illuminate\Foundation\Http\FormRequest;

class ChequeRecoveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Cheques::enabled() && $this->user()?->hasPermission($this->routeIs('cheques.resolve-return') ? 'cheques.resolve_return' : 'cheques.return');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'resolution' => $this->input('resolution', 'DUE'),
            'recovery_side' => $this->input('recovery_side', $this->route('cheque')?->type === 'OWN_CHEQUE' ? 'SUPPLIER' : 'CUSTOMER'),
        ]);
    }

    public function rules(): array
    {
        $rules = Cheques::rules();
        $rules['cheques'][0] = $this->input('resolution') === 'REPLACE' ? 'required' : 'prohibited';

        return $rules + [
            'confirmed' => 'required|accepted',
            'token' => 'sometimes|required|uuid',
            'resolution' => 'required|in:DUE,REPLACE,PAYMENT',
            'recovery_side' => 'required|in:CUSTOMER,SUPPLIER',
            'reason' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
            'amount' => [$this->input('resolution') === 'DUE' ? 'prohibited' : 'required', 'numeric', 'gt:0', 'max:9999999999999.99', 'decimal:0,2'],
            'payment_method_id' => [$this->input('resolution') === 'PAYMENT' ? 'required' : 'prohibited', 'integer', 'exists:payment_methods,id'],
            'reference' => 'nullable|string|max:255',
            'take_from_register' => 'sometimes|boolean',
        ];
    }
}
