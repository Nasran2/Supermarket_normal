<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransactionReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission($this->route('kind').'_returns.create') ?? false;
    }

    public function rules(): array
    {
        $noReceipt = $this->route('kind') === 'sales' && $this->input('return_type') === 'NO_RECEIPT';
        $item = $this->route('kind') === 'sales' ? 'sale_item_id' : 'purchase_item_id';
        $money = ['nullable', 'numeric', 'min:0', 'max:9999999999999', 'decimal:0,2'];

        return [
            'token' => [$this->routeIs('returns.store', 'returns.draft') ? 'required' : 'nullable', 'uuid'],
            'return_type' => 'nullable|in:'.($this->route('kind') === 'sales' ? 'INVOICE,NO_RECEIPT' : 'INVOICE'),
            'customer_id' => 'nullable|integer|exists:customers,id',
            'original_id' => $noReceipt ? 'prohibited' : 'required|integer|min:1', 'quote_hash' => 'nullable|string|size:64',
            'reason' => 'nullable|string|max:1000', 'notes' => 'nullable|string|max:2000',
            'resolution' => 'required|in:MONEY,SAME,OTHER',
            'items' => 'required|array|min:1|max:100',
            'items.*' => 'array:'.$item.',quantity,stock_action,supplier_id,reason'.($noReceipt ? ',product_id,unit_id,credit_price,price_override_reason,cost_basis,stock_selling_price,purchased_on,notes' : ''),
            'items.*.'.$item => ($noReceipt ? 'nullable' : 'required').'|integer|distinct|min:1',
            'items.*.product_id' => ($noReceipt ? 'required' : 'prohibited').'|integer|exists:products,id',
            'items.*.unit_id' => 'nullable|integer|exists:units,id',
            'items.*.credit_price' => $money, 'items.*.cost_basis' => $money, 'items.*.stock_selling_price' => $money,
            'items.*.price_override_reason' => 'nullable|string|max:1000', 'items.*.notes' => 'nullable|string|max:2000',
            'items.*.purchased_on' => 'nullable|date_format:Y-m-d|before_or_equal:today',
            'items.*.quantity' => 'required|numeric|min:0|max:999999999|decimal:0,3',
            'items.*.stock_action' => 'nullable|in:RESTOCK,WRITEOFF,SUPPLIER',
            'items.*.supplier_id' => 'nullable|integer|exists:suppliers,id',
            'items.*.reason' => 'nullable|string|max:1000',
            'replacements' => 'nullable|array|max:100',
            'replacements.*' => 'array:product_id,unit_id,quantity,stock_price,cost,selling_price,sale_item_id,purchase_item_id,return_line_index',
            'replacements.*.product_id' => 'required|integer|exists:products,id',
            'replacements.*.unit_id' => 'nullable|integer|exists:units,id',
            'replacements.*.return_line_index' => 'nullable|integer|min:0|max:99',
            'replacements.*.sale_item_id' => 'nullable|integer',
            'replacements.*.purchase_item_id' => 'nullable|integer',
            'replacements.*.quantity' => 'required|numeric|gt:0|max:999999999|decimal:0,3',
            'replacements.*.stock_price' => $money, 'replacements.*.cost' => $money, 'replacements.*.selling_price' => $money,
            'apply_due' => $money, 'keep_credit' => $money,
            'credit_customer_id' => 'nullable|integer|exists:customers,id',
            'payment_method_id' => 'nullable|integer|exists:payment_methods,id',
            'add_to_due' => 'nullable|boolean', 'use_current_price' => 'nullable|boolean',
        ];
    }
}
