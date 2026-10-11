<?php

namespace App\Http\Requests;

use App\Support\Expiry;
use Illuminate\Foundation\Http\FormRequest;

class StockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('stock-adjustments.create');
    }

    public function rules(): array
    {
        return ['expires_at' => [Expiry::enabled() ? 'nullable' : 'exclude', 'date_format:Y-m-d'], 'batch_number' => [Expiry::enabled() ? 'nullable' : 'exclude', 'string', 'max:100'], 'stock_layer_id' => 'nullable|integer|exists:product_stock_layers,id', 'quantity' => 'required|numeric|not_in:0|max:999999|min:-999999|decimal:0,3', 'reason' => 'required|string|min:3|max:255'];
    }

    public function attributes(): array
    {
        return ['items.*.expires_at' => 'expiry date', 'items.*.batch_number' => 'batch number', 'opening_layers.*.expires_at' => 'opening stock expiry date', 'opening_layers.*.batch_number' => 'opening stock batch number', 'expires_at' => 'expiry date'];
    }
}
