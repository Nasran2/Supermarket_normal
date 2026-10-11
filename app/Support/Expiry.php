<?php

namespace App\Support;

use App\Models\Product;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;

class Expiry
{
    public static function enabled(): bool
    {
        return (bool) config('features.expiry', false);
    }

    public static function tracks(Product $product): bool
    {
        return self::enabled() && $product->track_stock && $product->track_expiry;
    }

    public static function setting(string $key): mixed
    {
        return app(SettingsService::class)->get($key, config('expiry.'.$key.'.2'));
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(app(SettingsService::class)->get('timezone', config('app.timezone')))->startOfDay();
    }

    public static function expired($date): bool
    {
        return $date !== null && CarbonImmutable::parse($date)->toDateString() < self::today()->toDateString();
    }

    public static function days($date): ?int
    {
        return $date ? (int) self::today()->diffInDays(CarbonImmutable::parse($date instanceof \DateTimeInterface ? $date->format('Y-m-d') : substr($date, 0, 10), self::today()->getTimezone())->startOfDay(), false) : null;
    }

    public static function status($date): string
    {
        $days = self::days($date);

        return match (true) {
            $days === null => 'Unknown expiry', $days < 0 => 'Expired', $days <= (int) self::setting('expiry_urgent_days') => 'Urgent', $days <= (int) self::setting('expiry_alert_days') => 'Expiring soon', default => 'Safe'
        };
    }
}
