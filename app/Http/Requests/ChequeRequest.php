<?php

namespace App\Http\Requests;

use App\Support\Cheques;
use Illuminate\Foundation\Http\FormRequest;

class ChequeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Cheques::enabled() && $this->user()?->hasPermission('cheques.create');
    }

    public function rules(): array
    {
        return Cheques::rules() + ['type' => 'required|in:CUSTOMER_RECEIVED,OWN_CHEQUE', 'customer_id' => 'required_if:type,CUSTOMER_RECEIVED|nullable|integer|exists:customers,id', 'purchase_id' => 'required_if:type,OWN_CHEQUE|nullable|integer|exists:purchases,id', 'amount' => 'required|numeric|gt:0|max:9999999999999.99|decimal:0,2', 'token' => 'required|uuid', 'payment_date' => 'required|date'];
    }
}
