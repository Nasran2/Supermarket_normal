<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('reports.'.$this->route('report'));
    }

    public function rules(): array
    {
        return ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from', 'payment_method_id' => 'nullable|integer|exists:payment_methods,id', 'user_id' => 'nullable|integer|exists:users,id', 'rule_id' => 'nullable|integer|exists:payment_charge_rules,id', 'charge_bearer' => 'nullable|in:CUSTOMER,BUSINESS', 'q' => 'nullable|string|max:150'];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['from' => $this->input('from', today()->startOfMonth()->toDateString()), 'to' => $this->input('to', today()->toDateString())]);
    }
}
