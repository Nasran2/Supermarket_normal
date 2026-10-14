<?php

namespace App\Support;

use App\Models\Unit;
use Brick\Math\BigDecimal;

/** Presentation only: never change persisted units or stock quantities. */
final class Units
{
    public static function enabled(): bool
    {
        return (bool) config('features.unit', false);
    }

    public static function permission(string $name): bool
    {
        return str_starts_with($name, 'units.') || str_starts_with($name, 'unit-presets.') || str_starts_with($name, 'multiple_units.');
    }

    public static function label(Unit|string|null $unit): string
    {
        return self::enabled() ? ($unit instanceof Unit ? $unit->short_name : ($unit ?? '')) : '';
    }

    public static function payload(array $data): array
    {
        if (self::enabled()) {
            return $data;
        }
        if (array_key_exists('short_name', $data)) {
            $data['name'] = '';
            $data['short_name'] = '';
        }
        foreach ($data as $key => $value) {
            if (in_array($key, ['unit', 'unit_name', 'short_name'], true)) {
                $data[$key] = '';
            } elseif (is_array($value)) {
                $data[$key] = self::payload($value);
            }
        }

        return $data;
    }

    public static function quantity(string|int|float|null $quantity, Unit|string|null $unit = null): string
    {
        $number = (string) BigDecimal::of((string) ($quantity ?? 0))->stripTrailingZeros();
        $suffix = self::label($unit);

        return $number.($suffix !== '' ? ' '.$suffix : '');
    }
}
