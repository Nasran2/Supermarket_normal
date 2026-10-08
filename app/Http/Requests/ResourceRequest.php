<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Support\Resources;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Resources::permission($this->route('resource'), $this->route('id') ? 'edit' : 'create'));
    }

    public function rules(): array
    {
        $resource = $this->route('resource');
        $definition = Resources::get($resource);
        $model = new $definition['model'];
        $id = $this->route('id');
        $r = [];
        foreach ($definition['fields'] as $key => $field) {
            $type = $field[1];
            $required = $field[2] ?? true;
            $rules = [$required ? 'required' : 'nullable'];
            if (in_array($type, ['text', 'textarea', 'email', 'password'])) {
                $rules = array_merge($rules, ['string', 'max:'.($type === 'textarea' ? 2000 : 255)]);
            }
            if ($type === 'email') {
                $rules[] = 'email';
            }
            if (in_array($type, ['money', 'quantity', 'decimal', 'number'])) {
                $rules = array_merge($rules, ['numeric', 'max:'.($type === 'quantity' ? 999999 : 999999999)]);
                if ($key === 'priority') {
                    $rules[] = 'min:-999999999';
                }
                if ($key !== 'priority') {
                    $rules[] = 'min:0';
                }
                $rules[] = 'decimal:0,'.match ($type) {
                    'quantity' => 3,'decimal' => 4,default => 2
                };
                if ($type === 'number') {
                    $rules[] = 'integer';
                }
            }
            if ($type === 'checkbox') {
                $rules = ['required', 'boolean'];
            }
            if ($type === 'select') {
                $rules[] = 'integer';
                $rules[] = Rule::exists((new $field[3])->getTable(), 'id');
            }
            if ($type === 'multiselect') {
                $rules = ['nullable', 'array'];
                $r[$key.'.*'] = ['integer', Rule::exists((new $field[3])->getTable(), 'id')];
            }
            if ($type === 'options') {
                $rules[] = Rule::in(array_keys($field[3]));
            }
            if ($type === 'date') {
                $rules = array_merge($rules, ['date', 'before_or_equal:today']);
            }
            if ($type === 'file') {
                $rules = ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'];
            }
            if ($type === 'permissions') {
                $rules = ['nullable', 'array'];
            }
            $r[$key] = $rules;
        }
        $unique = match ($resource) {
            'products' => ['sku', 'barcode'],'units' => ['name', 'short_name'],'payment-methods' => ['name', 'code'],'users' => ['email', 'username'],'roles','categories','expense-categories','unit-presets' => ['name'],default => []
        };
        foreach ($unique as $key) {
            $r[$key][] = Rule::unique($model->getTable(), $key)->ignore($id);
        }
        if ($resource === 'units') {
            $r['short_name'][] = 'max:20';
        }
        if ($resource === 'users') {
            $r['username'] = ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9._-]*$/', Rule::unique('users', 'username')->ignore($id)];
            $r['password'] = ['nullable', 'string', 'min:10', 'max:128'];
            if (! $id) {
                $r['password'][0] = 'required';
            }
        }
        if ($resource === 'roles') {
            $r['permissions.*'] = ['integer', 'exists:permissions,id'];
        }
        if ($resource === 'payment-rules') {
            $r['maximum_amount'][] = 'gte:minimum_amount';
            if ($this->input('charge_type') === 'PERCENTAGE') {
                $r['charge_value'][] = 'max:100';
            } else {
                $r['charge_value'][] = 'gt:0';
            }
        }
        if ($resource === 'expenses') {
            $r['amount'][] = 'gt:0';
        }
        if (in_array($resource, ['products', 'unit-presets'])) {
            $r['conversions'] = ['sometimes', 'array', 'max:20'];
            $r['conversions.*'] = ['array:unit_id,base_quantity,converted_quantity'.($resource === 'products' ? ',price' : '')];
            $r['conversions.*.unit_id'] = ['required', 'integer', 'distinct', Rule::exists('units', 'id')->where('active', true), 'different:unit_id'];
            foreach (['base_quantity', 'converted_quantity'] as $quantity) {
                $r['conversions.*.'.$quantity] = ['required', 'numeric', 'gt:0', 'max:999999', 'decimal:0,6'];
            }
            if ($resource === 'unit-presets') {
                $r['conversions'] = ['required', 'array', 'min:1', 'max:20'];
            }
            if ($resource === 'products') {
                $r['conversions.*.price'] = ['nullable', 'numeric', 'min:0', 'max:999999999', 'decimal:0,2'];
            }
        }
        if ($resource === 'products' && ! $id) {
            $r['opening_layers'] = ['sometimes', 'array', 'min:1', 'max:30'];
            $r['opening_layers.*'] = ['array:quantity,cost,selling_price'];
            $r['opening_layers.*.quantity'] = ['required', 'numeric', 'min:0', 'max:999999', 'decimal:0,3'];
            foreach (['cost', 'selling_price'] as $key) {
                $r['opening_layers.*.'.$key] = ['required', 'numeric', 'min:0', 'max:999999999', 'decimal:0,2'];
            }
        }
        if ($resource === 'products' && $id) {
            unset($r['stock']);
        }

        return $r;
    }

    protected function prepareForValidation(): void
    {
        if ($this->route('resource') === 'products' && ! $this->route('id') && is_array($this->input('opening_layers'))) {
            $rows = $this->input('opening_layers');
            $this->merge(['cost' => $rows[0]['cost'] ?? null, 'price' => $rows[0]['selling_price'] ?? null, 'stock' => '0']);
        }
        if ($this->route('resource') === 'products' && empty($this->input('sku'))) {
            do {
                $sku = (string) random_int(10000000, 99999999);
            } while (Product::where('sku', $sku)->exists());
            $this->merge(['sku' => $sku]);
        }
        if ($this->route('resource') === 'users' && is_string($this->input('username'))) {
            $this->merge(['username' => strtolower(trim($this->input('username')))]);
        }
        if (in_array($this->route('resource'), ['products', 'unit-presets']) && $this->boolean('conversions_present')) {
            $this->merge(['conversions' => $this->input('conversions', [])]);
        }
        foreach (Resources::get($this->route('resource'))['fields'] as $key => $field) {
            if ($field[1] === 'checkbox') {
                $this->merge([$key => $this->boolean($key)]);
            }
        }
    }
}
