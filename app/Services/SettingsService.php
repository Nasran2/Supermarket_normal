<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SettingsService
{
    public function all(): array
    {
        return Cache::remember('business_settings', 3600, fn () => Setting::pluck('value', 'key')->map(fn ($v) => json_decode($v, true))->all());
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function put(string $group, array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['group' => $group, 'value' => json_encode($value)]);
        }Cache::forget('business_settings');
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => Cache::forget('business_settings'));
        }
    }
}
