<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\RegisterService;
use App\Services\SaleRevisionService;
use App\Services\StockLayerService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockPriceLayersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Unit $piece;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::create(['name' => 'Price QA', 'username' => 'price-qa', 'email' => 'prices@example.test', 'password' => 'test-password', 'role_id' => Role::where('name', 'Administrator')->value('id')]);
        $this->actingAs($this->admin);
        $this->piece = Unit::where('short_name', 'pcs')->firstOrFail();
        app(RegisterService::class)->open($this->admin->id, '0');
    }

    private function product(array $layers = [], ?Unit $unit = null): Product
    {
        $sku = 'PRICE-'.Str::random(8);
        $rows = $layers ?: [['quantity' => '20', 'cost' => '100', 'selling_price' => '130'], ['quantity' => '15', 'cost' => '110', 'selling_price' => '140']];
        $this->post(route('manage.store', 'products'), ['name' => 'Bath Soap', 'sku' => $sku, 'unit_id' => ($unit ?? $this->piece)->id, 'low_stock' => '5', 'active' => true, 'opening_layers' => $rows])->assertSessionHasNoErrors()->assertRedirect();

        return Product::where('sku', $sku)->firstOrFail();
    }

    private function data(Product $p, string $qty = '1', string $price = '130'): array
    {
        return ['items' => [['product_id' => $p->id, 'quantity' => $qty, 'stock_price' => $price]], 'payment_method_id' => PaymentMethod::where('type', 'CASH')->value('id')];
    }

    private function sale(array $data): Sale
    {
        $quote = $this->postJson(route('pos.quote'), $data)->assertOk()->json();
        $this->postJson(route('pos.complete'), $data + ['checkout_token' => (string) Str::uuid(), 'quote_hash' => $quote['quote_hash'], 'amount_paid' => $quote['customer_payable']])->assertOk();

        return Sale::latest('id')->firstOrFail();
    }

    public function test_opening_rows_preserve_costs_stock_and_distinct_price_choices(): void
    {
        $p = $this->product();
        $this->assertSame('35.000', $p->stock);
        $this->assertSame(['100.00', '110.00'], $p->stockLayers->pluck('cost_price')->all());
        $groups = $this->getJson(route('pos.products'))->assertOk()->json('0.price_options');
        $this->assertCount(2, $groups);
        $this->assertSame(['20.000', '15.000'], array_column($groups, 'quantity'));
        $this->postJson(route('pos.quote'), array_replace_recursive($this->data($p), ['items' => [['stock_price' => null]]]))->assertUnprocessable();
    }

    public function test_depleted_prices_disappear_and_last_price_has_one_direct_choice(): void
    {
        $p = $this->product();
        $this->sale($this->data($p, '20'));
        $groups = $this->getJson(route('pos.products'))->assertOk()->json('0.price_options');
        $this->assertCount(1, $groups);
        $this->assertSame('140.00', $groups[0]['stock_price']);
        $this->assertSame('15.000', $groups[0]['quantity']);
    }

    public function test_price_choices_are_sorted_and_ignore_depleted_preloaded_deliveries(): void
    {
        $p = $this->product([['quantity' => '15', 'cost' => '110', 'selling_price' => '140'], ['quantity' => '20', 'cost' => '100', 'selling_price' => '130']]);
        $groups = $this->getJson(route('pos.products'))->assertOk()->json('0.price_options');
        $this->assertSame(['130.00', '140.00'], array_column($groups, 'stock_price'));
        $p->stockLayers()->where('selling_price', '130')->update(['remaining_quantity' => '0']);
        $p->load('stockLayers');
        $groups = app(StockLayerService::class)->groups($p);
        $this->assertCount(1, $groups);
        $this->assertSame('140.00', $groups[0]['stock_price']);
        $this->assertSame('15.000', $groups[0]['quantity']);
    }

    public function test_identical_selling_prices_aggregate_and_fifo_uses_actual_cost(): void
    {
        $p = $this->product([['quantity' => '10', 'cost' => '100', 'selling_price' => '130'], ['quantity' => '10', 'cost' => '110', 'selling_price' => '130']]);
        $this->assertCount(1, app(StockLayerService::class)->groups($p));
        $sale = $this->sale($this->data($p, '15'));
        $this->assertSame('1550.00', $sale->cost_total);
        $this->assertSame(['10.000', '5.000'], $sale->items->first()->allocations->pluck('quantity')->all());
        $this->assertSame(['0.000', '5.000'], $p->stockLayers()->orderBy('id')->pluck('remaining_quantity')->all());
    }

    public function test_selected_price_cannot_borrow_stock_from_another_price(): void
    {
        $p = $this->product();
        $this->postJson(route('pos.quote'), $this->data($p, '21'))->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertSame('35.000', $p->fresh()->stock);
    }

    public function test_same_price_stock_rows_are_selectable_and_selected_delivery_is_consumed_and_edited(): void
    {
        $p = $this->product([['quantity' => '44', 'cost' => '390', 'selling_price' => '490'], ['quantity' => '10', 'cost' => '420', 'selling_price' => '490']]);
        $choices = $this->getJson(route('pos.products', ['q' => $p->sku]))->assertOk()->json('0.price_options');
        $this->assertCount(2, $choices);
        $this->assertSame(['490.00', '490.00'], array_column($choices, 'stock_price'));
        $this->assertSame(['44.000', '10.000'], array_column($choices, 'quantity'));
        $this->assertArrayNotHasKey('cost_price', $choices[1]);
        $data = $this->data($p, '2', '490');
        $data['items'][0]['stock_layer_id'] = $choices[1]['stock_layer_id'];
        $sale = $this->sale($data);
        $this->assertSame('840.00', $sale->cost_total);
        $this->assertSame(['44.000', '8.000'], $p->stockLayers()->orderBy('id')->pluck('remaining_quantity')->all());
        $this->assertSame($choices[1]['stock_layer_id'], $sale->items->first()->allocations->first()->stock_layer_id);
        $this->get(route('sales.edit', $sale))->assertOk()->assertViewHas('editSeed', fn ($seed) => $seed['items'][0]['stock_layer_id'] === $choices[1]['stock_layer_id']);
        $revision = ['version' => app(SaleRevisionService::class)->version($sale->fresh()), 'items' => [['product_id' => $p->id, 'stock_price' => '490', 'stock_layer_id' => $choices[1]['stock_layer_id'], 'quantity' => '3']], 'payments' => [['payment_method_id' => $data['payment_method_id'], 'amount' => '1470']]];
        $quote = $this->postJson(route('sales.edit.quote', $sale), $revision)->assertOk()->json();
        $revision['payments'][0]['amount_paid'] = $quote['customer_payable'];
        $this->postJson(route('sales.revise', $sale), $revision + ['checkout_token' => (string) Str::uuid(), 'quote_hash' => $quote['quote_hash']])->assertOk();
        $this->assertSame('1260.00', $sale->fresh()->cost_total);
        $this->assertSame(['44.000', '7.000'], $p->stockLayers()->orderBy('id')->pluck('remaining_quantity')->all());
    }

    public function test_selected_stock_row_cannot_borrow_from_same_price_or_another_product(): void
    {
        $p = $this->product([['quantity' => '44', 'cost' => '390', 'selling_price' => '490'], ['quantity' => '10', 'cost' => '420', 'selling_price' => '490']]);
        $data = $this->data($p, '11', '490');
        $data['items'][0]['stock_layer_id'] = $p->stockLayers()->orderByDesc('id')->value('id');
        $this->postJson(route('pos.quote'), $data)->assertUnprocessable()->assertJsonValidationErrors('items');
        $data['items'][0]['quantity'] = '6';
        $data['items'][] = $data['items'][0];
        $this->postJson(route('pos.quote'), $data)->assertUnprocessable();
        $other = $this->product();
        $data['items'] = [$data['items'][0]];
        $data['items'][0]['stock_layer_id'] = $other->stockLayers()->value('id');
        $this->postJson(route('pos.quote'), $data)->assertUnprocessable();
        $this->assertSame('54.000', $p->fresh()->stock);
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_duplicate_product_lines_reserve_cumulatively_and_different_prices_remain_separate(): void
    {
        $p = $this->product();
        $data = $this->data($p, '15');
        $data['items'][] = $data['items'][0];
        $this->postJson(route('pos.quote'), $data)->assertUnprocessable();
        $data['items'][1] = ['product_id' => $p->id, 'quantity' => '2', 'stock_price' => '140'];
        $sale = $this->sale($data);
        $this->assertCount(2, $sale->items);
        $this->assertSame('1720.00', $sale->cost_total);
        $this->assertSame('18.000', $p->fresh()->stock);
    }

    public function test_purchase_creates_new_lot_without_repricing_old_stock_and_used_purchase_cannot_be_voided(): void
    {
        $p = $this->product();
        $supplier = Supplier::create(['name' => 'Test supplier', 'active' => true]);
        $this->post(route('purchases.store'), ['reference' => 'PRICE-PUR-1', 'supplier_id' => $supplier->id, 'purchase_date' => today()->toDateString(), 'items' => [['product_id' => $p->id, 'quantity' => '10', 'cost' => '120', 'selling_price' => '150']]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('45.000', $p->fresh()->stock);
        $this->assertSame(['130.00', '140.00', '150.00'], $p->stockLayers()->orderBy('id')->pluck('selling_price')->all());
        $this->sale($this->data($p, '1', '150'));
        $purchase = Purchase::firstOrFail();
        $this->postJson(route('purchases.void', $purchase), ['reason' => 'Cannot erase cost'])->assertUnprocessable();
        $this->assertSame('44.000', $p->fresh()->stock);
    }

    public function test_void_and_partial_returns_restore_the_original_cost_layers(): void
    {
        $p = $this->product([['quantity' => '10', 'cost' => '100', 'selling_price' => '130'], ['quantity' => '10', 'cost' => '110', 'selling_price' => '130']]);
        $sale = $this->sale($this->data($p, '15'));
        $this->post(route('sales.void', $sale), ['reason' => 'Original layer restore'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['10.000', '10.000'], $p->stockLayers()->orderBy('id')->pluck('remaining_quantity')->all());
        $sale = $this->sale($this->data($p, '15'));
        $this->post(route('sales.returns.store', $sale), ['token' => (string) Str::uuid(), 'reason' => 'Partial return', 'payment_method_id' => PaymentMethod::where('type', 'CASH')->value('id'), 'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => '12']]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('1250.00', $sale->returns()->first()->cost_total);
        $this->assertSame(['7.000', '10.000'], $p->stockLayers()->orderBy('id')->pluck('remaining_quantity')->all());
    }

    public function test_piece_rejects_fractional_quantity_but_weight_accepts_it(): void
    {
        $p = $this->product();
        $this->postJson(route('pos.quote'), $this->data($p, '1.5'))->assertUnprocessable();
        $kg = Unit::where('short_name', 'kg')->firstOrFail();
        $weight = $this->product([['quantity' => '5.5', 'cost' => '100', 'selling_price' => '130']], $kg);
        $this->sale($this->data($weight, '1.5'));
        $this->assertSame('4.000', $weight->fresh()->stock);
    }

    public function test_default_unit_changes_only_new_products_and_cannot_be_disabled_or_deleted(): void
    {
        $p = $this->product();
        $this->get(route('manage.create', 'products'))->assertOk()->assertSee('value="'.$this->piece->id.'" data-short="pcs" selected', false);
        $kg = Unit::where('short_name', 'kg')->firstOrFail();
        $this->post(route('units.default', $kg), ['confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('manage.create', 'products'))->assertOk()->assertSee('value="'.$kg->id.'" data-short="kg" selected', false);
        $this->assertSame($this->piece->id, $p->fresh()->unit_id);
        $this->assertSame(1, Unit::where('default_slot', 1)->count());
        $this->putJson(route('manage.update', ['units', $kg->id]), ['name' => $kg->name, 'short_name' => $kg->short_name, 'allow_decimal' => true, 'active' => false])->assertUnprocessable();
        $this->deleteJson(route('manage.destroy', ['units', $kg->id]))->assertUnprocessable();
    }

    public function test_cashier_never_receives_cost_metadata_and_cannot_reprice(): void
    {
        $p = $this->product([['quantity' => '10', 'cost' => '100', 'selling_price' => '130']]);
        $cashier = User::create(['name' => 'Cashier', 'username' => 'layer-cashier', 'email' => 'layer-cashier@example.test', 'password' => 'test-password', 'role_id' => Role::where('name', 'Cashier')->value('id')]);
        $this->flushSession();
        $this->actingAs($cashier);
        app(RegisterService::class)->open($cashier->id, '0');
        $response = $this->getJson(route('pos.products'))->assertOk();
        $this->assertArrayNotHasKey('cost', $response->json('0.units.0'));
        $this->assertArrayNotHasKey('cost', $response->json('0.price_options.0.units.0'));
        $quote = $this->postJson(route('pos.quote'), $this->data($p))->assertOk()->json();
        $this->assertArrayNotHasKey('cost_total', $quote);
        $this->assertArrayNotHasKey('allocations', $quote['items'][0]);
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Today’s profit');
        $this->get(route('manage.show', ['products', $p->id]))->assertOk()->assertDontSee('Cost price')->assertDontSee('Stock value at cost')->assertDontSee('Originally 10');
        $this->putJson(route('stock-prices.update', $p->stockLayers->first()), ['selling_price' => '150', 'confirmed' => 1])->assertForbidden();
    }

    public function test_repricing_does_not_change_historical_cogs_and_stale_quote_is_rejected(): void
    {
        $p = $this->product([['quantity' => '10', 'cost' => '100', 'selling_price' => '130']]);
        $sale = $this->sale($this->data($p, '2'));
        $data = $this->data($p);
        $quote = $this->postJson(route('pos.quote'), $data)->assertOk()->json();
        $this->put(route('stock-prices.update', $p->stockLayers->first()), ['selling_price' => '140', 'confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->postJson(route('pos.complete'), $data + ['amount_paid' => '130', 'checkout_token' => (string) Str::uuid(), 'quote_hash' => $quote['quote_hash']])->assertUnprocessable();
        $this->assertSame('200.00', $sale->fresh()->cost_total);
        $this->assertSame('260.00', $sale->fresh()->sale_amount);
    }

    public function test_multi_unit_sales_and_layer_reports_use_primary_quantities_and_costs(): void
    {
        $p = $this->product();
        $box = Unit::where('short_name', 'box')->firstOrFail();
        $p->conversions()->create(['unit_id' => $box->id, 'base_quantity' => '12', 'converted_quantity' => '1']);
        $data = $this->data($p);
        $data['items'][0]['unit_id'] = $box->id;
        $sale = $this->sale($data);
        $this->assertSame('1560.00', $sale->sale_amount);
        $this->assertSame('1200.00', $sale->cost_total);
        $this->assertSame('23.000', $p->fresh()->stock);
        $this->get(route('reports.show', ['report' => 'stock', 'stock_view' => 'layers']))->assertOk()->assertSee('130.00')->assertSee('140.00');
        $this->get(route('reports.show', 'profit'))->assertOk()->assertSee('1,200.00');
    }

    public function test_multiple_incoming_purchase_rows_keep_their_sources_and_prices(): void
    {
        $p = $this->product();
        $supplier = Supplier::create(['name' => 'Grouped incoming supplier', 'active' => true]);
        $this->post(route('purchases.store'), ['reference' => 'INCOMING-MULTI', 'supplier_id' => $supplier->id, 'purchase_date' => today()->toDateString(), 'items' => [
            ['product_id' => $p->id, 'quantity' => '4', 'cost' => '120', 'selling_price' => '150'],
            ['product_id' => $p->id, 'quantity' => '6', 'cost' => '125', 'selling_price' => '150'],
        ]])->assertRedirect()->assertSessionHasNoErrors();
        $lots = $p->stockLayers()->where('source_type', 'PURCHASE')->orderBy('id')->get();
        $this->assertCount(2, $lots);
        $this->assertNotSame($lots[0]->purchase_item_id, $lots[1]->purchase_item_id);
        $this->assertSame('45.000', $p->fresh()->stock);
        $sale = $this->sale($this->data($p, '5', '150'));
        $this->assertSame('605.00', $sale->cost_total);
        $this->assertSame(['4.000', '1.000'], $sale->items->first()->allocations->pluck('quantity')->all());
    }

    public function test_unused_purchase_can_be_edited_and_voided_without_removing_other_stock(): void
    {
        $p = $this->product();
        $supplier = Supplier::create(['name' => 'Safe edit supplier', 'active' => true]);
        $data = ['reference' => 'SAFE-PURCHASE', 'supplier_id' => $supplier->id, 'purchase_date' => today()->toDateString(), 'items' => [['product_id' => $p->id, 'quantity' => '10', 'cost' => '120', 'selling_price' => '150']]];
        $this->post(route('purchases.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $purchase = Purchase::firstOrFail();
        $data['items'][0]['quantity'] = '5';
        $data['items'][0]['selling_price'] = '155';
        $this->put(route('purchases.update', $purchase), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('40.000', $p->fresh()->stock);
        $this->assertSame(['130.00', '140.00', '155.00'], $p->stockLayers()->available()->orderBy('id')->pluck('selling_price')->all());
        $this->post(route('purchases.void', $purchase), ['reason' => 'Unused delivery correction'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('35.000', $p->fresh()->stock);
        $this->assertSame(['20.000', '15.000'], $p->stockLayers()->available()->orderBy('id')->pluck('remaining_quantity')->all());
    }

    public function test_selected_adjustment_uses_existing_prices_and_reverses_exact_source(): void
    {
        $p = $this->product();
        $lot = $p->stockLayers()->where('selling_price', '140')->firstOrFail();
        app(StockLayerService::class)->adjust($p, '3', $lot->id, '999', '999', 'ADJ-EXISTING', 'Physical stock correction', $this->admin->id);
        $incoming = $p->stockLayers()->where('source_reference', 'ADJ-EXISTING')->firstOrFail();
        $this->assertSame('110.00', $incoming->cost_price);
        $this->assertSame('140.00', $incoming->selling_price);
        $this->assertSame('38.000', $p->fresh()->stock);
        DB::transaction(fn () => app(StockLayerService::class)->reverseSource($p, 'ADJ-EXISTING', 'REVERSE adjustment', $this->admin->id));
        $this->assertSame('35.000', $p->fresh()->stock);
        $this->assertSame('15.000', $lot->fresh()->remaining_quantity);
        $this->assertSame('0.000', $incoming->fresh()->remaining_quantity);
    }

    public function test_rejected_fractional_opening_row_rolls_back_the_entire_product(): void
    {
        $before = Product::count();
        $this->postJson(route('manage.store', 'products'), ['name' => 'Invalid piece lot', 'sku' => 'BAD-PIECE-LOT', 'unit_id' => $this->piece->id, 'low_stock' => '0', 'active' => true, 'opening_layers' => [
            ['quantity' => '10', 'cost' => '100', 'selling_price' => '130'], ['quantity' => '1.5', 'cost' => '110', 'selling_price' => '140'],
        ]])->assertUnprocessable();
        $this->assertSame($before, Product::count());
        $this->assertDatabaseCount('product_stock_layers', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_partial_returns_reverse_the_exact_rounded_allocation_cost(): void
    {
        $kg = Unit::where('short_name', 'kg')->firstOrFail();
        $p = $this->product([['quantity' => '0.001', 'cost' => '5', 'selling_price' => '130'], ['quantity' => '0.001', 'cost' => '5', 'selling_price' => '130']], $kg);
        $sale = $this->sale($this->data($p, '0.002'));
        $this->assertSame('0.01', $sale->cost_total);
        $this->assertSame('0.01', Money::sum($sale->items->first()->allocations->pluck('cost_total')));
        $this->post(route('sales.returns.store', $sale), ['token' => (string) Str::uuid(), 'reason' => 'Exact small quantity return', 'payment_method_id' => PaymentMethod::where('type', 'CASH')->value('id'), 'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => '0.002']]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('0.01', $sale->returns()->first()->cost_total);
        $this->assertSame('0.002', $p->fresh()->stock);
    }

    public function test_migration_backfills_current_stock_once_without_replaying_movements(): void
    {
        $migration = require database_path('migrations/2026_10_08_180000_create_stock_price_layers.php');
        $migration->down();
        $p = Product::create(['name' => 'Legacy product', 'sku' => 'LEGACY-IMPORT', 'unit_id' => $this->piece->id, 'stock' => '7', 'cost' => '110', 'price' => '140', 'low_stock' => '0', 'active' => true]);
        $migration->up();
        $this->assertSame('7.000', $p->fresh()->stock);
        $this->assertSame('7.000', $p->stockLayers()->first()->remaining_quantity);
        $this->assertSame('MIGRATED_STOCK', $p->stockLayers()->first()->source_type);
        $this->assertSame('110.00', $p->stockLayers()->first()->cost_price);
        $this->assertDatabaseCount('stock_movements', 0);
        app(StockLayerService::class)->ensureLegacy($p);
        $this->assertDatabaseCount('product_stock_layers', 1);
    }
}
