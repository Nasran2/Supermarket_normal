<?php

namespace App\Services;

use App\Support\Demo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class DemoDataService
{
    // Explicit business-data allowlist. Credentials, configuration, sessions,
    // queues, cache and migration history must never be reset.
    private const TABLES = [
        'categories', 'customers', 'suppliers', 'expense_categories', 'products',
        'product_units', 'category_product', 'registers', 'register_movements',
        'sales', 'sale_items', 'sale_payments', 'sale_revisions', 'sale_returns',
        'sale_return_items', 'sale_collections', 'customer_payments', 'customer_payment_allocations',
        'purchases', 'purchase_items', 'purchase_payments', 'purchase_charges',
        'purchase_returns', 'purchase_return_items', 'supplier_returns',
        'return_stock_allocations', 'return_account_allocations', 'return_settlements',
        'expenses', 'stock_movements', 'stock_adjustments', 'stock_adjustment_items',
        'product_stock_layers', 'sale_stock_allocations', 'stock_layer_movements',
        'document_sequences', 'audit_logs', 'hr_staff', 'hr_employment_events',
        'hr_attendances', 'hr_leaves', 'hr_payrolls', 'hr_payments',
    ];

    public function synchronized(callable $callback): mixed
    {
        if (! Demo::enabled()) {
            return $callback();
        }
        // The web app and scheduler share a filesystem lock, even if the
        // application's normal cache store is array or is being reconfigured.
        $identity = DB::connection()->getName().'|'.DB::connection()->getDatabaseName();

        return Cache::store('file')->lock('demo-cycle-'.hash('sha256', $identity), 300)->block(15, $callback);
    }

    /** Called under synchronized() by web requests and the scheduled command. */
    public function refresh(): array
    {
        if (! Demo::enabled()) {
            return ['reset' => false, 'cycle' => null, 'next_reset_at' => null];
        }
        if (! Schema::hasTable('demo_cycles')) {
            throw new RuntimeException('Demo setup is incomplete. Run php artisan migrate before using DEMO=true.');
        }
        $state = DB::table('demo_cycles')->where('id', 1)->first();
        if (! $state) {
            DB::transaction(function () {
                $baseline = [];
                foreach (self::TABLES as $table) {
                    if (Schema::hasTable($table)) {
                        $baseline[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
                    }
                }
                DB::table('demo_cycles')->insert([
                    'id' => 1, 'baseline' => json_encode($baseline, JSON_THROW_ON_ERROR),
                    'cycle' => 1, 'started_at' => now('UTC'), 'next_reset_at' => now('UTC')->addDays(config('demo.reset_days')),
                ]);
            });
            $state = DB::table('demo_cycles')->where('id', 1)->first();
        }
        $reset = Carbon::parse($state->next_reset_at, 'UTC')->lte(now('UTC'));
        if ($reset) {
            $baseline = json_decode($state->baseline, true, flags: JSON_THROW_ON_ERROR);
            $tables = array_values(array_filter(self::TABLES, fn ($table) => Schema::hasTable($table)));
            if (array_diff($tables, array_keys($baseline)) || array_diff(array_keys($baseline), $tables)) {
                throw new RuntimeException('Demo baseline tables do not match the installed schema.');
            }
            // Validate everything before deleting. Schema changes require a
            // deliberately recreated baseline by the installation owner.
            foreach ($baseline as $table => $rows) {
                if (! in_array($table, self::TABLES, true) || ! Schema::hasTable($table)) {
                    throw new RuntimeException('Demo baseline does not match the installed schema.');
                }
                if ($rows && array_diff(array_keys($rows[0]), Schema::getColumnListing($table))) {
                    throw new RuntimeException('Demo baseline columns do not match the installed schema.');
                }
            }
            Schema::withoutForeignKeyConstraints(function () use ($baseline, $state) {
                DB::transaction(function () use ($baseline, $state) {
                    foreach (array_keys($baseline) as $table) {
                        DB::table($table)->delete();
                    }
                    foreach ($baseline as $table => $rows) {
                        foreach (array_chunk($rows, 100) as $chunk) {
                            DB::table($table)->insert($chunk);
                        }
                    }
                    DB::table('demo_cycles')->where('id', 1)->update([
                        'cycle' => $state->cycle + 1, 'started_at' => now('UTC'),
                        'next_reset_at' => now('UTC')->addDays(config('demo.reset_days')),
                    ]);
                });
            });
            Cache::forget('business_settings');
            $state = DB::table('demo_cycles')->where('id', 1)->first();
        }

        return ['reset' => $reset, 'cycle' => $state->cycle, 'next_reset_at' => Carbon::parse($state->next_reset_at, 'UTC')];
    }
}
