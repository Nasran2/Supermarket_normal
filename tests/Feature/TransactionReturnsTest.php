<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\CustomerLedgerService;
use App\Services\ProfitLossService;
use App\Services\PurchaseReturnService;
use App\Services\PurchaseService;
use App\Services\RegisterService;
use App\Services\ReturnSettlementService;
use App\Services\SaleService;
use App\Services\SalesReturnService;
use App\Services\SettingsService;
use App\Services\StockLayerService;
use App\Services\SupplierLedgerService;
use App\Services\SupplierReturnService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TransactionReturnsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    private Supplier $supplier;

    private int $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::firstOrFail();
        $this->actingAs($this->admin);
        $this->cash = PaymentMethod::where('type', 'CASH')->value('id');
        app(RegisterService::class)->open($this->admin->id, '10000');
        $this->supplier = Supplier::create(['name' => 'Return supplier', 'active' => true]);
        $this->product = Product::create(['name' => 'Return soap', 'sku' => 'RETURN-SOAP', 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'cost' => '40', 'price' => '100', 'stock' => '0', 'active' => true]);
        app(StockLayerService::class)->receive($this->product, '40', '40', '100', 'OPENING_STOCK', 'OPEN-RET', $this->admin->id);
    }

    private function sale(string $qty = '10', string $paid = '1000', ?Customer $customer = null): Sale
    {
        $customer ??= Customer::create(['name' => 'Return customer', 'active' => true]);
        $data = ['customer_id' => $customer->id, 'items' => [['product_id' => $this->product->id, 'quantity' => $qty, 'stock_price' => '100']], 'payments' => [['payment_method_id' => $this->cash, 'amount' => $paid, 'amount_paid' => $paid]], 'allow_due' => Money::compare($paid, Money::mul('100', $qty)) < 0];
        $quote = app(SaleService::class)->quote($data, $this->admin);

        return app(SaleService::class)->complete($data + ['checkout_token' => (string) Str::uuid(), 'quote_hash' => $quote['quote_hash']], $this->admin);
    }

    private function salesData(Sale $sale, string $qty = '5', string $action = 'RESTOCK'): array
    {
        return ['token' => (string) Str::uuid(), 'original_id' => $sale->id, 'reason' => 'Damaged', 'resolution' => 'MONEY', 'payment_method_id' => $this->cash, 'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => $qty, 'stock_action' => $action]]];
    }

    private function purchase(string $paid = '200'): Purchase
    {
        return app(PurchaseService::class)->save(['supplier_id' => $this->supplier->id, 'purchase_date' => today()->toDateString(), 'auto_reference' => true, 'payment_mode' => 'AUTO', 'amount_paid' => $paid, 'payment_method_id' => $this->cash, 'items' => [['product_id' => $this->product->id, 'quantity' => '10', 'cost' => '100', 'selling_price' => '150']]], $this->admin->id);
    }

    private function purchaseData(Purchase $p, string $qty = '5'): array
    {
        return ['token' => (string) Str::uuid(), 'original_id' => $p->id, 'reason' => 'Damaged', 'resolution' => 'MONEY', 'payment_method_id' => $this->cash, 'items' => [['purchase_item_id' => $p->items->first()->id, 'quantity' => $qty]]];
    }

    private function expectValidation(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected validation failure.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    public static function salesDueCases(): array
    {
        return [['1000', '5', '0.00', '500.00', '0.00'], ['200', '10', '800.00', '200.00', '0.00'], ['200', '4', '400.00', '0.00', '400.00'], ['700', '5', '300.00', '200.00', '0.00']];
    }

    #[DataProvider('salesDueCases')]
    public function test_sales_returns_reduce_original_due_before_refunding(string $paid, string $qty, string $reduced, string $refund, string $due): void
    {
        $sale = $this->sale('10', $paid);
        $r = app(SalesReturnService::class)->complete($sale, $this->salesData($sale, $qty), $this->admin);
        $this->assertSame($reduced, $r->due_reduction);
        $this->assertSame($refund, $r->refund_amount);
        $this->assertSame($due, $sale->fresh()->due_balance);
        $this->assertSame($due, $sale->customer->fresh()->due_balance);
        $ledger = app(CustomerLedgerService::class)->build($sale->customer);
        $this->assertSame($due, $ledger->last()['balance']);
    }

    public function test_split_credit_applies_other_customer_due_and_refunds_remainder(): void
    {
        $sale = $this->sale();
        $other = $this->sale('3', '0', $sale->customer);
        $d = $this->salesData($sale);
        $d['apply_due'] = '300';
        $d['credit_customer_id'] = $sale->customer_id;
        $r = app(SalesReturnService::class)->complete($sale, $d, $this->admin);
        $this->assertSame('200.00', $r->refund_amount);
        $this->assertSame('0.00', $other->fresh()->due_balance);
        $this->assertDatabaseHas('return_account_allocations', ['sale_return_id' => $r->id, 'sale_id' => $other->id, 'amount' => '300.00']);
        $this->assertSame('0.00', app(CustomerLedgerService::class)->build($sale->customer)->last()['balance']);
    }

    public function test_anonymous_credit_is_audited_without_changing_original_customer(): void
    {
        $sale = $this->sale();
        $sale->update(['customer_id' => null]);
        $customer = Customer::create(['name' => 'Searched account', 'opening_due' => '300', 'active' => true]);
        $d = $this->salesData($sale);
        $d['apply_due'] = '300';
        $d['credit_customer_id'] = $customer->id;
        $r = app(SalesReturnService::class)->complete($sale, $d, $this->admin);
        $this->assertNull($sale->fresh()->customer_id);
        $this->assertSame('0.00', $customer->fresh()->due_balance);
        $this->assertDatabaseHas('return_account_allocations', ['sale_return_id' => $r->id, 'customer_id' => $customer->id, 'kind' => 'OPENING_DUE']);
    }

    public function test_historical_allocations_are_reversed_last_first_across_partial_returns(): void
    {
        $this->product->stockLayers()->update(['remaining_quantity' => '0']);
        $this->product->update(['stock' => '0']);
        $a = app(StockLayerService::class)->receive($this->product, '2', '100', '100', 'OPENING_STOCK', 'A', $this->admin->id);
        $b = app(StockLayerService::class)->receive($this->product, '2', '110', '100', 'OPENING_STOCK', 'B', $this->admin->id);
        $sale = $this->sale('4', '400');
        $this->product->update(['cost' => '999']);
        $r = app(SalesReturnService::class)->complete($sale, $this->salesData($sale, '1'), $this->admin);
        $this->assertSame('110.00', $r->cost_total);
        $this->assertSame('1.000', $b->fresh()->remaining_quantity);
        $this->assertSame('0.000', $a->fresh()->remaining_quantity);
        $r2 = app(SalesReturnService::class)->complete($sale, $this->salesData($sale, '2'), $this->admin);
        $this->assertSame('210.00', $r2->cost_total);
        $this->assertSame('1.000', $a->fresh()->remaining_quantity);
        $this->assertSame('2.000', $b->fresh()->remaining_quantity);
        $this->assertDatabaseHas('return_stock_allocations', ['sale_return_item_id' => $r->items->first()->id, 'stock_layer_id' => $b->id, 'cost_total' => '110.00']);
    }

    public function test_writeoff_uses_historical_cost_once_in_profit(): void
    {
        $sale = $this->sale('1', '100');
        $stock = $this->product->fresh()->stock;
        $this->product->update(['cost' => '999']);
        $r = app(SalesReturnService::class)->complete($sale, $this->salesData($sale, '1', 'WRITEOFF'), $this->admin);
        $this->assertSame($stock, $this->product->fresh()->stock);
        $this->assertSame('40.00', $r->cost_total);
        $this->assertDatabaseHas('expenses', ['reference' => $r->reference, 'type' => 'RETURN_WRITEOFF', 'amount' => '40.00']);
        $p = app(ProfitLossService::class)->calculate(today()->toDateString(), today()->toDateString());
        $this->assertSame('0.00', $p['cogs']);
        $this->assertSame('40.00', $p['returnWriteoffs']);
        $this->assertSame('-40.00', $p['net']);
    }

    public function test_same_product_exchange_deducts_current_stock_cost_and_has_zero_difference(): void
    {
        $sale = $this->sale('1', '100');
        $d = $this->salesData($sale, '1', 'WRITEOFF');
        $d['resolution'] = 'SAME';
        $d['replacements'] = [['product_id' => $this->product->id, 'sale_item_id' => $sale->items->first()->id, 'quantity' => '1', 'stock_price' => '100']];
        $r = app(SalesReturnService::class)->complete($sale, $d, $this->admin);
        $this->assertSame('100.00', $r->replacement_value);
        $this->assertSame('0.00', $r->refund_amount);
        $this->assertSame('0.00', $r->replacement->due_balance);
        $this->assertSame('38.000', $this->product->fresh()->stock);
        $this->assertSame('40.00', $r->replacement->cost_total);
    }

    public function test_exchange_due_credit_cannot_replace_unpaid_original_value(): void
    {
        $sale = $this->sale('1', '20');
        $d = $this->salesData($sale, '1');
        $d['resolution'] = 'SAME';
        $d['replacements'] = [['product_id' => $this->product->id, 'sale_item_id' => $sale->items->first()->id, 'quantity' => '1', 'stock_price' => '100']];
        $q = app(SalesReturnService::class)->quote($sale, $d, $this->admin);
        $this->assertSame('80.00', $q['must_pay']);
        $d['add_to_due'] = true;
        $r = app(SalesReturnService::class)->complete($sale, $d, $this->admin);
        $this->assertSame('0.00', $sale->fresh()->due_balance);
        $this->assertSame('80.00', $r->replacement->due_balance);
        $this->assertSame('80.00', $sale->customer->fresh()->due_balance);
    }

    public function test_another_product_exchange_collects_and_refunds_correct_differences(): void
    {
        $sale = $this->sale('5', '500');
        $d = $this->salesData($sale);
        $d['resolution'] = 'OTHER';
        $d['replacements'] = [['product_id' => $this->product->id, 'quantity' => '7', 'stock_price' => '100']];
        $r = app(SalesReturnService::class)->complete($sale, $d, $this->admin);
        $this->assertSame('200.00', $r->additional_payment);
        $this->assertSame('0.00', $r->replacement->due_balance);
        $this->assertSame('10700.00', app(RegisterService::class)->summary(app(RegisterService::class)->current($this->admin->id))['expected']);
    }

    public function test_return_to_supplier_splits_original_suppliers_without_sellable_stock(): void
    {
        $this->product->stockLayers()->update(['remaining_quantity' => '0']);
        $this->product->update(['stock' => '0']);
        $a = $this->purchase('0');
        $bSupplier = Supplier::create(['name' => 'Supplier B', 'active' => true]);
        $this->supplier = $bSupplier;
        $b = $this->purchase('0');
        $saleData = ['items' => [['product_id' => $this->product->id, 'quantity' => '15', 'stock_price' => '150']], 'payment_method_id' => $this->cash];
        $q = app(SaleService::class)->quote($saleData, $this->admin);
        $sale = app(SaleService::class)->complete($saleData + ['checkout_token' => (string) Str::uuid(), 'quote_hash' => $q['quote_hash'], 'amount_paid' => '2250'], $this->admin);
        $stock = $this->product->fresh()->stock;
        $r = app(SalesReturnService::class)->complete($sale, $this->salesData($sale, '7', 'SUPPLIER'), $this->admin);
        $this->assertSame($stock, $this->product->fresh()->stock);
        $this->assertCount(2, $r->supplierReturns);
        $this->assertSame('700.00', Money::sum($r->supplierReturns->pluck('amount')));
        $this->assertEqualsCanonicalizing([$a->supplier_id, $b->supplier_id], $r->supplierReturns->pluck('supplier_id')->all());
    }

    public function test_unknown_supplier_requires_authorized_manual_supplier(): void
    {
        $sale = $this->sale('1', '100');
        $d = $this->salesData($sale, '1', 'SUPPLIER');
        $this->expectValidation(fn () => app(SalesReturnService::class)->complete($sale, $d, $this->admin));
        $d['items'][0]['supplier_id'] = $this->supplier->id;
        $r = app(SalesReturnService::class)->complete($sale, $d, $this->admin);
        $this->assertSame($this->supplier->id, $r->supplierReturns->first()->supplier_id);
    }

    public function test_quantity_limits_and_duplicate_submission_are_atomic(): void
    {
        $sale = $this->sale('2', '200');
        $d = $this->salesData($sale, '1');
        $r = app(SalesReturnService::class)->complete($sale, $d, $this->admin);
        $repeat = app(SalesReturnService::class)->complete($sale, $d, $this->admin);
        $this->assertSame($r->id, $repeat->id);
        $this->expectValidation(fn () => app(SalesReturnService::class)->complete($sale, $this->salesData($sale, '2'), $this->admin));
        $this->assertDatabaseCount('sale_returns', 1);
        $this->assertSame('39.000', $this->product->fresh()->stock);
    }

    public function test_cash_refund_needs_register_but_due_only_return_does_not(): void
    {
        $paid = $this->sale('1', '100');
        $due = $this->sale('1', '0');
        $register = app(RegisterService::class)->current($this->admin->id);
        app(RegisterService::class)->close($register, '10100', null);
        $this->expectValidation(fn () => app(SalesReturnService::class)->complete($paid, $this->salesData($paid, '1'), $this->admin));
        $r = app(SalesReturnService::class)->complete($due, $this->salesData($due, '1'), $this->admin);
        $this->assertNull($r->register_id);
        $this->assertSame('100.00', $r->due_reduction);
    }

    public function test_cancellation_restores_stock_due_and_money_in_current_register(): void
    {
        $sale = $this->sale('10', '200');
        $d = $this->salesData($sale, '10');
        $r = app(SalesReturnService::class)->complete($sale, $d, $this->admin);
        $old = app(RegisterService::class)->current($this->admin->id);
        $oldCash = app(RegisterService::class)->summary($old)['expected'];
        app(RegisterService::class)->close($old, $oldCash, null);
        $new = app(RegisterService::class)->open($this->admin->id, '500');
        app(SalesReturnService::class)->cancel($r, 'Return entered by mistake', $this->admin);
        $this->assertSame('30.000', $this->product->fresh()->stock);
        $this->assertSame('800.00', $sale->fresh()->due_balance);
        $this->assertSame('700.00', app(RegisterService::class)->summary($new)['expected']);
        $this->assertSame($oldCash, app(RegisterService::class)->summary($old->fresh())['expected']);
        $this->assertSame('CANCELLED', $r->fresh()->status);
        $this->assertDatabaseCount('sale_returns', 1);
    }

    public function test_exchange_and_writeoff_cancel_restore_everything(): void
    {
        $sale = $this->sale('1', '100');
        $d = $this->salesData($sale, '1', 'WRITEOFF');
        $d['resolution'] = 'SAME';
        $d['replacements'] = [['product_id' => $this->product->id, 'sale_item_id' => $sale->items->first()->id, 'quantity' => '1', 'stock_price' => '100']];
        $r = app(SalesReturnService::class)->complete($sale, $d, $this->admin);
        app(SalesReturnService::class)->cancel($r, 'Wrong return', $this->admin);
        $this->assertSame('39.000', $this->product->fresh()->stock);
        $this->assertDatabaseHas('expenses', ['reference' => $r->reference, 'status' => 'REVERSED']);
        $this->assertSame('60.00', app(ProfitLossService::class)->calculate(today()->toDateString(), today()->toDateString())['net']);
    }

    public static function purchaseDueCases(): array
    {
        return [['1000', '5', '0.00', '500.00', '0.00'], ['200', '10', '800.00', '200.00', '0.00'], ['200', '4', '400.00', '0.00', '400.00']];
    }

    #[DataProvider('purchaseDueCases')]
    public function test_purchase_returns_reduce_payable_first(string $paid, string $qty, string $reduced, string $refund, string $due): void
    {
        $p = $this->purchase($paid);
        $r = app(PurchaseReturnService::class)->complete($p, $this->purchaseData($p, $qty), $this->admin);
        $this->assertSame($reduced, $r->due_reduction);
        $this->assertSame($refund, $r->refund_amount);
        $this->assertSame($due, $p->fresh()->due_amount);
        $this->assertSame($due, $this->supplier->fresh()->due_balance);
        $this->assertSame($due, app(SupplierLedgerService::class)->build($this->supplier)['closing']);
    }

    public function test_purchase_return_cannot_use_other_purchase_stock(): void
    {
        $p = $this->purchase('1000');
        $layer = $p->items->first()->stockLayers->first();
        $layer->update(['remaining_quantity' => '1']);
        $this->product->decrement('stock', 9);
        $this->expectValidation(fn () => app(PurchaseReturnService::class)->complete($p, $this->purchaseData($p, '2'), $this->admin));
        $this->assertDatabaseCount('purchase_returns', 0);
        $this->assertSame('1.000', $layer->fresh()->remaining_quantity);
    }

    public function test_purchase_same_replacement_creates_new_cost_layer_and_extra_payable(): void
    {
        $p = $this->purchase('200');
        $d = $this->purchaseData($p, '4');
        $d['resolution'] = 'SAME';
        $d['add_to_due'] = true;
        $d['replacements'] = [['product_id' => $this->product->id, 'purchase_item_id' => $p->items->first()->id, 'quantity' => '4', 'cost' => '100', 'selling_price' => '150']];
        $r = app(PurchaseReturnService::class)->complete($p, $d, $this->admin);
        $this->assertSame('400.00', $r->replacement->due_amount);
        $this->assertSame('400.00', $p->fresh()->due_amount);
        $this->assertSame('800.00', $this->supplier->fresh()->due_balance);
        $new = $r->replacement->items->first()->stockLayers->first();
        $this->assertNotSame($p->items->first()->stockLayers->first()->id, $new->id);
        $this->assertSame('4.000', $new->remaining_quantity);
        $this->assertSame('100.00', $new->cost_price);
    }

    public function test_different_purchase_replacement_larger_than_credit_collects_payment(): void
    {
        $p = $this->purchase('1000');
        $other = Product::create(['name' => 'Other incoming', 'sku' => 'OTHER-INCOMING', 'unit_id' => $this->product->unit_id, 'cost' => '150', 'price' => '200', 'stock' => '0', 'active' => true]);
        $d = $this->purchaseData($p, '5');
        $d['resolution'] = 'OTHER';
        $d['replacements'] = [['product_id' => $other->id, 'quantity' => '4', 'cost' => '150', 'selling_price' => '200']];
        $r = app(PurchaseReturnService::class)->complete($p, $d, $this->admin);
        $this->assertSame('100.00', $r->additional_payment);
        $this->assertSame('0.00', $r->replacement->due_amount);
        $this->assertSame('4.000', $other->fresh()->stock);
        $this->assertSame('8900.00', app(RegisterService::class)->summary(app(RegisterService::class)->current($this->admin->id))['expected']);
    }

    public function test_supplier_split_refund_and_other_due_credit(): void
    {
        $p = $this->purchase('1000');
        $other = $this->purchase('0');
        $d = $this->purchaseData($p);
        $d['apply_due'] = '300';
        $r = app(PurchaseReturnService::class)->complete($p, $d, $this->admin);
        $this->assertSame('200.00', $r->refund_amount);
        $this->assertSame('700.00', $other->fresh()->due_amount);
        $this->assertSame('700.00', $this->supplier->fresh()->due_balance);
        $this->assertSame('700.00', app(SupplierLedgerService::class)->build($this->supplier)['closing']);
    }

    public function test_supplier_credit_is_retained_and_purchase_cancellation_reverses_it(): void
    {
        $p = $this->purchase('1000');
        $d = $this->purchaseData($p);
        $d['keep_credit'] = '500';
        $r = app(PurchaseReturnService::class)->complete($p, $d, $this->admin);
        $this->assertSame('500.00', $this->supplier->fresh()->credit_balance);
        $this->assertSame('-500.00', app(SupplierLedgerService::class)->build($this->supplier)['closing']);
        app(PurchaseReturnService::class)->cancel($r, 'Cancelled return', $this->admin);
        $this->assertSame('0.00', $this->supplier->fresh()->credit_balance);
        $this->assertSame('10.000', $p->items->first()->stockLayers->first()->fresh()->remaining_quantity);
        $this->assertSame('0.00', app(SupplierLedgerService::class)->build($this->supplier)['closing']);
    }

    public function test_supplier_pending_goods_can_be_sent_and_settled_against_payable(): void
    {
        $p = $this->purchase('0');
        $saleData = ['items' => [['product_id' => $this->product->id, 'quantity' => '1', 'stock_price' => '150']], 'payment_method_id' => $this->cash];
        $q = app(SaleService::class)->quote($saleData, $this->admin);
        $sale = app(SaleService::class)->complete($saleData + ['checkout_token' => (string) Str::uuid(), 'quote_hash' => $q['quote_hash'], 'amount_paid' => '150'], $this->admin);
        $r = app(SalesReturnService::class)->complete($sale, $this->salesData($sale, '1', 'SUPPLIER'), $this->admin);
        $sr = $r->supplierReturns->first();
        $service = app(SupplierReturnService::class);
        $service->transition($sr, ['action' => 'SEND'], $this->admin);
        $service->transition($sr, ['action' => 'SETTLE', 'apply_due' => '100'], $this->admin);
        $this->assertSame('900.00', $p->fresh()->due_amount);
        $this->assertSame('SETTLED', $sr->fresh()->status);
        $this->expectValidation(fn () => app(SalesReturnService::class)->cancel($r, 'Cannot recall supplier goods', $this->admin));
    }

    public function test_fractional_and_converted_returns_preserve_original_conversion(): void
    {
        $kg = Unit::where('short_name', 'kg')->first();
        $this->product->update(['unit_id' => $kg->id]);
        $sale = $this->sale('2', '200');
        $r = app(SalesReturnService::class)->complete($sale, $this->salesData($sale, '0.500'), $this->admin);
        $this->assertSame('0.500', $r->items->first()->base_quantity);
        $this->assertSame('38.500', $this->product->fresh()->stock);
        $this->product->update(['unit_id' => Unit::where('short_name', 'pcs')->value('id')]);
        $box = Unit::where('short_name', 'box')->first();
        $this->product->conversions()->create(['unit_id' => $box->id, 'base_quantity' => '12', 'converted_quantity' => '1', 'price' => '1200']);
        $d = ['items' => [['product_id' => $this->product->id, 'unit_id' => $box->id, 'quantity' => '1', 'stock_price' => '100']], 'payment_method_id' => $this->cash];
        $q = app(SaleService::class)->quote($d, $this->admin);
        $s = app(SaleService::class)->complete($d + ['checkout_token' => (string) Str::uuid(), 'quote_hash' => $q['quote_hash'], 'amount_paid' => '1200'], $this->admin);
        $r = app(SalesReturnService::class)->complete($s, $this->salesData($s, '1'), $this->admin);
        $this->assertSame('12.000', $r->items->first()->base_quantity);
    }

    public function test_piece_decimal_return_and_voided_bill_are_rejected(): void
    {
        $sale = $this->sale('1', '100');
        $this->expectValidation(fn () => app(SalesReturnService::class)->complete($sale, $this->salesData($sale, '0.5'), $this->admin));
        $sale->update(['status' => 'VOIDED']);
        $this->expectValidation(fn () => app(SalesReturnService::class)->complete($sale, $this->salesData($sale, '1'), $this->admin));
    }

    public function test_wizard_pages_receipt_reports_and_profile_links_render(): void
    {
        $sale = $this->sale();
        $this->get(route('returns.create', ['sales', 'original_id' => $sale->id]))->assertOk()->assertSee('Select returned products')->assertSee('What should the customer receive?');
        $p = $this->purchase();
        $this->get(route('returns.create', ['purchase', 'original_id' => $p->id]))->assertOk();
        $r = app(SalesReturnService::class)->complete($sale, $this->salesData($sale), $this->admin);
        foreach (['show', 'receipt'] as $route) {
            $this->get(route('returns.'.$route, ['sales', $r->id]))->assertOk()->assertSee($r->reference);
        }
        $this->get(route('returns.index', 'sales'))->assertOk()->assertSee($r->reference);
        $this->get(route('returns.index', 'purchase'))->assertOk();
        $this->get(route('returns.suppliers'))->assertOk();
        $this->get(route('settings.edit', 'returns'))->assertOk();
        foreach (['returns', 'purchase-returns', 'payments', 'cash', 'profit', 'purchases'] as $report) {
            $this->get(route('reports.show', $report))->assertOk();
        }
        $this->get(route('manage.show', ['customers', $sale->customer_id]))->assertOk()->assertSee($r->reference);
        $this->get(route('manage.show', ['suppliers', $this->supplier->id]))->assertOk();
    }

    public function test_backend_quote_does_not_expose_cost_to_return_cashier(): void
    {
        $sale = $this->sale();
        $role = Role::create(['name' => 'Return cashier', 'sales_visibility' => 'ALL']);
        $role->permissions()->sync(Permission::whereIn('name', ['sales_returns.create', 'sales_returns.refund', 'sales_returns.view'])->pluck('id'));
        $user = User::create(['name' => 'Return clerk', 'email' => 'returns@local.test', 'password' => 'password', 'role_id' => $role->id, 'active' => true]);
        $this->actingAs($user);
        $d = $this->salesData($sale);
        $this->postJson(route('returns.quote', 'sales'), $d)->assertOk()->assertJsonMissingPath('cost_total')->assertJsonMissingPath('lines.0.allocations')->assertJsonMissingPath('lines.0.cost_total');
        $d['items'][0]['stock_action'] = 'WRITEOFF';
        $this->postJson(route('returns.quote', 'sales'), $d)->assertForbidden();
    }

    public function test_review_hash_prevents_saving_changed_due_totals(): void
    {
        $sale = $this->sale('10', '200');
        $d = $this->salesData($sale);
        $q = $this->postJson(route('returns.quote', 'sales'), $d)->assertOk()->json();
        $sale->payments()->update(['amount_paid' => '700']);
        $this->postJson(route('returns.store', 'sales'), $d + ['quote_hash' => $q['quote_hash']])->assertUnprocessable();
        $this->assertDatabaseCount('sale_returns', 0);
    }

    public function test_manager_threshold_and_disabled_returns_are_enforced(): void
    {
        $sale = $this->sale();
        app(SettingsService::class)->put('returns', ['sales_returns_enabled' => false]);
        $this->postJson(route('returns.store', 'sales'), $this->salesData($sale))->assertUnprocessable();
        $this->assertDatabaseCount('sale_returns', 0);
    }

    public function test_returned_stock_can_itself_supply_the_same_product_replacement(): void
    {
        $sale = $this->sale('40', '4000');
        $data = $this->salesData($sale, '1');
        $data['resolution'] = 'SAME';
        $data['replacements'] = [['product_id' => $this->product->id, 'sale_item_id' => $sale->items->first()->id, 'quantity' => '1', 'stock_price' => '100']];
        $q = app(SalesReturnService::class)->quote($sale, $data, $this->admin);
        $this->assertSame('0.00', $q['must_pay']);
        $r = app(SalesReturnService::class)->complete($sale, $data, $this->admin);
        $this->assertSame('0.000', $this->product->fresh()->stock);
        $this->assertSame('0.00', $r->replacement->due_balance);
        app(SalesReturnService::class)->cancel($r, 'Reverse exchange', $this->admin);
        $this->assertSame('0.000', $this->product->fresh()->stock);
    }

    public function test_supplier_credit_can_be_spent_once_on_other_payables(): void
    {
        $p = $this->purchase('1000');
        $data = $this->purchaseData($p);
        $data['keep_credit'] = '500';
        $r = app(PurchaseReturnService::class)->complete($p, $data, $this->admin);
        $other = $this->purchase('0');
        app(ReturnSettlementService::class)->useSupplierCredit($this->supplier, '300', $this->admin);
        $this->assertSame('200.00', $this->supplier->fresh()->credit_balance);
        $this->assertSame('700.00', $other->fresh()->due_amount);
        $this->assertSame('500.00', app(SupplierLedgerService::class)->build($this->supplier)['closing']);
        $this->expectValidation(fn () => app(ReturnSettlementService::class)->useSupplierCredit($this->supplier, '300', $this->admin));
        app(PurchaseReturnService::class)->cancel($r, 'Wrong return', $this->admin);
        $this->assertSame('1000.00', $other->fresh()->due_amount);
        $this->assertSame('0.00', $this->supplier->fresh()->credit_balance);
    }

    public function test_cancellation_uses_original_method_snapshots_after_method_is_disabled(): void
    {
        $sale = $this->sale('1', '100');
        $r = app(SalesReturnService::class)->complete($sale, $this->salesData($sale, '1'), $this->admin);
        PaymentMethod::whereKey($this->cash)->update(['active' => false, 'type' => 'OTHER']);
        app(SalesReturnService::class)->cancel($r, 'Reverse cash return', $this->admin);
        $this->assertSame('10100.00', app(RegisterService::class)->summary(app(RegisterService::class)->current($this->admin->id))['expected']);
        $this->assertDatabaseHas('return_settlements', ['sale_return_id' => $r->id, 'kind' => 'REVERSAL', 'method_type' => 'CASH', 'amount' => '100.00']);
    }

    public function test_past_period_is_preserved_when_return_is_cancelled_next_day(): void
    {
        $sale = $this->sale('1', '100');
        $r = app(SalesReturnService::class)->complete($sale, $this->salesData($sale, '1', 'WRITEOFF'), $this->admin);
        $day = today()->toDateString();
        $before = app(ProfitLossService::class)->calculate($day, $day);
        $this->travel(1)->days();
        app(SalesReturnService::class)->cancel($r, 'Next day reversal', $this->admin);
        $after = app(ProfitLossService::class)->calculate($day, $day);
        $this->assertSame($before['net'], $after['net']);
        $next = app(ProfitLossService::class)->calculate(today()->toDateString(), today()->toDateString());
        $this->assertSame('100.00', $next['net']);
        $this->travelBack();
    }

    public function test_cancellation_of_used_replacement_purchase_rolls_back_everything(): void
    {
        $p = $this->purchase('1000');
        $data = $this->purchaseData($p);
        $data['resolution'] = 'SAME';
        $data['replacements'] = [['product_id' => $this->product->id, 'purchase_item_id' => $p->items->first()->id, 'quantity' => '5', 'cost' => '100', 'selling_price' => '150']];
        $r = app(PurchaseReturnService::class)->complete($p, $data, $this->admin);
        $layer = $r->replacement->items->first()->stockLayers->first();
        $layer->update(['remaining_quantity' => '4']);
        $stock = $this->product->fresh()->stock;
        $this->expectValidation(fn () => app(PurchaseReturnService::class)->cancel($r, 'Cannot recall sold replacement', $this->admin));
        $this->assertSame('COMPLETED', $r->fresh()->status);
        $this->assertSame($stock, $this->product->fresh()->stock);
    }

    public function test_replacement_exchange_payment_fee_is_quoted_and_recorded_once(): void
    {
        $sale = $this->sale('1', '100');
        $card = PaymentMethod::where('type', 'CARD')->first();
        $card->update(['has_charge' => true, 'charge_type' => 'PERCENTAGE', 'charge_value' => '3', 'charge_bearer' => 'CUSTOMER']);
        $data = $this->salesData($sale, '1', 'WRITEOFF');
        $data['resolution'] = 'OTHER';
        $data['payment_method_id'] = $card->id;
        $data['replacements'] = [['product_id' => $this->product->id, 'quantity' => '2', 'stock_price' => '100']];
        $preview = $this->postJson(route('returns.quote', 'sales'), $data)->assertOk()->json();
        $this->assertSame('3.00', $preview['payment_charge']);
        $r = app(SalesReturnService::class)->complete($sale, $data, $this->admin);
        $this->assertSame('103.00', $r->additional_payment);
        $this->assertSame('0.00', $r->replacement->due_balance);
        $this->assertSame('3.00', $r->replacement->processing_charge);
    }

    public function test_original_fee_refund_policy_preserves_historical_charge(): void
    {
        $sale = $this->sale('2', '200');
        $sale->payments()->update(['charge_bearer' => 'CUSTOMER', 'processing_charge' => '6', 'amount_paid' => '206']);
        $sale->update(['customer_payable' => '206', 'processing_charge' => '6']);
        app(SettingsService::class)->put('returns', ['return_fee_policy' => 'PRO_RATA']);
        $r = app(SalesReturnService::class)->complete($sale, $this->salesData($sale, '1'), $this->admin);
        $this->assertSame('3.00', $r->fee_refund);
        $this->assertSame('103.00', $r->refund_amount);
        $this->assertSame('6.00', $sale->fresh()->customer_fees);
        $r2 = app(SalesReturnService::class)->complete($sale, $this->salesData($sale, '1'), $this->admin);
        $this->assertSame('3.00', $r2->fee_refund);
        $this->assertSame('0.00', $sale->fresh()->due_balance);
    }

    public function test_report_exports_include_complete_return_records(): void
    {
        $sale = $this->sale();
        $r = app(SalesReturnService::class)->complete($sale, $this->salesData($sale), $this->admin);
        $p = $this->purchase('1000');
        $pr = app(PurchaseReturnService::class)->complete($p, $this->purchaseData($p), $this->admin);
        foreach (['returns', 'purchase-returns'] as $kind) {
            $this->get(route('reports.pdf', $kind))->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $response = $this->get(route('reports.export', $kind))->assertOk();
            $this->assertStringContainsString($kind === 'returns' ? $r->reference : $pr->reference, $response->streamedContent());
        }
    }

    public function test_saved_draft_has_no_financial_effect_and_completes_with_same_reference_once(): void
    {
        $sale = $this->sale();
        $data = $this->salesData($sale);
        $stock = $this->product->fresh()->stock;
        $response = $this->postJson(route('returns.draft', 'sales'), $data)->assertOk();
        $r = SaleReturn::where('token', $data['token'])->firstOrFail();
        $this->assertSame('DRAFT', $r->status);
        $this->assertSame($stock, $this->product->fresh()->stock);
        $this->assertSame('0.00', $sale->fresh()->returned_total);
        $this->assertDatabaseCount('return_settlements', 0);
        $this->get(route('returns.create', ['sales', 'draft_id' => $r->id]))->assertOk()->assertSee('Draft '.$r->reference);
        $this->postJson(route('returns.store', 'sales'), $data)->assertOk()->assertJsonPath('reference', $r->reference)->assertJsonPath('auto_print', false);
        $this->assertDatabaseCount('sale_returns', 1);
        $this->assertSame('COMPLETED', $r->fresh()->status);
        app(SettingsService::class)->put('returns', ['return_auto_print' => true]);
        $printed = $this->postJson(route('returns.store', 'sales'), $data)->assertOk()->assertJsonPath('auto_print', true);
        $this->get($printed->json('receipt_url'))->assertOk()->assertSee("window.addEventListener('load',()=>window.print());", false);
        $this->assertDatabaseCount('return_settlements',1);
    }
}
