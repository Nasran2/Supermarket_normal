<?php

namespace App\Http\Requests;

use App\Services\SecretCodeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

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
        $service = app(SecretCodeService::class);
        if (SecretCodeService::enabled() && array_intersect_key($this->all(), $service->defaults())) {
            $this->merge(array_replace($service->settings(), $this->only(array_keys($service->defaults()))));
        }
    }

    public function rules(): array
    {
        $rules = array_map(fn ($f) => explode('|', $f[3]), config('barcode.settings'));
        foreach (app(SecretCodeService::class)->rules() as $key => $rule) {
            $rules[$key] = SecretCodeService::enabled() ? array_merge(['sometimes'], is_array($rule) ? $rule : explode('|', $rule)) : ['prohibited'];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function ($v) {
            if (SecretCodeService::enabled() && array_intersect_key($this->all(), app(SecretCodeService::class)->defaults()) && ! $v->errors()->isNotEmpty()) {
                try {
                    app(SecretCodeService::class)->validate($this->all());
                } catch (ValidationException $error) {
                    foreach ($error->errors() as $key => $messages) {
                        foreach ($messages as $message) {
                            $v->errors()->add($key, $message);
                        }
                    }
                }
            }
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
