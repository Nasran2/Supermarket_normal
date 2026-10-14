<?php

namespace App\Http\Requests;

use App\Support\Expiry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $g = $this->route('group');
        abort_if($g === 'expiry' && ! Expiry::enabled(), 404);

        return $this->user()?->hasPermission(match ($g) {
            'expiry' => 'expiry.manage_settings','stock' => 'settings.stock','system' => 'settings.system',default => 'settings.'.$g
        });
    }

    public function rules(): array
    {
        abort_if($this->route('group') === 'expiry' && ! Expiry::enabled(), 404);
        $config = config('pos.'.$this->route('group'));
        abort_unless($config, 404);
        $r = [];
        foreach ($config as $k => $f) {
            $r[$k] = explode('|', $f[3]);
            if ($f[1] === 'file') {
                $r['remove_'.$k] = ['nullable', 'boolean'];
            }
        }
        foreach (config('bill_fields.'.$this->route('group'), []) as $option) {
            $r[$option['setting']] = ['sometimes', 'boolean'];
        }
        if (isset($r['default_payment_method'])) {
            $r['default_payment_method'] = ['required', 'integer', Rule::exists('payment_methods', 'id')->where('active', true)];
        }

        if (! config('features.barcode')) {
            unset($r['barcode_enabled']);
            if (isset($r['search_mode'])) {
                $r['search_mode'] = ['required', 'in:all,name,sku'];
            }
        }

        if (! \App\Support\Units::enabled()) {
            unset($r['show_unit']);
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
