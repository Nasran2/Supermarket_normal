<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\UnitPreset;
use App\Models\User;
use App\Services\RegisterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductUnitsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Unit $pieces;

    private Unit $dozen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::create(['name' => 'Unit QA', 'username' => 'unit-qa', 'email' => 'unit@example.test', 'password' => 'test-password', 'role_id' => Role::where('name', 'Administrator')->value('id')]);
        $this->actingAs($this->admin);
        $this->pieces = Unit::where('short_name', 'pcs')->firstOrFail();
        $this->dozen = Unit::where('name', 'Dozen')->firstOrFail();
        app(RegisterService::class)->open($this->admin->id, '0');
    }

    private function data(array $extra = []): array
    {
        return $extra + ['name' => 'Unit test tea', 'sku' => 'UNIT-TEA', 'category_id' => Category::first()->id, 'unit_id' => $this->pieces->id, 'cost' => '50', 'price' => '100', 'stock' => '48', 'low_stock' => '5', 'active' => true, 'conversions_present' => true, 'conversions' => [['unit_id' => $this->dozen->id, 'base_quantity' => '12', 'converted_quantity' => '1', 'price' => null]]];
    }

    private function product(array $extra = []): Product
    {
        $this->post(route('manage.store', 'products'), $this->data($extra))->assertRedirect()->assertSessionHasNoErrors();

        return Product::where('sku', 'UNIT-TEA')->firstOrFail();
    }

    private function order(Product $product, string $quantity = '1'): array
    {
        return ['items' => [['product_id' => $product->id, 'unit_id' => $this->dozen->id, 'quantity' => $quantity]], 'payment_method_id' => PaymentMethod::where('type', 'CASH')->value('id')];
    }

    public function test_product_creation_persists_primary_and_multiple_units_prices_and_audit(): void
    {
        $p = $this->product();
        $this->assertSame('48.000', $p->stock);
        $this->assertSame($this->pieces->id, $p->unit_id);
        $this->assertSame('12.000000', $p->conversions->first()->base_quantity);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $p->id, 'quantity' => '48.000']);
        $audit = AuditLog::where('action', 'products.save')->latest('id')->first();
        $this->assertCount(1, $audit->after['conversions']);
        $options = $this->getJson(route('pos.products'))->assertOk()->json('0.units');
        $this->assertCount(2, $options);
        $this->assertSame('1200.00', $options[1]['price']);
        $this->get(route('manage.index', 'products'))->assertOk()->assertSee('Units & selling prices', false)->assertSee('1,200.00');
        $this->get(route('manage.edit', ['products', $p->id]))->assertOk()->assertSee('Primary stock unit')->assertSee('Additional units & conversions');
        $this->get(route('manage.show', ['products', $p->id]))->assertOk()->assertSee('12 pcs = 1 doz');
    }

    public function test_sale_deducts_base_stock_and_void_restores_original_snapshot_after_conversion_edit(): void
    {
        $p = $this->product();
        $data = $this->order($p, '2');
        $quote = $this->postJson(route('pos.quote'), $data)->assertOk()->assertJsonPath('subtotal', '2400.00')->assertJsonPath('cost_total', '1200.00')->assertJsonPath('items.0.base_quantity', '24.000')->json();
        $this->postJson(route('pos.complete'), $data + ['amount_paid' => '2400', 'checkout_token' => Str::uuid()->toString(), 'quote_hash' => $quote['quote_hash']])->assertOk();
        $this->assertSame('24.000', $p->fresh()->stock);
        $sale = Sale::firstOrFail();
        $this->assertSame('doz', $sale->items->first()->unit);
        $this->assertSame('2.000', $sale->items->first()->quantity);
        $this->put(route('manage.update', ['products', $p->id]), $this->data(['conversions' => [['unit_id' => $this->dozen->id, 'base_quantity' => '24', 'converted_quantity' => '1', 'price' => '2000']]]))->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('sales.void', $sale), ['reason' => 'QA reversal'])->assertRedirect();
        $this->assertSame('48.000', $p->fresh()->stock);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $p->id, 'reason' => 'SALE VOID', 'quantity' => '24.000']);
    }

    public function test_per_unit_override_changes_price_without_changing_stock_conversion(): void
    {
        $p = $this->product(['conversions' => [['unit_id' => $this->dozen->id, 'base_quantity' => '12', 'converted_quantity' => '1', 'price' => '1100']]]);
        $this->postJson(route('pos.quote'), $this->order($p))->assertOk()->assertJsonPath('subtotal', '1100.00')->assertJsonPath('items.0.base_quantity', '12.000')->assertJsonPath('cost_total', '600.00');
        $this->postJson(route('pos.quote'), ['items' => [['product_id' => $p->id, 'quantity' => '1']], 'payment_method_id' => PaymentMethod::where('type', 'CASH')->value('id')])->assertOk()->assertJsonPath('subtotal', '100.00')->assertJsonPath('items.0.base_quantity', '1.000');
    }

    public function test_bad_duplicate_primary_inactive_and_zero_conversion_rows_are_rejected(): void
    {
        foreach ([$this->data(['conversions' => [['unit_id' => $this->pieces->id, 'base_quantity' => '1', 'converted_quantity' => '1']]]), $this->data(['conversions' => [['unit_id' => $this->dozen->id, 'base_quantity' => '0', 'converted_quantity' => '1']]]), $this->data(['conversions' => [['unit_id' => $this->dozen->id, 'base_quantity' => '1.5', 'converted_quantity' => '1']]]), $this->data(['conversions' => array_fill(0, 2, ['unit_id' => $this->dozen->id, 'base_quantity' => '12', 'converted_quantity' => '1'])])] as $data) {
            $this->postJson(route('manage.store', 'products'), $data)->assertUnprocessable();
        }
        $this->dozen->update(['active' => false]);
        $this->postJson(route('manage.store', 'products'), $this->data())->assertUnprocessable();
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_units', 0);
    }

    public function test_stock_checks_and_unit_quantity_validation_use_converted_quantities(): void
    {
        $p = $this->product();
        $this->postJson(route('pos.quote'), $this->order($p, '5'))->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->postJson(route('pos.quote'), $this->order($p, '0.5'))->assertUnprocessable();
        $kg = Unit::where('short_name', 'kg')->firstOrFail();
        $this->postJson(route('pos.quote'), ['items' => [['product_id' => $p->id, 'unit_id' => $kg->id, 'quantity' => '1']], 'payment_method_id' => PaymentMethod::where('type', 'CASH')->value('id')])->assertUnprocessable();
        $this->dozen->update(['active' => false]);
        $this->postJson(route('pos.quote'), $this->order($p))->assertUnprocessable();
        $this->assertSame('48.000', $p->fresh()->stock);
    }

    public function test_weight_conversion_and_fractional_piece_guard(): void
    {
        $kg = Unit::where('short_name', 'kg')->firstOrFail();
        $g = Unit::where('short_name', 'g')->firstOrFail();
        $p = $this->product(['unit_id' => $kg->id, 'stock' => '10', 'price' => '800', 'cost' => '500', 'conversions' => [['unit_id' => $g->id, 'base_quantity' => '1', 'converted_quantity' => '1000', 'price' => null]]]);
        $data = ['items' => [['product_id' => $p->id, 'unit_id' => $g->id, 'quantity' => '250']], 'payment_method_id' => PaymentMethod::where('type', 'CASH')->value('id')];
        $this->postJson(route('pos.quote'), $data)->assertOk()->assertJsonPath('subtotal', '200.00')->assertJsonPath('cost_total', '125.00')->assertJsonPath('items.0.base_quantity', '0.250');
        $p->update(['unit_id' => $this->pieces->id]);
        $p->conversions()->update(['base_quantity' => '5', 'converted_quantity' => '2']);
        $this->postJson(route('pos.quote'), ['items' => [['product_id' => $p->id, 'unit_id' => $g->id, 'quantity' => '1']], 'payment_method_id' => PaymentMethod::where('type', 'CASH')->value('id')])->assertUnprocessable();
    }

    public function test_purchase_uses_selected_unit_cost_and_reverses_saved_base_quantity(): void
    {
        $p = $this->product();
        $supplier = Supplier::create(['name' => 'QA Supplier', 'active' => true]);
        $data = ['reference' => 'UNIT-PURCHASE', 'supplier_id' => $supplier->id, 'purchase_date' => today()->toDateString(), 'items' => [['product_id' => $p->id, 'unit_id' => $this->dozen->id, 'quantity' => '2', 'cost' => '720']]];
        $this->post(route('purchases.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $purchase = Purchase::firstOrFail();
        $this->assertSame('72.000', $p->fresh()->stock);
        $this->assertSame('60.00', $p->fresh()->cost);
        $this->assertSame('1440.00', $purchase->total);
        $this->assertSame('24.000', $purchase->items->first()->base_quantity);
        $p->conversions()->update(['base_quantity' => '24']);
        $this->post(route('purchases.void', $purchase), ['reason' => 'QA reversal'])->assertRedirect();
        $this->assertSame('48.000', $p->fresh()->stock);
        $this->assertSame('50.00', $p->fresh()->cost);
    }

    public function test_preset_creation_edit_is_audited_and_does_not_change_product_units(): void
    {
        $rows = [['unit_id' => $this->dozen->id, 'base_quantity' => '12', 'converted_quantity' => '1']];
        $this->post(route('manage.store', 'unit-presets'), ['name' => 'Pieces & dozen', 'unit_id' => $this->pieces->id, 'conversions' => $rows])->assertRedirect()->assertSessionHasNoErrors();
        $preset = UnitPreset::firstOrFail();
        $this->assertCount(1, $preset->conversions);
        $p = $this->product();
        $this->put(route('manage.update', ['unit-presets', $preset->id]), ['name' => 'Pieces & dozen', 'unit_id' => $this->pieces->id, 'conversions' => [['unit_id' => $this->dozen->id, 'base_quantity' => '24', 'converted_quantity' => '1']]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('12.000000', $p->fresh()->conversions->first()->base_quantity);
        $this->get(route('manage.index', 'unit-presets'))->assertOk()->assertSee('24 pcs = 1 doz');
        $this->get(route('manage.show', ['unit-presets', $preset->id]))->assertOk();
        $this->assertSame(2, AuditLog::where('action', 'unit-presets.save')->count());
    }

    public function test_removing_additional_units_and_protecting_primary_stock_unit(): void
    {
        $p = $this->product();
        $this->put(route('manage.update', ['products', $p->id]), $this->data(['conversions' => []]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('product_units', 0);
        $this->putJson(route('manage.update', ['products', $p->id]), $this->data(['unit_id' => $this->dozen->id, 'conversions' => []]))->assertUnprocessable()->assertJsonValidationErrors('unit_id');
        $this->assertSame($this->pieces->id, $p->fresh()->unit_id);
    }

    public function test_conversion_change_invalidates_payment_quote_without_deducting_stock(): void
    {
        $p = $this->product();
        $data = $this->order($p);
        $quote = $this->postJson(route('pos.quote'), $data)->assertOk()->json();
        $p->conversions()->update(['base_quantity' => '24']);
        $this->postJson(route('pos.complete'), $data + ['amount_paid' => '2400', 'checkout_token' => Str::uuid()->toString(), 'quote_hash' => $quote['quote_hash']])->assertUnprocessable()->assertJsonValidationErrors('payment');
        $this->assertSame('48.000', $p->fresh()->stock);
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_decimal_stock_conversion_rounds_once_and_reversal_uses_saved_quantity(): void
    {
        $meter = Unit::where('short_name', 'm')->firstOrFail();
        $yard = Unit::create(['name' => 'Yard', 'short_name' => 'yd', 'allow_decimal' => true, 'active' => true]);
        $p = $this->product(['unit_id' => $yard->id, 'stock' => '10', 'price' => '310', 'cost' => '200', 'conversions' => [['unit_id' => $meter->id, 'base_quantity' => '1', 'converted_quantity' => '0.9144', 'price' => null]]]);
        $data = ['items' => [['product_id' => $p->id, 'unit_id' => $meter->id, 'quantity' => '1']], 'payment_method_id' => PaymentMethod::where('type', 'CASH')->value('id')];
        $quote = $this->postJson(route('pos.quote'), $data)->assertOk()->assertJsonPath('items.0.base_quantity', '1.094')->assertJsonPath('subtotal', '339.02')->assertJsonPath('cost_total', '218.80')->json();
        $this->postJson(route('pos.complete'), $data + ['amount_paid' => '339.02', 'checkout_token' => Str::uuid()->toString(), 'quote_hash' => $quote['quote_hash']])->assertOk();
        $this->assertSame('8.906', $p->fresh()->stock);
        $this->post(route('sales.void', Sale::firstOrFail()), ['reason' => 'QA reverse rounded stock'])->assertRedirect();
        $this->assertSame('10.000', $p->fresh()->stock);
    }

    public function test_unit_decimal_setting_cannot_discard_fractional_conversion_quantities(): void
    {
        $g = Unit::where('short_name', 'g')->firstOrFail();
        $this->product(['conversions' => [['unit_id' => $g->id, 'base_quantity' => '1', 'converted_quantity' => '0.5', 'price' => null]]]);
        $this->putJson(route('manage.update', ['units', $g->id]), ['name' => $g->name, 'short_name' => $g->short_name, 'active' => true, 'allow_decimal' => false])->assertUnprocessable()->assertJsonValidationErrors('allow_decimal');
        $this->assertTrue($g->fresh()->allow_decimal);
    }

    public function test_cashier_cannot_manage_products_or_presets_but_can_sell_allowed_units(): void
    {
        $p = $this->product();
        $cashier = User::create(['name' => 'Cashier', 'username' => 'unit-cashier', 'email' => 'cashier@example.test', 'password' => 'test-password', 'role_id' => Role::where('name', 'Cashier')->value('id')]);
        $this->flushSession();
        $this->actingAs($cashier);
        app(RegisterService::class)->open($cashier->id, '0');
        $this->postJson(route('manage.store', 'products'), $this->data())->assertForbidden();
        $this->get(route('manage.index', 'unit-presets'))->assertForbidden();
        $this->postJson(route('pos.quote'), $this->order($p))->assertOk()->assertJsonPath('subtotal', '1200.00');
    }
}
