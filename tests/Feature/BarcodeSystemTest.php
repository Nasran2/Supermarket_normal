<?php

namespace Tests\Feature;

use App\Models\BarcodeLabelPreset;
use App\Models\BarcodePrintJob;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductStockLayer;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\BarcodePresetService;
use App\Services\BarcodePrintService;
use App\Services\BarcodeService;
use App\Services\PurchaseService;
use App\Services\RegisterService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BarcodeSystemTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['features.barcode' => true, 'features.expiry' => false]);
        $this->admin = User::whereHas('role', fn ($q) => $q->where('name', 'Administrator'))->firstOrFail();
        $this->actingAs($this->admin);
    }

    private function product(array $extra = []): Product
    {
        return Product::create(array_replace(['name' => 'Bath Soap', 'sku' => 'SOAP-'.Product::count(), 'barcode' => app(BarcodeService::class)->generate(), 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'cost' => '100', 'price' => '130', 'stock' => '25', 'low_stock' => 0, 'active' => true], $extra));
    }

    private function layer(Product $p, string $price = '130', ?string $expiry = null): ProductStockLayer
    {
        return $p->stockLayers()->create(['primary_unit_id' => $p->unit_id, 'source_type' => 'OPENING', 'source_reference' => 'TEST', 'cost_price' => '100', 'selling_price' => $price, 'original_quantity' => '25', 'remaining_quantity' => '25', 'status' => 'ACTIVE', 'received_at' => now(), 'expires_at' => $expiry, 'batch_number' => 'BATCH-'.ProductStockLayer::count()]);
    }

    private function job(Product $p, int $n = 5): array
    {
        $option = app(BarcodePrintService::class)->options($p)[0];

        return ['configuration' => app(BarcodePresetService::class)->defaults(), 'items' => [['product_id' => $p->id, 'option' => $option['key'], 'quantity' => $n]], 'preset_name' => 'Test layout', 'confirmed' => true];
    }

    public function test_disabled_ui_routes_search_and_data_preserve_barcodes(): void
    {
        $p = $this->product(['barcode' => 'ONLYBARCODE']);
        $before = $p->barcode;
        config(['features.barcode' => false]);
        $this->get(route('manage.create', 'products'))->assertOk()->assertDontSee('name="barcode"', false)->assertDontSee('Scan or enter barcode');
        $this->get(route('manage.edit', ['products', $p->id]))->assertOk()->assertDontSee('name="barcode"', false);
        $this->get(route('manage.show', ['products', $p->id]))->assertOk()->assertDontSee('ONLYBARCODE')->assertDontSee('Print Label')->assertDontSee('Barcode Labels');
        $this->get(route('manage.index', 'products'))->assertOk()->assertDontSee('ONLYBARCODE')->assertDontSee('barcode');
        $this->get(route('manage.index', ['resource' => 'products', 'q' => 'ONLYBARCODE']))->assertOk()->assertDontSee('Bath Soap');
        $this->get(route('manage.create', 'roles'))->assertOk()->assertDontSee('Barcode labels');
        $this->get(route('manage.show', ['roles', $this->admin->role_id]))->assertOk()->assertDontSee('Barcode labels');
        $this->get(route('settings.edit', 'pos'))->assertOk()->assertDontSee('Enable barcode scanner')->assertDontSee('>Barcode<', false);
        $this->get(route('settings.index'))->assertOk()->assertDontSee('Barcode');
        foreach (['barcode.index', 'barcode.print', 'barcode.presets.index', 'barcode.history', 'barcode.calibration', 'barcode.settings'] as $name) {
            $this->get(route($name))->assertNotFound();
        }
        foreach (['barcode.generate', 'barcode.preview', 'barcode.jobs.store', 'barcode.presets.store'] as $name) {
            $this->postJson(route($name), [])->assertNotFound();
        }
        $this->assertFalse($this->admin->hasPermission('barcode.print'));
        $this->assertArrayNotHasKey('barcode', $p->toArray());
        $this->assertSame($before, $p->fresh()->barcode);
        config(['features.barcode' => true]);
        $this->get(route('manage.show', ['products', $p->id]))->assertOk()->assertSee('ONLYBARCODE')->assertSee('Print Label');
    }

    public function test_disabled_product_updates_ignore_submitted_barcode_and_keep_hidden_role_permissions(): void
    {
        $p = $this->product();
        $barcode = $p->barcode;
        $role = Role::create(['name' => 'Label staff']);
        $permission = Permission::where('name', 'barcode.print')->first();
        $role->permissions()->attach($permission);
        config(['features.barcode' => false]);
        $this->put(route('manage.update', ['products', $p->id]), ['name' => $p->name, 'sku' => $p->sku, 'unit_id' => $p->unit_id, 'cost' => '100', 'price' => '130', 'low_stock' => 0, 'active' => 1, 'barcode' => 'CHANGED'])->assertSessionHasNoErrors();
        $this->assertSame($barcode, $p->fresh()->barcode);
        $this->put(route('manage.update', ['roles', $role->id]), ['name' => 'Label staff revised', 'sales_visibility' => 'OWN', 'permissions' => []])->assertSessionHasNoErrors();
        $this->assertTrue($role->fresh()->permissions->contains('id', $permission->id));
        $this->put(route('manage.update', ['roles', $role->id]), ['name' => 'Label staff revised', 'permissions' => [$permission->id]])->assertSessionHasErrors('permissions');
    }

    public function test_duplicate_named_error_and_invalid_ean_are_rejected(): void
    {
        $p = $this->product(['barcode' => 'SOAP-CODE']);
        $payload = ['name' => 'Another Soap', 'sku' => 'DIFFERENT', 'barcode' => $p->barcode, 'barcode_type' => 'CODE128', 'unit_id' => $p->unit_id, 'cost' => 0, 'price' => 130, 'stock' => 0, 'low_stock' => 0, 'active' => 1];
        $this->postJson(route('manage.store', 'products'), $payload)->assertUnprocessable()->assertJsonPath('errors.barcode.0', 'This barcode is already assigned to Bath Soap.');
        $payload['barcode'] = '1234567890123';
        $payload['barcode_type'] = 'EAN13';
        $this->postJson(route('manage.store', 'products'), $payload)->assertUnprocessable()->assertJsonValidationErrors('barcode');
    }

    public function test_generation_supports_all_formats_and_preserves_manual_entry(): void
    {
        foreach (BarcodeService::TYPES as $type) {
            $a = $this->postJson(route('barcode.generate'), ['barcode_type' => $type])->assertOk()->json('barcode');
            $b = $this->postJson(route('barcode.generate'), ['barcode_type' => $type])->assertOk()->json('barcode');
            $this->assertNotSame($a, $b);
            app(BarcodeService::class)->validate($a, $type);
        }
        app(SettingsService::class)->put('barcode', ['barcode_generate_on_create' => true]);
        $unit = Unit::where('short_name', 'pcs')->value('id');
        $body = ['name' => 'Manual Soap', 'sku' => 'MANUAL-SOAP', 'barcode' => 'MANUAL-123', 'barcode_type' => 'CODE128', 'unit_id' => $unit, 'cost' => 0, 'price' => 130, 'stock' => 0, 'low_stock' => 0, 'active' => 1];
        $this->post(route('manage.store', 'products'), $body)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('products', ['sku' => 'MANUAL-SOAP', 'barcode' => 'MANUAL-123']);
        $body['sku'] = 'AUTO-SOAP';
        unset($body['barcode']);
        $this->post(route('manage.store', 'products'), $body)->assertSessionHasNoErrors();
        $this->assertNotNull(Product::where('sku', 'AUTO-SOAP')->value('barcode'));
    }

    public function test_individual_job_has_exact_quantity_and_reprint_preserves_snapshots(): void
    {
        $p = $this->product();
        $data = $this->job($p);
        $this->postJson(route('barcode.preview'), $data)->assertOk()->assertJsonPath('total', 5)->assertJsonPath('rows', 3);
        $this->postJson(route('barcode.jobs.store'), $data)->assertOk();
        $job = BarcodePrintJob::latest('id')->first();
        $this->assertSame(5, $job->total_labels);
        $p->update(['name' => 'Changed name', 'price' => '999']);
        $html = $this->get(route('barcode.jobs.show', $job))->assertOk()->assertSee('Bath Soap')->assertDontSee('Changed name')->getContent();
        $this->assertSame(5, substr_count($html, 'class="barcode-label"'));
        $this->assertSame(3, substr_count($html, 'class="barcode-sheet-row"'));
    }

    public function test_multiple_prices_require_selection_and_support_quantities_per_price(): void
    {
        $p = $this->product();
        $this->layer($p, '130');
        $this->layer($p, '140');
        $p->unsetRelation('stockLayers');
        $options = app(BarcodePrintService::class)->options($p);
        $this->assertCount(2, $options);
        $data = $this->job($p);
        $data['items'][0]['option'] = '';
        $this->postJson(route('barcode.jobs.store'), $data)->assertUnprocessable();
        $data['items'] = [['product_id' => $p->id, 'option' => $options[0]['key'], 'quantity' => 10], ['product_id' => $p->id, 'option' => $options[1]['key'], 'quantity' => 15]];
        $this->postJson(route('barcode.jobs.store'), $data)->assertOk()->assertJsonPath('total', 25);
        $this->assertSame(['130.00', '140.00'], array_column(BarcodePrintJob::latest('id')->first()->labels, 'price'));
    }

    public function test_bulk_fifteen_labels_eight_rows_and_partial_row(): void
    {
        $a = $this->product();
        $b = $this->product(['name' => 'Milk']);
        $data = $this->job($a);
        $data['items'][] = $this->job($b, 10)['items'][0];
        $response = $this->postJson(route('barcode.preview'), $data)->assertOk()->assertJsonPath('total', 15)->assertJsonPath('rows', 8);
        $this->assertSame(8, substr_count($response->json('html'), 'class="barcode-label"'));
        $this->postJson(route('barcode.jobs.store'), $data)->assertOk();
        $job = BarcodePrintJob::latest('id')->first();
        $html = $this->get(route('barcode.jobs.show', $job))->assertOk()->getContent();
        $this->assertSame(15, substr_count($html, 'class="barcode-label"'));
        $this->assertSame(8, substr_count($html, 'class="barcode-sheet-row"'));
    }

    public function test_dimensions_validate_width_padding_and_page_height(): void
    {
        $p = $this->product();
        $data = $this->job($p);
        $this->postJson(route('barcode.preview'), $data)->assertOk();
        $data['configuration'] = array_replace($data['configuration'], ['sticker_width' => '50', 'column_gap' => '5', 'paper_width' => '100']);
        $this->postJson(route('barcode.preview'), $data)->assertUnprocessable()->assertJsonValidationErrors('configuration.paper_width');
        $data = $this->job($p);
        $data['configuration']['padding_left'] = '49';
        $this->postJson(route('barcode.preview'), $data)->assertUnprocessable();
        $data = $this->job($p);
        $data['configuration']['paper_height'] = '20';
        $this->postJson(route('barcode.preview'), $data)->assertUnprocessable();
    }

    public function test_missing_barcode_and_forged_prices_are_blocked(): void
    {
        $p = $this->product(['barcode' => null]);
        $data = $this->job($p);
        $this->postJson(route('barcode.jobs.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('items.0');
        $this->postJson(route('barcode.assign', $p), [])->assertOk();
        $p->refresh();
        $data = $this->job($p);
        $data['items'][0]['option'] = '1|0.01|';
        $this->postJson(route('barcode.jobs.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('items.0.option');
    }

    public function test_expiry_options_are_exact_and_hidden_flag_preserves_history(): void
    {
        config(['features.expiry' => true]);
        $p = $this->product(['track_expiry' => true]);
        $a = $this->layer($p, '130', '2026-12-20');
        $b = $this->layer($p, '130', '2027-02-15');
        $p->unsetRelation('stockLayers');
        $options = app(BarcodePrintService::class)->options($p);
        $this->assertCount(2, $options);
        $this->get(route('barcode.index', ['product' => $p->id]))->assertOk()->assertSee('show_expiry');
        $data = $this->job($p);
        $data['configuration']['show_expiry'] = true;
        $data['configuration']['show_batch'] = true;
        $this->postJson(route('barcode.jobs.store'), $data)->assertOk();
        $job = BarcodePrintJob::latest('id')->first();
        $this->get(route('barcode.jobs.show', $job))->assertOk()->assertSee('Exp: 20/12/2026')->assertSee($a->batch_number)->assertDontSee('Exp: 15/02/2027');
        config(['features.expiry' => false]);
        $this->get(route('barcode.index', ['product' => $p->id]))->assertOk()->assertDontSee('data-config="show_expiry"', false)->assertDontSee('data-config="expiry_font_size"', false);
        $this->get(route('barcode.jobs.show', $job))->assertOk()->assertDontSee('Exp: 20/12/2026')->assertDontSee('Batch:');
        $this->assertSame('2026-12-20', $job->labels[0]['expiry']);
    }

    public function test_preset_crud_single_default_and_snapshot_reprint(): void
    {
        $p = $this->product();
        $data = $this->job($p);
        $body = ['name' => 'Custom shelf', 'configuration' => $data['configuration'], 'is_default' => true];
        $id = $this->postJson(route('barcode.presets.store'), $body)->assertCreated()->json('id');
        $this->assertSame(1, BarcodeLabelPreset::where('is_default', true)->count());
        $this->delete(route('barcode.presets.destroy', $id))->assertSessionHasErrors('preset');
        $this->post(route('barcode.presets.duplicate', $id))->assertSessionHasNoErrors();
        $this->postJson(route('barcode.jobs.store'), $data)->assertOk();
        $job = BarcodePrintJob::latest('id')->first();
        $body['configuration']['price_font_size'] = 22;
        $this->putJson(route('barcode.presets.update', $id), $body)->assertOk();
        $this->assertSame(15, $job->fresh()->configuration['price_font_size']);
        $this->assertSame(1, BarcodeLabelPreset::where('is_default', true)->count());
        $this->assertTrue(BarcodeLabelPreset::find($id)->is_default);
        $other = BarcodeLabelPreset::where('id', '!=', $id)->first();
        $this->post(route('barcode.presets.default', $other))->assertSessionHasNoErrors();
        $this->delete(route('barcode.presets.destroy', $id))->assertSessionHasNoErrors();
    }

    public function test_label_products_require_search_and_match_name_sku_or_barcode(): void
    {
        $p = $this->product(['name' => 'Search Only Soap', 'sku' => 'SEARCH-ONLY-SOAP', 'barcode' => 'SEARCHONLYCODE']);
        $this->product(['name' => 'Other item']);
        foreach ([[], ['q' => '   '], ['active' => 1, 'has_barcode' => 'yes']] as $filters) {
            $this->getJson(route('barcode.products', $filters))->assertOk()->assertExactJson([]);
        }
        foreach ([$p->name, $p->sku, $p->barcode] as $search) {
            $this->getJson(route('barcode.products', ['q' => $search]))
                ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $p->id);
        }
        $this->getJson(route('barcode.products', ['q' => 'does-not-match-any-product']))
            ->assertOk()->assertExactJson([]);
    }

    public function test_permissions_are_enforced_and_cashier_does_not_receive_costs(): void
    {
        $p = $this->product();
        $data = $this->job($p);
        $role = Role::create(['name' => 'Barcode viewer']);
        $role->permissions()->sync(Permission::whereIn('name', ['barcode.view', 'products.view'])->pluck('id'));
        $staff = User::factory()->create(['role_id' => $role->id]);
        $this->flushSession();
        $this->actingAs($staff);
        $this->get(route('manage.show', ['products', $p->id]))->assertOk()->assertDontSee('>Print Label</a>', false);
        $this->postJson(route('barcode.jobs.store'), $data)->assertForbidden();
        $this->postJson(route('barcode.generate'), [])->assertForbidden();
        $this->get(route('barcode.settings'))->assertForbidden();
        $catalog = $this->getJson(route('barcode.products', ['q' => $p->sku]))->assertOk()->json();
        $this->assertArrayNotHasKey('cost', $catalog[0]);
        $this->assertArrayNotHasKey('cost', $catalog[0]['options'][0]);
        $role->permissions()->attach(Permission::where('name', 'barcode.print')->first());
        $staff->unsetRelation('role');
        $b = $this->product(['name' => 'Second']);
        $data['items'][] = $this->job($b)['items'][0];
        $this->postJson(route('barcode.jobs.store'), $data)->assertForbidden();
    }

    public function test_large_job_confirmation_stock_limit_and_barcode_required(): void
    {
        $p = $this->product();
        $data = $this->job($p, 501);
        $data['confirmed'] = false;
        $this->postJson(route('barcode.jobs.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        app(SettingsService::class)->put('barcode', ['barcode_allow_above_stock' => false]);
        $data = $this->job($p, 26);
        $this->postJson(route('barcode.jobs.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
        app(SettingsService::class)->put('barcode', ['barcode_required' => true]);
        $this->postJson(route('manage.store', 'products'), ['name' => 'Required', 'sku' => 'REQ', 'unit_id' => $p->unit_id, 'cost' => 0, 'price' => 0, 'stock' => 0, 'low_stock' => 0, 'active' => 1])->assertUnprocessable()->assertJsonValidationErrors('barcode');
    }

    public function test_pos_flag_search_and_multiple_stock_options(): void
    {
        $p = $this->product(['barcode' => 'SCAN-ONLY']);
        $this->layer($p, '130');
        $this->layer($p, '140');
        app(RegisterService::class)->open($this->admin->id, '0');
        $this->getJson(route('pos.products', ['q' => 'SCAN-ONLY', 'scan' => 1]))->assertOk()->assertJsonPath('0.id', $p->id)->assertJsonCount(2, '0.price_options');
        config(['features.barcode' => false]);
        $this->getJson(route('pos.products', ['q' => 'SCAN-ONLY', 'scan' => 1]))->assertOk()->assertExactJson([]);
        $this->get(route('pos.index'))->assertOk()->assertSee('data-barcode="0"', false)->assertDontSee('Scan a barcode');
    }

    public function test_purchase_suggestions_use_received_base_quantities_and_prices(): void
    {
        $a = $this->product(['stock' => 0]);
        $b = $this->product(['name' => 'Milk', 'stock' => 0]);
        $purchase = app(PurchaseService::class)->save(['reference' => 'PUR-BARCODE', 'supplier_id' => Supplier::create(['name' => 'Barcode test supplier', 'active' => true])->id, 'purchase_date' => today()->format('Y-m-d'), 'items' => [['product_id' => $a->id, 'unit_id' => $a->unit_id, 'quantity' => '24', 'cost' => '100', 'selling_price' => '130'], ['product_id' => $b->id, 'unit_id' => $b->unit_id, 'quantity' => '12', 'cost' => '100', 'selling_price' => '140']]], $this->admin->id);
        $rows = app(BarcodePrintService::class)->purchase($purchase);
        $this->assertSame([24, 12], array_column($rows, 'quantity'));
        $this->assertNotEmpty($rows[0]['option']);
        $this->assertNotEmpty($rows[1]['option']);
        $this->get(route('purchases.show', $purchase))->assertOk()->assertSee('Print Labels for Purchased Items');
        $this->get(route('barcode.index', ['purchase' => $purchase->id]))->assertOk();
    }

    public function test_all_mutating_routes_are_blocked_when_disabled(): void
    {
        $p = $this->product();
        $preset = BarcodeLabelPreset::first();
        $this->postJson(route('barcode.jobs.store'), $this->job($p))->assertOk();
        $job = BarcodePrintJob::latest('id')->first();
        config(['features.barcode' => false]);
        $this->postJson(route('barcode.assign', $p), [])->assertNotFound();
        $this->get(route('barcode.products'))->assertNotFound();
        $this->get(route('barcode.jobs.show', $job))->assertNotFound();
        $this->putJson(route('barcode.settings.update'), [])->assertNotFound();
        $this->putJson(route('barcode.presets.update', $preset), [])->assertNotFound();
        $this->post(route('barcode.presets.duplicate', $preset))->assertNotFound();
        $this->post(route('barcode.presets.default', $preset))->assertNotFound();
        $this->delete(route('barcode.presets.destroy', $preset))->assertNotFound();
        $this->get(route('barcode.presets.create'))->assertNotFound();
        $this->get(route('barcode.presets.edit', $preset))->assertNotFound();
        $this->assertDatabaseHas('barcode_print_jobs', ['id' => $job->id]);
        $this->assertDatabaseHas('barcode_label_presets', ['id' => $preset->id]);
    }

    public function test_stock_limit_counts_repeated_rows_and_unit_prices_use_existing_conversion(): void
    {
        $p = $this->product();
        $this->layer($p, '130');
        $box = Unit::where('short_name', 'box')->first();
        $p->conversions()->create(['unit_id' => $box->id, 'base_quantity' => '12', 'converted_quantity' => '1', 'price' => null]);
        $options = app(BarcodePrintService::class)->options($p->fresh());
        $this->assertSame('1560.00', collect($options)->firstWhere('unit_id', $box->id)['price']);
        $this->assertSame('2.083', collect($options)->firstWhere('unit_id', $box->id)['stock']);
        app(SettingsService::class)->put('barcode', ['barcode_allow_above_stock' => false]);
        $data = $this->job($p, 20);
        $data['items'][] = $data['items'][0];
        $this->postJson(route('barcode.jobs.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('items.1.quantity');
    }

    public function test_tiny_labels_long_barcodes_and_negative_offsets_are_rejected(): void
    {
        $p = $this->product();
        $data = $this->job($p);
        $data['configuration']['barcode_height'] = '1';
        $this->postJson(route('barcode.preview'), $data)->assertUnprocessable();
        $data = $this->job($p);
        $data['configuration']['offset_x'] = '-1';
        $this->postJson(route('barcode.preview'), $data)->assertUnprocessable();
        $data = $this->job($p);
        $data['configuration']['sticker_height'] = '10';
        $this->postJson(route('barcode.preview'), $data)->assertUnprocessable();
        $p->update(['barcode' => str_repeat('A', 80)]);
        $this->postJson(route('barcode.preview'), $this->job($p))->assertUnprocessable()->assertJsonValidationErrors('configuration.sticker_width');
    }

    public function test_svg_quiet_zones_and_sheet_offsets_are_stable(): void
    {
        $svg = app(BarcodeService::class)->svg('2000000000001', 'CODE128', '48', '8');
        $this->assertStringContainsString('viewBox="-10 0 ', $svg);
        $this->assertStringContainsString('width="48mm"', $svg);
        $this->assertStringContainsString('height="8mm"', $svg);
        $p = $this->product();
        $data = $this->job($p, 5);
        $data['configuration']['skip_labels'] = 1;
        $data['configuration']['paper_height'] = '53';
        $this->postJson(route('barcode.jobs.store'), $data)->assertOk();
        $html = $this->get(route('barcode.jobs.show', BarcodePrintJob::latest('id')->first()))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'class="barcode-blank"'));
        $this->assertSame(2, substr_count($html, 'class="barcode-sheet"'));
        $this->assertSame(5, substr_count($html, 'class="barcode-label"'));
    }
}
