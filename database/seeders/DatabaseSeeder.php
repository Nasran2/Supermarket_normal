<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Unit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $names = ['dashboard.view', 'pos.access', 'sales.void', 'register.open', 'register.close', 'register.view', 'settings.view', 'settings.business', 'settings.pos', 'settings.receipt', 'settings.payment_methods', 'settings.payment_rules'];
        foreach (['sales', 'products', 'units', 'purchases', 'expenses', 'users', 'roles'] as $module) {
            foreach (['view', 'create', 'edit', 'delete'] as $action) {
                $names[] = $module.'.'.$action;
            }
        }
        foreach (['sales', 'purchases', 'expenses', 'profit', 'stock', 'product-sales', 'payments', 'register', 'cash', 'payment-charges', 'card-charges', 'qr-charges', 'bank-charges', 'audit'] as $report) {
            $names[] = 'reports.'.$report;
        }
        foreach ($names as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }
        foreach (['Administrator', 'Manager', 'Cashier'] as $name) {
            $role = Role::firstOrCreate(['name' => $name], ['system' => true]);
            if ($role->wasRecentlyCreated) {
                $allowed = match ($name) {
                    'Administrator' => $names,'Manager' => array_values(array_filter($names, fn ($n) => ! str_starts_with($n, 'users.') && ! str_starts_with($n, 'roles.'))),default => ['dashboard.view', 'pos.access', 'sales.view', 'sales.create', 'register.view', 'register.open', 'register.close', 'products.view']
                };
                $role->permissions()->sync(Permission::whereIn('name', $allowed)->pluck('id'));
            }
        }
        Role::where('name', 'Administrator')->firstOrFail()->permissions()->syncWithoutDetaching(Permission::whereIn('name', $names)->pluck('id'));
        foreach ([['Piece', 'pcs', false], ['Kilogram', 'kg', true], ['Gram', 'g', true], ['Liter', 'L', true], ['Milliliter', 'ml', true], ['Pack', 'pack', false], ['Box', 'box', false], ['Bottle', 'bottle', false], ['Dozen', 'doz', false], ['Meter', 'm', true]] as [$name,$short,$decimal]) {
            if (! Unit::where('short_name', $short)->orWhere('name', $name)->exists()) {
                Unit::create(['short_name' => $short, 'name' => $name, 'allow_decimal' => $decimal, 'active' => true]);
            }
        }
        foreach ([['Cash', 'CASH'], ['Card', 'CARD'], ['QR', 'QR'], ['Bank Transfer', 'BANK_TRANSFER']] as $i => [$name,$type]) {
            if (! PaymentMethod::where('code', $type)->orWhere('name', $name)->orWhere('type', $type)->exists()) {
                PaymentMethod::create(['code' => $type, 'name' => $name, 'type' => $type, 'active' => true, 'display_order' => $i]);
            }
        }
        ExpenseCategory::firstOrCreate(['name' => 'Payment Processing Charges'], ['system' => true]);
        ExpenseCategory::firstOrCreate(['name' => 'General']);
        if (! Category::exists()) {
            Category::create(['name' => 'General']);
        }
        foreach (config('pos') as $group => $fields) {
            foreach ($fields as $key => $field) {
                Setting::firstOrCreate(['key' => $key], ['group' => $group, 'value' => json_encode($key === 'default_payment_method' ? PaymentMethod::where('type', 'CASH')->value('id') : $field[2])]);
            }
        }
        Cache::forget('business_settings');
    }
}
