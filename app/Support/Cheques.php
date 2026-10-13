<?php

namespace App\Support;

use App\Services\SettingsService;
use Carbon\CarbonImmutable;

class Cheques
{
    public static function enabled(): bool
    {
        return (bool) config('features.cheque');
    }

    public static function setting(string $key): mixed
    {
        return app(SettingsService::class)->get($key, config('cheques.settings.'.$key.'.2'));
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(app(SettingsService::class)->get('timezone', 'Asia/Colombo'))->startOfDay();
    }

    public static function rules(string $prefix = 'cheques'): array
    {
        return [
            $prefix => [self::enabled() ? 'nullable' : 'prohibited', 'array', 'list', 'min:1', 'max:30'],
            $prefix.'.*' => ['array:source,cheque_id,cheque_number,amount,cheque_date,bank_name,branch,drawer_name,account_number,bank_account_id,reference,notes'],
            $prefix.'.*.source' => ['nullable', 'in:OWN,THIRD_PARTY'],
            $prefix.'.*.cheque_id' => ['nullable', 'integer'],
            $prefix.'.*.cheque_number' => ['nullable', 'string', 'max:80'],
            $prefix.'.*.amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999999.99', 'decimal:0,2'],
            $prefix.'.*.cheque_date' => ['nullable', 'date_format:Y-m-d'],
            $prefix.'.*.bank_name' => ['nullable', 'string', 'max:150'],
            $prefix.'.*.branch' => ['nullable', 'string', 'max:150'],
            $prefix.'.*.drawer_name' => ['nullable', 'string', 'max:150'],
            $prefix.'.*.account_number' => ['nullable', 'string', 'max:80'],
            $prefix.'.*.bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            $prefix.'.*.reference' => ['nullable', 'string', 'max:255'],
            $prefix.'.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
