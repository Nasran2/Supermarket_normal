<?php

namespace App\Support;

class Branding
{
    public static function enabled(): bool
    {
        return (bool) config('features.colors', false);
    }

    public static function values(array $settings): array
    {
        $values = [];
        foreach (config('pos.branding', []) as $key => $field) {
            $value = array_key_exists($key, $settings) ? $settings[$key] : $field[2];
            $values[$key] = $field[1] === 'color' && ! preg_match('/^#[0-9a-fA-F]{6}$/', (string) $value)
                ? $field[2] : $value;
        }
        $values['brand_name'] = $values['brand_name'] ?: ($settings['business_name'] ?? 'Twinsofte');
        $values['login_brand_text'] = $values['login_brand_text'] ?: mb_strtoupper($values['brand_name']).' POS';
        $values['login_icon'] = array_key_exists($values['login_icon'], self::icons()) ? $values['login_icon'] : 'shopping-basket';
        $values['login_image'] = match ($values['login_icon_mode']) {
            'custom-logo' => $values['login_logo'],
            'icon' => null,
            default => $settings['logo'] ?? null,
        };

        return $values;
    }

    public static function icons(): array
    {
        return [
            'shopping-basket' => 'Basket', 'shopping-bag' => 'Shopping bag',
            'store' => 'Store', 'shopping-cart' => 'Shopping cart', 'scan-line' => 'Scanner',
            'receipt-text' => 'Receipt', 'building-2' => 'Building',
            'package' => 'Package', 'shield-check' => 'Shield',
        ];
    }

    public static function darken(string $hex): string
    {
        $channels = array_map(fn ($part) => (int) round(hexdec($part) * 0.8), str_split(ltrim($hex, '#'), 2));

        return sprintf('#%02x%02x%02x', ...$channels);
    }

    public static function foreground(string $hex): string
    {
        $channels = array_map(function ($part) {
            $value = hexdec($part) / 255;

            return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2] > 0.179 ? '#111827' : '#ffffff';
    }
}
