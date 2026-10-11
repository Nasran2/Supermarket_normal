<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;
use App\Services\RegisterService;
use App\Services\SaleService;
use App\Services\SalesReturnService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class BillVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::firstOrFail());
    }

    private function business(): array
    {
        return [
            'business_name' => 'Sample Bill Market', 'legal_name' => 'Sample Legal Company',
            'address' => '19 Example Lane', 'city' => 'Example City',
            'phone' => '0110001234', 'mobile' => '0770001234', 'whatsapp' => '0770005678',
            'email' => 'bill@example.test', 'website' => 'https://example.test/shop',
            'registration_number' => 'QA-REG-123', 'tax_number' => 'QA-TAX-456',
            'currency' => 'LKR', 'currency_symbol' => 'Rs.',
        ];
    }

    private function sale(): Sale
    {
        $user = User::firstOrFail();
        app(RegisterService::class)->open($user->id, '0');
        $product = Product::create(['name' => 'Sample item', 'sku' => 'BILL-SAMPLE', 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'price' => '100', 'cost' => '40', 'stock' => '0', 'track_stock' => false, 'active' => true]);
        $data = ['items' => [['product_id' => $product->id, 'quantity' => '2']], 'payments' => [['payment_method_id' => PaymentMethod::where('type', 'CASH')->value('id'), 'amount' => '200', 'amount_paid' => '200']]];
        $quote = app(SaleService::class)->quote($data, $user);

        return app(SaleService::class)->complete($data + ['checkout_token' => (string) Str::uuid(), 'quote_hash' => $quote['quote_hash']], $user);
    }

    public function test_business_checkboxes_persist_without_erasing_values_and_can_be_enabled_again(): void
    {
        $flags = collect(config('bill_fields.business'))->mapWithKeys(fn ($option) => [$option['setting'] => '0'])->all();
        $this->put(route('settings.update', 'business'), $this->business() + $flags)->assertSessionHasNoErrors()->assertRedirect();
        foreach ($flags as $key => $value) {
            $this->assertFalse((bool) app(SettingsService::class)->get($key));
        }
        foreach ($this->business() as $key => $value) {
            $this->assertSame($value, app(SettingsService::class)->get($key));
        }
        $form = $this->get(route('settings.edit', 'business'))->assertOk();
        foreach (config('bill_fields.business') as $field => $option) {
            $form->assertSee('name="'.$option['setting'].'"', false);
        }
        $this->put(route('settings.update', 'business'), $this->business() + array_fill_keys(array_keys($flags), '1'))->assertSessionHasNoErrors();
        foreach ($flags as $key => $value) {
            $this->assertTrue((bool) app(SettingsService::class)->get($key));
        }
    }

    public function test_each_business_detail_can_be_hidden_independently_and_blank_fields_do_not_print(): void
    {
        $settings = $this->business() + ['logo' => 'branding/sample.png', 'logo_secondary' => 'branding/second.png'];
        $full = view('partials.bill-business', compact('settings'))->render();
        $this->assertStringContainsString('WhatsApp: 0770005678', $full);
        $this->assertStringContainsString('Reg. No.: QA-REG-123', $full);
        foreach (config('bill_fields.business') as $field => $option) {
            if (in_array($field, ['currency', 'currency_symbol'])) {
                continue;
            }
            $hidden = $settings + [$option['setting'] => false];
            $html = view('partials.bill-business', ['settings' => $hidden])->render();
            $this->assertStringNotContainsString($settings[$field], $html, $field.' must be hidden');
            foreach (['whatsapp', 'registration_number'] as $other) {
                if ($other !== $field) {
                    $this->assertStringContainsString($settings[$other], $html);
                }
            }
        }
        $blank = view('partials.bill-business', ['settings' => []])->render();
        $this->assertStringNotContainsString('<h1>', $blank);
        $this->assertStringNotContainsString('<p>', $blank);
        $settings['whatsapp'] = '<script>alert(1)</script>';
        $this->assertStringNotContainsString('<script>', view('partials.bill-business', compact('settings'))->render());
    }

    public function test_sale_and_return_receipts_use_the_same_selected_details_and_footer(): void
    {
        app(SettingsService::class)->put('business', $this->business() + ['logo' => 'branding/first.png', 'logo_secondary' => 'branding/second.png']);
        app(SettingsService::class)->put('receipt', ['receipt_message' => 'Sample custom message', 'receipt_footer' => 'Sample footer message', 'paper_width' => '58']);
        $sale = $this->sale();
        $return = app(SalesReturnService::class)->complete($sale, ['token' => (string) Str::uuid(), 'original_id' => $sale->id, 'reason' => 'Sample return', 'resolution' => 'MONEY', 'payment_method_id' => PaymentMethod::where('type', 'CASH')->value('id'), 'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => '1', 'stock_action' => 'RESTOCK']]], User::firstOrFail());
        $routes = [route('sales.receipt', $sale), route('returns.receipt', ['sales', $return->id])];
        foreach ($routes as $route) {
            $this->get($route)->assertOk()->assertSee('WhatsApp: 0770005678')->assertSee('Reg. No.: QA-REG-123')->assertSee('Sample custom message')->assertSee('Sample footer message')->assertSee('branding/first.png')->assertSee('branding/second.png');
        }
        app(SettingsService::class)->put('business', ['show_whatsapp_receipt' => false, 'show_registration_number_receipt' => false, 'show_currency_symbol_receipt' => false, 'show_currency_receipt' => true]);
        app(SettingsService::class)->put('receipt', ['show_receipt_message' => false, 'show_receipt_footer' => false]);
        foreach ($routes as $route) {
            $this->get($route)->assertOk()->assertDontSee('0770005678')->assertDontSee('QA-REG-123')->assertDontSee('Sample custom message')->assertDontSee('Sample footer message')->assertDontSee('Rs.')->assertSee('LKR')->assertSee('Sample Bill Market');
        }
    }

    public function test_invalid_visibility_is_rejected_and_older_submissions_preserve_choices(): void
    {
        $this->putJson(route('settings.update', 'business'), $this->business() + ['show_whatsapp_receipt' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('show_whatsapp_receipt');
        app(SettingsService::class)->put('business', ['show_whatsapp_receipt' => false]);
        $this->put(route('settings.update', 'business'), $this->business())->assertSessionHasNoErrors();
        $this->assertFalse(app(SettingsService::class)->get('show_whatsapp_receipt'));
        $this->get(route('settings.edit', 'receipt'))->assertOk()->assertSee('name="show_receipt_footer"', false)->assertSee('name="show_receipt_message"', false);
    }

    public function test_cashier_cannot_change_bill_visibility(): void
    {
        $cashier = User::create(['name' => 'Bill cashier', 'username' => 'bill-cashier', 'email' => 'bill-cashier@example.test', 'password' => 'test-password', 'role_id' => Role::where('name', 'Cashier')->value('id'), 'active' => true]);
        $this->actingAs($cashier);
        $this->put(route('settings.update', 'business'), $this->business() + ['show_whatsapp_receipt' => false])->assertForbidden();
    }

    public function test_footer_and_message_checkboxes_save_with_receipt_preferences(): void
    {
        $values = collect(config('pos.receipt'))->mapWithKeys(fn ($field, $key) => [$key => $field[2]])->all();
        $values['receipt_footer'] = 'Saved footer';
        $values['receipt_message'] = 'Saved message';
        $this->put(route('settings.update', 'receipt'), $values + ['show_receipt_footer' => '0', 'show_receipt_message' => '1'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertFalse(app(SettingsService::class)->get('show_receipt_footer'));
        $this->assertTrue(app(SettingsService::class)->get('show_receipt_message'));
        $this->assertSame('Saved footer', app(SettingsService::class)->get('receipt_footer'));
        $this->assertSame('Saved message', app(SettingsService::class)->get('receipt_message'));
    }

    public function test_two_logos_upload_replace_and_remove_independently(): void
    {
        Storage::fake('public');
        $this->put(route('settings.update', 'business'), $this->business() + ['logo' => UploadedFile::fake()->image('first.png'), 'logo_secondary' => UploadedFile::fake()->image('second.png')])->assertSessionHasNoErrors()->assertRedirect();
        $primary = app(SettingsService::class)->get('logo');
        $secondary = app(SettingsService::class)->get('logo_secondary');
        Storage::disk('public')->assertExists([$primary, $secondary]);
        $this->assertNotSame($primary, $secondary);
        $this->get(route('settings.edit', 'business'))->assertOk()->assertSee('name="logo_secondary"', false)->assertSee('Choose second logo')->assertSee('Current second bill logo');
        $this->put(route('settings.update', 'business'), $this->business() + ['logo_secondary' => UploadedFile::fake()->image('replacement.png')])->assertSessionHasNoErrors();
        $replacement = app(SettingsService::class)->get('logo_secondary');
        $this->assertSame($primary, app(SettingsService::class)->get('logo'));
        Storage::disk('public')->assertExists([$primary, $replacement]);
        Storage::disk('public')->assertMissing($secondary);
        $this->put(route('settings.update', 'business'), $this->business() + ['remove_logo' => '1'])->assertSessionHasNoErrors();
        $this->assertNull(app(SettingsService::class)->get('logo'));
        $this->assertSame($replacement, app(SettingsService::class)->get('logo_secondary'));
        Storage::disk('public')->assertMissing($primary);
        Storage::disk('public')->assertExists($replacement);
        $this->put(route('settings.update', 'business'), $this->business() + ['remove_logo_secondary' => '1'])->assertSessionHasNoErrors();
        $this->assertNull(app(SettingsService::class)->get('logo_secondary'));
        Storage::disk('public')->assertMissing($replacement);
    }

    public function test_each_logo_visibility_is_independent_on_sales_and_return_receipts(): void
    {
        $settings = ['logo' => 'branding/first.png', 'logo_secondary' => 'branding/second.png'];
        $html = view('partials.bill-business', compact('settings'))->render();
        $this->assertStringContainsString('class="bill-logo-row"', $html);
        $this->assertSame(2, substr_count($html, '<img '));
        foreach (['show_logo_receipt' => 'branding/first.png', 'show_second_logo_receipt' => 'branding/second.png'] as $flag => $path) {
            $one = view('partials.bill-business', ['settings' => $settings + [$flag => false]])->render();
            $this->assertSame(1, substr_count($one, '<img '));
            $this->assertStringNotContainsString($path, $one);
        }
        $none = view('partials.bill-business', ['settings' => $settings + ['show_logo_receipt' => false, 'show_second_logo_receipt' => false]])->render();
        $this->assertStringNotContainsString('bill-logo-row', $none);
        app(SettingsService::class)->put('business', $settings);
        $this->get(route('sales.receipt', $this->sale()))->assertOk()->assertSee('branding/first.png')->assertSee('branding/second.png')->assertSee('class="bill-logo-row"', false);
    }

    public function test_second_logo_rejects_invalid_files_and_removal_flags(): void
    {
        Storage::fake('public');
        foreach ([UploadedFile::fake()->create('bad.php', 1, 'application/x-php'), UploadedFile::fake()->image('too-big.png')->size(3000)] as $file) {
            $this->putJson(route('settings.update', 'business'), $this->business() + ['logo_secondary' => $file])->assertUnprocessable()->assertJsonValidationErrors('logo_secondary');
        }
        $this->putJson(route('settings.update', 'business'), $this->business() + ['remove_logo_secondary' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('remove_logo_secondary');
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    public function test_other_settings_groups_cannot_remove_business_logos(): void
    {
        app(SettingsService::class)->put('business', ['logo' => 'branding/first.png', 'logo_secondary' => 'branding/second.png']);
        $values = collect(config('pos.receipt'))->mapWithKeys(fn ($field, $key) => [$key => $field[2]])->all();
        $this->put(route('settings.update', 'receipt'), $values + ['remove_logo' => '1', 'remove_logo_secondary' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('branding/first.png', app(SettingsService::class)->get('logo'));
        $this->assertSame('branding/second.png', app(SettingsService::class)->get('logo_secondary'));
    }

    public function test_failed_save_cleans_up_new_uploads_and_keeps_both_original_logos(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('branding/first.png', 'Original first logo');
        Storage::disk('public')->put('branding/second.png', 'Original second logo');
        app(SettingsService::class)->put('business', ['logo' => 'branding/first.png', 'logo_secondary' => 'branding/second.png']);
        $event = 'eloquent.saving: '.Setting::class;
        Event::listen($event, function (Setting $setting) {
            if ($setting->key === 'logo_secondary') {
                throw new \RuntimeException('Sample save failure');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->put(route('settings.update', 'business'), $this->business() + ['logo' => UploadedFile::fake()->image('new-first.png'), 'logo_secondary' => UploadedFile::fake()->image('new-second.png')]);
            $this->fail('The simulated save failure must be raised.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Sample save failure', $e->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertSame('branding/first.png', app(SettingsService::class)->get('logo'));
        $this->assertSame('branding/second.png', app(SettingsService::class)->get('logo_secondary'));
        $this->assertEqualsCanonicalizing(['branding/first.png', 'branding/second.png'], Storage::disk('public')->allFiles());
    }
}
