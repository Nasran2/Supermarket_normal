<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('stock-adjustments.create');
    }

    public function rules(): array
    {
        return ['stock_layer_id' => 'nullable|integer|exists:product_stock_layers,id', 'quantity' => 'required|numeric|not_in:0|max:999999|min:-999999|decimal:0,3', 'reason' => 'required|string|min:3|max:255'];
    }
}
