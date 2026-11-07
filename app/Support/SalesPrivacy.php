<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final class SalesPrivacy
{
    private const SETTING = 'sales_privacy';

    public const SESSION_KEY = 'sales_privacy_mode';

    public const MODES = ['all' => 'All sales', 'high' => 'Highest amounts', 'low' => 'Lowest amounts'];

    public const SHORTCUTS = ['high' => 'D', 'low' => 'X'];

    public const SURFACES = [
        'pos' => ['POS screen', 'Switch from the register. When Sales List is enabled, the ranked view opens in a new tab.'],
        'sales' => ['Sales List', 'Rank matching invoices and recalculate the list totals.'],
        'reports' => ['Reports', 'Filter sales reports and their PDF and CSV exports.'],
        'dashboard' => ['Dashboard widgets', 'Use selected bills in sales totals, charts and recent sales.'],
        'customers' => ['Customer purchase history', 'Rank purchases and recalculate purchase totals for each customer.'],
    ];

    public static function settings(): array
    {
        $defaults = [
            'enabled' => false,
            'bill_count' => 5,
            'applies_to' => ['pos', 'sales', 'reports', 'dashboard', 'customers'],
        ];

        $saved = json_decode((string) SecretControl::get(self::SETTING, ''), true);

        return array_replace($defaults, is_array($saved) ? array_intersect_key($saved, $defaults) : []);
    }

    public static function available(): ?array
    {
        if (! SecretControl::isSuperAdminFeatureActive() || SecretControl::isSuperAdminArea() || ! auth()->user()?->hasPermission('sales.view')) {
            return null;
        }

        $settings = self::settings();

        return $settings['enabled'] ? $settings : null;
    }

    public static function view(string $surface): ?array
    {
        $settings = self::available();
        if ($settings === null || ! in_array($surface, $settings['applies_to'], true)) {
            return null;
        }

        $mode = request()->session()->get(self::SESSION_KEY, 'all');

        return $settings + ['mode' => isset(self::MODES[$mode]) ? $mode : 'all'];
    }

    public static function selectedIds(Builder $sales, ?array $view): ?QueryBuilder
    {
        if ($view === null || $view['mode'] === 'all') {
            return null;
        }

        $ranked = (clone $sales)->reorder()->select('sales.id')->where('sales.status', 'ACTIVE')
            ->orderBy('sales.customer_payable', self::direction($view))->orderBy('sales.id')
            ->limit($view['bill_count']);

        // MariaDB cannot use LIMIT directly inside an IN subquery.
        return DB::query()->fromSub($ranked, 'ranked_sales')->select('ranked_sales.id');
    }

    public static function direction(array $view): string
    {
        return $view['mode'] === 'high' ? 'desc' : 'asc';
    }

    public static function save(array $settings): void
    {
        DB::table('system_settings')->upsert(
            [['key' => self::SETTING, 'value' => json_encode($settings, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]],
            ['key'],
            ['value', 'updated_at'],
        );
        SecretControl::flushRuntimeCache();
    }
}
