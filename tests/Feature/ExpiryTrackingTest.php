<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductStockLayer;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\ExpiryReportService;
use App\Services\ExpiryWriteoffService;
use App\Services\NoReceiptSalesReturnService;
use App\Services\PdfReportService;
use App\Services\ProfitLossService;
use App\Services\PurchaseReturnService;
use App\Services\PurchaseService;
use App\Services\RegisterService;
use App\Services\SaleService;
use App\Services\SalesReturnService;
use App\Services\SettingsService;
use App\Services\StockLayerService;
use App\Support\Expiry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ExpiryTrackingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Unit $piece;

    private Supplier $supplier;

    private int $cash;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.expiry' => true]);
        $this->seed();
        $this->admin = User::firstOrFail();
        $this->actingAs($this->admin);
        $this->piece = Unit::where('short_name', 'pcs')->firstOrFail();
        $this->supplier = Supplier::create(['name' => 'Expiry supplier', 'active' => true]);
        $this->cash = PaymentMethod::where('type', 'CASH')->value('id');
        $this->travelTo(Carbon::parse('2026-10-11 12:00:00', 'Asia/Colombo'));
        app(RegisterService::class)->open($this->admin->id, '10000');
    }

    private function product(bool $tracks = true): Product
    {
        return Product::create(['name' => 'Expiry Milk '.Str::random(4), 'sku' => Str::random(10), 'unit_id' => $this->piece->id, 'supplier_id' => $this->supplier->id, 'price' => '130', 'cost' => '100', 'stock' => '0', 'low_stock' => '5', 'active' => true, 'track_expiry' => $tracks]);
    }

    private function layer(Product $p, string $qty = '10', string $date = '2027-01-15', string $price = '130', string $cost = '100'): ProductStockLayer
    {
        return app(StockLayerService::class)->receive($p, $qty, $cost, $price, 'OPENING_STOCK', 'TEST-'.Str::random(5), $this->admin->id, expiresAt: $date, batchNumber: 'BATCH-A');
    }

    private function data(Product $p, string $qty = '1', ?string $date = '2027-01-15', string $price = '130'): array
    {
        return ['items' => [['product_id' => $p->id, 'quantity' => $qty, 'stock_price' => $price, 'expires_at' => $date]], 'payment_method_id' => $this->cash];
    }

    private function sale(array $data): Sale
    {
        $q = app(SaleService::class)->quote($data, $this->admin);

        return app(SaleService::class)->complete($data + ['checkout_token' => (string) Str::uuid(), 'quote_hash' => $q['quote_hash'], 'amount_paid' => $q['customer_payable']], $this->admin);
    }

    private function rejects(callable $fn, string $contains = ''): void
    {
        try {
            $fn();
            $this->fail('Expected validation failure');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
            if ($contains) {
                $this->assertStringContainsString($contains, $e->getMessage());
            }
        }
    }

    private function purchase(Product $p, string $date = '2027-01-15', string $qty = '10', string $price = '130')
    {
        return app(PurchaseService::class)->save(['supplier_id' => $this->supplier->id, 'purchase_date' => '2026-10-11', 'auto_reference' => true, 'payment_mode' => 'UNPAID', 'items' => [['product_id' => $p->id, 'quantity' => $qty, 'cost' => '100', 'selling_price' => $price, 'expires_at' => $date, 'batch_number' => 'PUR-BATCH']]], $this->admin->id);
    }

    public function test_disabled_routes_ui_and_tracking_are_hidden(): void
    {
        config(['features.expiry' => false]);
        foreach (['expiry.index', 'settings.edit'] as $route) {
            $this->get(route($route, $route === 'settings.edit' ? 'expiry' : []))->assertNotFound();
        }
        $this->put(route('settings.update', 'expiry'), [])->assertNotFound();
        $this->post(route('expiry.writeoff', 1), [])->assertNotFound();
        $this->get(route('settings.index'))->assertOk()->assertDontSee('Batch dates, cashier');
        $this->get(route('manage.create', 'products'))->assertOk()->assertDontSee('id="track-expiry"', false)->assertDontSee('data-expiry-field', false);
        $this->get(route('purchases.create'))->assertOk()->assertDontSee('data-purchase-expiry', false);
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Expiring soon');
    }

    public function test_disabling_preserves_batches_and_allows_normal_sale_without_date(): void
    {
        $p = $this->product();
        $l = $this->layer($p, '10', '2026-10-01');
        config(['features.expiry' => false]);
        $sale = $this->sale($this->data($p, '1', null));
        $this->assertSame('9.000', $l->fresh()->remaining_quantity);
        $this->assertSame('2026-10-01', $l->fresh()->expires_at->toDateString());
        config(['features.expiry' => true]);
        $this->assertSame([], app(StockLayerService::class)->choices($p->fresh()));
        $this->assertSame('2026-10-01', $sale->items->first()->allocations->first()->expires_at);
    }

    public function test_existing_and_new_products_default_to_no_expiry(): void
    {
        $p = $this->product(false);
        $this->assertFalse($p->fresh()->track_expiry);
        $this->assertFalse((bool) Expiry::setting('expiry_default_tracking'));
        $this->assertSame('ASK', Expiry::setting('expiry_selection_mode'));
    }

    public function test_opening_rows_keep_distinct_dates_and_costs(): void
    {
        $sku = Str::random(9);
        $this->post(route('manage.store', 'products'), ['name' => 'Opening milk', 'sku' => $sku, 'unit_id' => $this->piece->id, 'low_stock' => '5', 'active' => 1, 'track_stock' => 1, 'track_expiry' => 1, 'opening_layers' => [['quantity' => '20', 'cost' => '100', 'selling_price' => '130', 'expires_at' => '2027-01-15'], ['quantity' => '15', 'cost' => '110', 'selling_price' => '140', 'expires_at' => '2027-04-15']]])->assertSessionHasNoErrors()->assertRedirect();
        $p = Product::where('sku', $sku)->firstOrFail();
        $this->assertSame('35.000', $p->stock);
        $this->assertCount(2, $p->stockLayers);
        $this->assertSame(['2027-01-15', '2027-04-15'], $p->stockLayers->map(fn ($l) => $l->expires_at->toDateString())->all());
    }

    public function test_expiry_product_requires_stock_tracking(): void
    {
        $this->post(route('manage.store', 'products'), ['name' => 'No stock milk', 'sku' => Str::random(10), 'unit_id' => $this->piece->id, 'cost' => 0, 'price' => 100, 'stock' => 0, 'low_stock' => 0, 'active' => 1, 'track_stock' => 0, 'track_expiry' => 1])->assertSessionHasErrors('track_expiry');
    }

    public function test_purchase_same_prices_different_dates_remain_separate(): void
    {
        $p = $this->product();
        $a = $this->purchase($p);
        $b = $this->purchase($p, '2027-04-15');
        $this->assertSame('20.000', $p->fresh()->stock);
        $this->assertCount(2, $p->stockLayers);
        $this->assertSame('2027-01-15', $a->items->first()->expires_at);
        $this->assertSame('PUR-BATCH', $b->items->first()->batch_number);
        $this->assertCount(2, app(StockLayerService::class)->choices($p->fresh()));
    }

    public function test_purchase_requires_valid_future_date(): void
    {
        $p = $this->product();
        foreach (['', '2026-10-01', '2026-10-11', '2027-02-30'] as $date) {
            $this->rejects(fn () => $this->purchase($p, $date));
        }
        $this->assertDatabaseCount('purchases', 0);
        $this->assertSame('0.000', $p->fresh()->stock);
    }

    public function test_untracked_product_purchase_needs_no_date(): void
    {
        $p = $this->product(false);
        $purchase = $this->purchase($p, '');
        $this->assertNull($purchase->items->first()->expires_at);
        $this->assertNull($p->stockLayers->first()->expires_at);
    }

    public function test_disabled_purchase_edit_retains_original_dates(): void
    {
        $p = $this->product();
        $purchase = $this->purchase($p);
        $item = $purchase->items->first();
        config(['features.expiry' => false]);
        app(PurchaseService::class)->save(['supplier_id' => $this->supplier->id, 'purchase_date' => '2026-10-11', 'reference' => $purchase->reference, 'items' => [['purchase_item_id' => $item->id, 'product_id' => $p->id, 'quantity' => '10', 'cost' => '100', 'selling_price' => '130']]], $this->admin->id, $purchase);
        $this->assertSame('2027-01-15', $purchase->fresh()->items->first()->expires_at);
        $this->assertSame('2027-01-15', $p->stockLayers()->available()->first()->expires_at->toDateString());
    }

    public function test_one_choice_contains_date_and_no_costs(): void
    {
        $p = $this->product();
        $this->layer($p);
        $row = $this->getJson(route('pos.products', ['q' => $p->sku]))->assertOk()->json('0');
        $this->assertCount(1, $row['price_options']);
        $this->assertSame('2027-01-15', $row['price_options'][0]['expires_at']);
        $this->assertArrayNotHasKey('cost', $row['price_options'][0]);
        $this->assertArrayNotHasKey('cost', $row['price_options'][0]['units'][0]);
    }

    public function test_multiple_prices_dates_form_one_choice_list(): void
    {
        $p = $this->product();
        $this->layer($p);
        $this->layer($p, '15', '2027-04-15', '140');
        $this->layer($p, '5', '2027-07-15', '140');
        $choices = app(StockLayerService::class)->choices($p->fresh());
        $this->assertCount(3, $choices);
        $this->assertTrue($choices[0]['recommended']);
    }

    public function test_same_price_and_date_group_different_costs(): void
    {
        $p = $this->product();
        $a = $this->layer($p, '2');
        $b = $this->layer($p, '3', '2027-01-15', '130', '110');
        $choices = app(StockLayerService::class)->choices($p->fresh());
        $this->assertCount(1, $choices);
        $this->assertSame('5.000', $choices[0]['quantity']);
        $sale = $this->sale($this->data($p, '4'));
        $this->assertSame('420.00', $sale->cost_total);
        $this->assertSame('0.000', $a->fresh()->remaining_quantity);
        $this->assertSame('1.000', $b->fresh()->remaining_quantity);
    }

    public function test_expired_and_depleted_batches_are_not_pos_choices(): void
    {
        $p = $this->product();
        $this->layer($p, '5', '2026-10-01');
        $this->layer($p)->update(['remaining_quantity' => 0]);
        $this->layer($p, '2', '2026-10-11');
        $choices = app(StockLayerService::class)->choices($p->fresh());
        $this->assertCount(1, $choices);
        $this->assertSame('2026-10-11', $choices[0]['expires_at']);
        $this->postJson(route('pos.quote'), $this->data($p, '1', '2026-10-01'))->assertUnprocessable();
    }

    public function test_explicit_batch_never_borrows_other_expiry_stock(): void
    {
        $p = $this->product();
        $this->layer($p, '2');
        $this->layer($p, '10', '2027-04-15');
        $this->rejects(fn () => app(SaleService::class)->quote($this->data($p, '5'), $this->admin), 'selected expiry batch');
    }

    public function test_ask_mode_requires_choice_with_multiple_dates(): void
    {
        $p = $this->product();
        $this->layer($p);
        $this->layer($p, '10', '2027-04-15');
        $this->rejects(fn () => app(SaleService::class)->quote($this->data($p, '1', null), $this->admin), 'Choose an expiry');
    }

    public function test_fefo_splits_quantity_by_earliest_date_at_same_price(): void
    {
        app(SettingsService::class)->put('expiry', ['expiry_selection_mode' => 'FEFO']);
        $p = $this->product();
        $later = $this->layer($p, '10', '2027-04-15');
        $earlier = $this->layer($p, '2');
        $choices = app(StockLayerService::class)->choices($p->fresh());
        $this->assertCount(1, $choices);
        $this->assertTrue($choices[0]['fefo']);
        $sale = $this->sale($this->data($p, '5', null));
        $this->assertSame('0.000', $earlier->fresh()->remaining_quantity);
        $this->assertSame('7.000', $later->fresh()->remaining_quantity);
        $this->assertCount(2, $sale->items->first()->allocations);
    }

    public function test_fefo_does_not_borrow_from_another_price(): void
    {
        app(SettingsService::class)->put('expiry', ['expiry_selection_mode' => 'FEFO']);
        $p = $this->product();
        $this->layer($p, '2');
        $this->layer($p, '10', '2027-04-15', '140');
        $this->assertCount(2, app(StockLayerService::class)->choices($p->fresh()));
        $this->rejects(fn () => app(SaleService::class)->quote($this->data($p, '5', null), $this->admin));
    }

    public function test_stale_checkout_rolls_back_when_batch_changes(): void
    {
        $p = $this->product();
        $layer = $this->layer($p);
        $d = $this->data($p);
        $q = app(SaleService::class)->quote($d, $this->admin);
        $layer->update(['expires_at' => '2027-04-15']);
        $this->rejects(fn () => app(SaleService::class)->complete($d + ['checkout_token' => (string) Str::uuid(), 'quote_hash' => $q['quote_hash'], 'amount_paid' => $q['customer_payable']], $this->admin));
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame('10.000', $p->fresh()->stock);
    }

    public function test_expired_sale_override_needs_policy_permission_and_confirmation(): void
    {
        $p = $this->product();
        $this->layer($p, '10', '2026-10-01');
        app(SettingsService::class)->put('expiry', ['expiry_block_expired' => false, 'expiry_allow_override' => true]);
        $d = $this->data($p, '1', '2026-10-01');
        $this->rejects(fn () => app(SaleService::class)->quote($d, $this->admin));
        $d['items'][0]['expired_confirmed'] = true;
        $sale = $this->sale($d);
        $this->assertSame('2026-10-01', $sale->items->first()->allocations->first()->expires_at);
    }

    public function test_alert_window_dashboard_and_report_use_remaining_stock(): void
    {
        $p = $this->product();
        $near = $this->layer($p, '10', Expiry::today()->addDays(45)->toDateString());
        $this->layer($p, '10', Expiry::today()->addDays(46)->toDateString());
        $this->layer($p, '10', Expiry::today()->addDays(5)->toDateString())->update(['remaining_quantity' => 0]);
        $this->assertSame([$near->id], app(ExpiryReportService::class)->query()->pluck('id')->all());
        $this->get(route('dashboard'))->assertOk()->assertSee('Expiring soon');
        app(SettingsService::class)->put('expiry', ['expiry_alert_days' => 30]);
        $this->assertSame(0, app(ExpiryReportService::class)->query()->count());
    }

    public function test_multi_unit_deducts_only_selected_expiry_base_quantity(): void
    {
        $p = $this->product();
        $a = $this->layer($p, '24');
        $b = $this->layer($p, '24', '2027-04-15');
        $box = Unit::create(['name' => 'Expiry box', 'short_name' => 'exbox', 'allow_decimal' => false, 'active' => true]);
        ProductUnit::create(['product_id' => $p->id, 'unit_id' => $box->id, 'base_quantity' => '12', 'converted_quantity' => '1', 'price' => null]);
        $data = $this->data($p);
        $data['items'][0]['unit_id'] = $box->id;
        $sale = $this->sale($data);
        $this->assertSame('12.000', $a->fresh()->remaining_quantity);
        $this->assertSame('24.000', $b->fresh()->remaining_quantity);
        $this->assertSame('12.000', $sale->items->first()->base_quantity);
    }

    public function test_sales_return_restores_original_expiry_only(): void
    {
        $p = $this->product();
        $a = $this->layer($p, '10');
        $b = $this->layer($p, '10', '2027-04-15');
        $sale = $this->sale($this->data($p, '2'));
        $d = ['token' => (string) Str::uuid(), 'original_id' => $sale->id, 'reason' => 'Customer return', 'resolution' => 'MONEY', 'payment_method_id' => $this->cash, 'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => '1', 'stock_action' => 'RESTOCK']]];
        $r = app(SalesReturnService::class)->complete($sale, $d, $this->admin);
        $this->assertSame('9.000', $a->fresh()->remaining_quantity);
        $this->assertSame('10.000', $b->fresh()->remaining_quantity);
        $this->assertSame($a->id, $r->items->first()->allocations->first()->stock_layer_id);
    }

    public function test_now_expired_sales_return_requires_disposal_or_supplier_action(): void
    {
        $p = $this->product();
        $this->layer($p, '10', '2026-10-12');
        $sale = $this->sale($this->data($p, '1', '2026-10-12'));
        $this->travel(2)->days();
        $d = ['token' => (string) Str::uuid(), 'original_id' => $sale->id, 'reason' => 'Returned expired', 'resolution' => 'MONEY', 'payment_method_id' => $this->cash, 'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => '1', 'stock_action' => 'RESTOCK']]];
        $this->rejects(fn () => app(SalesReturnService::class)->complete($sale, $d, $this->admin), 'cannot be returned');
        $d['items'][0]['stock_action'] = 'WRITEOFF';
        $r = app(SalesReturnService::class)->complete($sale, $d, $this->admin);
        $this->assertSame('WRITEOFF', $r->items->first()->stock_action);
        $this->assertSame('9.000', $p->fresh()->stock);
    }

    public function test_purchase_return_deducts_original_purchase_batch(): void
    {
        $p = $this->product();
        $first = $this->purchase($p);
        $second = $this->purchase($p, '2027-04-15');
        $item = $first->items->first();
        $d = ['token' => (string) Str::uuid(), 'original_id' => $first->id, 'reason' => 'Supplier recall', 'resolution' => 'MONEY', 'payment_method_id' => $this->cash, 'items' => [['purchase_item_id' => $item->id, 'quantity' => '3']]];
        app(PurchaseReturnService::class)->complete($first, $d, $this->admin);
        $this->assertSame('7.000', $item->stockLayers->first()->fresh()->remaining_quantity);
        $this->assertSame('10.000', $second->items->first()->stockLayers->first()->remaining_quantity);
    }

    public function test_expired_writeoff_records_inventory_cost_in_profit_once(): void
    {
        $p = $this->product();
        $l = $this->layer($p, '5', '2026-10-01');
        $expense = app(ExpiryWriteoffService::class)->writeoff($l->id, '5', 'Expired milk', $this->admin);
        $this->assertSame('500.00', $expense->amount);
        $this->assertSame('0.000', $p->fresh()->stock);
        $this->assertSame('500.00', app(ProfitLossService::class)->calculate('2026-10-11', '2026-10-11')['expenses']);
        $this->rejects(fn () => app(ExpiryWriteoffService::class)->writeoff($l->id, '5', 'Repeat', $this->admin));
        $this->assertDatabaseCount('expiry_writeoffs', 1);
        $this->assertSame('500.00', app(ProfitLossService::class)->calculate('2026-10-11', '2026-10-11')['expenses']);
    }

    public function test_receipt_setting_and_cost_permissions_are_enforced(): void
    {
        $p = $this->product();
        $this->layer($p);
        $sale = $this->sale($this->data($p));
        $this->get(route('sales.receipt', $sale))->assertOk()->assertDontSee('Exp:');
        app(SettingsService::class)->put('expiry', ['expiry_show_receipt' => true]);
        $this->get(route('sales.receipt', $sale))->assertOk()->assertSee('Exp: 15/01/2027');
        $role = Role::create(['name' => 'Expiry viewer', 'sales_visibility' => 'ALL']);
        $role->permissions()->sync(Permission::whereIn('name', ['expiry.view', 'pos.access', 'sales.create'])->pluck('id'));
        $cashier = User::create(['name' => 'Expiry cashier', 'username' => 'expiry-cashier', 'email' => 'expiry-cashier@example.test', 'password' => 'password', 'role_id' => $role->id, 'active' => true]);
        $this->flushSession();
        $this->actingAs($cashier);
        $this->get(route('expiry.index', ['tab' => 'all']))->assertOk()->assertDontSee('Cost value')->assertDontSee('Confirm write-off');
        $this->post(route('expiry.writeoff', 1), ['quantity' => 1, 'reason' => 'Expired stock'])->assertForbidden();
        $this->get(route('settings.edit', 'expiry'))->assertForbidden();
    }

    public function test_unknown_expiry_stock_is_not_sellable_and_low_stock_uses_valid_stock(): void
    {
        $p = $this->product();
        $this->layer($p, '20', '2026-10-01');
        $this->layer($p, '2');
        $this->layer($p, '10')->update(['expires_at' => null]);
        $this->assertTrue(Product::whereKey($p->id)->lowStock()->exists());
        $choices = app(StockLayerService::class)->choices($p->fresh());
        $this->assertSame('2.000', $choices[0]['quantity']);
    }

    public function test_adjustment_keeps_selected_date_or_requires_new_batch_date(): void
    {
        $p = $this->product();
        $l = $this->layer($p);
        app(StockLayerService::class)->adjust($p, '2', $l->id, '100', '130', 'ADJ-T', 'Count correction', $this->admin->id);
        $new = $p->stockLayers()->orderByDesc('id')->first();
        $this->assertSame('2027-01-15', $new->expires_at->toDateString());
        $this->rejects(fn () => app(StockLayerService::class)->adjust($p, '2', null, '100', '130', 'ADJ-N', 'Count correction', $this->admin->id));
    }

    public function test_no_receipt_return_requires_known_expiry_and_records_entered_date(): void
    {
        $p = $this->product();
        $this->layer($p);
        app(SettingsService::class)->put('returns', ['no_receipt_cash_refund' => true, 'no_receipt_cost_method' => 'CURRENT_DEFAULT']);
        $data = ['return_type' => 'NO_RECEIPT', 'token' => (string) Str::uuid(), 'reason' => 'Customer returned product', 'resolution' => 'MONEY', 'payment_method_id' => $this->cash, 'items' => [['product_id' => $p->id, 'unit_id' => $p->unit_id, 'quantity' => '1', 'stock_action' => 'RESTOCK']]];
        $this->rejects(fn () => app(NoReceiptSalesReturnService::class)->complete($data, $this->admin));
        $data['items'][0]['expires_at'] = '2027-03-15';
        $data['items'][0]['batch_number'] = 'RETURN-MARCH';
        $return = app(NoReceiptSalesReturnService::class)->complete($data, $this->admin);
        $layer = $return->items->first()->allocations->first()->layer;
        $this->assertSame('2027-03-15', $layer->expires_at->toDateString());
        $this->assertSame('RETURN-MARCH', $layer->batch_number);
        $this->assertSame('NO_RECEIPT_SALES_RETURN', $layer->source_type);
    }

    public function test_partial_writeoff_duplicate_confirmation_is_idempotent(): void
    {
        $p = $this->product();
        $layer = $this->layer($p, '5', '2026-10-01');
        $token = (string) Str::uuid();
        $body = ['token' => $token, 'quantity' => '2', 'reason' => 'Expired packaging'];
        $this->post(route('expiry.writeoff', $layer->id), $body)->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('expiry.writeoff', $layer->id), $body)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('3.000', $layer->fresh()->remaining_quantity);
        $this->assertDatabaseCount('expiry_writeoffs', 1);
        $this->assertSame('200.00', app(ProfitLossService::class)->calculate('2026-10-11', '2026-10-11')['expenses']);
        $this->post(route('expiry.writeoff', $layer->id), array_replace($body, ['quantity' => '1']))->assertSessionHasErrors('quantity');
    }

    public function test_writeoff_uses_remaining_landed_cost_and_rejects_safe_stock(): void
    {
        $p = $this->product();
        $layer = $this->layer($p, '5', '2026-10-01');
        $layer->update(['remaining_cost_total' => '550.00', 'inventory_cost_total' => '550.00']);
        $expense = app(ExpiryWriteoffService::class)->writeoff($layer->id, '2', 'Expired stock', $this->admin);
        $this->assertSame('220.00', $expense->amount);
        $this->assertSame('330.00', $layer->fresh()->remaining_cost_total);
        $safe = $this->layer($p);
        $this->rejects(fn () => app(ExpiryWriteoffService::class)->writeoff($safe->id, '1', 'Wrong batch', $this->admin));
    }

    public function test_report_pdf_csv_and_all_routes_are_flag_protected(): void
    {
        $p = $this->product();
        $this->layer($p);
        $this->get(route('expiry.index', ['tab' => 'all']))->assertOk()->assertSee('2027');
        $this->get(route('expiry.pdf', ['tab' => 'all']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $csv = $this->get(route('expiry.export', ['tab' => 'all']))->assertOk()->streamedContent();
        $this->assertStringContainsString('15 Jan 2027', $csv);
        $this->assertStringContainsString('BATCH-A', $csv);
        config(['features.expiry' => false]);
        $this->get(route('expiry.pdf'))->assertNotFound();
        $this->get(route('expiry.export'))->assertNotFound();
        $this->put(route('expiry.layer', 1), [])->assertNotFound();
    }

    public function test_expiry_csv_neutralizes_control_prefixed_product_names(): void
    {
        $p = $this->product();
        $p->update(['name' => "\t=1+1"]);
        $this->layer($p);
        $csv = $this->get(route('expiry.export', ['tab' => 'all']))->assertOk()->streamedContent();
        $this->assertStringContainsString("'\t=1+1", $csv);
    }

    public function test_manager_can_identify_undated_remaining_stock_and_known_sale_dates_are_protected(): void
    {
        $p = $this->product();
        $layer = $this->layer($p);
        $layer->update(['expires_at' => null]);
        $this->put(route('expiry.layer', $layer->id), ['expires_at' => '2027-01-15', 'batch_number' => 'BATCH-A', 'reason' => 'Checked packaging'])->assertSessionHasNoErrors()->assertRedirect();
        $sale = $this->sale($this->data($p));
        $this->put(route('expiry.layer', $layer->id), ['expires_at' => '2027-05-15', 'reason' => 'Different date'])->assertSessionHasErrors('expires_at');
        $this->assertSame('2027-01-15', $sale->items->first()->allocations->first()->expires_at);
    }

    public function test_expiry_days_use_shop_calendar_even_when_date_object_has_another_timezone(): void
    {
        $this->assertSame(6, Expiry::days(Carbon::parse('2026-10-17 00:00:00', 'UTC')));
        $this->assertFalse(Expiry::expired('2026-10-11'));
        $this->assertTrue(Expiry::expired('2026-10-10'));
    }

    public function test_disabled_purchase_and_receipt_ui_hide_dates_but_keep_history(): void
    {
        $p = $this->product();
        $purchase = $this->purchase($p);
        $sale = $this->sale($this->data($p));
        app(SettingsService::class)->put('expiry', ['expiry_show_receipt' => true]);
        config(['features.expiry' => false]);
        $this->get(route('purchases.show', $purchase))->assertOk()->assertDontSee('Expiry 15 Jan');
        $this->get(route('sales.receipt', $sale))->assertOk()->assertDontSee('Exp:');
        $this->get(route('manage.show', ['products', $p->id]))->assertOk()->assertDontSee('Stock by expiry');
        $this->assertSame('2027-01-15', $purchase->items->first()->expires_at);
        $this->assertSame('2027-01-15', $sale->items->first()->allocations->first()->expires_at);
    }

    public function test_batch_setting_hides_optional_inputs_and_preserves_existing_batch_on_date_correction(): void
    {
        $p = $this->product();
        $layer = $this->layer($p);
        $purchase = $this->purchase($p);
        $item = $purchase->items->first();
        app(SettingsService::class)->put('expiry', ['expiry_batch_enabled' => false]);
        app(PurchaseService::class)->save(['reference' => $purchase->reference, 'supplier_id' => $this->supplier->id, 'purchase_date' => '2026-10-11', 'items' => [['purchase_item_id' => $item->id, 'product_id' => $p->id, 'quantity' => '10', 'cost' => '100', 'selling_price' => '130', 'expires_at' => '2027-01-15']]], $this->admin->id, $purchase);
        $this->assertSame('PUR-BATCH', $purchase->fresh()->items->first()->batch_number);
        $this->get(route('manage.show', ['products', $p->id]))->assertOk()->assertDontSee('name="batch_number"', false);
        $this->put(route('expiry.layer', $layer), ['expires_at' => '2027-02-15', 'reason' => 'Packaging date verified'])->assertRedirect();
        $this->assertSame('BATCH-A', $layer->fresh()->batch_number);
        $csv = $this->get(route('expiry.export', ['tab' => 'all']))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Batch', strtok($csv, "\n"));
    }

    public function test_exports_include_batches_beyond_the_first_report_page(): void
    {
        $p = $this->product();
        for ($i = 0; $i < 31; $i++) {
            $layer = $this->layer($p, '1');
            $layer->update(['source_reference' => $i === 30 ? 'FINAL-PAGE-BATCH' : 'REPORT-BATCH-'.$i]);
        }
        $this->get(route('expiry.index', ['tab' => 'all']))->assertOk()->assertDontSee('FINAL-PAGE-BATCH');
        $this->get(route('expiry.index', ['tab' => 'all', 'page' => 2]))->assertOk()->assertSee('FINAL-PAGE-BATCH');
        $csv = $this->get(route('expiry.export', ['tab' => 'all']))->assertOk()->streamedContent();
        $this->assertStringContainsString('FINAL-PAGE-BATCH', $csv);
        $this->assertCount(32, array_filter(explode("\n", $csv)));
        $this->mock(PdfReportService::class, function ($mock) {
            $mock->shouldReceive('download')->once()->withArgs(function ($filename, $data) {
                $this->assertSame('expiry-stock', $filename);
                $this->assertSame(31, $data['rowCount']);
                $this->assertCount(31, $rows = $data['rows']->collect());
                $this->assertTrue($rows->contains(fn ($row) => in_array('FINAL-PAGE-BATCH', $row, true)));

                return true;
            })->andReturn(response('%PDF-verified-export-input', 200, ['Content-Type' => 'application/pdf']));
        });
        $this->get(route('expiry.pdf', ['tab' => 'all']))->assertOk();
    }
}
