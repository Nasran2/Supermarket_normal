<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $g = $this->route('group');

        return $this->user()?->hasPermission(match ($g) {
            'stock' => 'settings.pos','system' => 'settings.business',default => 'settings.'.$g
        });
    }

    public function rules(): array
    {
        $config = config('pos.'.$this->route('group'));
        abort_unless($config, 404);
        $r = [];
        foreach ($config as $k => $f) {
            $r[$k] = explode('|', $f[3]);
        }
        $r['remove_logo'] = ['nullable', 'boolean'];
        if (isset($r['default_payment_method'])) {
            $r['default_payment_method'] = ['required', 'integer', Rule::exists('payment_methods', 'id')->where('active', true)];
        }

        return $r;
    }

    protected function prepareForValidation(): void
    {
        foreach (config('pos.'.$this->route('group'), []) as $key => $f) {
            if ($f[1] === 'checkbox') {
                $this->merge([$key => $this->boolean($key)]);
            }
        }
    }
}
