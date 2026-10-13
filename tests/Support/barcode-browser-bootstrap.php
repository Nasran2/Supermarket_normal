<?php

use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\BarcodeService;
use App\Services\PurchaseService;
use App\Services\RegisterService;
use App\Services\ResourceService;
use App\Services\SettingsService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

// Run only against a disposable SQLite file; never seed the shop database.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite' || ! str_contains(config('database.connections.sqlite.database'), 'twinsofte-barcode-browser')) {
    throw new RuntimeException('Use the isolated twinsofte-barcode-browser SQLite fixture.');
}
Artisan::call('migrate', ['--seed' => true, '--force' => true]);
$admin = User::where('username', 'admin')->firstOrFail();
auth()->login($admin);
if (! app(RegisterService::class)->current($admin->id)) {
    app(RegisterService::class)->open($admin->id, '0');
}
Supplier::firstOrCreate(['name' => 'Barcode Browser Supplier'], ['active' => true]);
foreach ([['Milk Powder', 'BROWSER-MILK', '950', 24], ['Biscuits', 'BROWSER-BISCUITS', '250', 50]] as [$name,$sku,$price,$stock]) {
    if (Product::where('sku', $sku)->exists()) {
        continue;
    }
    app(ResourceService::class)->save('products', ['name' => $name, 'sku' => $sku, 'barcode' => app(BarcodeService::class)->generate(), 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'cost' => '100', 'price' => $price, 'stock' => $stock, 'low_stock' => 0, 'active' => true]);
}
app(SettingsService::class)->put('business', ['business_name' => 'Barcode Test Shop']);
echo "Isolated barcode browser fixtures ready.\n";

if (! Product::where('sku', 'BROWSER-EXPIRY')->exists()) {
    app(ResourceService::class)->save('products', [
        'name' => 'Browser Dated Soap', 'sku' => 'BROWSER-EXPIRY', 'barcode' => app(BarcodeService::class)->generate(),
        'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'cost' => '100', 'price' => '130', 'stock' => 0, 'low_stock' => 0, 'active' => true, 'track_expiry' => true,
        'opening_layers' => [['quantity' => 10, 'cost' => '100', 'selling_price' => '130', 'expires_at' => '2026-12-20', 'batch_number' => 'BROWSER-DEC'], ['quantity' => 20, 'cost' => '100', 'selling_price' => '130', 'expires_at' => '2027-02-15', 'batch_number' => 'BROWSER-FEB']],
    ]);
}
if (! Purchase::where('reference', 'PUR-BROWSER-LABELS')->exists()) {
    $milk = Product::where('sku', 'BROWSER-MILK')->firstOrFail();
    $biscuits = Product::where('sku', 'BROWSER-BISCUITS')->firstOrFail();
    app(PurchaseService::class)->save(['reference' => 'PUR-BROWSER-LABELS', 'purchase_date' => today()->format('Y-m-d'), 'supplier_id' => Supplier::where('name', 'Barcode Browser Supplier')->value('id'),
        'items' => [['product_id' => $milk->id, 'unit_id' => $milk->unit_id, 'quantity' => 24, 'cost' => '100', 'selling_price' => '950'], ['product_id' => $biscuits->id, 'unit_id' => $biscuits->unit_id, 'quantity' => 12, 'cost' => '100', 'selling_price' => '250']],
    ], $admin->id);
}
