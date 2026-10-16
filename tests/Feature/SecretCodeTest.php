<?php

namespace Tests\Feature;

use App\Models\BarcodeLabelPreset;
use App\Models\BarcodePrintJob;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\BarcodePresetService;
use App\Services\BarcodePrintService;
use App\Services\PurchaseService;
use App\Services\SecretCodeService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecretCodeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.barcode' => true, 'features.scode' => true, 'features.expiry' => false]);
        $this->seed();
        $this->admin = User::where('username', 'admin')->firstOrFail();
        $this->actingAs($this->admin);
        app(SettingsService::class)->put('barcode', $this->settings());
    }

    private function settings(array $extra = []): array
    {
        return $extra + ['scode_digit_0' => 'K', 'scode_digit_5' => 'T', 'scode_digit_7' => 'H', 'scode_digit_8' => 'G', 'scode_fillers' => 'X', 'scode_min_length' => 1] + app(SecretCodeService::class)->defaults();
    }

    private function barcodeSettings(array $extra = []): array
    {
        return $extra + array_map(fn ($field) => $field[2], config('barcode.settings'));
    }

    private function product(array $extra = []): Product
    {
        return Product::create($extra + ['name' => 'Cost Code Grocery', 'sku' => 'SCODE-'.Product::count(), 'barcode' => 'SCODE-'.Product::count(), 'barcode_type' => 'CODE128', 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'cost' => '850', 'price' => '1250', 'stock' => 20, 'active' => true]);
    }

    private function job(Product $p, array $configuration = []): array
    {
        return ['items' => [['product_id' => $p->id, 'option' => app(BarcodePrintService::class)->options($p)[0]['key'], 'quantity' => 1]], 'configuration' => $configuration + app(BarcodePresetService::class)->defaults()];
    }

    public function test_mapping_preserves_case_and_padding_masks_digit_count_on_either_side(): void
    {
        $service = app(SecretCodeService::class);
        $this->assertSame('BCA', $service->encode('231', '400'));
        $this->assertSame('GTKX', $service->encode('850', '1250'));
        $this->assertSame('XGTK', $service->encode('850', '1250', $this->settings(['scode_padding_side' => 'PREFIX'])));
        $this->assertSame('bca', $service->encode('231', '400', $this->settings(['scode_digit_1' => 'a', 'scode_digit_2' => 'b', 'scode_digit_3' => 'c'])));
        $this->assertSame('GTKXXX', $service->encode('850', '1250', $this->settings(['scode_padding' => 'FIXED', 'scode_min_length' => 6])));
        $this->assertSame('GTK', $service->encode('850', '1250', $this->settings(['scode_padding' => 'NONE'])));
        $this->assertSame('X', preg_replace('/[^X]/', '', $service->encode('0', '0', $this->settings(['scode_min_length' => 2]))));
        $this->assertSame('ABCDEFGHI', $service->encode('123456789', '1', array_replace($service->defaults(), ['scode_padding' => 'FIXED', 'scode_min_length' => 1])));
    }

    public function test_decimal_precision_and_rounding_are_explicit_and_do_not_drop_zeroes(): void
    {
        $service = app(SecretCodeService::class);
        $this->assertSame('GTKX', $service->encode('850.49', '1250'));
        $this->assertSame('GTAX', $service->encode('850.50', '1250'));
        $this->assertSame('GTKTKX', $service->encode('850.50', '1250.00', $this->settings(['scode_decimals' => 2])));
        $this->assertSame('KKAXXX', $service->encode('0.01', '1250.00', $this->settings(['scode_decimals' => 2])));
    }

    public function test_settings_reject_duplicate_non_letters_and_fillers_that_overlap_mapping(): void
    {
        $this->get(route('barcode.settings'))->assertOk()->assertSee('Digit 8')->assertSee('Filler letters');
        foreach ([['scode_digit_2' => 'a'], ['scode_digit_2' => '22'], ['scode_fillers' => 'G'], ['scode_fillers' => 'XX'], ['scode_min_length' => 33], ['scode_font_size' => 100], ['scode_padding_side' => 'MIDDLE']] as $extra) {
            $this->putJson(route('barcode.settings.update'), $this->barcodeSettings($this->settings($extra)))->assertUnprocessable();
        }
        $this->put(route('barcode.settings.update'), $this->barcodeSettings($this->settings(['scode_padding_side' => 'PREFIX'])))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('XGTK', app(SecretCodeService::class)->encode('850', '1250'));
    }

    public function test_preview_layout_and_job_snapshots_never_contain_raw_cost_or_mapping(): void
    {
        $p = $this->product();
        $data = $this->job($p, ['secret_code_use_defaults' => false, 'secret_code_position' => 'top', 'secret_code_alignment' => 'left', 'secret_code_font_size' => 9]);
        $html = $this->postJson(route('barcode.preview'), $data)->assertOk()->json('html');
        $this->assertStringContainsString('>GTKX</div>', $html);
        $this->assertTrue(strpos($html, '>GTKX</div>') < strpos($html, '>Cost Code Grocery</div>'));
        $this->assertStringContainsString('text-align:left', $html);
        $this->assertStringNotContainsString('850', $html);
        $this->assertStringNotContainsString('scode_digit', $html);
        $this->postJson(route('barcode.jobs.store'), $data)->assertOk();
        $job = BarcodePrintJob::latest('id')->firstOrFail();
        $this->assertSame('GTKX', $job->labels[0]['secret_code']);
        $this->assertArrayNotHasKey('cost', $job->labels[0]);
        $p->update(['cost' => '999']);
        app(SettingsService::class)->put('barcode', $this->settings(['scode_digit_8' => 'Z']));
        $this->get(route('barcode.jobs.show', $job))->assertOk()->assertSee('>GTKX</div>', false);
        config(['features.scode' => false]);
        $this->get(route('barcode.jobs.show', $job))->assertOk()->assertDontSee('>GTKX</div>', false);
        config(['features.scode' => true]);
        $this->get(route('barcode.jobs.show', $job))->assertOk()->assertSee('>GTKX</div>', false);
    }

    public function test_stock_layer_cost_and_unit_conversion_are_used_without_exposing_costs_in_options(): void
    {
        $p = $this->product(['cost' => '999']);
        foreach (['850', '900'] as $cost) {
            $p->stockLayers()->create(['primary_unit_id' => $p->unit_id, 'cost_price' => $cost, 'selling_price' => '1250', 'original_quantity' => 10, 'remaining_quantity' => 10, 'source_type' => 'OPENING', 'status' => 'ACTIVE', 'received_at' => now()]);
        }
        $box = Unit::create(['name' => 'Cost Code Box', 'short_name' => 'sbox', 'allow_decimal' => false, 'active' => true]);
        $p->conversions()->create(['unit_id' => $box->id, 'base_quantity' => '6', 'converted_quantity' => '1']);
        $p->refresh();
        $options = app(BarcodePrintService::class)->options($p);
        $this->assertCount(4, $options);
        foreach ($options as $option) {
            $this->assertArrayNotHasKey('_cost', $option);
        }
        $response = $this->getJson(route('barcode.products', ['q' => $p->name]))->assertOk()->getContent();
        $this->assertStringNotContainsString('"cost"', $response);
        $this->assertStringNotContainsString('"_cost"', $response);
        $job = $this->job($p);
        $this->assertSame('GTKX', app(BarcodePrintService::class)->resolve($job)['labels'][0]['secret_code']);
        $job['items'][0]['option'] = $options[1]['key'];
        $this->assertSame('TAKK', app(BarcodePrintService::class)->resolve($job)['labels'][0]['secret_code']);
        $job['items'][0]['option'] = $options[2]['key'];
        $this->assertSame('IKKX', app(BarcodePrintService::class)->resolve($job)['labels'][0]['secret_code']);
    }

    public function test_disabled_feature_hides_all_controls_ignores_forged_print_settings_and_preserves_preset(): void
    {
        $p = $this->product();
        $service = app(BarcodePresetService::class);
        $preset = $service->save(['name' => 'Cost layout', 'configuration' => ['secret_code_use_defaults' => false, 'show_secret_code' => true, 'secret_code_position' => 'top'] + $service->defaults()]);
        config(['features.scode' => false]);
        $this->get(route('barcode.settings'))->assertOk()->assertDontSee('name="scode_digit_0"', false);
        $this->get(route('barcode.index'))->assertOk()->assertDontSee('data-secret-layout', false);
        $this->get(route('barcode.presets.edit', $preset))->assertOk()->assertDontSee('data-secret-layout', false);
        $this->putJson(route('barcode.settings.update'), $this->barcodeSettings(['scode_digit_0' => 'Z']))->assertUnprocessable();
        $data = $this->job($p, ['show_secret_code' => true, 'secret_code' => 'FORGED-CODE']);
        $this->postJson(route('barcode.preview'), $data)->assertOk()->assertDontSee('FORGED-CODE')->assertDontSee('>GTKX</div>');
        $service->save(['name' => $preset->name, 'configuration' => $service->defaults()], $preset);
        $this->assertTrue($preset->fresh()->configuration['show_secret_code']);
        $this->assertSame('top', $preset->fresh()->configuration['secret_code_position']);
        config(['features.scode' => true]);
        $this->assertSame('GTKX', app(SecretCodeService::class)->encode('850', '1250'));
    }

    public function test_layout_defaults_apply_to_older_presets_but_allow_individual_overrides(): void
    {
        $preset = BarcodeLabelPreset::firstOrFail();
        app(SettingsService::class)->put('barcode', ['scode_position' => 'top', 'scode_font_size' => 8]);
        $c = app(BarcodePresetService::class)->normalize($preset->configuration);
        $this->assertTrue($c['show_secret_code']);
        $this->assertSame('top', $c['secret_code_position']);
        $this->assertSame(8, $c['secret_code_font_size']);
        $c = app(BarcodePresetService::class)->normalize(['secret_code_use_defaults' => false, 'secret_code_position' => 'before_barcode', 'secret_code_font_size' => 11] + $preset->configuration);
        $this->assertSame('before_barcode', $c['secret_code_position']);
        $this->assertSame(11, $c['secret_code_font_size']);
    }

    public function test_label_staff_cannot_read_or_change_secret_mapping_settings(): void
    {
        $role = Role::create(['name' => 'Label only']);
        $role->permissions()->sync(Permission::whereIn('name', ['barcode.view', 'barcode.print'])->pluck('id'));
        $this->actingAs(User::factory()->create(['role_id' => $role->id]));
        $this->get(route('barcode.settings'))->assertForbidden();
        $this->putJson(route('barcode.settings.update'), $this->barcodeSettings($this->settings()))->assertForbidden();
        $this->get(route('barcode.index'))->assertOk()->assertDontSee('scode_digit_0');
        config(['features.barcode' => false]);
        $this->assertFalse(SecretCodeService::enabled());
    }

    public function test_expiry_purchase_selection_and_disabled_units_keep_the_received_cost(): void
    {
        config(['features.unit' => false, 'features.expiry' => true]);
        $p = $this->product(['stock' => 0, 'track_expiry' => true]);
        $supplier = Supplier::create(['name' => 'Secret code supplier', 'active' => true]);
        $purchase = app(PurchaseService::class)->save(['supplier_id' => $supplier->id, 'purchase_date' => today()->toDateString(), 'auto_reference' => true, 'payment_mode' => 'UNPAID', 'items' => [['product_id' => $p->id, 'quantity' => '5', 'cost' => '850', 'selling_price' => '1250', 'expires_at' => '2027-01-20', 'batch_number' => 'SECRET-LOT']]], $this->admin->id);
        $p->update(['cost' => '999']);
        $rows = app(BarcodePrintService::class)->purchase($purchase);
        $this->assertNotEmpty($rows[0]['option']);
        $labels = app(BarcodePrintService::class)->resolve(['configuration' => app(BarcodePresetService::class)->defaults(), 'items' => [['product_id' => $p->id, 'option' => $rows[0]['option'], 'quantity' => 5]]])['labels'];
        $this->assertSame('GTKX', $labels[0]['secret_code']);
        $this->assertSame('SECRET-LOT', $labels[0]['batch']);
        $this->assertSame('2027-01-20', $labels[0]['expiry']);
        $this->assertSame('', $labels[0]['unit']);
    }

    public function test_calibration_includes_sample_code_only_when_enabled(): void
    {
        $this->get(route('barcode.calibration'))->assertOk()->assertSee('>GTX</div>', false);
        config(['features.scode' => false]);
        $this->get(route('barcode.calibration'))->assertOk()->assertDontSee('>GTX</div>', false);
    }
}
