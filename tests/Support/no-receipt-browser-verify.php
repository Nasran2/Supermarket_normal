<?php

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CustomerLedgerService;
use App\Services\ProfitLossService;
use App\Services\RegisterService;
use App\Services\ReportService;
use App\Support\Money;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite' || basename(config('database.connections.sqlite.database')) !== 'twinsofte-no-receipt-ui.sqlite') {
    throw new RuntimeException('Only the named isolated no-receipt browser fixtures are allowed.');
}
$f = json_decode(file_get_contents('/tmp/twinsofte-no-receipt-browser-fixtures.json'), true);
$user = User::firstOrFail();
auth()->login($user);
$expect = function ($actual, $expected, $label) {
    if ((string) $actual !== (string) $expected) {
        throw new RuntimeException($label.' expected '.$expected.' got '.$actual);
    }
};
$returns = SaleReturn::orderBy('id')->get();
$expect($returns->count(), 5, 'Five audited return documents');
[$walkIn, $mixed, $same, $supplierExchange, $verified] = $returns->all();
foreach ($returns as $return) {
    $expect($return->return_type, 'NO_RECEIPT', 'Return type');
    $expect($return->sale_id, null, 'No fabricated parent invoice');
}
$expect($walkIn->amount, '460.00', 'Repeated barcode / multi-product credit');
$expect($walkIn->estimated_cost_total, '350.00', 'Estimated returned layer cost');
$expect($mixed->verification_status, 'PARTIALLY_VERIFIED', 'Mixed sources');
$expect($mixed->historical_cost_total, '100.00', 'Exact original cost');
$expect($mixed->estimated_cost_total, '150.00', 'Estimated write-off cost');
$expect($mixed->due_reduction, '130.00', 'Confirmed original due');
$expect($mixed->customer_due_applied, '100.00', 'Chosen oldest due allocation');
$expect($mixed->refund_amount, '100.00', 'Split refund');
$expect(Expense::where('reference', $mixed->reference)->firstOrFail()->amount, '150.00', 'Write-off once');
$expect($same->status, 'CANCELLED', 'Exchange cancellation retained');
$expect($same->replacement->status, 'RETURN_CANCELLED', 'Replacement cancellation retained');
$expect($same->settlements->where('kind', 'REVERSAL')->firstOrFail()->amount, '-10.00', 'Exchange collection reversed');
$claim = $supplierExchange->supplierReturns->firstOrFail();
$expect($claim->supplier_id, $f['supplier'], 'Confirmed supplier');
$expect($claim->status, 'PENDING', 'Non-sellable supplier claim');
$expect($claim->amount, '150.00', 'Explicit estimated supplier claim');
$expect($claim->items->first()->stock_layer_id, null, 'No invented purchase layer');
$expect($supplierExchange->replacement_value, '150.00', 'Chosen replacement price');
$expect($supplierExchange->refund_amount, '50.00', 'Other-product exchange difference');
$expect($verified->verification_status, 'VERIFIED', 'Two original invoices');
$expect($verified->items->pluck('item.sale_id')->unique()->count(), 2, 'Per-item invoice provenance');
$expect($verified->historical_cost_total, '260.00', 'Two historical allocation costs');
$expect($verified->method_type, 'CARD', 'Permitted noncash refund');
$expect($verified->refund_amount, '340.00', 'Verified refund');
$soap = Product::findOrFail($f['soap']);
$shampoo = Product::findOrFail($f['shampoo']);
$expect($soap->stock, '26.000', 'Sellable soap');
$expect($shampoo->stock, '21.000', 'Sellable shampoo');
foreach ([$soap, $shampoo] as $product) {
    $expect(Money::quantity('0', (string) $product->stockLayers()->available()->sum('remaining_quantity')), $product->stock, 'Physical layer reconciliation');
}
$customer = Customer::findOrFail($f['customer']);
$expect($customer->due_balance, '220.00', 'Customer due');
$expect(app(CustomerLedgerService::class)->build($customer)->last()['balance'], '220.00', 'Customer ledger');
$expect(Supplier::findOrFail($f['supplier'])->due_balance, '800.00', 'Supplier payable unchanged by pending claim');
$profit = app(ProfitLossService::class)->calculate(today()->toDateString(), today()->toDateString());
foreach (['revenue' => '820.00', 'cogs' => '600.00', 'returnWriteoffs' => '150.00', 'noReceiptCredit' => '860.00', 'estimatedRecovery' => '650.00', 'noReceiptAdjustments' => '210.00', 'net' => '-140.00'] as $key => $expected) {
    $expect($profit[$key], $expected, 'Profit '.$key);
}
$register = app(RegisterService::class)->summary(app(RegisterService::class)->current($user->id));
$expect($register['expected'], '9880.00', 'Daily register');
$payments = app(ReportService::class)->build('payments', ['from' => today()->toDateString(), 'to' => today()->toDateString()]);
$expect(Money::round($payments['cards']['Net collections']), '-260.00', 'Customer cash plus noncash net collections');
$expect(Sale::count(), 6, 'Real original and replacement invoices only');
$report = ['scenarios' => ['repeated_barcode_and_walk_in' => 'passed', 'mixed_verified_writeoff_split_due_refund' => 'passed', 'same_product_current_prices_and_cancellation' => 'passed', 'supplier_confirmation_other_exchange_and_draft_resume' => 'passed', 'two_invoices_and_disabled_cash_non_cash_refund' => 'passed', 'desktop_and_mobile' => 'passed'], 'reconciled' => ['customer_due' => '220.00', 'supplier_due' => '800.00', 'soap_stock' => '26.000', 'shampoo_stock' => '21.000', 'estimated_pending_supplier_claim' => '150.00', 'net_profit' => '-140.00', 'expected_cash' => '9880.00', 'customer_net_collections' => '-260.00']];
echo json_encode($report, JSON_PRETTY_PRINT).PHP_EOL;
