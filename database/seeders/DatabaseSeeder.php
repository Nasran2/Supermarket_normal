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

        // 1. Roles & Permissions
        foreach (['Administrator', 'Manager', 'Cashier'] as $name) {
            $role = Role::firstOrCreate(['name' => $name], ['system' => true, 'sales_visibility' => $name === 'Cashier' ? 'OWN' : 'ALL']);
            if ($role->wasRecentlyCreated) {
                $allowed = match ($name) {
                    'Administrator' => $names,
                    'Manager' => array_values(array_filter($names, fn ($n) => ! str_starts_with($n, 'users.') && ! str_starts_with($n, 'roles.'))),
                    default => ['dashboard.view', 'pos.access', 'sales.create', 'register.view', 'register.open', 'register.close', 'customers.create', 'sales.receipt', 'pos.discount', 'pos.override_price', 'pos.split_payment', 'pos.due_sale', 'dashboard.register']
                };
                $role->permissions()->sync(Permission::whereIn('name', $allowed)->pluck('id'));
            }
        }
        Role::where('name', 'Administrator')->firstOrFail()->permissions()->syncWithoutDetaching(Permission::whereIn('name', $names)->pluck('id'));

        // 2. Admin User
        if (! \App\Models\User::exists()) {
            \App\Models\User::create([
                'name' => 'Admin User',
                'username' => 'admin',
                'email' => 'admin@example.com',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'role_id' => Role::where('name', 'Administrator')->value('id'),
                'active' => true,
            ]);
        }

        // 3. Units
        foreach ([['Piece', 'pcs', false], ['Kilogram', 'kg', true], ['Gram', 'g', true], ['Liter', 'L', true], ['Milliliter', 'ml', true], ['Pack', 'pack', false], ['Box', 'box', false], ['Bottle', 'bottle', false], ['Dozen', 'doz', false], ['Meter', 'm', true], ['Carton', 'ctn', false]] as [$name,$short,$decimal]) {
            if (! Unit::where('short_name', $short)->orWhere('name', $name)->exists()) {
                Unit::create(['short_name' => $short, 'name' => $name, 'allow_decimal' => $decimal, 'active' => true]);
            }
        }
        if (! Unit::where('default_slot', 1)->exists()) {
            Unit::where('short_name', 'pcs')->update(['default_slot' => 1]);
        }

        // 4. Multiple Units (Unit Presets)
        $pcs = Unit::where('short_name', 'pcs')->first();
        $box = Unit::where('short_name', 'box')->first();
        $ctn = Unit::where('short_name', 'ctn')->first();
        $pack = Unit::where('short_name', 'pack')->first();
        $doz = Unit::where('short_name', 'doz')->first();

        if ($pcs && ! \App\Models\UnitPreset::exists()) {
            $presets = [
                ['Box of 12', $box, 12],
                ['Carton of 24', $ctn, 24],
                ['Pack of 6', $pack, 6],
                ['Dozen', $doz, 12],
            ];
            foreach ($presets as [$pName, $uModel, $qty]) {
                if ($uModel) {
                    $preset = \App\Models\UnitPreset::create(['name' => $pName, 'unit_id' => $pcs->id]);
                    $preset->conversions()->create([
                        'unit_id' => $uModel->id,
                        'base_quantity' => $qty,
                        'converted_quantity' => 1
                    ]);
                }
            }
        }

        // 5. Payment Methods
        foreach ([['Cash', 'CASH'], ['Card', 'CARD'], ['QR', 'QR'], ['Bank Transfer', 'BANK_TRANSFER']] as $i => [$name,$type]) {
            if (! PaymentMethod::where('code', $type)->orWhere('name', $name)->orWhere('type', $type)->exists()) {
                PaymentMethod::create(['code' => $type, 'name' => $name, 'type' => $type, 'active' => true, 'display_order' => $i]);
            }
        }

        // 6. Expense Categories
        ExpenseCategory::firstOrCreate(['name' => 'Payment Processing Charges'], ['system' => true]);
        $expenseCats = ['General', 'Utility Bills', 'Rent', 'Salaries & Wages', 'Inventory Transport', 'Maintenance & Repairs', 'Marketing & Promotions', 'Office Supplies', 'Taxes & Licenses'];
        foreach ($expenseCats as $ec) {
            ExpenseCategory::firstOrCreate(['name' => $ec]);
        }

        // 7. Product Categories
        $categories = ['General', 'Groceries', 'Beverages', 'Snacks', 'Fresh Produce', 'Dairy & Eggs', 'Meat & Seafood', 'Bakery', 'Frozen Foods', 'Household & Cleaning', 'Personal Care', 'Baby Care', 'Pet Care'];
        foreach ($categories as $cat) {
            Category::firstOrCreate(['name' => $cat]);
        }

        // 8. Settings
        foreach (config('pos') as $group => $fields) {
            foreach ($fields as $key => $field) {
                Setting::firstOrCreate(['key' => $key], ['group' => $group, 'value' => json_encode($key === 'default_payment_method' ? PaymentMethod::where('type', 'CASH')->value('id') : $field[2])]);
            }
        }
        Cache::forget('business_settings');
    }
}
