<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\RegisterService;
use App\Services\ResourceService;
use App\Services\SettingsService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite' || ! str_contains(config('database.connections.sqlite.database'), 'twinsofte-tax-browser')) {
    throw new RuntimeException('Use the isolated twinsofte-tax-browser SQLite fixture.');
}
Artisan::call('migrate', ['--seed' => true, '--force' => true]);
$admin = User::firstOrCreate(['username' => 'tax-browser'], ['name' => 'Tax Browser', 'email' => 'tax-browser@example.test', 'password' => 'password', 'role_id' => Role::where('name', 'Administrator')->value('id'), 'active' => true]);
auth()->login($admin);
if (! app(RegisterService::class)->current($admin->id)) {
    app(RegisterService::class)->open($admin->id, '0');
}
if (! Product::where('sku', 'TAX-BROWSER')->exists()) {
    app(ResourceService::class)->save('products', ['name' => 'Tax Browser Grocery', 'sku' => 'TAX-BROWSER', 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'cost' => '40', 'price' => '118', 'stock' => 100, 'low_stock' => 0, 'active' => true]);
}
Customer::updateOrCreate(['name' => 'Incomplete Tax Customer'], ['phone' => '0771234567', 'tax_number' => null, 'address' => null, 'active' => true]);
Customer::firstOrCreate(['name' => 'Complete Tax Customer'], ['phone' => '0771234568', 'tax_number' => '987654321', 'address' => '20 Customer Lane', 'active' => true]);
app(SettingsService::class)->put('business', ['business_name' => 'Tax Browser Store', 'tax_number' => '123456789', 'address' => '10 Store Street', 'phone' => '0112345678']);
app(SettingsService::class)->put('pos', ['show_receipt' => false, 'auto_print' => true]);
app(SettingsService::class)->put('customer_tax', ['vat_rate' => '18', 'vat_price_mode' => 'INCLUSIVE']);
echo "Isolated tax browser fixtures ready.\n";

if (getenv('TAX_BROWSER_MISSING_SUPPLIER') === 'true') {
    app(SettingsService::class)->put('business', ['tax_number' => '', 'address' => '', 'phone' => '']);
}
