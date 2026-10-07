<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.connections.mysql.database') !== 'twinsofte_supermarket_ui') {
    throw new RuntimeException('Browser fixtures require the isolated twinsofte_supermarket_ui database.');
}
Artisan::call('migrate', ['--seed' => true, '--force' => true]);
Artisan::call('db:seed', ['--class' => 'PaymentExampleSeeder', '--force' => true]);
$admin = User::firstOrCreate(['email' => 'browser@example.test'], ['name' => 'Browser Test Admin', 'username' => 'browser', 'password' => 'Browser-test-password-2026', 'role_id' => Role::where('name', 'Administrator')->value('id'), 'active' => true]);
$category = Category::firstOrCreate(['name' => 'Browser test groceries']);
foreach ([['Rice', 'RICE', 'kg', '800', '500'], ['Milk', 'MILK', 'pcs', '350', '220'], ['Biscuits', 'BISCUITS', 'pcs', '250', '150'], ['Card test order', 'CARDTEST', 'pcs', '10000', '5000'], ['QR above threshold', 'QRHIGH', 'pcs', '6000', '3000'], ['QR below threshold', 'QRLOW', 'pcs', '4500', '2000'], ['QR exact threshold', 'QREXACT', 'pcs', '5000', '2500']] as [$name,$sku,$unit,$price,$cost]) {
    Product::firstOrCreate(['sku' => $sku], ['name' => $name, 'barcode' => $sku, 'category_id' => $category->id, 'unit_id' => Unit::where('short_name', $unit)->value('id'), 'price' => $price, 'cost' => $cost, 'stock' => '100.000', 'low_stock' => '5.000']);
}
Supplier::firstOrCreate(['name' => 'Browser test supplier']);
app(SettingsService::class)->put('pos', ['show_receipt' => false, 'auto_print' => false]);
