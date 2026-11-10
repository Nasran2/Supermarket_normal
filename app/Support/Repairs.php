<?php

namespace App\Support;

use App\Models\RepairJob;
use App\Services\SettingsService;
use Carbon\Carbon;
use Carbon\CarbonInterface;

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

    /**
     * Generate the next sequential tracking number for a repair date: TR-YYYYMMDD001.
     */
    public static function nextTracking(CarbonInterface|string|null $date = null, bool $lock = false): string
    {
        $prefix = 'TR-'.Carbon::parse($date ?? today())->format('Ymd');
        $query = RepairJob::where('tracking', 'like', $prefix.'%');
        if ($lock) {
            $query->lockForUpdate();
        }
        $maxSeq = $query->pluck('tracking')->map(function (string $code) use ($prefix) {
            $suffix = substr($code, strlen($prefix));

            return ctype_digit($suffix) ? (int) $suffix : 0;
        })->max() ?? 0;

        $seq = $maxSeq + 1;
        $tracking = $prefix.str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
        while (RepairJob::where('tracking', $tracking)->exists()) {
            $seq++;
            $tracking = $prefix.str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
        }

        return $tracking;
    }

    /**
     * Return highest existing sequence number per YYYYMMDD prefix for live draft previews.
     *
     * @return array<string, int>
     */
    public static function trackingMaxByDate(): array
    {
        $map = [];
        foreach (RepairJob::where('tracking', 'like', 'TR-%')->pluck('tracking') as $code) {
            if (preg_match('/^TR-(\d{8})(\d+)$/', $code, $m)) {
                $ymd = $m[1];
                $seq = (int) $m[2];
                if ($seq > ($map[$ymd] ?? 0)) {
                    $map[$ymd] = $seq;
                }
            }
        }

        return $map;
    }
}
