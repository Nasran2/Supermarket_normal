<?php

namespace App\Support;

final class Registers
{
    public static function enabled(): bool
    {
        return (bool) config('features.register_cashier', false) && app(\App\Services\DeveloperAccess::class)->moduleAllowed('register');
    }

    public static function permission(string $name): bool
    {
        return str_starts_with($name, 'register.') || $name === 'dashboard.register'
            || $name === 'reports.register' || str_starts_with($name, 'reports.register.')
            || $name === 'reports.cash' || str_starts_with($name, 'reports.cash.');
    }
}
