<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Support\Str;

final class AuditPresentation
{
    private const LABELS = [
        'sku' => 'SKU', 'barcode' => 'Barcode', 'cost' => 'Cost price', 'price' => 'Selling price',
        'stock' => 'Stock quantity', 'low_stock' => 'Low stock alert', 'track_stock' => 'Maintain stock', 'track_expiry' => 'Track expiry', 'expires_at' => 'Expiry date', 'batch_number' => 'Batch number',
        'active' => 'Active', 'unit_id' => 'Unit', 'supplier_id' => 'Supplier',
        'category_id' => 'Category', 'expense_category_id' => 'Expense category', 'payment_method_id' => 'Payment method',
        'default_discount_type' => 'Default discount type', 'default_discount_value' => 'Default discount',
        'image' => 'Product image', 'logo' => 'Business logo', 'logo_secondary' => 'Second bill logo',
        'phone' => 'Telephone', 'mobile' => 'Mobile', 'whatsapp' => 'WhatsApp', 'registration_number' => 'Registration number',
        'id' => 'Record ID', 'created_at' => 'Created on', 'updated_at' => 'Updated on',
        'created_by' => 'Created by', 'updated_by' => 'Updated by',
    ];

    private const BOOLEAN_FIELDS = ['active', 'track_expiry', 'track_stock', 'allow_decimal', 'system', 'allow_discount', 'barcode_enabled', 'auto_print', 'show_receipt'];

    private const MONEY_FIELDS = ['cost', 'price', 'cost_price', 'selling_price', 'unit_price', 'amount', 'total', 'subtotal', 'discount', 'processing_charge', 'opening_cash', 'actual_cash', 'expected_cash', 'difference', 'amount_paid', 'sale_amount', 'customer_payable', 'due_balance', 'refund_amount', 'opening_due', 'opening_due_paid', 'base_cost', 'catalog_price', 'line_subtotal', 'line_discount', 'line_discounts', 'invoice_discount', 'stock_price', 'cogs_total', 'cost_total', 'remaining_cost_total', 'inventory_cost_total', 'business_expense', 'allocated_amount', 'remaining_amount', 'customer_processing_charge', 'change'];

    public static function compare(?array $before, ?array $after, array $settings = [], string $action = ''): array
    {
        [$before, $after] = self::snapshots($before ?? [], $after ?? [], $action);
        $old = self::flatten($before ?? []);
        $new = self::flatten($after ?? []);
        $fields = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $path) {
            $parts = json_decode($path, true);
            $key = end($parts);
            $hasBefore = array_key_exists($path, $old);
            $hasAfter = array_key_exists($path, $new);
            $changed = $hasBefore !== $hasAfter || self::canonical($key, $old[$path] ?? null) !== self::canonical($key, $new[$path] ?? null);
            $fields[] = [
                'label' => implode(' → ', array_map(fn ($part) => self::LABELS[$part] ?? Str::headline($part), $parts)),
                'before' => $hasBefore ? self::value($key, $old[$path], self::context($before, $parts), $settings) : 'Not recorded',
                'after' => $hasAfter ? self::value($key, $new[$path], self::context($after, $parts), $settings) : 'Not recorded',
                'changed' => $changed,
                'detail' => ! $changed || in_array($key, ['id', 'created_at', 'updated_at', 'created_by', 'updated_by']),
            ];
        }

        return ['fields' => $fields, 'before_empty' => ! $old, 'after_empty' => ! $new];
    }

    public static function formatRow(array $row, array $settings = []): array
    {
        [$before, $after] = self::snapshots(json_decode($row[4], true) ?? [], json_decode($row[5], true) ?? [], $row[2]);
        $row[4] = self::text($before, $settings);
        $row[5] = self::text($after, $settings);

        return $row;
    }

    private static function snapshots(array $before, array $after, string $action): array
    {
        if (str_starts_with($action, 'settings.')) {
            // Older settings audits store the original database JSON in each value.
            foreach ($before as $key => $value) {
                if (is_string($value)) {
                    $decoded = json_decode($value, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $before[$key] = $decoded;
                    }
                }
            }
            // Omitted file inputs keep their previous value when settings are saved.
            $after = array_replace($before, $after);
        }

        return [$before, $after];
    }

    public static function text(?array $snapshot, array $settings = []): string
    {
        if (! $snapshot) {
            return 'No values recorded';
        }
        $lines = [];
        foreach (self::flatten($snapshot) as $path => $value) {
            $parts = json_decode($path, true);
            $label = implode(' → ', array_map(fn ($part) => self::LABELS[$part] ?? Str::headline($part), $parts));
            $lines[] = $label.': '.self::value(end($parts), $value, self::context($snapshot, $parts), $settings);
        }

        return implode("\n", $lines);
    }

    private static function flatten(array $values, array $path = []): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (! Units::enabled() && in_array($key, ['unit', 'unit_id', 'primary_unit_id', 'unit_name', 'short_name', 'conversions', 'base_quantity', 'converted_quantity', 'allow_decimal', 'default_slot', 'show_unit', 'unit_font_size', 'unit_bold'])) {
                continue;
            }
            if (! config('features.cheque') && (str_starts_with((string) $key, 'cheque') || (is_array($value) && (($value['method_type'] ?? '') === 'CHEQUE' || ! empty($value['cheque_id']))))) {
                continue;
            }
            if (! config('features.barcode') && ($key === 'barcode' || str_starts_with((string) $key, 'barcode_'))) {
                continue;
            }
            if (! Expiry::enabled() && (in_array($key, ['track_expiry', 'expires_at', 'batch_number', 'expiry_source']) || str_starts_with((string) $key, 'expiry_'))) {
                continue;
            }
            $parts = [...$path, is_int($key) ? (string) ($key + 1) : $key];
            if (is_array($value) && $value && (! array_is_list($value) || collect($value)->contains(fn ($item) => is_array($item)))) {
                $result += self::flatten($value, $parts);
            } else {
                $result[json_encode($parts)] = $value;
            }
        }

        return $result;
    }

    private static function booleanField(string $key): bool
    {
        return in_array($key, self::BOOLEAN_FIELDS) || str_starts_with($key, 'show_') || str_starts_with($key, 'enable_');
    }

    private static function context(array $snapshot, array $parts): array
    {
        foreach (array_slice($parts, 0, -1) as $part) {
            $key = array_is_list($snapshot) && ctype_digit($part) ? (int) $part - 1 : $part;
            $snapshot = $snapshot[$key] ?? [];
        }

        return $snapshot;
    }

    private static function canonical(string $key, mixed $value): mixed
    {
        if ($value !== null && self::booleanField($key) && in_array($value, [true, false, 1, 0, '1', '0'], true)) {
            return (bool) $value;
        }
        if (is_array($value)) {
            $result = array_map(fn ($item) => self::canonical($key, $item), $value);
            if (in_array($key, ['categories', 'permissions'])) {
                sort($result);
            }

            return $result;
        }
        if (is_numeric($value) && (in_array($key, self::MONEY_FIELDS) || in_array($key, ['stock', 'low_stock', 'quantity', 'base_quantity', 'converted_quantity', 'default_discount_value', 'charge_value']) || str_ends_with($key, '_id') || $key === 'categories' || $key === 'permissions')) {
            return (string) BigDecimal::of((string) $value)->stripTrailingZeros();
        }

        return $value;
    }

    private static function value(string $key, mixed $value, array $snapshot, array $settings): string
    {
        if ($value === null || $value === '') {
            return 'Not set';
        }
        if (is_bool($value) || (self::booleanField($key) && in_array($value, [1, 0, '1', '0'], true))) {
            return $value ? 'Yes' : 'No';
        }
        if (is_array($value)) {
            return $value ? implode(', ', array_map(fn ($item) => self::value($key, $item, $snapshot, $settings), $value)) : 'None';
        }
        if (is_numeric($value)) {
            $discountType = $snapshot[$key === 'default_discount_value' ? 'default_discount_type' : 'discount_type'] ?? '';
            if (in_array($key, ['default_discount_value', 'discount_value']) && $discountType === 'PERCENT') {
                return rtrim(rtrim(number_format((float) $value, 2, '.', ','), '0'), '.').'%';
            }
            if (in_array($key, self::MONEY_FIELDS) || (in_array($key, ['default_discount_value', 'discount_value']) && $discountType === 'AMOUNT')) {
                return ($settings['currency_symbol'] ?? 'Rs.').' '.number_format((float) $value, 2);
            }
            if (in_array($key, ['stock', 'low_stock', 'quantity', 'base_quantity', 'converted_quantity'])) {
                return rtrim(rtrim(number_format((float) $value, 6, '.', ','), '0'), '.');
            }
            if (str_ends_with($key, '_id') || in_array($key, ['id', 'created_by', 'updated_by', 'categories', 'permissions'])) {
                return '#'.$value;
            }
        }
        if (str_ends_with($key, '_at') || str_ends_with($key, '_date')) {
            try {
                $timezone = $settings['timezone'] ?? config('app.timezone', 'UTC');

                return Carbon::parse($value, $timezone)->setTimezone($timezone)->format(($settings['date_format'] ?? 'd/m/Y').(str_ends_with($key, '_at') ? ' h:i A' : ''));
            } catch (\Throwable) {
                return (string) $value;
            }
        }
        if (in_array($key, ['default_discount_type', 'discount_type'])) {
            return ['NONE' => 'None', 'AMOUNT' => 'Fixed amount', 'PERCENT' => 'Percentage'][$value] ?? (string) $value;
        }
        if (in_array($key, ['status', 'type', 'charge_type', 'charge_bearer', 'sales_visibility']) && preg_match('/^[A-Z][A-Z_]*$/', (string) $value)) {
            return Str::headline(strtolower($value));
        }

        return (string) $value;
    }
}
