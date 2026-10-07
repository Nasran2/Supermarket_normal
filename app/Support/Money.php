<?php

namespace App\Support;

use App\Services\SettingsService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class Money
{
    public static function round(string|int $value, int $scale = 2): string
    {
        return (string) BigDecimal::of($value)->toScale($scale, RoundingMode::HALF_UP);
    }

    public static function add(string|int $a, string|int $b): string
    {
        return self::round((string) BigDecimal::of($a)->plus($b));
    }

    public static function sub(string|int $a, string|int $b): string
    {
        return self::round((string) BigDecimal::of($a)->minus($b));
    }

    public static function mul(string|int $a, string|int $b): string
    {
        return self::round((string) BigDecimal::of($a)->multipliedBy($b));
    }

    public static function percent(string $amount, string $rate): string
    {
        return (string) BigDecimal::of($amount)->multipliedBy($rate)->dividedBy(100, 2, RoundingMode::HALF_UP);
    }

    public static function compare(string|int $a, string|int $b): int
    {
        return BigDecimal::of($a)->compareTo($b);
    }

    public static function sum(iterable $values): string
    {
        $sum = BigDecimal::of(0);
        foreach ($values as $value) {
            $sum = $sum->plus($value ?? 0);
        }

        return self::round((string) $sum);
    }

    public static function quantity(string $a, string $b): string
    {
        return (string) BigDecimal::of($a)->plus($b)->toScale(3, RoundingMode::UNNECESSARY);
    }

    public static function display(string|int|float|null $value): string
    {
        $precision = (int) app(SettingsService::class)->get('number_decimals', 2);
        $rounded = self::round((string) ($value ?? 0));
        if (self::compare($rounded, self::round($rounded, 0)) !== 0) {
            $precision = max(2, $precision);
        }
        [$whole,$fraction] = array_pad(explode('.', self::round((string) ($value ?? 0), $precision)), 2, '');
        $sign = str_starts_with($whole, '-') ? '-' : '';
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', ltrim($whole, '-'));

        return $sign.$whole.($precision > 0 ? '.'.$fraction : '');
    }
}
