<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Unit;
use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Permissions::install();
        $names = array_keys(Permissions::all());
        foreach (['Administrator', 'Manager', 'Cashier'] as $name) {
            $role = Role::firstOrCreate(['name' => $name], ['system' => true, 'sales_visibility' => $name === 'Cashier' ? 'OWN' : 'ALL']);
            if ($role->wasRecentlyCreated) {
                $allowed = match ($name) {
                    'Administrator' => $names,'Manager' => array_values(array_filter($names, fn ($n) => ! str_starts_with($n, 'users.') && ! str_starts_with($n, 'roles.'))),default => ['dashboard.view', 'pos.access', 'sales.view', 'sales.create', 'register.view', 'register.open', 'register.close', 'products.view', 'customers.create', 'sales.receipt', 'pos.discount', 'pos.override_price', 'pos.split_payment', 'pos.due_sale', 'dashboard.sales', 'dashboard.expenses', 'dashboard.transactions', 'dashboard.collections', 'dashboard.sales_overview', 'dashboard.register', 'dashboard.recent_sales', 'dashboard.low_stock', 'dashboard.top_products', 'dashboard.recent_expenses', 'products.view_history', 'stock-adjustments.view']
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
        if (! Unit::where('default_slot', 1)->exists()) {
            Unit::where('short_name', 'pcs')->update(['default_slot' => 1]);
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
