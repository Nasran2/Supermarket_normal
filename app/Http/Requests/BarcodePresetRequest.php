<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BarcodePresetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return config('features.barcode') && $this->user()->hasPermission('barcode.manage_presets');
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:150', Rule::unique('barcode_label_presets', 'name')->ignore($this->route('preset')?->id)], 'is_default' => 'sometimes|boolean', 'configuration' => 'required|array'];
    }
}
