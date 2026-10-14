<?php

namespace App\Http\Requests;

use App\Support\Expiry;
use Illuminate\Foundation\Http\FormRequest;

class StockBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission($this->routeIs('adjustments.update') ? 'stock-adjustments.edit' : 'stock-adjustments.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'revision' => ['nullable', 'integer', 'min:1'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.expires_at' => [Expiry::enabled() ? 'nullable' : 'exclude', 'date_format:Y-m-d'],
            'items.*.batch_number' => [Expiry::enabled() ? 'nullable' : 'exclude', 'string', 'max:100'],
            'items.*.stock_layer_id' => ['nullable', 'integer', 'exists:product_stock_layers,id'],
            'items.*.unit_id' => [config('features.unit') ? 'required' : 'exclude', 'integer', 'exists:units,id'],
            'items.*.mode' => ['required', 'in:ADD,REMOVE,SET'],
            'items.*.quantity' => ['required', 'numeric', 'min:0', 'max:999999999999', 'decimal:0,3'],
            'items.*.expected_stock' => ['required', 'numeric', 'min:-999999999999', 'max:999999999999', 'decimal:0,3'],
            'items.*.expected_price' => ['required', 'numeric', 'min:0', 'max:9999999999999', 'decimal:0,2'],
            'items.*.expected_cost' => [$this->user()->hasPermission('products.view_cost') ? 'required' : 'nullable', 'numeric', 'min:0', 'max:9999999999999', 'decimal:0,2'],
            'items.*.price' => ['nullable', 'numeric', 'min:0', 'max:999999999', 'decimal:0,2'],
            'items.*.cost' => ['nullable', 'numeric', 'min:0', 'max:999999999', 'decimal:0,2'],
        ];
    }

    public function attributes(): array
    {
        return ['items.*.expires_at' => 'expiry date', 'items.*.batch_number' => 'batch number', 'opening_layers.*.expires_at' => 'opening stock expiry date', 'opening_layers.*.batch_number' => 'opening stock batch number', 'expires_at' => 'expiry date'];
    }
}
