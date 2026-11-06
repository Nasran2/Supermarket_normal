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
     * Whether the current request is inside the Super Admin panel.
     */
    public static function isSuperAdminArea(): bool
    {
        try {
            return request()->is('superadmin*');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Multiplier factor for prices, costs, and profits.
     * When viewing inside the Super Admin panel (/superadmin*), ALWAYS 1.0 (100% real numbers).
     * For ALL normal app pages (even when logged in as admin), scales by (visible_profit_percentage / 100).
     */
    public static function multiplier(): float
    {
        // Inside Super Admin area, always show 100% real numbers
        if (static::isSuperAdminArea()) {
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
        return (float) static::get('visible_profit_percentage', 100);
    }

    /**
     * Check if a feature/card/widget is hidden from normal users.
     * Always returns false inside Super Admin area.
     */
    public static function isHidden(string $key): bool
    {
        if (static::isSuperAdminArea()) {
            return false;
        }

        return (bool) static::get($key, 0);
    }

    /**
     * Check if a feature is enabled.
     */
    public static function isEnabled(string $key): bool
    {
        return (bool) static::get($key, 0);
    }

    /**
     * Scale a selling price by the price multiplier.
     */
    public static function scalePrice(float|int|string|null $price): float
    {
        $val = (float) ($price ?? 0);
        return round($val * static::priceMultiplier(), 2);
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
     * Always 1.0 in Super Admin area.
     */
    public static function stockMultiplier(): float
    {
        if (static::isSuperAdminArea()) {
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
        return (float) static::get('visible_stock_percentage', 100);
    }

    /**
     * Multiplier factor for selling prices.
     * Always 1.0 in Super Admin area.
     */
    public static function priceMultiplier(): float
    {
        if (static::isSuperAdminArea()) {
            return 1.0;
        }

        $pct = (float) static::get('visible_price_percentage', static::get('visible_profit_percentage', 100));
        if ($pct <= 0) {
            return 0.0;
        }

        return min(100.0, $pct) / 100.0;
    }

    /**
     * Raw visible price percentage.
     */
    public static function pricePercentage(): float
    {
        return (float) static::get('visible_price_percentage', static::get('visible_profit_percentage', 100));
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
     * When inside Super Admin (/superadmin*), returns empty array (unrestricted).
     */
    public static function hiddenProductIds(): array
    {
        if (static::isSuperAdminArea()) {
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
        if (static::isSuperAdminArea()) {
            return false;
        }

        if (static::isHidden('hide_all_price_data') || static::isHidden('hide_exact_purchase_cost') || static::isHidden('hide_stock_money')) {
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
        if (static::isSuperAdminArea()) {
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

        if (static::isSuperAdminArea()) {
            return $name;
        }

        if (static::isHidden('hide_product_level_data')) {
            $id = is_object($product) ? ($product->id ?? $product->sku ?? 'item') : 'item';
            return 'Item #' . $id;
        }

        return $name;
    }
}

