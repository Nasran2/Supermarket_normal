<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\CustomerLedgerService;
use App\Services\NoReceiptSalesReturnService;
use App\Services\PaymentActivityService;
use App\Services\ProfitLossService;
use App\Services\PurchaseReturnService;
use App\Services\PurchaseService;
use App\Services\RegisterService;
use App\Services\ReportService;
use App\Services\SaleService;
use App\Services\SalesReturnService;
use App\Services\SettingsService;
use App\Services\StockLayerService;
use App\Services\SupplierReturnService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class NoReceiptSalesReturnsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $soap;

    private Product $milk;

    private Customer $customer;

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
        app(SettingsService::class)->put('returns', ['no_receipt_cash_refund' => true, 'no_receipt_cost_method' => 'CURRENT_DEFAULT']);
        $this->customer = Customer::create(['name' => 'No receipt customer', 'phone' => '0771111222', 'active' => true]);
        $this->supplier = Supplier::create(['name' => 'No receipt supplier', 'active' => true]);
        $this->soap = Product::create(['name' => 'NR Soap', 'sku' => 'NR-SOAP', 'barcode' => '9000000000011', 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'cost' => '100', 'price' => '140', 'stock' => '0', 'active' => true]);
        app(StockLayerService::class)->receive($this->soap, '40', '100', '130', 'OPENING_STOCK', 'NR-OPEN-A', $this->admin->id);
        app(StockLayerService::class)->receive($this->soap, '20', '110', '140', 'OPENING_STOCK', 'NR-OPEN-B', $this->admin->id);
        $this->milk = Product::create(['name' => 'NR Milk', 'sku' => 'NR-MILK', 'unit_id' => $this->soap->unit_id, 'cost' => '50', 'price' => '220', 'stock' => '0', 'active' => true]);
        app(StockLayerService::class)->receive($this->milk, '20', '50', '220', 'OPENING_STOCK', 'NR-OPEN-M', $this->admin->id);
    }

    private function data(?Product $product = null): array
    {
        $product ??= $this->soap;

        return ['return_type' => 'NO_RECEIPT', 'token' => (string) Str::uuid(), 'reason' => 'Damaged', 'resolution' => 'MONEY', 'payment_method_id' => $this->cash, 'items' => [['product_id' => $product->id, 'unit_id' => $product->unit_id, 'quantity' => '1', 'stock_action' => 'RESTOCK']]];
    }

    private function sale(Product $product, string $quantity = '1', string $paid = '130', string $discount = '0'): Sale
    {
        $d = ['customer_id' => $this->customer->id, 'discount' => $discount, 'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'stock_price' => $product->id === $this->soap->id ? '130' : '220']], 'payments' => [['payment_method_id' => $this->cash, 'amount' => $paid, 'amount_paid' => $paid]], 'allow_due' => true];
        $q = app(SaleService::class)->quote($d, $this->admin);

        return app(SaleService::class)->complete($d + ['checkout_token' => (string) Str::uuid(), 'quote_hash' => $q['quote_hash']], $this->admin);
    }

    private function complete(array $data): SaleReturn
    {
        return app(NoReceiptSalesReturnService::class)->complete($data, $this->admin);
    }

    private function invalid(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected financial validation.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    private function roleUser(array $permissions): User
    {
        $role = Role::create(['name' => 'No receipt cashier', 'sales_visibility' => 'OWN']);
        $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));

        return User::create(['name' => 'NR Cashier', 'username' => 'nr-cashier', 'email' => 'nr-cashier@example.test', 'password' => bcrypt('test-password'), 'role_id' => $role->id, 'active' => true]);
    }

    public function test_with_bill_remains_default_and_its_financial_workflow_is_unchanged(): void
    {
        $this->get(route('returns.create', 'sales'))->assertOk()->assertSee('Return With Bill')->assertSee('Find bill');
        $s = $this->sale($this->soap, '5', '200');
        $r = app(SalesReturnService::class)->complete($s, ['token' => (string) Str::uuid(), 'reason' => 'Damaged', 'resolution' => 'MONEY', 'items' => [['sale_item_id' => $s->items->first()->id, 'quantity' => '2', 'stock_action' => 'RESTOCK']]], $this->admin);
        $this->assertSame('260.00', $r->due_reduction);
        $this->assertSame('0.00', $r->refund_amount);
        $this->assertSame('INVOICE', $r->return_type);
        $this->assertSame('190.00', $s->fresh()->due_balance);
    }

    public function test_walk_in_restock_creates_a_separate_estimated_layer_and_cash_refund(): void
    {
        $r = $this->complete($this->data());
        $line = $r->items->first();
        $layer = $line->allocations->first()->layer;
        $this->assertNull($r->sale_id);
        $this->assertNull($line->sale_item_id);
        $this->assertSame('UNVERIFIED', $r->verification_status);
        $this->assertSame('130.00', $r->amount);
        $this->assertSame('CURRENT_LOWEST', $line->credit_price_source);
        $this->assertSame('ESTIMATED', $line->cost_basis_type);
        $this->assertSame('100.00', $layer->cost_price);
        $this->assertSame('NO_RECEIPT_SALES_RETURN', $layer->source_type);
        $this->assertNull($layer->purchase_item_id);
        $this->assertSame('61.000', $this->soap->fresh()->stock);
        $this->assertSame('9870.00', app(RegisterService::class)->summary(app(RegisterService::class)->current($this->admin->id))['expected']);
        $this->assertDatabaseHas('return_settlements', ['sale_return_id' => $r->id, 'kind' => 'NO_RECEIPT_SALES_RETURN_REFUND', 'amount' => '-130']);
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_multiple_products_can_use_independent_stock_actions_and_item_reasons(): void
    {
        $d = $this->data();
        $d['items'][0]['quantity'] = '2';
        $d['items'][] = ['product_id' => $this->milk->id, 'quantity' => '1', 'stock_action' => 'WRITEOFF', 'reason' => 'Expired'];
        $r = $this->complete($d);
        $this->assertSame('480.00', $r->amount);
        $this->assertSame('62.000', $this->soap->fresh()->stock);
        $this->assertSame('20.000', $this->milk->fresh()->stock);
        $this->assertSame('50.00', Expense::where('reference', $r->reference)->firstOrFail()->amount);
        $this->assertSame('Expired', $r->items->last()->reason);
    }

    public function test_customer_is_optional_and_unverified_returns_never_automatically_reduce_a_bill(): void
    {
        $sale = $this->sale($this->soap, '5', '200');
        $d = $this->data();
        $d['customer_id'] = $this->customer->id;
        $r = $this->complete($d);
        $this->assertSame('0.00', $r->due_reduction);
        $this->assertSame('450.00', $sale->fresh()->due_balance);
        $this->assertSame('130.00', $r->refund_amount);
        $this->assertSame($this->customer->id, $r->customer_id);
    }

    public function test_customer_history_finds_eligible_sales_and_hides_fully_returned_items(): void
    {
        $sale = $this->sale($this->soap);
        $url = route('returns.no-receipt.matches', ['sales', 'customer_id' => $this->customer->id, 'product_id' => $this->soap->id]);
        $this->getJson($url)->assertOk()->assertJsonPath('matches.0.sale_item_id', $sale->items->first()->id);
        $d = $this->data();
        $d['customer_id'] = $this->customer->id;
        $d['items'][0]['sale_item_id'] = $sale->items->first()->id;
        $this->complete($d);
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'matches')->assertJsonPath('warning', fn ($value) => str_contains($value, 'without a receipt'));
    }

    public function test_linked_item_uses_exact_discounted_original_value_cost_and_due(): void
    {
        $sale = $this->sale($this->soap, '1', '20', '10');
        $d = $this->data();
        $d['customer_id'] = $this->customer->id;
        $d['items'][0]['sale_item_id'] = $sale->items->first()->id;
        $d['items'][0]['credit_price'] = '999';
        $r = $this->complete($d);
        $this->assertSame('120.00', $r->amount);
        $this->assertSame('100.00', $r->historical_cost_total);
        $this->assertSame('100.00', $r->due_reduction);
        $this->assertSame('20.00', $r->refund_amount);
        $this->assertSame('VERIFIED', $r->verification_status);
        $this->assertSame('0.00', $sale->fresh()->due_balance);
        $this->assertSame('120.00', $sale->fresh()->returned_total);
        $this->assertSame('ORIGINAL_SALE', $r->items->first()->credit_price_source);
    }

    public function test_two_historical_invoices_are_linked_per_item_without_a_fake_parent_invoice(): void
    {
        $a = $this->sale($this->soap);
        $b = $this->sale($this->milk, '1', '220');
        $d = $this->data();
        $d['customer_id'] = $this->customer->id;
        $d['items'][0]['sale_item_id'] = $a->items->first()->id;
        $d['items'][] = ['product_id' => $this->milk->id, 'quantity' => '1', 'stock_action' => 'RESTOCK', 'sale_item_id' => $b->items->first()->id];
        $r = $this->complete($d);
        $this->assertNull($r->sale_id);
        $this->assertSame('350.00', $r->verified_amount);
        $this->assertSame('150.00', $r->historical_cost_total);
        $this->assertSame('0.00', $r->estimated_cost_total);
        $this->assertSame('VERIFIED', $r->verification_status);
        $this->assertDatabaseCount('sales', 2);
        $report = app(ReportService::class)->build('sales', ['from' => today()->toDateString(), 'to' => today()->toDateString(), 'q' => $a->invoice]);
        $this->assertSame('130.00', Money::round($report['cards']['Returns in period']));
        $row = $report['map']($a->fresh());
        $costColumn = array_search('Invoice COGS after returns', $report['headers']);
        $this->assertSame('0.00', $row[$costColumn]);
        app(SalesReturnService::class)->cancel($r, 'Wrong linked invoices', $this->admin);
        $report = app(ReportService::class)->build('sales', ['from' => today()->toDateString(), 'to' => today()->toDateString(), 'q' => $a->invoice]);
        $this->assertSame('0.00', Money::round($report['cards']['Returns in period']));

    }

    public function test_mixed_return_separates_historical_reversals_from_estimated_adjustments(): void
    {
        $sale = $this->sale($this->soap, '1', '70', '10');
        $d = $this->data();
        $d['customer_id'] = $this->customer->id;
        $d['items'][0]['sale_item_id'] = $sale->items->first()->id;
        $d['items'][] = ['product_id' => $this->milk->id, 'quantity' => '1', 'stock_action' => 'RESTOCK'];
        $r = $this->complete($d);
        $p = app(ProfitLossService::class)->calculate(today()->toDateString(), today()->toDateString());
        $this->assertSame('PARTIALLY_VERIFIED', $r->verification_status);
        $this->assertSame('120.00', $r->verified_amount);
        $this->assertSame('220.00', $r->unverified_amount);
        $this->assertSame('0.00', $p['cogs']);
        $this->assertSame('0.00', $p['revenue']);
        $this->assertSame('170.00', $p['noReceiptAdjustments']);
        $this->assertSame('-170.00', $p['net']);
    }

    public function test_latest_purchase_cost_is_explicitly_estimated_and_not_a_supplier_provenance_link(): void
    {
        $purchase = app(PurchaseService::class)->save(['supplier_id' => $this->supplier->id, 'purchase_date' => today()->toDateString(), 'auto_reference' => true, 'payment_mode' => 'UNPAID', 'items' => [['product_id' => $this->soap->id, 'quantity' => '5', 'cost' => '105', 'selling_price' => '140']]], $this->admin->id);
        app(SettingsService::class)->put('returns', ['no_receipt_cost_method' => 'LATEST_PURCHASE']);
        $r = $this->complete($this->data());
        $line = $r->items->first();
        $this->assertSame('105.00', $line->cost_basis);
        $this->assertSame('LATEST_PURCHASE', $line->cost_basis_source);
        $this->assertSame($purchase->reference, $line->cost_basis_reference);
        $this->assertNull($line->allocations->first()->layer->purchase_item_id);
    }

    public function test_writeoff_counts_estimated_cost_once_and_never_reverses_unknown_historical_cogs(): void
    {
        $d = $this->data();
        $d['items'][0]['stock_action'] = 'WRITEOFF';
        $r = $this->complete($d);
        $p = app(ProfitLossService::class)->calculate(today()->toDateString(), today()->toDateString());
        $this->assertSame('60.000', $this->soap->fresh()->stock);
        $this->assertSame('0.00', $p['cogs']);
        $this->assertSame('100.00', $p['returnWriteoffs']);
        $this->assertSame('30.00', $p['noReceiptAdjustments']);
        $this->assertSame('-130.00', $p['net']);
        $this->assertNull($r->items->first()->allocations->first()->stock_layer_id);
    }

    public function test_unverified_supplier_claim_requires_explicit_confirmation_and_is_non_sellable(): void
    {
        $d = $this->data();
        $d['items'][0]['stock_action'] = 'SUPPLIER';
        $this->invalid(fn () => $this->complete($d));
        $d['items'][0]['supplier_id'] = $this->supplier->id;
        $r = $this->complete($d);
        $claim = $r->supplierReturns->firstOrFail();
        $this->assertSame($this->supplier->id, $claim->supplier_id);
        $this->assertSame('100.00', $claim->amount);
        $this->assertSame('60.000', $this->soap->fresh()->stock);
        $this->assertNull($claim->items->first()->stock_layer_id);
        $this->get(route('returns.suppliers'))->assertOk()->assertSee($claim->reference)->assertSee('Estimated');
        app(SupplierReturnService::class)->transition($claim, ['action' => 'SEND'], $this->admin);
        app(SupplierReturnService::class)->transition($claim->fresh(), ['action' => 'SETTLE', 'apply_due' => '0', 'payment_method_id' => $this->cash], $this->admin);
        $this->assertSame('SETTLED', $claim->fresh()->status);
    }

    public function test_same_product_exchange_uses_current_real_stock_and_current_selling_price(): void
    {
        $d = $this->data();
        $d['resolution'] = 'SAME';
        $d['replacements'] = [['return_line_index' => 0, 'product_id' => $this->soap->id, 'quantity' => '1', 'stock_price' => '140']];
        $r = $this->complete($d);
        $this->assertSame('140.00', $r->replacement_value);
        $this->assertSame('10.00', $r->additional_payment);
        $this->assertSame('110.00', $r->replacement->cost_total);
        $this->assertSame('60.000', $this->soap->fresh()->stock);
        $this->assertSame('0.00', $r->replacement->due_balance);
    }

    public function test_other_product_exchange_collects_the_difference(): void
    {
        $d = $this->data();
        $d['resolution'] = 'OTHER';
        $d['replacements'] = [['product_id' => $this->milk->id, 'quantity' => '1', 'stock_price' => '220']];
        $r = $this->complete($d);
        $this->assertSame('90.00', $r->additional_payment);
        $this->assertSame('19.000', $this->milk->fresh()->stock);
        $this->assertSame('0.00', $r->replacement->due_balance);
    }

    public function test_smaller_replacement_leaves_a_refundable_credit(): void
    {
        $d = $this->data($this->milk);
        $d['resolution'] = 'OTHER';
        $d['replacements'] = [['product_id' => $this->soap->id, 'quantity' => '1', 'stock_price' => '130']];
        $r = $this->complete($d);
        $this->assertSame('90.00', $r->refund_amount);
    }

    public function test_customer_can_add_exchange_difference_to_due(): void
    {
        $d = $this->data();
        $d['customer_id'] = $this->customer->id;
        $d['resolution'] = 'OTHER';
        $d['add_to_due'] = true;
        $d['replacements'] = [['product_id' => $this->milk->id, 'quantity' => '1', 'stock_price' => '220']];
        $q = app(NoReceiptSalesReturnService::class)->quote($d, $this->admin);
        $r = $this->complete($d);
        $this->assertSame('90.00', $q['customer_due_after']);
        $this->assertSame('90.00', $this->customer->fresh()->due_balance);
        $this->assertSame('90.00', $r->replacement->due_balance);
        $this->assertSame('0.00', $r->additional_payment);
    }

    public function test_explicit_account_credit_is_previewed_oldest_first_and_ledger_matches(): void
    {
        $this->customer->update(['opening_due' => '100']);
        $a = $this->sale($this->soap, '3', '90');
        $b = $this->sale($this->milk, '3', '60');
        $d = $this->data();
        $d['customer_id'] = $this->customer->id;
        $d['items'][0]['quantity'] = '4';
        $d['apply_due'] = '500';
        $q = app(NoReceiptSalesReturnService::class)->quote($d, $this->admin);
        $this->assertSame([['invoice' => 'Opening due', 'amount' => '100.00'], ['sale_id' => $a->id, 'invoice' => $a->invoice, 'amount' => '300.00'], ['sale_id' => $b->id, 'invoice' => $b->invoice, 'amount' => '100.00']], $q['due_allocations']);
        $r = $this->complete($d);
        $this->assertSame('20.00', $r->refund_amount);
        $this->assertSame('500.00', $this->customer->fresh()->due_balance);
        $this->assertSame('500.00', app(CustomerLedgerService::class)->build($this->customer)->last()['balance']);
    }

    public function test_walk_in_cannot_apply_credit_or_add_exchange_difference_to_customer_due(): void
    {
        $d = $this->data();
        $d['apply_due'] = '10';
        $this->invalid(fn () => $this->complete($d));
        $d['apply_due'] = '0';
        $d['credit_customer_id'] = $this->customer->id;
        $this->invalid(fn () => $this->complete($d));
        $d['credit_customer_id'] = null;
        $d['add_to_due'] = true;
        $this->invalid(fn () => $this->complete($d));
    }

    public function test_disabled_no_receipt_cash_refunds_are_rejected_but_noncash_refunds_remain_available(): void
    {
        app(SettingsService::class)->put('returns', ['no_receipt_cash_refund' => false]);
        $d = $this->data();
        $this->invalid(fn () => $this->complete($d));
        $d['payment_method_id'] = PaymentMethod::where('type', 'CARD')->value('id');
        $r = $this->complete($d);
        $this->assertSame('CARD', $r->method_type);
    }

    public function test_cash_refund_requires_current_open_register(): void
    {
        app(RegisterService::class)->current($this->admin->id)->update(['closed_at' => now(), 'open_user_id' => null]);
        $this->invalid(fn () => $this->complete($this->data()));
        $this->assertDatabaseCount('sale_returns', 0);
    }

    public function test_price_override_requires_permission_and_records_suggestion_final_price_actor_and_reason(): void
    {
        $user = $this->roleUser(['sales_returns.create', 'sales_returns.no_receipt', 'sales_returns.refund']);
        $d = $this->data();
        $d['items'][0]['credit_price'] = '150';
        $d['items'][0]['price_override_reason'] = 'Customer confirmed paid amount';
        try {
            app(NoReceiptSalesReturnService::class)->quote($d, $user);
            $this->fail('Override should require permission.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $r = $this->complete($d);
        $line = $r->items->first();
        $this->assertSame('130.00', $line->suggested_credit_price);
        $this->assertSame('150.00', $line->credit_price);
        $this->assertSame($this->admin->id, $line->price_changed_by);
        $this->assertSame('MANUAL', $line->credit_price_source);
    }

    public function test_price_and_manual_cost_confirmation_must_have_a_reason(): void
    {
        $d = $this->data();
        $d['items'][0]['credit_price'] = '150';
        $this->invalid(fn () => $this->complete($d));
        app(SettingsService::class)->put('returns', ['no_receipt_credit_price' => 'MANUAL', 'no_receipt_cost_method' => 'MANUAL']);
        $this->invalid(fn () => $this->complete($this->data()));
        $d['items'][0] += ['cost_basis' => '95', 'notes' => 'Manager selected a conservative estimated cost', 'price_override_reason' => 'Verified customer recollection'];
        $r = $this->complete($d);
        $this->assertSame('95.00', $r->estimated_cost_total);
        $this->assertSame('MANUAL', $r->items->first()->cost_basis_source);
    }

    public function test_quantity_unit_precision_limits_and_days_policy_are_enforced(): void
    {
        $d = $this->data();
        $d['items'][0]['quantity'] = '1.5';
        $this->invalid(fn () => $this->complete($d));
        $d['items'][0]['quantity'] = '3';
        app(SettingsService::class)->put('returns', ['no_receipt_max_quantity' => '2']);
        $this->invalid(fn () => $this->complete($d));
        app(SettingsService::class)->put('returns', ['no_receipt_days_limit' => 30]);
        $d = $this->data();
        $this->invalid(fn () => $this->complete($d));
        $d['items'][0]['purchased_on'] = today()->subDays(31)->toDateString();
        $this->invalid(fn () => $this->complete($d));
        $d['items'][0]['purchased_on'] = today()->subDays(10)->toDateString();
        $this->assertSame('130.00', $this->complete($d)->amount);
    }

    public function test_decimal_and_box_units_restore_correct_primary_quantities(): void
    {
        $kg = Unit::where('short_name', 'kg')->firstOrFail();
        $rice = Product::create(['name' => 'NR Rice', 'sku' => 'NR-RICE', 'unit_id' => $kg->id, 'cost' => '100', 'price' => '200', 'stock' => '0', 'active' => true]);
        $d = $this->data($rice);
        $d['items'][0]['quantity'] = '0.500';
        $r = $this->complete($d);
        $this->assertSame('0.500', $rice->fresh()->stock);
        $this->assertSame('100.00', $r->amount);
        $box = Unit::create(['name' => 'NR Box', 'short_name' => 'nrbox', 'allow_decimal' => false, 'active' => true]);
        $this->soap->conversions()->create(['unit_id' => $box->id, 'base_quantity' => '12', 'converted_quantity' => '1']);
        $d = $this->data();
        $d['items'][0]['unit_id'] = $box->id;
        $r = $this->complete($d);
        $this->assertSame('12.000', $r->items->first()->base_quantity);
        $this->assertSame('72.000', $this->soap->fresh()->stock);
    }

    public function test_product_search_supports_barcode_sku_category_and_multiple_current_prices_without_cost_leak(): void
    {
        $user = $this->roleUser(['sales_returns.create', 'sales_returns.no_receipt']);
        $this->actingAs($user);
        $response = $this->getJson(route('returns.no-receipt.products', ['sales', 'q' => $this->soap->barcode]))->assertOk()->assertJsonCount(1)->assertJsonPath('0.units.0.suggestion.suggested_credit_price', '130.00');
        $this->assertArrayNotHasKey('cost_basis', $response->json('0.units.0.suggestion'));
        $this->assertArrayNotHasKey('cost', $response->json('0.units.0'));
        $this->getJson(route('returns.no-receipt.products', ['sales', 'q' => 'NR-MILK']))->assertOk()->assertJsonPath('0.id', $this->milk->id);
        $category = Category::firstOrFail();
        $this->milk->categories()->attach($category);
        $this->getJson(route('returns.no-receipt.products', ['sales', 'category_id' => $category->id]))->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $this->milk->id);
    }

    public function test_linking_prevents_overreturns_cross_customer_matches_and_duplicate_sale_lines(): void
    {
        $sale = $this->sale($this->soap);
        $d = $this->data();
        $d['customer_id'] = $this->customer->id;
        $d['items'][0]['sale_item_id'] = $sale->items->first()->id;
        $d['items'][0]['quantity'] = '2';
        $this->invalid(fn () => $this->complete($d));
        $d['items'][0]['quantity'] = '1';
        $d['customer_id'] = Customer::create(['name' => 'Another', 'active' => true])->id;
        $this->invalid(fn () => $this->complete($d));
        $d['customer_id'] = $this->customer->id;
        $d['items'][] = $d['items'][0];
        $this->invalid(fn () => $this->complete($d));
    }

    public function test_cancellation_reverses_stock_cash_due_estimates_and_writeoffs_without_deleting_history(): void
    {
        $this->customer->update(['opening_due' => '100']);
        $d = $this->data();
        $d['customer_id'] = $this->customer->id;
        $d['apply_due'] = '100';
        $d['items'][] = ['product_id' => $this->milk->id, 'quantity' => '1', 'stock_action' => 'WRITEOFF'];
        $r = $this->complete($d);
        app(SalesReturnService::class)->cancel($r, 'Customer withdrew request', $this->admin);
        $this->assertSame('CANCELLED', $r->fresh()->status);
        $this->assertSame('60.000', $this->soap->fresh()->stock);
        $this->assertSame('100.00', $this->customer->fresh()->due_balance);
        $this->assertSame('10000.00', app(RegisterService::class)->summary(app(RegisterService::class)->current($this->admin->id))['expected']);
        $p = app(ProfitLossService::class)->calculate(today()->toDateString(), today()->toDateString());
        $this->assertSame('0.00', $p['net']);
        $this->assertSame('REVERSED', Expense::where('reference', $r->reference)->first()->status);
        $this->assertDatabaseCount('sale_returns', 1);
    }

    public function test_cancelled_linked_return_restores_original_eligibility_due_and_cogs(): void
    {
        $sale = $this->sale($this->soap, '1', '20');
        $d = $this->data();
        $d['customer_id'] = $this->customer->id;
        $d['items'][0]['sale_item_id'] = $sale->items->first()->id;
        $r = $this->complete($d);
        app(SalesReturnService::class)->cancel($r, 'Incorrect linked product', $this->admin);
        $this->assertSame('110.00', $sale->fresh()->due_balance);
        $this->assertSame('0.00', $sale->fresh()->returned_total);
        $this->assertTrue($sale->fresh()->has_returnable_items);
        $p = app(ProfitLossService::class)->calculate(today()->toDateString(), today()->toDateString());
        $this->assertSame('100.00', $p['cogs']);
        $this->assertSame('30.00', $p['net']);
    }

    public function test_retries_do_not_duplicate_stock_or_refund_and_stale_quotes_are_rejected(): void
    {
        $d = $this->data();
        $q = app(NoReceiptSalesReturnService::class)->quote($d, $this->admin);
        $this->soap->update(['cost' => '99']);
        $this->invalid(fn () => $this->complete($d + ['quote_hash' => $q['quote_hash']]));
        $r = $this->complete($d);
        $again = $this->complete($d);
        $this->assertSame($r->id, $again->id);
        $this->assertDatabaseCount('return_settlements', 1);
        $this->assertSame('61.000', $this->soap->fresh()->stock);
    }

    public function test_no_receipt_permission_manager_threshold_and_feature_switch_cannot_be_bypassed(): void
    {
        $user = $this->roleUser(['sales_returns.create', 'sales_returns.no_receipt', 'sales_returns.refund']);
        app(SettingsService::class)->put('returns', ['no_receipt_approval_above' => '100']);
        try {
            app(NoReceiptSalesReturnService::class)->quote($this->data(), $user);
            $this->fail('Approval required.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        app(SettingsService::class)->put('returns', ['no_receipt_enabled' => false]);
        $this->invalid(fn () => $this->complete($this->data()));
    }

    public function test_reports_receipts_profiles_cash_payment_activity_and_drafts_handle_missing_invoice(): void
    {
        $d = $this->data();
        $d['customer_id'] = $this->customer->id;
        $draft = $this->postJson(route('returns.draft', 'sales'), $d)->assertOk();
        $r = SaleReturn::where('token', $d['token'])->firstOrFail();
        $this->assertSame('60.000', $this->soap->fresh()->stock);
        $this->assertDatabaseCount('return_settlements', 0);
        $this->get(route('returns.create', ['sales', 'draft_id' => $r->id]))->assertOk()->assertSee('Return Without Bill');
        $this->postJson(route('returns.store', 'sales'), $d)->assertOk()->assertJsonPath('reference', $draft->json('reference'));
        $r->refresh();
        $this->get(route('returns.show', ['sales', $r->id]))->assertOk()->assertSee('No original invoice linked')->assertSee('ESTIMATED');
        $this->get(route('returns.receipt', ['sales', $r->id]))->assertOk()->assertSee('No Receipt')->assertSee($this->customer->name);
        $this->get(route('manage.show', ['customers', $this->customer->id]))->assertOk()->assertSee($r->reference);
        $this->get(route('returns.index', ['sales', 'return_type' => 'NO_RECEIPT', 'verification_status' => 'UNVERIFIED']))->assertOk()->assertSee($r->reference);
        foreach (['returns', 'profit', 'cash', 'payments'] as $report) {
            $this->get(route('reports.show', $report))->assertOk();
            $this->get(route('reports.pdf', $report))->assertOk();
        }
        $csv = $this->get(route('reports.export', ['returns', 'return_type' => 'NO_RECEIPT']))->assertOk()->streamedContent();
        $this->assertStringContainsString('NO_RECEIPT', $csv);
        $activity = app(PaymentActivityService::class)->totals(app(PaymentActivityService::class)->query(['from' => today()->toDateString(), 'to' => today()->toDateString()]));
        $this->assertSame('130.00', $activity['refunded']);
        $this->assertSame('-130.00', $activity['net']);
        $this->get(route('settings.edit', 'returns'))->assertOk()->assertSee('Latest known purchase cost');
    }

    public function test_no_receipt_documents_and_matching_history_respect_own_sales_visibility(): void
    {
        $sale = $this->sale($this->soap);
        $return = $this->complete($this->data());
        $user = $this->roleUser(['sales_returns.view', 'sales_returns.create', 'sales_returns.no_receipt', 'sales_returns.refund']);
        $this->actingAs($user);
        $this->get(route('returns.index', 'sales'))->assertOk()->assertDontSee($return->reference);
        $this->get(route('returns.show', ['sales', $return->id]))->assertForbidden();
        $this->get(route('returns.receipt', ['sales', $return->id]))->assertForbidden();
        $this->getJson(route('returns.no-receipt.matches', ['sales', 'customer_id' => $this->customer->id, 'product_id' => $this->soap->id]))->assertOk()->assertJsonCount(0, 'matches');
        $data = $this->data();
        $data['payment_method_id'] = PaymentMethod::where('type', 'CARD')->value('id');
        $quote = $this->postJson(route('returns.quote', 'sales'), $data)->assertOk()->json();
        $this->assertArrayNotHasKey('cost_total', $quote);
        $this->assertArrayNotHasKey('cost_basis', $quote['lines'][0]);
        $this->assertArrayNotHasKey('allocations', $quote['lines'][0]);
        $data['customer_id'] = $this->customer->id;
        $data['items'][0]['sale_item_id'] = $sale->items->first()->id;
        $this->postJson(route('returns.quote', 'sales'), $data)->assertForbidden();
    }

    public function test_original_fee_refunds_are_shared_with_bill_returns_and_preserve_item_notes(): void
    {
        $sale = $this->sale($this->soap, '2', '260');
        $sale->payments()->update(['charge_bearer' => 'CUSTOMER', 'processing_charge' => '6', 'amount_paid' => '266']);
        $sale->update(['customer_payable' => '266', 'processing_charge' => '6']);
        app(SettingsService::class)->put('returns', ['return_fee_policy' => 'PRO_RATA']);
        $data = $this->data();
        $data['customer_id'] = $this->customer->id;
        $data['items'][0]['sale_item_id'] = $sale->items->first()->id;
        $data['items'][0]['notes'] = 'Customer confirmed this original purchase';
        $first = $this->complete($data);
        $this->assertSame('3.00', $first->fee_refund);
        $this->assertSame('133.00', $first->refund_amount);
        $this->assertSame($data['items'][0]['notes'], $first->items->first()->notes);
        $second = app(SalesReturnService::class)->complete($sale, ['token' => (string) Str::uuid(), 'reason' => 'Damaged', 'resolution' => 'MONEY', 'payment_method_id' => $this->cash, 'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => '1', 'stock_action' => 'RESTOCK']]], $this->admin);
        $this->assertSame('3.00', $second->fee_refund);
        $this->assertSame('133.00', $second->refund_amount);
        $this->assertSame('0.00', $sale->fresh()->due_balance);
    }

    public function test_unverified_exchange_uses_existing_inventory_and_cancellation_reverses_both_documents(): void
    {
        $data = $this->data();
        $data['resolution'] = 'SAME';
        $data['replacements'] = [['product_id' => $this->soap->id, 'quantity' => '1', 'stock_price' => '130', 'return_line_index' => 0]];
        $return = $this->complete($data);
        $returnedLayer = $return->items->first()->allocations->first()->layer;
        $this->assertSame('1.000', $returnedLayer->remaining_quantity);
        $this->assertSame('OPENING_STOCK', $return->replacement->items->first()->allocations->first()->layer->source_type);
        app(SalesReturnService::class)->cancel($return, 'Exchange cancelled', $this->admin);
        $this->assertSame('RETURN_CANCELLED', $return->replacement->fresh()->status);
        $this->assertSame('0.000', $returnedLayer->fresh()->remaining_quantity);
        $this->assertSame('60.000', $this->soap->fresh()->stock);
        $this->assertSame('0.00', app(ProfitLossService::class)->calculate(today()->toDateString(), today()->toDateString())['net']);
    }

    public function test_purchase_return_detail_and_printing_continue_to_use_purchase_relations(): void
    {
        $purchase = app(PurchaseService::class)->save(['supplier_id' => $this->supplier->id, 'purchase_date' => today()->toDateString(), 'auto_reference' => true, 'payment_mode' => 'AUTO', 'amount_paid' => '100', 'payment_method_id' => $this->cash, 'items' => [['product_id' => $this->soap->id, 'quantity' => '1', 'cost' => '100', 'selling_price' => '150']]], $this->admin->id);
        $return = app(PurchaseReturnService::class)->complete($purchase, ['token' => (string) Str::uuid(), 'reason' => 'Damaged', 'resolution' => 'MONEY', 'payment_method_id' => $this->cash, 'items' => [['purchase_item_id' => $purchase->items->first()->id, 'quantity' => '1']]], $this->admin);
        $this->get(route('returns.show', ['purchase', $return->id]))->assertOk()->assertSee($this->soap->name);
        $this->get(route('returns.receipt', ['purchase', $return->id]))->assertOk()->assertSee($purchase->reference);
    }
}
