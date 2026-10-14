<?php

namespace App\Http\Requests;

use App\Support\Cheques;
use Illuminate\Foundation\Http\FormRequest;

class ChequeEditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Cheques::enabled() && $this->user()?->hasPermission('cheques.edit');
    }

    public function rules(): array
    {
        return [
            'version' => 'required|string|size:64',
            'cheque_number' => 'required|string|max:80',
            'cheque_date' => 'required|date_format:Y-m-d',
            'bank_name' => 'required|string|max:150',
            'branch' => [Cheques::setting('cheque_require_branch') ? 'required' : 'nullable', 'string', 'max:150'],
            'drawer_name' => 'nullable|string|max:150',
            'account_number' => 'nullable|string|max:80',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
            'bank_account_id' => 'nullable|integer|exists:bank_accounts,id',
            'amount' => 'prohibited',
            'type' => 'prohibited',
            'status' => 'prohibited',
            'origin_customer_id' => 'prohibited',
            'destination_supplier_id' => 'prohibited',
            'sale_id' => 'prohibited',
            'purchase_id' => 'prohibited',
        ];
    }
}
