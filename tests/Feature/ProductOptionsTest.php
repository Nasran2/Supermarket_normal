<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\NoReceiptSalesReturnService;
use App\Services\PurchaseReturnService;
use App\Services\PurchaseService;
use App\Services\RegisterService;
use App\Services\SalesReturnService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductOptionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    private int $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::firstOrFail();
        $this->actingAs($this->admin);
        app(RegisterService::class)->open($this->admin->id, '10000');
        $this->cash = PaymentMethod::where('type', 'CASH')->value('id');
        $this->product = Product::create(['name' => 'Sample product', 'sku' => 'OPTIONS-TEST', 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'cost' => '40', 'price' => '100', 'stock' => '20', 'active' => true]);
    }

    private function order(array $line = []): array
    {
        return ['items' => [$line + ['product_id' => $this->product->id, 'quantity' => '2', 'use_default_discount' => true]], 'payment_method_id' => $this->cash];
    }

    private function complete(array $data): Sale
    {
        $q = $this->postJson(route('pos.quote'), $data)->assertOk()->json();
        $this->postJson(route('pos.complete'), $data + ['amount_paid' => $q['customer_payable'], 'checkout_token' => (string) Str::uuid(), 'quote_hash' => $q['quote_hash']])->assertOk();

        return Sale::latest('id')->firstOrFail();
    }

    private function productData(array $extra = []): array
    {
        return $extra + ['name' => 'Another sample', 'sku' => 'OPTIONS-NEW', 'unit_id' => $this->product->unit_id, 'cost' => '20', 'price' => '100', 'stock' => '0', 'low_stock' => '0', 'active' => true];
    }

    public function test_products_keep_stock_tracking_and_no_discount_by_default(): void
    {
        $this->post(route('manage.store', 'products'), $this->productData())->assertRedirect()->assertSessionHasNoErrors();
        $p = Product::where('sku', 'OPTIONS-NEW')->firstOrFail();
        $this->assertTrue($p->track_stock);
        $this->assertSame('NONE', $p->default_discount_type);
        $sale = $this->complete($this->order());
        $this->assertSame('200.00', $sale->sale_amount);
        $this->assertSame('18.000', $this->product->fresh()->stock);
        $this->assertTrue($sale->items->first()->track_stock);
    }

    public function test_default_fixed_discount_applies_once_to_the_whole_line_and_returns_use_saved_net_amount(): void
    {
        $this->product->update(['default_discount_type' => 'AMOUNT', 'default_discount_value' => '10']);
        $sale = $this->complete($this->order());
        $this->assertSame('190.00', $sale->sale_amount);
        $this->assertSame('10.00', $sale->discount);
        $this->assertTrue($sale->items->first()->use_default_discount);
        $this->get(route('sales.receipt', $sale))->assertOk()->assertSee('95.00');
        $this->product->update(['default_discount_value' => '80']);
        $q = app(SalesReturnService::class)->quote($sale, ['reason' => 'Customer changed mind', 'resolution' => 'MONEY', 'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => '1']]], $this->admin);
        $this->assertSame('95.00', $q['amount']);
    }

    public function test_percentage_discount_is_saved_and_fractional_values_round_correctly(): void
    {
        $this->product->update(['default_discount_type' => 'PERCENT', 'default_discount_value' => '12.5']);
        $this->postJson(route('pos.quote'), $this->order())->assertOk()->assertJsonPath('items.0.line_discount', '25.00')->assertJsonPath('sale_amount', '175.00');
        $this->product->update(['unit_id' => Unit::where('short_name', 'kg')->value('id'), 'price' => '8.02', 'track_stock' => false, 'stock' => '0']);
        $this->postJson(route('pos.quote'), $this->order(['quantity' => '1.25']))->assertOk()->assertJsonPath('items.0.line_subtotal', '10.03')->assertJsonPath('items.0.line_discount', '1.25')->assertJsonPath('sale_amount', '8.78');
    }

    public function test_approved_defaults_work_for_cashiers_without_manual_discount_permission_and_cannot_be_forged(): void
    {
        $this->product->update(['default_discount_type' => 'PERCENT', 'default_discount_value' => '25']);
        $role = Role::create(['name' => 'Product promotion cashier', 'sales_visibility' => 'OWN']);
        $role->permissions()->sync(Permission::whereIn('name', ['pos.access', 'sales.create'])->pluck('id'));
        $user = User::create(['name' => 'Cashier', 'username' => 'options-cashier', 'email' => 'options@example.test', 'password' => 'test-password', 'role_id' => $role->id, 'active' => true]);
        $this->actingAs($user);
        app(RegisterService::class)->open($user->id, '0');
        $this->postJson(route('pos.quote'), $this->order(['discount_type' => 'PERCENT', 'discount_value' => '99']))->assertOk()->assertJsonPath('sale_amount', '150.00');
        $this->postJson(route('pos.quote'), $this->order(['use_default_discount' => false, 'discount_type' => 'PERCENT', 'discount_value' => '99']))->assertForbidden();
    }

    public function test_discount_setting_and_manual_override_remain_supported_without_stacking(): void
    {
        $this->product->update(['default_discount_type' => 'AMOUNT', 'default_discount_value' => '10']);
        $this->postJson(route('pos.quote'), $this->order(['use_default_discount' => false, 'discount_type' => 'AMOUNT', 'discount_value' => '5']))->assertOk()->assertJsonPath('discount', '5.00');
        app(SettingsService::class)->put('pos', ['allow_discount' => false]);
        $this->postJson(route('pos.quote'), $this->order())->assertOk()->assertJsonPath('discount', '0.00');
        $this->postJson(route('pos.quote'), $this->order(['use_default_discount' => false, 'discount_value' => '5']))->assertUnprocessable();
    }

    public function test_fixed_default_never_makes_a_line_negative_and_changed_defaults_invalidate_payment_quotes(): void
    {
        $this->product->update(['default_discount_type' => 'AMOUNT', 'default_discount_value' => '500']);
        $this->postJson(route('pos.quote'), $this->order())->assertOk()->assertJsonPath('sale_amount', '0.00');
        $this->product->update(['default_discount_value' => '10']);
        $q = $this->postJson(route('pos.quote'), $this->order())->assertOk()->json();
        $this->product->update(['default_discount_value' => '20']);
        $this->postJson(route('pos.complete'), $this->order() + ['amount_paid' => '190', 'checkout_token' => (string) Str::uuid(), 'quote_hash' => $q['quote_hash']])->assertUnprocessable()->assertJsonValidationErrors('payment');
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_product_form_saves_options_and_rejects_invalid_values_or_discarding_stock(): void
    {
        foreach ([['default_discount_type' => 'PERCENT', 'default_discount_value' => '101'], ['default_discount_type' => 'AMOUNT', 'default_discount_value' => '-1'], ['default_discount_type' => 'OTHER'], ['track_stock' => 'invalid']] as $extra) {
            $this->postJson(route('manage.store', 'products'), $this->productData($extra))->assertUnprocessable();
        }
        $this->post(route('manage.store', 'products'), $this->productData(['track_stock' => false, 'default_discount_type' => 'AMOUNT', 'default_discount_value' => '15']))->assertRedirect()->assertSessionHasNoErrors();
        $p = Product::where('sku', 'OPTIONS-NEW')->firstOrFail();
        $this->assertFalse($p->track_stock);
        $this->assertSame('15.00', $p->default_discount_value);
        $this->put(route('manage.update', ['products', $this->product->id]), $this->productData(['sku' => $this->product->sku, 'track_stock' => false]))->assertSessionHasErrors('track_stock');
        $this->assertTrue($this->product->fresh()->track_stock);
        $this->assertSame('20.000', $this->product->fresh()->stock);
    }

    public function test_untracked_products_sell_without_inventory_and_void_uses_the_original_snapshot(): void
    {
        $this->product->update(['track_stock' => false, 'stock' => '0']);
        $this->getJson(route('pos.products'))->assertOk()->assertJsonFragment(['track_stock' => false]);
        $sale = $this->complete($this->order(['quantity' => '3']));
        $this->assertSame('120.00', $sale->cost_total);
        $this->assertFalse($sale->items->first()->track_stock);
        $this->assertDatabaseCount('sale_stock_allocations', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->product->update(['track_stock' => true]);
        $this->post(route('sales.void', $sale), ['reason' => 'Sample correction'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('0.000', $this->product->fresh()->stock);
        $this->assertDatabaseCount('product_stock_layers', 0);
    }

    public function test_untracked_sale_return_and_cancellation_do_not_create_stock(): void
    {
        $this->product->update(['track_stock' => false, 'stock' => '0', 'default_discount_type' => 'AMOUNT', 'default_discount_value' => '10']);
        $sale = $this->complete($this->order());
        $r = app(SalesReturnService::class)->complete($sale, ['token' => (string) Str::uuid(), 'reason' => 'Damaged', 'resolution' => 'MONEY', 'payment_method_id' => $this->cash, 'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => '1', 'stock_action' => 'RESTOCK']]], $this->admin);
        $this->assertSame('95.00', $r->refund_amount);
        $this->assertFalse($r->items->first()->track_stock);
        app(SalesReturnService::class)->cancel($r, 'Sample cancellation', $this->admin);
        $this->assertSame('0.000', $this->product->fresh()->stock);
        $this->assertDatabaseCount('product_stock_layers', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_untracked_no_receipt_return_and_cancellation_do_not_create_stock(): void
    {
        $this->product->update(['track_stock' => false, 'stock' => '0']);
        app(SettingsService::class)->put('returns', ['no_receipt_cash_refund' => true]);
        $r = app(NoReceiptSalesReturnService::class)->complete(['return_type' => 'NO_RECEIPT', 'token' => (string) Str::uuid(), 'reason' => 'Damaged', 'resolution' => 'MONEY', 'payment_method_id' => $this->cash, 'items' => [['product_id' => $this->product->id, 'quantity' => '1', 'stock_action' => 'RESTOCK']]], $this->admin);
        $this->assertFalse($r->items->first()->track_stock);
        app(NoReceiptSalesReturnService::class)->cancel($r, 'Sample cancellation', $this->admin);
        $this->assertSame('0.000', $this->product->fresh()->stock);
        $this->assertDatabaseCount('product_stock_layers', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_untracked_purchase_and_return_keep_supplier_amounts_without_inventory(): void
    {
        $this->product->update(['track_stock' => false, 'stock' => '0']);
        $supplier = Supplier::create(['name' => 'Sample supplier', 'active' => true]);
        $p = app(PurchaseService::class)->save(['supplier_id' => $supplier->id, 'purchase_date' => today()->toDateString(), 'payment_mode' => 'UNPAID', 'items' => [['product_id' => $this->product->id, 'quantity' => '5', 'cost' => '40', 'selling_price' => '100']]], $this->admin->id);
        $this->assertSame('200.00', $p->total);
        $this->assertFalse($p->items->first()->track_stock);
        $r = app(PurchaseReturnService::class)->complete($p, ['token' => (string) Str::uuid(), 'reason' => 'Damaged', 'resolution' => 'MONEY', 'items' => [['purchase_item_id' => $p->items->first()->id, 'quantity' => '2']]], $this->admin);
        $this->assertSame('80.00', $r->due_reduction);
        $this->assertSame('120.00', $p->fresh()->due_amount);
        app(PurchaseReturnService::class)->cancel($r, 'Sample cancellation', $this->admin);
        $this->assertSame('0.000', $this->product->fresh()->stock);
        $this->assertDatabaseCount('product_stock_layers', 0);
    }

    public function test_untracked_products_are_not_reported_as_low_stock_and_tracked_empty_products_remain_blocked(): void
    {
        $this->product->update(['track_stock' => false, 'stock' => '0']);
        $this->get(route('manage.index', ['products', 'low_stock' => 1]))->assertOk()->assertDontSee('OPTIONS-TEST');
        $this->get(route('manage.index', 'products'))->assertOk()->assertSee('Not maintained');
        $this->product->update(['track_stock' => true]);
        $this->postJson(route('pos.quote'), $this->order())->assertUnprocessable();
    }

    public function test_untracked_purchase_can_be_voided_after_tracking_is_enabled_without_restoring_inventory(): void
    {
        $this->product->update(['track_stock' => false, 'stock' => '0']);
        $supplier = Supplier::create(['name' => 'Sample supplier', 'active' => true]);
        $p = app(PurchaseService::class)->save(['supplier_id' => $supplier->id, 'purchase_date' => today()->toDateString(), 'payment_mode' => 'UNPAID', 'items' => [['product_id' => $this->product->id, 'quantity' => '5', 'cost' => '40', 'selling_price' => '100']]], $this->admin->id);
        $this->product->update(['track_stock' => true]);
        app(PurchaseService::class)->void($p, $this->admin->id, 'Sample correction');
        $this->assertSame('VOIDED', $p->fresh()->status);
        $this->assertSame('0.000', $this->product->fresh()->stock);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_inventory_charges_are_rejected_for_untracked_products_instead_of_losing_the_cost(): void
    {
        $this->product->update(['track_stock' => false, 'stock' => '0']);
        $supplier = Supplier::create(['name' => 'Sample supplier', 'active' => true]);
        $this->expectException(ValidationException::class);
        app(PurchaseService::class)->save(['supplier_id' => $supplier->id, 'purchase_date' => today()->toDateString(), 'payment_mode' => 'UNPAID', 'charges' => [['label' => 'Delivery', 'amount' => '10']], 'charge_treatment' => 'COST', 'items' => [['product_id' => $this->product->id, 'quantity' => '5', 'cost' => '40', 'selling_price' => '100']]], $this->admin->id);
    }
}
