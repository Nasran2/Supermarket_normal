<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SecretControl
{
    /**
     * Cached settings array during the request.
     */
    protected static ?array $cache = null;

    /**
     * Cached hidden product IDs during the request.
     */
    protected static ?array $cachedHiddenProductIds = null;

    /**
     * Clear all runtime caches.
     */
    public static function flushRuntimeCache(): void
    {
        static::$cache = null;
        static::$cachedHiddenProductIds = null;
    }

    /**
     * Load all system settings.
     */
    protected static function load(): array
    {
        if (static::$cache !== null) {
            return static::$cache;
        }

        try {
            if (! Schema::hasTable('system_settings')) {
                return static::$cache = [];
            }

            return static::$cache = DB::table('system_settings')
                ->pluck('value', 'key')
                ->toArray();
        } catch (\Throwable $e) {
            return static::$cache = [];
        }
    }

    /**
     * Get a setting from system_settings.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $all = static::load();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /**
     * Set a setting in system_settings.
     */
    public static function set(string $key, mixed $value): void
    {
        try {
            DB::table('system_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => (string) $value, 'updated_at' => now(), 'created_at' => now()]
            );

            if (static::$cache !== null) {
                static::$cache[$key] = (string) $value;
            }
            static::$cachedHiddenProductIds = null;
        } catch (\Throwable $e) {
            // Log or ignore if table missing
        }
    }

    /**
     * Whether the Super Admin feature is enabled via .env (SUPER_ADMIN=true).
     * When SUPER_ADMIN is false, all secret controls, multipliers, and hiding features
     * are completely bypassed/disabled, and all prices, costs, stock, and UI elements
     * revert 100% to normal.
     */
    public static function isSuperAdminFeatureActive(): bool
    {
        return filter_var(config('features.super_admin', env('SUPER_ADMIN', false)), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Whether the current request is inside the Super Admin panel.
     */
    public static function isSuperAdminArea(): bool
    {
        if (! static::isSuperAdminFeatureActive()) {
            return false;
        }

        try {
            return request()->is('superadmin*');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Multiplier factor for prices, costs, and profits.
     * When SUPER_ADMIN=false, or when viewing inside the Super Admin panel (/superadmin*),
     * ALWAYS returns 1.0 (100% real numbers).
     * For normal app pages when SUPER_ADMIN=true, scales by (visible_profit_percentage / 100).
     */
    public static function multiplier(): float
    {
        if (! static::isSuperAdminFeatureActive() || static::isSuperAdminArea()) {
            return 1.0;
        }

        $pct = (float) static::get('visible_profit_percentage', 100);
        if ($pct <= 0) {
            return 0.0;
        }

        return min(100.0, $pct) / 100.0;
    }

    /**
     * Return the raw visible profit percentage (default: 100).
     */
    public static function percentage(): float
    {
        if (! static::isSuperAdminFeatureActive()) {
            return 100.0;
        }

        return (float) static::get('visible_profit_percentage', 100);
    }

    /**
     * Check if a feature/card/widget is hidden from normal users.
     * Always returns false when SUPER_ADMIN=false or inside Super Admin area.
     */
    public static function isHidden(string $key): bool
    {
        if (! static::isSuperAdminFeatureActive() || static::isSuperAdminArea()) {
            return false;
        }

        return (bool) static::get($key, 0);
    }

    /**
     * Check if a feature is enabled.
     */
    public static function isEnabled(string $key): bool
    {
        if (! static::isSuperAdminFeatureActive()) {
            return false;
        }

        return (bool) static::get($key, 0);
    }

    /**
     * Scale a selling price by the price multiplier.
     */
    public static function scalePrice(float|int|string|null $price): float
    {
        $val = (float) ($price ?? 0);
        return round($val * static::sellingPriceMultiplier(), 2);
    }

    /**
     * Scale a cost price by the cost multiplier.
     */
    public static function scaleCost(float|int|string|null $cost): float
    {
        $val = (float) ($cost ?? 0);
        return round($val * static::costMultiplier(), 2);
    }

    /**
     * Scale a given numeric value by the multiplier.
     */
    public static function scale(float|int|string|null $value): float
    {
        $val = (float) ($value ?? 0);
        return round($val * static::multiplier(), 2);
    }

    /**
     * Multiplier factor for stock/quantities.
     * Always 1.0 when SUPER_ADMIN=false or in Super Admin area.
     */
    public static function stockMultiplier(): float
    {
        if (! static::isSuperAdminFeatureActive() || static::isSuperAdminArea()) {
            return 1.0;
        }

        $pct = (float) static::get('visible_stock_percentage', 100);
        if ($pct <= 0) {
            return 0.0;
        }

        return min(100.0, $pct) / 100.0;
    }

    /**
     * Raw visible stock percentage.
     */
    public static function stockPercentage(): float
    {
        if (! static::isSuperAdminFeatureActive()) {
            return 100.0;
        }

        return (float) static::get('visible_stock_percentage', 100);
    }

    /**
     * Multiplier factor for selling prices.
     * Always 1.0 when SUPER_ADMIN=false or in Super Admin area.
     */
    public static function sellingPriceMultiplier(): float
    {
        if (! static::isSuperAdminFeatureActive() || static::isSuperAdminArea()) {
            return 1.0;
        }

        $pct = static::sellingPricePercentage();
        if ($pct <= 0) {
            return 0.0;
        }

        return min(100.0, $pct) / 100.0;
    }

    /**
     * Raw visible selling price percentage.
     */
    public static function sellingPricePercentage(): float
    {
        if (! static::isSuperAdminFeatureActive()) {
            return 100.0;
        }

        return (float) static::get('visible_selling_price_percentage', static::get('visible_price_percentage', static::get('visible_profit_percentage', 100)));
    }

    /**
     * Multiplier factor for selling prices (alias).
     */
    public static function priceMultiplier(): float
    {
        return static::sellingPriceMultiplier();
    }

    /**
     * Raw visible price percentage (alias).
     */
    public static function pricePercentage(): float
    {
        return static::sellingPricePercentage();
    }

    /**
     * Multiplier factor for cost prices.
     * Always 1.0 when SUPER_ADMIN=false or in Super Admin area.
     */
    public static function costMultiplier(): float
    {
        if (! static::isSuperAdminFeatureActive() || static::isSuperAdminArea()) {
            return 1.0;
        }

        $pct = static::costPercentage();
        if ($pct <= 0) {
            return 0.0;
        }

        return min(100.0, $pct) / 100.0;
    }

    /**
     * Raw visible cost price percentage.
     */
    public static function costPercentage(): float
    {
        if (! static::isSuperAdminFeatureActive()) {
            return 100.0;
        }

        return (float) static::get('visible_cost_percentage', static::get('visible_profit_percentage', 100));
    }

    /**
     * Parse range string like "0-50,120-200" or "200-300" into an array of [['min' => float, 'max' => float]].
     */
    public static function parseRanges(?string $rangesStr): array
    {
        if (empty(trim((string) $rangesStr))) {
            return [];
        }

        $parsed = [];
        $ranges = explode(',', $rangesStr);
        foreach ($ranges as $range) {
            $parts = explode('-', trim($range));
            if (count($parts) === 2 && is_numeric(trim($parts[0])) && is_numeric(trim($parts[1]))) {
                $parsed[] = [
                    'min' => (float) trim($parts[0]),
                    'max' => (float) trim($parts[1]),
                ];
            }
        }

        return $parsed;
    }

    /**
     * Array of hidden product IDs.
     * Combines products explicitly hidden by search + products matching selling price/cost ranges.
     * When SUPER_ADMIN=false or inside Super Admin (/superadmin*), returns empty array (unrestricted).
     */
    public static function hiddenProductIds(): array
    {
        if (! static::isSuperAdminFeatureActive() || static::isSuperAdminArea()) {
            return [];
        }

        if (static::$cachedHiddenProductIds !== null) {
            return static::$cachedHiddenProductIds;
        }

        $ids = [];

        // 1. Explicitly hidden products (search & add)
        $raw = static::get('hidden_products', '[]');
        $list = is_string($raw) ? json_decode($raw, true) : (array) $raw;
        if (is_array($list)) {
            $ids = array_map('intval', array_filter(array_column($list, 'id')));
        }

        // 2. Selling price ranges & cost price ranges
        $sellingRanges = static::parseRanges(static::get('hide_selling_price_ranges'));
        $costRanges = static::parseRanges(static::get('hide_cost_price_ranges'));

        if (! empty($sellingRanges) || ! empty($costRanges)) {
            try {
                // Check products table
                $productQuery = DB::table('products')->select('id');
                $productQuery->where(function ($q) use ($sellingRanges, $costRanges) {
                    foreach ($sellingRanges as $r) {
                        $q->orWhereBetween('price', [$r['min'], $r['max']]);
                    }
                    foreach ($costRanges as $r) {
                        $q->orWhereBetween('cost', [$r['min'], $r['max']]);
                    }
                });
                $rangeProductIds = $productQuery->pluck('id')->all();
                $ids = array_merge($ids, array_map('intval', $rangeProductIds));

                // Also check product_stock_layers table
                if (Schema::hasTable('product_stock_layers')) {
                    $layerQuery = DB::table('product_stock_layers')->select('product_id')->distinct();
                    $layerQuery->where(function ($q) use ($sellingRanges, $costRanges) {
                        foreach ($sellingRanges as $r) {
                            $q->orWhereBetween('selling_price', [$r['min'], $r['max']]);
                        }
                        foreach ($costRanges as $r) {
                            $q->orWhereBetween('cost_price', [$r['min'], $r['max']]);
                        }
                    });
                    $layerProductIds = $layerQuery->pluck('product_id')->all();
                    $ids = array_merge($ids, array_map('intval', $layerProductIds));
                }
            } catch (\Throwable $e) {
                // If tables missing during migration/setup, ignore
            }
        }

        return static::$cachedHiddenProductIds = array_values(array_unique(array_filter($ids)));
    }

    /**
     * Array of hidden products with details.
     */
    public static function hiddenProductsList(): array
    {
        $raw = static::get('hidden_products', '[]');
        $list = is_string($raw) ? json_decode($raw, true) : (array) $raw;
        return is_array($list) ? $list : [];
    }

    /**
     * Add a product to hidden products.
     */
    public static function addHiddenProduct(int $id): bool
    {
        $product = \App\Models\Product::find($id);
        if (! $product) {
            return false;
        }

        $list = static::hiddenProductsList();
        foreach ($list as $item) {
            if ((int) ($item['id'] ?? 0) === $id) {
                return true; // Already added
            }
        }

        $list[] = [
            'id'   => $product->id,
            'name' => $product->name,
            'sku'  => $product->sku,
            'barcode' => $product->barcode,
        ];

        static::set('hidden_products', json_encode(array_values($list)));
        return true;
    }

    /**
     * Remove a product from hidden products.
     */
    public static function removeHiddenProduct(int $id): bool
    {
        $list = static::hiddenProductsList();
        $newList = array_values(array_filter($list, fn ($item) => (int) ($item['id'] ?? 0) !== $id));
        static::set('hidden_products', json_encode($newList));
        return true;
    }

    /**
     * Test if a numeric value falls within a comma-separated range string (e.g. "0-50,120-200").
     */
    public static function matchesRange(float|int|string|null $val, ?string $rangesStr): bool
    {
        if ($val === null || empty(trim((string) $rangesStr))) {
            return false;
        }

        $num = (float) $val;
        $ranges = explode(',', $rangesStr);
        foreach ($ranges as $range) {
            $parts = explode('-', trim($range));
            if (count($parts) === 2 && is_numeric(trim($parts[0])) && is_numeric(trim($parts[1]))) {
                $min = (float) trim($parts[0]);
                $max = (float) trim($parts[1]);
                if ($num >= $min && $num <= $max) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if a product's cost price is hidden.
     */
    public static function isCostHiddenFor(float|int|string|null $cost): bool
    {
        if (! static::isSuperAdminFeatureActive() || static::isSuperAdminArea()) {
            return false;
        }

        if (static::isHidden('hide_all_price_data') || static::isHidden('hide_exact_purchase_cost') || static::isHidden('hide_stock_money') || static::isHidden('hide_genuine_stock_values')) {
            return true;
        }

        $ranges = static::get('hide_cost_price_ranges');
        if (! empty($ranges) && static::matchesRange($cost, $ranges)) {
            return true;
        }

        return false;
    }

    /**
     * Check if a product's selling price is hidden.
     */
    public static function isSellingPriceHiddenFor(float|int|string|null $price): bool
    {
        if (! static::isSuperAdminFeatureActive() || static::isSuperAdminArea()) {
            return false;
        }

        if (static::isHidden('hide_all_price_data') || static::isHidden('hide_exact_selling_price')) {
            return true;
        }

        $ranges = static::get('hide_selling_price_ranges');
        if (! empty($ranges) && static::matchesRange($price, $ranges)) {
            return true;
        }

        return false;
    }

    /**
     * Format/display a product's name. Masks if hide_product_level_data is active.
     */
    public static function productName(mixed $product): string
    {
        if (! $product) {
            return '';
        }

        $name = is_object($product) ? ($product->name ?? '') : (string) $product;

        if (! static::isSuperAdminFeatureActive() || static::isSuperAdminArea()) {
            return $name;
        }

        if (static::isHidden('hide_product_level_data')) {
            $id = is_object($product) ? ($product->id ?? $product->sku ?? 'item') : 'item';
            return 'Item #' . $id;
        }

        return $name;
    }

    /**
     * Customer amount percentage (default: 100).
     */
    public static function customerAmountPercentage(): float
    {
        if (! static::isSuperAdminFeatureActive()) {
            return 100.0;
        }

        return (float) static::get('visible_customer_amount_percentage', 100);
    }

    /**
     * Multiplier factor for customer balances, dues, and payments.
     */
    public static function customerAmountMultiplier(): float
    {
        if (! static::isSuperAdminFeatureActive() || static::isSuperAdminArea()) {
            return 1.0;
        }

        $pct = static::customerAmountPercentage();
        if ($pct <= 0) {
            return 0.0;
        }

        return min(100.0, $pct) / 100.0;
    }

    /**
     * Cheque amount percentage (default: 100).
     */
    public static function chequeAmountPercentage(): float
    {
        if (! static::isSuperAdminFeatureActive()) {
            return 100.0;
        }

        return (float) static::get('visible_cheque_amount_percentage', 100);
    }

    /**
     * Multiplier factor for displayed cheque amounts.
     */
    public static function chequeAmountMultiplier(): float
    {
        if (! static::isSuperAdminFeatureActive() || static::isSuperAdminArea()) {
            return 1.0;
        }

        $pct = static::chequeAmountPercentage();
        if ($pct <= 0) {
            return 0.0;
        }

        return min(100.0, $pct) / 100.0;
    }

    /**
     * Cheque IDs to hide based on cheque count rules.
     */
    public static function hiddenChequeIds(): array
    {
        if (! static::isSuperAdminFeatureActive() || static::isSuperAdminArea()) {
            return [];
        }

        $rule = static::get('cheque_count_rule', 'NONE');
        $limit = (int) static::get('cheque_rule_limit', 0);

        if ($rule === 'HIDE_ALL') {
            try {
                return DB::table('cheques')->pluck('id')->all();
            } catch (\Throwable $e) {
                return [];
            }
        }

        if ($limit > 0 && in_array($rule, ['HIDE_TOP_HIGHEST', 'HIDE_TOP_LOWEST'], true)) {
            try {
                $dir = $rule === 'HIDE_TOP_HIGHEST' ? 'desc' : 'asc';
                return DB::table('cheques')->orderBy('amount', $dir)->limit($limit)->pluck('id')->all();
            } catch (\Throwable $e) {
                return [];
            }
        }

        return [];
    }

    /**
     * Hidden suppliers list.
     */
    public static function hiddenSuppliersList(): array
    {
        $raw = static::get('hidden_suppliers', '[]');
        $list = is_string($raw) ? json_decode($raw, true) : (array) $raw;
        return is_array($list) ? $list : [];
    }

    /**
     * Hidden supplier IDs.
     */
    public static function hiddenSupplierIds(): array
    {
        if (! static::isSuperAdminFeatureActive() || static::isSuperAdminArea()) {
            return [];
        }

        $list = static::hiddenSuppliersList();
        return array_values(array_unique(array_filter(array_map('intval', array_column($list, 'id')))));
    }

    /**
     * Add a supplier to hidden suppliers.
     */
    public static function addHiddenSupplier(int $id): bool
    {
        $supplier = \App\Models\Supplier::find($id);
        if (! $supplier) {
            return false;
        }

        $list = static::hiddenSuppliersList();
        foreach ($list as $item) {
            if ((int) ($item['id'] ?? 0) === $id) {
                return true;
            }
        }

        $list[] = [
            'id'    => $supplier->id,
            'name'  => $supplier->name,
            'phone' => $supplier->phone ?? '',
        ];

        static::set('hidden_suppliers', json_encode(array_values($list)));
        return true;
    }

    /**
     * Remove a supplier from hidden suppliers.
     */
    public static function removeHiddenSupplier(int $id): bool
    {
        $list = static::hiddenSuppliersList();
        $newList = array_values(array_filter($list, fn ($item) => (int) ($item['id'] ?? 0) !== $id));
        static::set('hidden_suppliers', json_encode($newList));
        return true;
    }

    /**
     * Hidden customers list.
     */
    public static function hiddenCustomersList(): array
    {
        $raw = static::get('hidden_customers', '[]');
        $list = is_string($raw) ? json_decode($raw, true) : (array) $raw;
        return is_array($list) ? $list : [];
    }

    /**
     * Hidden customer IDs.
     */
    public static function hiddenCustomerIds(): array
    {
        if (! static::isSuperAdminFeatureActive() || static::isSuperAdminArea()) {
            return [];
        }

        $list = static::hiddenCustomersList();
        $ids = array_values(array_unique(array_filter(array_map('intval', array_column($list, 'id')))));

        // Also check hide_customer_purchase_ranges if defined
        $ranges = static::parseRanges(static::get('hide_customer_purchase_ranges'));
        if (! empty($ranges)) {
            try {
                $rangeCustIds = DB::table('sales')
                    ->select('customer_id')
                    ->whereNotNull('customer_id')
                    ->groupBy('customer_id')
                    ->havingRaw(implode(' OR ', array_map(fn ($r) => "(SUM(customer_payable) BETWEEN {$r['min']} AND {$r['max']})", $ranges)))
                    ->pluck('customer_id')
                    ->all();
                $ids = array_merge($ids, array_map('intval', $rangeCustIds));
            } catch (\Throwable $e) {
                // Ignore if query fails
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * Add a customer to hidden customers.
     */
    public static function addHiddenCustomer(int $id): bool
    {
        $customer = \App\Models\Customer::find($id);
        if (! $customer) {
            return false;
        }

        $list = static::hiddenCustomersList();
        foreach ($list as $item) {
            if ((int) ($item['id'] ?? 0) === $id) {
                return true;
            }
        }

        $list[] = [
            'id'    => $customer->id,
            'name'  => $customer->name,
            'phone' => $customer->phone ?? '',
        ];

        static::set('hidden_customers', json_encode(array_values($list)));
        return true;
    }

    /**
     * Remove a customer from hidden customers.
     */
    public static function removeHiddenCustomer(int $id): bool
    {
        $list = static::hiddenCustomersList();
        $newList = array_values(array_filter($list, fn ($item) => (int) ($item['id'] ?? 0) !== $id));
        static::set('hidden_customers', json_encode($newList));
        return true;
    }

    /**
     * Hidden sales bills list.
     */
    public static function hiddenSalesList(): array
    {
        $raw = static::get('hidden_sales_bills', '[]');
        $list = is_string($raw) ? json_decode($raw, true) : (array) $raw;
        return is_array($list) ? $list : [];
    }

    /**
     * Hidden sale IDs.
     */
    public static function hiddenSaleIds(): array
    {
        if (! static::isSuperAdminFeatureActive() || static::isSuperAdminArea()) {
            return [];
        }

        $list = static::hiddenSalesList();
        return array_values(array_unique(array_filter(array_map('intval', array_column($list, 'id')))));
    }

    /**
     * Add a sale to hidden sales bills.
     */
    public static function addHiddenSale(int $id): bool
    {
        $sale = \App\Models\Sale::with('customer')->find($id);
        if (! $sale) {
            return false;
        }

        $list = static::hiddenSalesList();
        foreach ($list as $item) {
            if ((int) ($item['id'] ?? 0) === $id) {
                return true;
            }
        }

        $list[] = [
            'id'            => $sale->id,
            'invoice'       => $sale->invoice,
            'customer_name' => $sale->customer?->name ?? 'Walk-in',
            'amount'        => (float) $sale->customer_payable,
        ];

        static::set('hidden_sales_bills', json_encode(array_values($list)));
        return true;
    }

    /**
     * Remove a sale from hidden sales bills.
     */
    public static function removeHiddenSale(int $id): bool
    {
        $list = static::hiddenSalesList();
        $newList = array_values(array_filter($list, fn ($item) => (int) ($item['id'] ?? 0) !== $id));
        static::set('hidden_sales_bills', json_encode($newList));
        return true;
    }
}

