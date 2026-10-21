<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\SettingsService;
use App\Support\Branding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrandingSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.colors' => true]);
        $this->seed();
    }

    private function defaults(): array
    {
        return collect(config('pos.branding'))->except('login_logo')->map(fn ($field) => $field[2])->all();
    }

    public function test_disabled_branding_hides_all_links_and_blocks_viewing_and_saving(): void
    {
        $this->actingAs(User::firstOrFail());
        config(['features.colors' => false]);
        $url = route('settings.edit', 'branding');
        $this->get(route('settings.index'))->assertOk()->assertDontSee($url, false)->assertDontSee('Appearance &amp; branding', false);
        $this->get(route('settings.edit', 'business'))->assertOk()->assertDontSee($url, false);
        $this->get($url)->assertNotFound();
        $this->putJson(route('settings.update', 'branding'), array_replace($this->defaults(), ['theme_color' => '#123456']))->assertNotFound();
        $this->assertSame('#047857', app(SettingsService::class)->get('theme_color'));
    }

    public function test_missing_flag_defaults_to_hidden_and_reenabling_restores_settings(): void
    {
        $this->actingAs(User::firstOrFail());
        app(SettingsService::class)->put('branding', ['brand_name' => 'Saved Market', 'theme_color' => '#123456']);
        $features = config('features');
        unset($features['colors']);
        config(['features' => $features]);
        $this->assertFalse(Branding::enabled());
        $this->get(route('settings.edit', 'branding'))->assertNotFound();
        $this->get(route('settings.index'))->assertOk()->assertSee('Saved Market')->assertSee('--green: #123456;', false)->assertDontSee(route('settings.edit', 'branding'), false);
        config(['features.colors' => true]);
        $this->get(route('settings.index'))->assertOk()->assertSee(route('settings.edit', 'branding'), false);
        $this->get(route('settings.edit', 'business'))->assertOk()->assertSee(route('settings.edit', 'branding'), false);
        $this->get(route('settings.edit', 'branding'))->assertOk()->assertSee('Saved Market')->assertSee('#123456');
    }

    public function test_saved_branding_is_visible_in_workspace_and_guest_login(): void
    {
        $this->actingAs(User::firstOrFail());
        $this->get(route('settings.index'))->assertOk()->assertSee('Appearance &amp; branding', false);
        $this->get(route('settings.edit', 'branding'))->assertOk()->assertSee('Live preview');
        $data = array_replace($this->defaults(), [
            'brand_name' => 'Blue Market', 'brand_subtitle' => 'Everyday essentials',
            'login_brand_text' => 'BLUE MARKET CHECKOUT', 'login_title' => 'Hello team',
            'login_description' => 'Ready for a great day?', 'theme_color' => '#2563eb',
            'brand_name_color' => '#112233', 'brand_subtitle_color' => '#445566',
            'login_accent_color' => '#7c3aed', 'login_background_color' => '#f5f3ff',
            'login_icon_mode' => 'icon', 'login_icon' => 'store',
        ]);
        $this->put(route('settings.update', 'branding'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('settings.index'))->assertOk()->assertSee('Blue Market')->assertSee('Everyday essentials')->assertSee('--green: #2563eb;', false)->assertSee('--brand-subtitle-color: #445566;', false);
        $this->app['auth']->forgetGuards();
        $this->get('/login')->assertOk()->assertSee('BLUE MARKET CHECKOUT')->assertSee('Hello team')->assertSee('Ready for a great day?')->assertSee('data-lucide="store"', false)->assertSee('--login-accent: #7c3aed;', false);
    }

    public function test_invalid_colors_icons_and_oversized_text_cannot_be_saved(): void
    {
        $this->actingAs(User::firstOrFail());
        $data = array_replace($this->defaults(), ['theme_color' => 'red; background:url(test)', 'login_icon' => 'unknown-icon', 'login_icon_mode' => 'unknown', 'brand_name' => str_repeat('A', 151)]);
        $this->putJson(route('settings.update', 'branding'), $data)->assertUnprocessable()->assertJsonValidationErrors(['theme_color', 'login_icon', 'login_icon_mode', 'brand_name']);
        $this->assertSame('#047857', app(SettingsService::class)->get('theme_color'));
    }

    public function test_branding_uses_business_permission_and_remains_protected_in_demo(): void
    {
        $cashier = User::factory()->create(['role_id' => Role::where('name', 'Cashier')->value('id')]);
        $this->actingAs($cashier)->get(route('settings.edit', 'branding'))->assertForbidden();
        $this->putJson(route('settings.update', 'branding'), $this->defaults())->assertForbidden();
        $this->flushSession();
        $this->actingAs(User::firstOrFail());
        config(['demo.enabled' => true]);
        $this->putJson(route('settings.update', 'branding'), $this->defaults())->assertForbidden();
    }

    public function test_login_image_upload_replace_and_remove_leave_bill_logos_untouched(): void
    {
        Storage::fake('public');
        $this->actingAs(User::firstOrFail());
        app(SettingsService::class)->put('business', ['logo' => 'branding/bill.png']);
        Storage::disk('public')->put('branding/bill.png', 'Bill logo');
        $data = array_replace($this->defaults(), ['login_icon_mode' => 'custom-logo']);
        $this->put(route('settings.update', 'branding'), $data + ['login_logo' => UploadedFile::fake()->image('login.png')])->assertSessionHasNoErrors();
        $first = app(SettingsService::class)->get('login_logo');
        Storage::disk('public')->assertExists($first);
        $this->assertSame($first, Branding::values(app(SettingsService::class)->all())['login_image']);
        $this->put(route('settings.update', 'branding'), $data + ['login_logo' => UploadedFile::fake()->image('replacement.png')])->assertSessionHasNoErrors();
        $second = app(SettingsService::class)->get('login_logo');
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
        $this->put(route('settings.update', 'branding'), $data + ['remove_login_logo' => '1'])->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($second);
        Storage::disk('public')->assertExists('branding/bill.png');
        $this->assertNull(Branding::values(app(SettingsService::class)->all())['login_image']);
    }

    public function test_existing_defaults_and_empty_optional_text_are_supported(): void
    {
        $legacy = Branding::values(['business_name' => 'Existing Store', 'logo' => 'branding/store.png']);
        $this->assertSame('Existing Store', $legacy['brand_name']);
        $this->assertSame('EXISTING STORE POS', $legacy['login_brand_text']);
        $this->assertSame('branding/store.png', $legacy['login_image']);
        $this->actingAs(User::firstOrFail());
        $this->put(route('settings.update', 'branding'), array_replace($this->defaults(), ['brand_subtitle' => '', 'login_description' => '']))->assertSessionHasNoErrors();
        $saved = Branding::values(app(SettingsService::class)->all());
        $this->assertEmpty($saved['brand_subtitle']);
        $this->assertEmpty($saved['login_description']);
        $this->get(route('settings.edit', 'branding'))->assertOk()->assertSee('name="brand_subtitle" value=""', false);
    }
}
