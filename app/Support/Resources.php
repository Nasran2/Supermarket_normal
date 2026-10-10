<?php

namespace App\Support;

use App\Models;

class Resources
{
    public static function all(): array
    {
        $contact = ['name' => ['Name', 'text'], 'phone' => ['Phone', 'text', false], 'email' => ['Email', 'email', false], 'address' => ['Address', 'textarea', false], 'active' => ['Active', 'checkbox']];

        return [
            'products' => ['title' => 'Products', 'model' => Models\Product::class, 'permission' => 'products', 'search' => ['name', 'sku', 'barcode'], 'relations' => ['categories', 'unit', 'conversions.unit', 'supplier'], 'columns' => ['name' => 'Product', 'sku' => 'SKU', 'supplier.name' => 'Supplier', 'barcode' => 'Barcode', 'categories_list' => 'Categories', 'unit.short_name' => 'Unit', 'price' => 'Price', 'stock' => 'Stock', 'active' => 'Active'], 'fields' => ['name' => ['Product name', 'text'], 'sku' => ['SKU', 'text'], 'barcode' => ['Barcode', 'text', false], 'categories' => ['Categories', 'multiselect', false, Models\Category::class], 'supplier_id' => ['Supplier', 'select', false, Models\Supplier::class], 'unit_id' => ['Unit', 'select', true, Models\Unit::class], 'cost' => ['Cost', 'money'], 'price' => ['Selling price', 'money'], 'stock' => ['Opening stock', 'quantity'], 'low_stock' => ['Low stock alert', 'quantity'], 'active' => ['Active', 'checkbox'], 'image' => ['Product image', 'file', false]]],
            'categories' => ['title' => 'Categories', 'model' => Models\Category::class, 'permission' => 'categories', 'search' => ['name'], 'columns' => ['name' => 'Name'], 'fields' => ['name' => ['Category name', 'text']]],
            'units' => ['title' => 'Units', 'model' => Models\Unit::class, 'permission' => 'units', 'search' => ['name', 'short_name'], 'columns' => ['name' => 'Name', 'short_name' => 'Short name', 'allow_decimal' => 'Decimal quantity', 'active' => 'Active'], 'fields' => ['name' => ['Unit name', 'text'], 'short_name' => ['Short name', 'text'], 'allow_decimal' => ['Allow decimal quantities', 'checkbox'], 'active' => ['Active', 'checkbox']]],
            'unit-presets' => ['title' => 'Multiple units', 'model' => Models\UnitPreset::class, 'permission' => 'unit-presets', 'search' => ['name'], 'relations' => ['unit', 'conversions.unit'], 'columns' => ['name' => 'Preset', 'unit.name' => 'Primary unit'], 'fields' => ['name' => ['Preset name', 'text'], 'unit_id' => ['Primary stock unit', 'select', true, Models\Unit::class]]],
            'suppliers' => ['title' => 'Suppliers', 'model' => Models\Supplier::class, 'permission' => 'suppliers', 'search' => ['name', 'phone'], 'columns' => ['name' => 'Name', 'phone' => 'Phone', 'email' => 'Email', 'active' => 'Active'], 'fields' => $contact],
            'customers' => ['title' => 'Customers', 'model' => Models\Customer::class, 'permission' => 'customers', 'search' => ['name', 'phone', 'email'], 'columns' => ['name' => 'Name', 'phone' => 'Phone', 'email' => 'Email', 'opening_due' => 'Opening due', 'active' => 'Active'], 'fields' => $contact + ['opening_due' => ['Old balance (due)', 'money', false]]],
            'expense-categories' => ['title' => 'Expense categories', 'model' => Models\ExpenseCategory::class, 'permission' => 'expense-categories', 'search' => ['name'], 'columns' => ['name' => 'Name', 'system' => 'Protected'], 'fields' => ['name' => ['Category name', 'text']]],
            'expenses' => ['title' => 'Expenses', 'model' => Models\Expense::class, 'permission' => 'expenses', 'search' => ['reference', 'description'], 'relations' => ['category', 'user', 'method'], 'columns' => ['expense_date' => 'Date', 'reference' => 'Reference', 'category.name' => 'Category', 'description' => 'Description', 'amount' => 'Amount', 'type' => 'Type', 'status' => 'Status', 'user.name' => 'Recorded by'], 'fields' => ['expense_category_id' => ['Category', 'select', true, Models\ExpenseCategory::class], 'expense_date' => ['Date', 'date'], 'payment_method_id' => ['Paid using', 'select', true, Models\PaymentMethod::class], 'reference' => ['Reference', 'text', false], 'description' => ['Description', 'textarea'], 'amount' => ['Amount', 'money']]],
            'payment-methods' => ['title' => 'Payment methods', 'model' => Models\PaymentMethod::class, 'permission' => 'payment-methods', 'search' => ['name', 'code'], 'columns' => ['name' => 'Name', 'code' => 'Code', 'type' => 'Type', 'has_charge' => 'Has charge', 'active' => 'Active', 'display_order' => 'Order'], 'fields' => ['name' => ['Payment method name', 'text'], 'code' => ['Unique code', 'text'], 'type' => ['Payment type', 'options', true, ['CASH' => 'Cash', 'CARD' => 'Card', 'QR' => 'QR', 'BANK_TRANSFER' => 'Bank transfer', 'CUSTOM' => 'Custom']], 'has_charge' => ['Apply a charge/fee for this method', 'checkbox'], 'charge_minimum_amount' => ['Minimum bill amount', 'money', false], 'charge_type' => ['Charge type', 'options', false, ['PERCENTAGE' => 'Percentage', 'FIXED' => 'Fixed amount']], 'charge_value' => ['Charge value', 'decimal', false], 'charge_bearer' => ['Default charge bearer', 'options', true, ['BUSINESS' => 'Business', 'CUSTOMER' => 'Customer']], 'display_order' => ['Display order', 'number'], 'active' => ['Active', 'checkbox']]],
            'users' => ['title' => 'Users', 'model' => Models\User::class, 'permission' => 'users', 'search' => ['name', 'username', 'email'], 'relations' => ['role'], 'columns' => ['name' => 'Name', 'username' => 'Username', 'email' => 'Email', 'role.name' => 'Role', 'active' => 'Active'], 'fields' => ['name' => ['Name', 'text'], 'username' => ['Username', 'text'], 'email' => ['Email', 'email'], 'role_id' => ['Role', 'select', true, Models\Role::class], 'password' => ['Password (leave blank to keep)', 'password', false], 'active' => ['Active', 'checkbox']]],
            'roles' => ['title' => 'Roles & permissions', 'model' => Models\Role::class, 'permission' => 'roles', 'search' => ['name'], 'relations' => ['permissions'], 'columns' => ['name' => 'Role', 'system' => 'System role'], 'fields' => ['name' => ['Role name', 'text'], 'sales_visibility' => ['Sales visibility', 'sales_visibility', false], 'permissions' => ['Permissions', 'permissions']]],
        ];
    }

    public static function get(string $resource): array
    {
        $all = self::all();
        abort_unless(isset($all[$resource]), 404);

        return $all[$resource];
    }

    public static function permission(string $resource, string $action): string
    {
        $p = self::get($resource)['permission'];

        return $p.'.'.$action;
    }
}
