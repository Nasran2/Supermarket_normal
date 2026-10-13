<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\RegisterService;
use App\Services\ResourceService;
use App\Services\SettingsService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite' || ! str_contains(config('database.connections.sqlite.database'), 'twinsofte-cheque-browser')) {
    throw new RuntimeException('Use the isolated twinsofte-cheque-browser SQLite fixture.');
}
Artisan::call('migrate', ['--seed' => true, '--force' => true]);
$admin = User::where('username', 'admin')->firstOrFail();
$admin->update(['password' => 'password']);
auth()->login($admin);
if (! app(RegisterService::class)->current($admin->id)) {
    app(RegisterService::class)->open($admin->id, '1000');
}
app(SettingsService::class)->put('business', ['business_name' => 'Cheque Test Shop', 'timezone' => 'Asia/Colombo']);
$customer = Customer::firstOrCreate(['name' => 'Browser Cheque Customer'], ['opening_due' => 50000, 'active' => true]);
$supplier = Supplier::firstOrCreate(['name' => 'Browser Cheque Supplier'], ['active' => true]);
$product = Product::where('sku', 'CHEQUE-BROWSER')->first();
if (! $product) {
    $product = app(ResourceService::class)->save('products', ['name' => 'Cheque Browser Grocery', 'sku' => 'CHEQUE-BROWSER', 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'cost' => '100', 'price' => '25000', 'stock' => 100, 'low_stock' => 0, 'active' => true]);
}
if (! Purchase::where('reference', 'PUR-CHEQUE-BROWSER')->exists()) {
    app(PurchaseService::class)->save(['reference' => 'PUR-CHEQUE-BROWSER', 'supplier_id' => $supplier->id, 'purchase_date' => today()->toDateString(), 'items' => [['product_id' => $product->id, 'quantity' => '1', 'cost' => '25000']], 'payment_mode' => 'UNPAID'], $admin->id);
}
echo "Isolated cheque browser fixtures ready.\n";
