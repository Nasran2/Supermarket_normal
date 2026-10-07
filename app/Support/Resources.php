<?php

namespace App\Support;

use App\Models;

class Resources
{
    public static function all(): array
    {
        $contact = ['name' => ['Name', 'text'], 'phone' => ['Phone', 'text', false], 'email' => ['Email', 'email', false], 'address' => ['Address', 'textarea', false], 'active' => ['Active', 'checkbox']];

        return [
            'products' => ['title' => 'Products', 'model' => Models\Product::class, 'permission' => 'products', 'search' => ['name', 'sku', 'barcode'], 'relations' => ['category', 'unit'], 'columns' => ['name' => 'Product', 'sku' => 'SKU', 'barcode' => 'Barcode', 'category.name' => 'Category', 'unit.short_name' => 'Unit', 'price' => 'Price', 'stock' => 'Stock', 'active' => 'Active'], 'fields' => ['name' => ['Product name', 'text'], 'sku' => ['SKU', 'text'], 'barcode' => ['Barcode', 'text', false], 'category_id' => ['Category', 'select', true, Models\Category::class], 'unit_id' => ['Unit', 'select', true, Models\Unit::class], 'cost' => ['Cost', 'money'], 'price' => ['Selling price', 'money'], 'stock' => ['Opening stock', 'quantity'], 'low_stock' => ['Low stock alert', 'quantity'], 'active' => ['Active', 'checkbox'], 'image' => ['Product image', 'file', false]]],
            'categories' => ['title' => 'Categories', 'model' => Models\Category::class, 'permission' => 'products', 'search' => ['name'], 'columns' => ['name' => 'Name'], 'fields' => ['name' => ['Category name', 'text']]],
            'units' => ['title' => 'Units', 'model' => Models\Unit::class, 'permission' => 'units', 'search' => ['name', 'short_name'], 'columns' => ['name' => 'Name', 'short_name' => 'Short name', 'allow_decimal' => 'Decimal quantity', 'active' => 'Active'], 'fields' => ['name' => ['Unit name', 'text'], 'short_name' => ['Short name', 'text'], 'allow_decimal' => ['Allow decimal quantities', 'checkbox'], 'active' => ['Active', 'checkbox']]],
            'suppliers' => ['title' => 'Suppliers', 'model' => Models\Supplier::class, 'permission' => 'purchases', 'search' => ['name', 'phone'], 'columns' => ['name' => 'Name', 'phone' => 'Phone', 'email' => 'Email', 'active' => 'Active'], 'fields' => $contact],
            'customers' => ['title' => 'Customers', 'model' => Models\Customer::class, 'permission' => 'sales', 'search' => ['name', 'phone'], 'columns' => ['name' => 'Name', 'phone' => 'Phone', 'email' => 'Email', 'active' => 'Active'], 'fields' => $contact],
            'expense-categories' => ['title' => 'Expense categories', 'model' => Models\ExpenseCategory::class, 'permission' => 'expenses', 'search' => ['name'], 'columns' => ['name' => 'Name', 'system' => 'Protected'], 'fields' => ['name' => ['Category name', 'text']]],
            'expenses' => ['title' => 'Expenses', 'model' => Models\Expense::class, 'permission' => 'expenses', 'search' => ['reference', 'description'], 'relations' => ['category', 'user', 'method'], 'columns' => ['expense_date' => 'Date', 'reference' => 'Reference', 'category.name' => 'Category', 'description' => 'Description', 'amount' => 'Amount', 'type' => 'Type', 'status' => 'Status', 'user.name' => 'Recorded by'], 'fields' => ['expense_category_id' => ['Category', 'select', true, Models\ExpenseCategory::class], 'expense_date' => ['Date', 'date'], 'payment_method_id' => ['Paid using', 'select', true, Models\PaymentMethod::class], 'reference' => ['Reference', 'text', false], 'description' => ['Description', 'textarea'], 'amount' => ['Amount', 'money']]],
            'payment-methods' => ['title' => 'Payment methods', 'model' => Models\PaymentMethod::class, 'permission' => 'settings.payment_methods', 'search' => ['name', 'code'], 'columns' => ['name' => 'Name', 'code' => 'Code', 'type' => 'Type', 'charge_bearer' => 'Default charge bearer', 'active' => 'Active', 'display_order' => 'Order'], 'fields' => ['name' => ['Payment method name', 'text'], 'code' => ['Unique code', 'text'], 'type' => ['Payment type', 'options', true, ['CASH' => 'Cash', 'CARD' => 'Card', 'QR' => 'QR', 'BANK_TRANSFER' => 'Bank transfer', 'CUSTOM' => 'Custom']], 'charge_bearer' => ['Default charge bearer', 'options', true, ['CUSTOMER' => 'Customer', 'BUSINESS' => 'Business']], 'display_order' => ['Display order', 'number'], 'active' => ['Active', 'checkbox']]],
            'payment-rules' => ['title' => 'Payment charge rules', 'model' => Models\PaymentChargeRule::class, 'permission' => 'settings.payment_rules', 'search' => ['name'], 'relations' => ['method'], 'columns' => ['name' => 'Rule', 'method.name' => 'Payment method', 'minimum_amount' => 'Minimum', 'comparison_operator' => 'Minimum condition', 'maximum_amount' => 'Maximum (inclusive)', 'charge_type' => 'Charge type', 'charge_value' => 'Value', 'charge_bearer' => 'Paid by', 'priority' => 'Priority', 'active' => 'Active'], 'fields' => ['payment_method_id' => ['Payment method', 'select', true, Models\PaymentMethod::class], 'name' => ['Rule name', 'text'], 'minimum_amount' => ['Minimum bill amount', 'money'], 'comparison_operator' => ['Minimum condition', 'options', true, ['GTE' => 'Greater than or equal to', 'GT' => 'Greater than']], 'maximum_amount' => ['Maximum bill amount (optional)', 'money', false], 'charge_type' => ['Charge type', 'options', true, ['PERCENTAGE' => 'Percentage', 'FIXED' => 'Fixed amount']], 'charge_value' => ['Charge value', 'decimal'], 'charge_bearer' => ['Charge bearer', 'options', true, ['DEFAULT' => 'Use payment method default', 'CUSTOMER' => 'Customer', 'BUSINESS' => 'Business']], 'priority' => ['Priority (higher wins)', 'number'], 'active' => ['Active', 'checkbox']]],
            'users' => ['title' => 'Users', 'model' => Models\User::class, 'permission' => 'users', 'search' => ['name', 'email'], 'relations' => ['role'], 'columns' => ['name' => 'Name', 'email' => 'Email', 'role.name' => 'Role', 'active' => 'Active'], 'fields' => ['name' => ['Name', 'text'], 'email' => ['Email', 'email'], 'role_id' => ['Role', 'select', true, Models\Role::class], 'password' => ['Password (leave blank to keep)', 'password', false], 'active' => ['Active', 'checkbox']]],
            'roles' => ['title' => 'Roles & permissions', 'model' => Models\Role::class, 'permission' => 'roles', 'search' => ['name'], 'relations' => ['permissions'], 'columns' => ['name' => 'Role', 'system' => 'System role'], 'fields' => ['name' => ['Role name', 'text'], 'permissions' => ['Permissions', 'permissions']]],
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

        return str_starts_with($p, 'settings.') ? $p : $p.'.'.$action;
    }
}
