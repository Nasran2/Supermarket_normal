<?php

use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\RegisterService;
use App\Services\SaleService;
use App\Services\SettingsService;
use App\Services\StockLayerService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite' || basename(config('database.connections.sqlite.database')) !== 'twinsofte-no-receipt-ui.sqlite') {
    throw new RuntimeException('Returns browser fixtures require the isolated twinsofte-no-receipt-ui.sqlite database.');
}
Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
$user = User::firstOrFail();
$user->update(['username' => 'nr-qa', 'password' => 'NoReceipt-QA-2026-password']);
app(RegisterService::class)->open($user->id, '10000');
$cash = PaymentMethod::where('type', 'CASH')->value('id');
$customer = Customer::create(['name' => 'Returns QA Customer', 'phone' => '0770000123', 'active' => true]);
$supplier = Supplier::create(['name' => 'Returns QA Supplier', 'active' => true]);
$soap = Product::create(['name' => 'QA Bath Soap', 'sku' => 'QA-SOAP', 'barcode' => 'QA-SOAP', 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'price' => '130', 'cost' => '100', 'stock' => '0', 'active' => true]);
$shampoo = Product::create(['name' => 'QA Shampoo', 'sku' => 'QA-SHAMPOO', 'barcode' => 'QA-SHAMPOO', 'unit_id' => $soap->unit_id, 'price' => '200', 'cost' => '150', 'stock' => '0', 'active' => true]);
app(StockLayerService::class)->receive($soap, '10', '100', '130', 'OPENING_STOCK', 'QA-OPEN-1', $user->id);
app(StockLayerService::class)->receive($soap, '10', '110', '140', 'OPENING_STOCK', 'QA-OPEN-2', $user->id);
app(StockLayerService::class)->receive($shampoo, '20', '150', '200', 'OPENING_STOCK', 'QA-OPEN-3', $user->id);
$purchase = app(PurchaseService::class)->save(['supplier_id' => $supplier->id, 'auto_reference' => true, 'purchase_date' => today()->toDateString(), 'payment_mode' => 'AUTO', 'amount_paid' => '200', 'payment_method_id' => $cash, 'items' => [['product_id' => $soap->id, 'quantity' => '10', 'cost' => '100', 'selling_price' => '150']]], $user->id);
$make = function ($quantity, $price, $paid, $product = null) use ($soap, $user, $cash, $customer) {
    $product ??= $soap;
    $data = ['items' => [['product_id' => $product->id, 'quantity' => $quantity, 'stock_price' => $price]], 'customer_id' => $customer->id, 'payments' => [['payment_method_id' => $cash, 'amount' => $paid, 'amount_paid' => $paid]], 'allow_due' => true];
    $quote = app(SaleService::class)->quote($data, $user);

    return app(SaleService::class)->complete($data + ['checkout_token' => (string) Str::uuid(), 'quote_hash' => $quote['quote_hash']], $user);
};
$a = $make('5', '130', '200');
$b = $make('1', '140', '140');
$c = $make('1', '150', '150');
$d = $make('1', '200', '200', $shampoo);
app(SettingsService::class)->put('returns', ['return_auto_print' => false, 'no_receipt_cash_refund' => true, 'no_receipt_approval_above' => '0']);
file_put_contents('/tmp/twinsofte-no-receipt-browser-fixtures.json', json_encode(['saleA' => $a->id, 'saleB' => $b->id, 'saleC' => $c->id, 'shampooSale' => $d->id, 'purchase' => $purchase->id, 'soap' => $soap->id, 'shampoo' => $shampoo->id, 'customer' => $customer->id, 'supplier' => $supplier->id]));
echo 'Isolated no-receipt browser fixtures ready.'.PHP_EOL;
