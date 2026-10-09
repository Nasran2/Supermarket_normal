<?php

namespace App\Support;

use App\Models\User;
use App\Services\ReportService;

class Sidebar
{
    public static function sections(User $user): array
    {
        $link = fn ($label, $route, $parameters, $permissions, $active = false) => ['label' => $label, 'url' => route($route, $parameters), 'permissions' => (array) $permissions, 'active' => $active];
        $group = function ($label, $icon, $children) use ($user) {
            $children = array_values(array_filter($children, fn ($c) => collect($c['permissions'])->every(fn ($p) => $user->hasPermission($p))));

            return ['label' => $label, 'icon' => $icon, 'children' => $children, 'active' => collect($children)->contains('active', true)];
        };
        $resource = function ($key, $label, $icon, $singular) use ($link, $group) {
            $active = request()->route('resource') === $key;

            return $group($label, $icon, [
                $link('All '.strtolower($label), 'manage.index', $key, Resources::permission($key, 'view'), $active && ! request()->routeIs('manage.create')),
                $link('Add '.$singular, 'manage.create', $key, Resources::permission($key, 'create'), $active && request()->routeIs('manage.create')),
            ]);
        };
        $workspace = [
            $group('Sales', 'receipt-text', [$link('All sales', 'sales.index', [], 'sales.view', request()->is('sales*')), $link('New sale', 'pos.index', [], ['pos.access', 'sales.create']), $link('Sales report', 'reports.show', 'sales', 'reports.sales')]),
            $group('Purchases', 'package-open', [$link('All purchases', 'purchases.index', [], 'purchases.view', request()->is('purchases*') && ! request()->routeIs('purchases.create')), $link('Add purchase', 'purchases.create', [], 'purchases.create', request()->routeIs('purchases.create')), $link('Purchase report', 'reports.show', 'purchases', 'reports.purchases')]),
            $group('Daily register', 'wallet', [$link('Register & history', 'register.index', [], 'register.view', request()->is('register*')), $link('Register report', 'reports.show', 'register', 'reports.register'), $link('Cash summary', 'reports.show', 'cash', 'reports.cash')]),
        ];
        $products = $resource('products', 'Products', 'package', 'product');
        foreach ([$link('Stock adjustments', 'adjustments.index', [], 'stock-adjustments.view', request()->is('stock-adjustments*') && ! request()->routeIs('adjustments.create')), $link('New adjustment', 'adjustments.create', [], 'stock-adjustments.create', request()->routeIs('adjustments.create')), $link('Low stock', 'manage.index', ['resource' => 'products', 'low_stock' => 1], 'products.view')] as $child) {
            if ($user->hasPermission($child['permissions'][0])) {
                $products['children'][] = $child;
            }
        }
        $products['active'] = collect($products['children'])->contains('active', true);
        $expenses = $resource('expenses', 'Expenses', 'circle-dollar-sign', 'expense');
        foreach ([$link('Expense categories', 'manage.index', 'expense-categories', 'expense-categories.view', request()->route('resource') === 'expense-categories'), $link('Add expense category', 'manage.create', 'expense-categories', 'expense-categories.create')] as $child) {
            if ($user->hasPermission($child['permissions'][0])) {
                $expenses['children'][] = $child;
            }
        }
        $expenses['active'] = collect($expenses['children'])->contains('active', true);
        $inventory = [$products, $resource('categories', 'Categories', 'tags', 'category'), $resource('units', 'Units', 'ruler', 'unit'), $resource('unit-presets', 'Multiple units', 'boxes', 'unit preset'), $resource('suppliers', 'Suppliers', 'truck', 'supplier'), $resource('customers', 'Customers', 'contact', 'customer'), $expenses];
        $reports = [$link('All reports', 'reports.index', [], [], request()->routeIs('reports.index'))];
        foreach (ReportService::TITLES as $key => $title) {
            $reports[] = $link($title, 'reports.show', $key, ReportService::permission($key), request()->route('report') === $key);
        }
        if (! collect(array_keys(ReportService::TITLES))->contains(fn ($kind) => $user->hasPermission(ReportService::permission($kind)))) {
            $reports = [];
        }
        $settings = [$link('Overview', 'settings.index', [], 'settings.view', request()->routeIs('settings.index'))];
        foreach (['business' => ['Business', 'settings.business'], 'pos' => ['Point of sale', 'settings.pos'], 'receipt' => ['Receipt', 'settings.receipt'], 'stock' => ['Stock controls', 'settings.stock'], 'system' => ['System', 'settings.system']] as $key => [$title, $permission]) {
            $settings[] = $link($title, 'settings.edit', $key, $permission, request()->route('group') === $key);
        }
        foreach (['payment-methods' => ['Payment methods', 'payment method']] as $key => [$title, $singular]) {
            $settings[] = $link($title, 'manage.index', $key, Resources::permission($key, 'view'), request()->route('resource') === $key && ! request()->routeIs('manage.create'));
            $settings[] = $link('Add '.$singular, 'manage.create', $key, Resources::permission($key, 'create'), request()->route('resource') === $key && request()->routeIs('manage.create'));
        }

        return ['WORKSPACE' => $workspace, 'INVENTORY' => $inventory, 'MANAGEMENT' => [$group('Reports', 'chart-no-axes-combined', $reports), $resource('users', 'Users', 'users', 'user'), $resource('roles', 'Roles & permissions', 'shield-check', 'role'), $group('Settings', 'settings-2', $settings)]];
    }
}
