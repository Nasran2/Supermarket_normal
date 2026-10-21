<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use App\Services\RegisterService;
use App\Services\SettingsService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite' || basename(config('database.connections.sqlite.database')) !== 'twinsofte-sms-browser.sqlite') {
    throw new RuntimeException('SMS fixtures require the isolated twinsofte-sms-browser.sqlite database.');
}
Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
$user = User::firstOrFail();
$register = app(RegisterService::class)->open($user->id, '0');
app(SettingsService::class)->put('business', ['business_name' => 'SMS Test Market']);
app(SettingsService::class)->put('pos', ['show_receipt' => true, 'auto_print' => false]);
Product::create(['name' => 'SMS Test Product', 'sku' => 'SMS-TEST', 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'price' => '100', 'cost' => '20', 'track_stock' => false, 'stock' => '0', 'active' => true]);
Customer::create(['name' => 'Existing SMS Buyer', 'phone' => '0771234567', 'active' => true, 'opening_due' => '500']);
Sale::create(['invoice' => 'SMS-WALKIN', 'checkout_token' => Str::uuid(), 'user_id' => $user->id, 'register_id' => $register->id,
    'subtotal' => '100', 'sale_amount' => '100', 'customer_payable' => '100', 'cost_total' => '20', 'status' => 'ACTIVE', 'sold_at' => now()]);
$missingPhoneCustomer = Customer::create(['name' => 'Missing Phone Buyer', 'active' => true]);
Sale::create(['invoice' => 'SMS-MISSING-PHONE', 'checkout_token' => Str::uuid(), 'customer_id' => $missingPhoneCustomer->id, 'user_id' => $user->id, 'register_id' => $register->id,
    'subtotal' => '100', 'sale_amount' => '100', 'customer_payable' => '100', 'cost_total' => '20', 'status' => 'ACTIVE', 'sold_at' => now()]);
echo "Isolated SMS browser fixtures ready.\n";
