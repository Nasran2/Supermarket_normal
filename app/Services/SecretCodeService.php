<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Validator;

class SecretCodeService
{
    public static function enabled(): bool
    {
        return (bool) config('features.barcode') && (bool) config('features.scode');
    }

    public function defaults(): array
    {
        $values = ['scode_padding' => 'PRICE', 'scode_padding_side' => 'SUFFIX', 'scode_fillers' => 'XYZ', 'scode_min_length' => 4, 'scode_decimals' => 0, 'scode_show' => true, 'scode_font_size' => 10, 'scode_position' => 'bottom', 'scode_alignment' => 'right'];
        foreach (str_split('JABCDEFGHI') as $digit => $letter) {
            $values['scode_digit_'.$digit] = $letter;
        }

        return $values;
    }

    public function settings(): array
    {
        return array_replace($this->defaults(), array_intersect_key(app(SettingsService::class)->all(), $this->defaults()));
    }

    public function rules(): array
    {
        $rules = ['scode_padding' => 'required|in:PRICE,FIXED,NONE', 'scode_padding_side' => 'required|in:PREFIX,SUFFIX', 'scode_fillers' => ['required', 'string', 'regex:/^[a-zA-Z]{1,16}$/'], 'scode_min_length' => 'required|integer|min:1|max:32', 'scode_decimals' => 'required|integer|in:0,2', 'scode_show' => 'required|boolean', 'scode_font_size' => 'required|integer|min:6|max:32', 'scode_position' => 'required|in:top,before_price,after_price,before_barcode,bottom', 'scode_alignment' => 'required|in:left,center,right'];
        for ($i = 0; $i <= 9; $i++) {
            $rules['scode_digit_'.$i] = ['required', 'string', 'regex:/^[a-zA-Z]$/'];
        }

        return $rules;
    }

    public function validate(array $values): array
    {
        $validator = Validator::make($values, $this->rules());
        $validator->after(function ($v) use ($values) {
            $letters = [];
            for ($i = 0; $i <= 9; $i++) {
                $letter = strtolower((string) ($values['scode_digit_'.$i] ?? ''));
                if (in_array($letter, $letters, true)) {
                    $v->errors()->add('scode_digit_'.$i, 'Use a different letter for each digit (A and a count as the same letter).');
                }
                $letters[] = $letter;
            }
            $fillers = str_split(strtolower((string) ($values['scode_fillers'] ?? '')));
            if (array_intersect($letters, $fillers)) {
                $v->errors()->add('scode_fillers', 'Filler letters must be different from all ten digit letters.');
            }
            if (count($fillers) !== count(array_unique($fillers))) {
                $v->errors()->add('scode_fillers', 'Enter each filler letter only once.');
            }
        });

        return $validator->validate();
    }

    public function encode(string $cost, string $price, ?array $settings = null): ?string
    {
        if (! self::enabled()) {
            return null;
        }
        $s = $this->validate($settings ?? $this->settings());
        $digits = $this->digits($cost, (int) $s['scode_decimals']);
        $map = [];
        for ($i = 0; $i <= 9; $i++) {
            $map[(string) $i] = $s['scode_digit_'.$i];
        }
        $code = strtr($digits, $map);
        $length = match ($s['scode_padding']) {
            'PRICE' => max((int) $s['scode_min_length'], strlen($this->digits($price, (int) $s['scode_decimals']))),
            'FIXED' => (int) $s['scode_min_length'],
            default => strlen($code),
        };
        $padding = '';
        for ($i = strlen($code); $i < $length; $i++) {
            $padding .= $s['scode_fillers'][random_int(0, strlen($s['scode_fillers']) - 1)];
        }

        return $s['scode_padding_side'] === 'PREFIX' ? $padding.$code : $code.$padding;
    }

    private function digits(string $amount, int $decimals): string
    {
        return str_replace('.', '', (string) BigDecimal::of($amount)->abs()->toScale($decimals, RoundingMode::HALF_UP));
    }
}
