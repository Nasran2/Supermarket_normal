<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BarcodeSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('barcode.manage_settings');
    }

    protected function prepareForValidation(): void
    {
        foreach (config('barcode.settings') as $key => $f) {
            if ($f[1] === 'checkbox') {
                $this->merge([$key => $this->boolean($key)]);
            }
        }
    }

    public function rules(): array
    {
        return array_map(fn ($f) => explode('|', $f[3]), config('barcode.settings'));
    }

    public function after(): array
    {
        return [function ($v) {
            $type = $this->input('barcode_default_type', 'CODE128');
            $len = $type === 'CODE128' ? (int) $this->input('barcode_length') : ['EAN13' => 12, 'EAN8' => 7, 'UPCA' => 11][$type] ?? 0;
            if (strlen((string) $this->input('barcode_prefix')) >= $len) {
                $v->errors()->add('barcode_prefix', 'The prefix must leave space for a sequence number.');
            }
            if ($this->boolean('barcode_generate_on_create') && ! $this->boolean('barcode_auto_generate')) {
                $v->errors()->add('barcode_generate_on_create', 'Enable automatic generation before enabling generation on create.');
            }
        }];
    }
}
