<?php

use App\Models\PaymentMethod;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\RegisterService;
use App\Services\ResourceService;
use App\Services\SaleService;
use App\Services\SettingsService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite' || ! str_contains(config('database.connections.sqlite.database'), 'twinsofte-unit-browser')) {
    throw new RuntimeException('Unit browser fixtures require a disposable twinsofte-unit-browser SQLite database.');
}
config(['features.unit' => true]);
Artisan::call('migrate', ['--seed' => true, '--force' => true]);
$admin = User::where('username', 'admin')->firstOrFail();
auth()->login($admin);
app(RegisterService::class)->open($admin->id, '10000');
Supplier::create(['name' => 'Browser supplier', 'active' => true]);
$products = app(ResourceService::class);
$tomatoes = $products->save('products', ['name' => 'Browser Tomatoes', 'sku' => 'BROWSER-TOMATO', 'barcode' => 'BROWSER-TOMATO', 'unit_id' => Unit::where('short_name', 'kg')->value('id'), 'cost' => '100', 'price' => '130', 'stock' => '48', 'low_stock' => '2', 'active' => true, 'conversions' => [['unit_id' => Unit::where('short_name', 'g')->value('id'), 'base_quantity' => '1', 'converted_quantity' => '1000', 'price' => null]]]);
$soap = $products->save('products', ['name' => 'Browser Soap', 'sku' => 'BROWSER-SOAP', 'barcode' => 'BROWSER-SOAP', 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'cost' => '100', 'price' => '130', 'stock' => '0', 'low_stock' => '2', 'active' => true, 'conversions' => [['unit_id' => Unit::where('short_name', 'doz')->value('id'), 'base_quantity' => '12', 'converted_quantity' => '1', 'price' => null]], 'opening_layers' => [['quantity' => 30, 'cost' => 100, 'selling_price' => 130], ['quantity' => 20, 'cost' => 110, 'selling_price' => 140]]]);
$milk = $products->save('products', ['name' => 'Browser Dated Milk', 'sku' => 'BROWSER-MILK', 'barcode' => 'BROWSER-MILK', 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'cost' => '100', 'price' => '130', 'stock' => '0', 'low_stock' => '2', 'active' => true, 'track_expiry' => true, 'opening_layers' => [['quantity' => 10, 'cost' => 100, 'selling_price' => 130, 'expires_at' => today()->addDays(10)->toDateString(), 'batch_number' => 'MILK-A'], ['quantity' => 20, 'cost' => 110, 'selling_price' => 140, 'expires_at' => today()->addDays(20)->toDateString(), 'batch_number' => 'MILK-B']]]);
app(SettingsService::class)->put('pos', ['show_receipt' => false, 'auto_print' => false]);
app(SettingsService::class)->put('returns', ['no_receipt_cash_refund' => true, 'no_receipt_cost_method' => 'CURRENT_DEFAULT']);
$cash = PaymentMethod::where('type', 'CASH')->value('id');
$data = ['items' => [['product_id' => $soap->id, 'unit_id' => Unit::where('short_name', 'doz')->value('id'), 'quantity' => '1', 'stock_price' => '130']], 'payment_method_id' => $cash];
$q = app(SaleService::class)->quote($data, $admin);
$sale = app(SaleService::class)->complete($data + ['amount_paid' => $q['customer_payable'], 'checkout_token' => (string) Str::uuid(), 'quote_hash' => $q['quote_hash']], $admin);
echo json_encode(['tomatoes' => $tomatoes->id, 'soap' => $soap->id, 'milk' => $milk->id, 'sale' => $sale->id]);
