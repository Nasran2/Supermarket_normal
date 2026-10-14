<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\BarcodePresetService;
use App\Services\BarcodePrintService;
use App\Services\DefaultUnitService;
use App\Services\ProductUnitService;
use App\Services\RegisterService;
use App\Services\ReportService;
use App\Services\SettingsService;
use App\Services\UnitService;
use App\Support\Permissions;
use App\Support\Units;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UnitModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $cash;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.unit' => true]);
        $this->seed();
        $this->admin = User::where('username', 'admin')->firstOrFail();
        $this->actingAs($this->admin);
        $this->cash = PaymentMethod::where('type', 'CASH')->value('id');
        app(RegisterService::class)->open($this->admin->id, '10000');
    }

    private function product(string $short = 'pcs', array $extra = []): Product
    {
        $sku = 'QTY-'.Str::random(8);
        $this->post(route('manage.store', 'products'), $extra + ['name' => 'Quantity test product', 'sku' => $sku, 'unit_id' => Unit::where('short_name', $short)->value('id'), 'cost' => '100', 'price' => '130', 'stock' => '48', 'low_stock' => '2', 'active' => true])->assertRedirect()->assertSessionHasNoErrors();

        return Product::where('sku', $sku)->firstOrFail();
    }

    private function sale(Product $p, string $quantity = '2', ?int $unitId = null): Sale
    {
        $d = ['items' => [['product_id' => $p->id, 'quantity' => $quantity, 'unit_id' => $unitId]], 'payment_method_id' => $this->cash];
        $q = $this->postJson(route('pos.quote'), $d)->assertOk()->json();
        $this->postJson(route('pos.complete'), $d + ['amount_paid' => $q['customer_payable'], 'checkout_token' => (string) Str::uuid(), 'quote_hash' => $q['quote_hash']])->assertOk();

        return Sale::latest('id')->firstOrFail();
    }

    private function visible(string $html): string
    {
        return html_entity_decode(strip_tags(preg_replace('~<(script|style)\b[^>]*>.*?</\1>~si', '', $html)));
    }

    public function test_disabled_routes_permissions_forms_and_sidebar_and_reenable(): void
    {
        $p = $this->product();
        config(['features.unit' => false]);
        foreach (['units', 'unit-presets'] as $resource) {
            foreach (['index', 'create'] as $action) {
                $this->get(route('manage.'.$action, $resource))->assertNotFound();
            }
            $this->postJson(route('manage.store', $resource), [])->assertNotFound();
            $this->get(route('manage.edit', [$resource, 1]))->assertNotFound();
            $this->get(route('manage.show', [$resource, 1]))->assertNotFound();
            $this->putJson(route('manage.update', [$resource, 1]), [])->assertNotFound();
            $this->deleteJson(route('manage.destroy', [$resource, 1]))->assertNotFound();
        }
        $this->post(route('units.default', Unit::first()))->assertNotFound();
        foreach (array_keys(Permissions::visible()) as $name) {
            $this->assertFalse(Units::permission($name));
        }
        $this->assertFalse($this->admin->hasPermission('units.set_default'));
        foreach ([route('dashboard'), route('manage.create', 'products'), route('manage.edit', ['products', $p->id]), route('manage.index', 'products'), route('manage.show', ['products', $p->id]), route('purchases.create'), route('pos.index'), route('manage.create', 'roles'), route('settings.index'), route('settings.edit', 'receipt')] as $url) {
            $r = $this->get($url)->assertOk();
            $this->assertDoesNotMatchRegularExpression('/\b(?:units?|pcs|kilogram|conversions?)\b/i', $this->visible($r->getContent()), $url);
            $r->assertDontSee('name="unit_id"', false)->assertDontSee('id="line-unit"', false)->assertDontSee('data-field="unit_id"', false);
        }
        config(['features.unit' => true]);
        $this->get(route('manage.index', 'units'))->assertOk();
        $this->get(route('manage.index', 'unit-presets'))->assertOk();
        $this->get(route('manage.create', 'products'))->assertOk()->assertSee('Primary stock unit');
        $this->assertTrue($this->admin->hasPermission('units.set_default'));
    }

    public function test_disabled_creation_uses_resolved_default_and_product_edit_preserves_units_and_conversions(): void
    {
        $grams = Unit::where('short_name', 'g')->firstOrFail();
        $p = $this->product('kg', ['conversions' => [['unit_id' => $grams->id, 'base_quantity' => '1', 'converted_quantity' => '1000', 'price' => null]]]);
        $before = $p->conversions->toArray();
        config(['features.unit' => false]);
        $new = $this->product('kg', ['conversions' => [['unit_id' => -999]], 'conversions_present' => true]);
        $this->assertSame(app(DefaultUnitService::class)->resolve()->id, $new->unit_id);
        $this->assertCount(0, $new->conversions);
        $this->put(route('manage.update', ['products', $p->id]), ['name' => 'Renamed tomatoes', 'sku' => $p->sku, 'cost' => '100', 'price' => '130', 'low_stock' => '2', 'active' => true, 'unit_id' => $new->unit_id, 'conversions_present' => true, 'conversions' => []])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(Unit::where('short_name', 'kg')->value('id'), $p->fresh()->unit_id);
        $this->assertSame($before, $p->fresh()->conversions->toArray());
        $this->assertSame('48.000', $p->fresh()->stock);
        config(['features.unit' => true]);
        $this->assertCount(2, app(ProductUnitService::class)->options($p->fresh()));
        $this->get(route('manage.show', ['products', $p->id]))->assertOk()->assertSee('1000 g');
    }

    public function test_default_is_idempotent_repairs_old_data_and_respects_admin_choice(): void
    {
        Unit::where('default_slot', 1)->update(['default_slot' => null]);
        $service = app(DefaultUnitService::class);
        $count = Unit::count();
        $first = $service->resolve();
        $this->assertSame('pcs', $first->short_name);
        $this->assertSame($first->id, $service->resolve()->id);
        $this->assertSame($count, Unit::count());
        $kg = Unit::where('short_name', 'kg')->firstOrFail();
        $this->post(route('units.default', $kg), ['confirmed' => 1])->assertRedirect();
        config(['features.unit' => false]);
        $this->assertSame($kg->id, $service->resolve()->id);
        $p = $this->product();
        $this->assertSame($kg->id, $p->unit_id);
        $this->assertSame(1, Unit::whereNotNull('default_slot')->count());
        $count = Unit::count();
        Unit::whereNotNull('default_slot')->update(['default_slot' => null]);
        Unit::where('short_name', 'pcs')->update(['name' => 'Each', 'short_name' => 'ea']);
        $fallback = $service->resolve();
        $this->assertSame('pcs', $fallback->short_name);
        $this->assertFalse($fallback->allow_decimal);
        $this->assertSame($count + 1, Unit::count());
        $this->assertSame($fallback->id, $service->resolve()->id);
        $this->assertSame($kg->id, $p->fresh()->unit_id);
    }

    public function test_hidden_role_permissions_survive_edit_and_cannot_be_granted(): void
    {
        $role = Role::create(['name' => 'Unit operator', 'sales_visibility' => 'OWN']);
        $ids = Permission::whereIn('name', ['units.view', 'units.edit', 'unit-presets.create'])->pluck('id')->all();
        $role->permissions()->sync($ids);
        config(['features.unit' => false]);
        $this->get(route('manage.edit', ['roles', $role->id]))->assertOk()->assertDontSee('>Units<', false)->assertDontSee('>Multiple units<', false);
        $this->get(route('manage.show', ['roles', $role->id]))->assertOk()->assertDontSee('>Units<', false);
        $this->put(route('manage.update', ['roles', $role->id]), ['name' => $role->name, 'sales_visibility' => 'OWN', 'permissions' => []])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing($ids, $role->fresh()->permissions->pluck('id')->all());
        $this->postJson(route('manage.store', 'roles'), ['name' => 'Forged permissions', 'sales_visibility' => 'OWN', 'permissions' => $ids])->assertUnprocessable();
        config(['features.unit' => true]);
        $this->get(route('manage.show', ['roles', $role->id]))->assertOk()->assertSee('Units');
    }

    public function test_disabled_pos_uses_existing_decimal_basis_and_rejects_fractional_pieces(): void
    {
        $p = $this->product('kg');
        config(['features.unit' => false]);
        $payload = $this->getJson(route('pos.products', ['ids' => [$p->id]]))->assertOk()->json('0');
        $this->assertSame('', $payload['unit']);
        $this->assertTrue($payload['decimal']);
        $this->assertSame('', $payload['units'][0]['name']);
        $this->assertCount(1, $payload['units']);
        $sale = $this->sale($p, '1.5', Unit::where('short_name', 'g')->value('id'));
        $this->assertSame('46.500', $p->fresh()->stock);
        $this->assertSame('kg', $sale->items->first()->unit);
        $this->assertSame('1.500', $sale->items->first()->base_quantity);
        foreach (['sales.show', 'sales.receipt'] as $route) {
            $r = $this->get(route($route, $sale))->assertOk();
            $this->assertDoesNotMatchRegularExpression('/\b(?:kg|pcs|unit|units)\b/i', $this->visible($r->getContent()));
        }
        $piece = $this->product();
        $r = $this->postJson(route('pos.quote'), ['items' => [['product_id' => $piece->id, 'quantity' => '1.5']], 'payment_method_id' => $this->cash])->assertUnprocessable();
        $this->assertStringNotContainsString('pcs', json_encode($r->json()));
    }

    public function test_purchase_and_stock_adjustment_require_no_browser_unit_and_keep_primary_basis(): void
    {
        $p = $this->product('kg');
        $supplier = Supplier::create(['name' => 'Unit supplier', 'active' => true]);
        config(['features.unit' => false]);
        $this->post(route('purchases.store'), ['supplier_id' => $supplier->id, 'purchase_date' => today()->toDateString(), 'auto_reference' => true, 'payment_mode' => 'UNPAID', 'items' => [['product_id' => $p->id, 'quantity' => '2.5', 'cost' => '110', 'selling_price' => '140', 'unit_id' => -1]]])->assertRedirect()->assertSessionHasNoErrors();
        $purchase = Purchase::latest('id')->firstOrFail();
        $this->assertSame('50.500', $p->fresh()->stock);
        $this->assertSame($p->unit_id, $purchase->items->first()->unit_id);
        $this->assertSame('kg', $purchase->items->first()->unit);
        $row = $this->getJson(route('adjustments.products', ['q' => $p->sku]))->assertOk()->json('0');
        $this->assertSame('', $row['unit']);
        unset($row['unit_id']);
        $this->post(route('adjustments.store'), ['reason' => 'Count correction', 'items' => [$row + ['mode' => 'ADD', 'quantity' => '0.5']]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('51.000', $p->fresh()->stock);
        $this->get(route('purchases.show', $purchase))->assertOk()->assertDontSee('kg')->assertDontSee('Cost / unit');
    }

    public function test_historical_sale_and_purchase_returns_restore_saved_conversion_quantities(): void
    {
        $dozen = Unit::where('short_name', 'doz')->firstOrFail();
        $p = $this->product('pcs', ['conversions' => [['unit_id' => $dozen->id, 'base_quantity' => '12', 'converted_quantity' => '1', 'price' => null]]]);
        $sale = $this->sale($p, '2', $dozen->id);
        $snapshot = $sale->items->first()->getAttributes();
        config(['features.unit' => false]);
        $d = ['token' => (string) Str::uuid(), 'original_id' => $sale->id, 'reason' => 'Wrong item', 'resolution' => 'MONEY', 'payment_method_id' => $this->cash, 'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => '1', 'stock_action' => 'RESTOCK']]];
        $q = $this->postJson(route('returns.quote', 'sales'), $d)->assertOk()->assertJsonPath('lines.0.unit', '')->json();
        $this->postJson(route('returns.store', 'sales'), $d + ['quote_hash' => $q['quote_hash']])->assertOk();
        $this->assertSame('36.000', $p->fresh()->stock);
        $this->assertSame($snapshot, $sale->items->first()->fresh()->getAttributes());
        $this->get(route('returns.receipt', ['sales', $sale->returns()->first()->id]))->assertOk()->assertDontSee('doz');
        config(['features.unit' => true]);
        $supplier = Supplier::create(['name' => 'Historical supplier', 'active' => true]);
        $this->post(route('purchases.store'), ['supplier_id' => $supplier->id, 'purchase_date' => today()->toDateString(), 'auto_reference' => true, 'payment_mode' => 'UNPAID', 'items' => [['product_id' => $p->id, 'unit_id' => $dozen->id, 'quantity' => '2', 'cost' => '1200', 'selling_price' => '1560']]])->assertRedirect()->assertSessionHasNoErrors();
        $purchase = Purchase::latest('id')->firstOrFail();
        config(['features.unit' => false]);
        $this->postJson(route('returns.store', 'purchase'), ['token' => (string) Str::uuid(), 'original_id' => $purchase->id, 'reason' => 'Damaged', 'resolution' => 'MONEY', 'payment_method_id' => $this->cash, 'items' => [['purchase_item_id' => $purchase->items->first()->id, 'quantity' => '1']]])->assertOk();
        $this->assertSame('48.000', $p->fresh()->stock);
        config(['features.unit' => true]);
        $this->assertSame('doz', $purchase->items->first()->fresh()->unit);
        $this->assertCount(1, $p->fresh()->conversions);
    }

    public function test_disabled_exports_remove_columns_and_values_and_receipt_settings_are_preserved(): void
    {
        $p = $this->product('kg');
        $this->sale($p);
        config(['features.unit' => false]);
        foreach (['stock', 'product-sales'] as $kind) {
            $report = app(ReportService::class)->build($kind, ['from' => today()->toDateString(), 'to' => today()->toDateString()]);
            $this->assertNotContains('Unit', $report['headers']);
            $this->assertNotContains('Primary unit', $report['headers']);
            foreach ($report['query']->get() as $row) {
                $values = $report['map']($row);
                $this->assertCount(count($report['headers']), $values);
                $this->assertNotContains('kg', $values);
            }
            $csv = $this->get(route('reports.export', $kind))->assertOk()->streamedContent();
            $this->assertStringNotContainsString('Unit', $csv);
            $this->assertStringNotContainsString(',kg,', $csv);
        }
        $this->get(route('reports.pdf', 'stock'))->assertOk()->assertHeader('content-type', 'application/pdf');
        $settings = app(SettingsService::class)->all();
        $fields = config('pos.receipt');
        $data = array_intersect_key($settings, $fields);
        $data['show_unit'] = false;
        $this->put(route('settings.update', 'receipt'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(app(SettingsService::class)->get('show_unit'));
    }

    public function test_barcode_expiry_cheque_remain_independent_and_unit_settings_are_preserved(): void
    {
        config(['features.barcode' => true, 'features.expiry' => true, 'features.cheque' => true]);
        $p = $this->product('pcs', ['barcode' => 'UNIT-BARCODE', 'track_expiry' => true, 'stock' => '0', 'opening_layers' => [['quantity' => 10, 'cost' => 100, 'selling_price' => 130, 'expires_at' => today()->addDays(10)->toDateString()], ['quantity' => 20, 'cost' => 110, 'selling_price' => 140, 'expires_at' => today()->addDays(20)->toDateString()]]]);
        $presets = app(BarcodePresetService::class);
        $config = $presets->defaults();
        $config['show_unit'] = true;
        $preset = $presets->save(['name' => 'Unit label', 'configuration' => $config]);
        config(['features.unit' => false]);
        $printing = app(BarcodePrintService::class);
        $options = $printing->options($p);
        $this->assertCount(2, $options);
        $this->assertSame('', $options[0]['unit']);
        $resolved = $printing->resolve(['configuration' => $config, 'items' => [['product_id' => $p->id, 'option' => $options[0]['key'], 'quantity' => 1]]]);
        $html = view('barcode.sheet', ['labels' => $resolved['labels'], 'c' => $resolved['configuration']])->render();
        $this->assertStringNotContainsString('pcs', $this->visible($html));
        $this->get(route('barcode.index'))->assertOk()->assertDontSee('data-config="show_unit"', false)->assertDontSee('data-config="unit_font_size"', false);
        $presets->save(['name' => $preset->name, 'configuration' => $presets->defaults()], $preset);
        $this->assertTrue($preset->fresh()->configuration['show_unit']);
        $payload = $this->getJson(route('pos.products', ['ids' => [$p->id]]))->assertOk()->json('0');
        $this->assertCount(2, $payload['price_options']);
        $this->assertTrue($payload['track_expiry']);
        $this->assertSame('', $payload['unit']);
        $this->get(route('expiry.index'))->assertOk()->assertDontSee('pcs');
        $csv = $this->get(route('expiry.export'))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Unit', $csv);
        $this->assertStringNotContainsString('pcs', $csv);
        $this->get(route('cheques.index'))->assertOk();
        $this->assertTrue($this->admin->hasPermission('barcode.print'));
        $this->assertTrue($this->admin->hasPermission('expiry.view'));
        $this->assertTrue($this->admin->hasPermission('cheques.view'));
    }

    public function test_historical_same_product_exchanges_keep_original_sale_and_purchase_stock_basis(): void
    {
        $dozen = Unit::where('short_name', 'doz')->firstOrFail();
        $p = $this->product('pcs', ['conversions' => [['unit_id' => $dozen->id, 'base_quantity' => '12', 'converted_quantity' => '1', 'price' => null]]]);
        $sale = $this->sale($p, '2', $dozen->id);
        config(['features.unit' => false]);
        $d = ['token' => (string) Str::uuid(), 'original_id' => $sale->id, 'reason' => 'Wrong item', 'resolution' => 'SAME', 'payment_method_id' => $this->cash, 'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => '1', 'stock_action' => 'RESTOCK']], 'replacements' => [['product_id' => $p->id, 'sale_item_id' => $sale->items->first()->id, 'quantity' => '1', 'stock_price' => '130']]];
        $this->postJson(route('returns.store', 'sales'), $d)->assertOk();
        $this->assertSame('24.000', $p->fresh()->stock);
        $replacement = $sale->returns()->first()->replacement->items->first();
        $this->assertSame('12.000', $replacement->base_quantity);
        $this->assertSame('1.000', $replacement->quantity);
        $this->assertSame($dozen->id, $replacement->unit_id);
        config(['features.unit' => true]);
        $supplier = Supplier::create(['name' => 'Exchange supplier', 'active' => true]);
        $this->post(route('purchases.store'), ['supplier_id' => $supplier->id, 'purchase_date' => today()->toDateString(), 'auto_reference' => true, 'payment_mode' => 'UNPAID', 'items' => [['product_id' => $p->id, 'unit_id' => $dozen->id, 'quantity' => '2', 'cost' => '1200', 'selling_price' => '1560']]])->assertRedirect()->assertSessionHasNoErrors();
        $purchase = Purchase::latest('id')->firstOrFail();
        config(['features.unit' => false]);
        $this->postJson(route('returns.store', 'purchase'), ['token' => (string) Str::uuid(), 'original_id' => $purchase->id, 'reason' => 'Damaged', 'resolution' => 'SAME', 'payment_method_id' => $this->cash, 'items' => [['purchase_item_id' => $purchase->items->first()->id, 'quantity' => '1']], 'replacements' => [['product_id' => $p->id, 'purchase_item_id' => $purchase->items->first()->id, 'quantity' => '1', 'cost' => '1200', 'selling_price' => '1560']]])->assertOk();
        $this->assertSame('48.000', $p->fresh()->stock);
        $this->assertSame('12.000', $purchase->returns()->first()->replacement->items->first()->base_quantity);
    }

    public function test_no_receipt_returns_use_primary_basis_and_help_does_not_expose_unit_controls(): void
    {
        $p = $this->product('kg');
        config(['features.unit' => false]);
        app(SettingsService::class)->put('returns', ['no_receipt_cash_refund' => true, 'no_receipt_cost_method' => 'CURRENT_DEFAULT']);
        $this->postJson(route('returns.store', 'sales'), ['return_type' => 'NO_RECEIPT', 'token' => (string) Str::uuid(), 'reason' => 'Wrong item', 'resolution' => 'MONEY', 'payment_method_id' => $this->cash, 'items' => [['product_id' => $p->id, 'quantity' => '0.5', 'stock_action' => 'RESTOCK', 'unit_id' => -999]]])->assertOk();
        $this->assertSame('48.500', $p->fresh()->stock);
        foreach (['add-a-product', 'make-a-sale', 'receive-a-purchase', 'units-and-categories', 'maintain-product-stock'] as $slug) {
            $r = $this->get(route('documentation.show', $slug))->assertOk();
            $this->assertDoesNotMatchRegularExpression('/\b(?:units?|pcs|kg|kilogram|conversions?)\b/i', $this->visible($r->getContent()));
            $r->assertDontSee('help-assets/screens/', false);
        }
    }

    public function test_disabled_purchase_edit_keeps_original_cost_and_does_not_duplicate_landed_charges(): void
    {
        $dozen = Unit::where('short_name', 'doz')->firstOrFail();
        $p = $this->product('pcs', ['conversions' => [['unit_id' => $dozen->id, 'base_quantity' => '12', 'converted_quantity' => '1', 'price' => null]]]);
        $supplier = Supplier::create(['name' => 'Landed cost supplier', 'active' => true]);
        $data = ['supplier_id' => $supplier->id, 'purchase_date' => today()->toDateString(), 'auto_reference' => true, 'payment_mode' => 'UNPAID', 'charge_treatment' => 'COST', 'charges' => [['label' => 'Delivery', 'amount' => '240']], 'items' => [['product_id' => $p->id, 'unit_id' => $dozen->id, 'quantity' => '2', 'cost' => '1200', 'selling_price' => '1560']]];
        $this->post(route('purchases.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $purchase = Purchase::latest('id')->firstOrFail();
        $this->assertSame('110.00', $purchase->items->first()->base_cost);
        config(['features.unit' => false]);
        $input = app(UnitService::class)->purchaseInput($purchase->items->first());
        $this->assertSame('100.00', $input['cost']);
        $this->assertSame('24.000', $input['quantity']);
        $data['items'] = [$input + ['purchase_item_id' => $purchase->items->first()->id]];
        $data['reference'] = $purchase->reference;
        unset($data['payment_mode']);
        $this->put(route('purchases.update', $purchase), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('2640.00', $purchase->fresh()->total);
        $this->assertSame('110.00', $purchase->fresh()->items->first()->base_cost);
        $this->assertSame('72.000', $p->fresh()->stock);
    }
}
