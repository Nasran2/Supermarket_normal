<?php

namespace App\Support;

use App\Services\SettingsService;

final class Repairs
{
    public const PERMISSIONS = [
        'view' => 'View repair jobs', 'create' => 'Receive devices', 'edit' => 'Manage repair jobs',
        'parts' => 'Manage and consume repair parts', 'pay' => 'Collect repair payments',
        'correct_payment' => 'Correct and refund repair payments', 'unlock' => 'Reveal device unlock credentials',
        'reports' => 'View and export repair reports', 'settings' => 'Manage repair settings',
    ];

    public const STATUS = [
        'RECEIVED' => 'Received', 'DIAGNOSING' => 'Diagnosing', 'WAITING_APPROVAL' => 'Waiting approval',
        'WAITING_PARTS' => 'Waiting parts', 'IN_PROGRESS' => 'In progress', 'READY' => 'Ready',
        'COMPLETED' => 'Completed', 'DELIVERED' => 'Delivered', 'CANCELLED' => 'Cancelled', 'UNREPAIRABLE' => 'Unrepairable',
    ];

    public const INTAKE_ISSUES = ['Cracked screen', 'Touch problem', 'Battery drains fast', 'Won’t charge', 'Won’t turn on', 'Sound problem', 'Camera problem', 'Water damage', 'Software issue', 'Routine check'];

    public const INTAKE_ITEMS = ['Charger', 'Cable', 'Case', 'SIM card', 'Memory card', 'Box'];

    public const INTAKE_CONDITIONS = ['Scratches', 'Dents', 'Cracked glass', 'Liquid damage', 'Missing parts', 'No visible damage'];

    public const CLOSED = ['COMPLETED', 'DELIVERED', 'CANCELLED', 'UNREPAIRABLE'];

    public static function enabled(): bool
    {
        return (bool) config('services.phone_rep');
    }

    public static function sms(): bool
    {
        return self::enabled() && config('sms.enabled');
    }

    public static function smsEnabled(): bool
    {
        return self::sms() && (bool) app(SettingsService::class)->get('repair_sms_enabled', true);
    }

    public static function transitions(string $status): array
    {
        return match ($status) {
            'RECEIVED' => ['DIAGNOSING', 'CANCELLED', 'UNREPAIRABLE'],
            'DIAGNOSING', 'WAITING_APPROVAL', 'WAITING_PARTS', 'IN_PROGRESS', 'READY' => array_values(array_diff(
                ['DIAGNOSING', 'WAITING_APPROVAL', 'WAITING_PARTS', 'IN_PROGRESS', 'READY', 'CANCELLED', 'UNREPAIRABLE', ...($status === 'READY' ? ['COMPLETED'] : [])],
                [$status],
            )),
            'COMPLETED' => ['DELIVERED'],
            default => [],
        };
    }
}
