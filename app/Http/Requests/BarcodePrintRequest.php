<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BarcodePrintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return config('features.barcode') && $this->user()->hasPermission($this->routeIs('barcode.preview') ? 'barcode.view' : 'barcode.print');
    }

    public function rules(): array
    {
        return ['configuration' => 'required|array', 'items' => 'required|array|min:1|max:300', 'items.*' => 'array:product_id,option,quantity', 'items.*.product_id' => 'required|integer|exists:products,id', 'items.*.option' => 'required|string|max:100', 'items.*.quantity' => 'required|integer|min:1|max:10000', 'preset_name' => 'nullable|string|max:150', 'confirmed' => 'sometimes|boolean'];
    }
}
