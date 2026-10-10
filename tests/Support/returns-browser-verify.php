<?php

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Product;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ProfitLossService;
use App\Services\RegisterService;
use App\Services\SupplierLedgerService;
use App\Support\Money;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite' || basename(config('database.connections.sqlite.database')) !== 'twinsofte-returns-ui.sqlite') {
    throw new RuntimeException('Only isolated return browser fixtures are allowed.');
}
$f = json_decode(file_get_contents('/tmp/twinsofte-returns-browser-fixtures.json'), true);
$user = User::firstOrFail();
auth()->login($user);
$expect = function ($actual, $expected, $label) {
    if ((string) $actual !== (string) $expected) {
        throw new RuntimeException($label.' expected '.$expected.' got '.$actual);
    }
};
$a = SaleReturn::where('sale_id', $f['saleA'])->firstOrFail();
$b = SaleReturn::where('sale_id', $f['saleB'])->firstOrFail();
$c = SaleReturn::where('sale_id', $f['saleC'])->firstOrFail();
$expect($a->due_reduction, '260.00', 'A due reduction');
$expect($a->refund_amount, '0.00', 'A refund');
$expect(Sale::find($f['saleA'])->due_balance, '190.00', 'A remaining due');
$expect($b->cost_total, '110.00', 'B historical cost');
$expect($b->replacement_value, '140.00', 'B replacement value');
$expect($b->replacement->due_balance, '0.00', 'B replacement paid by credit');
$expect($b->replacement->cost_total, '100.00', 'B current outgoing cost');
$expense = Expense::where('reference', $b->reference)->where('type', 'RETURN_WRITEOFF')->firstOrFail();
$expect($expense->amount, '110.00', 'B write-off');
$expect($c->additional_payment, '50.00', 'C difference');
$pending = $c->supplierReturns->firstOrFail();
$expect($pending->status, 'PENDING', 'C supplier status');
$expect($pending->supplier_id, $f['supplier'], 'C supplier provenance');
$expect($pending->amount, '100.00', 'C pending historical cost');
$d = PurchaseReturn::where('resolution', 'SAME')->firstOrFail();
$e = PurchaseReturn::where('resolution', 'OTHER')->firstOrFail();
$returnF = PurchaseReturn::where('purchase_id', $f['purchaseF'])->firstOrFail();
$expect($d->due_reduction, '400.00', 'D source payable reduced');
$expect($d->replacement_value, '400.00', 'D new incoming stock');
$expect($e->replacement_value, '300.00', 'E higher replacement value');
$expect($e->replacement->due_amount, '300.00', 'E supplier payable');
$expect($returnF->supplier_due_applied, '300.00', 'F due allocation');
$expect($returnF->refund_amount, '200.00', 'F cash received');
$stock = Product::find($f['soap']);
$expect($stock->stock, '27.000', 'Sellable stock');
$expect(Money::quantity('0', (string) $stock->stockLayers()->available()->sum('remaining_quantity')), '27.000', 'Stock layer balance');
$expect(Customer::find($f['customer'])->due_balance, '190.00', 'Customer due');
$supplier = Supplier::find($f['supplier']);
$expect($supplier->due_balance, '600.00', 'Supplier due');
$expect(app(SupplierLedgerService::class)->build($supplier)['closing'], '600.00', 'Supplier ledger');
$profit = app(ProfitLossService::class)->calculate(today()->toDateString(), today()->toDateString());
$expect($profit['net'], '70.00', 'P&L net');
$cash = app(RegisterService::class)->summary(app(RegisterService::class)->current($user->id));
$expect($cash['expected'], '9540.00', 'Cash register');
$report = ['scenarios' => ['A' => 'Due-first restock passed', 'B' => 'Historical write-off and same-product exchange passed', 'C' => 'Supplier provenance and exchange payment passed', 'D' => 'Purchase return and new same-product layer passed', 'E' => 'Another product and added supplier payable passed', 'F' => 'Split due allocation and cash receipt passed'], 'verified' => ['customer_due' => '190.00', 'supplier_due' => '600.00', 'sellable_soap_stock' => '27.000', 'non_sellable_supplier_pending' => '1.000', 'historical_writeoff' => '110.00', 'net_profit' => '70.00', 'expected_register_cash' => '9540.00']];
echo json_encode($report,JSON_PRETTY_PRINT).PHP_EOL;
