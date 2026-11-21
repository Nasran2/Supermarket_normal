<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\RegisterService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BaminiProductNameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::firstOrFail());
    }

    public function test_enabled_flag_shows_settings_and_product_controls(): void
    {
        config(['features.bamini' => true]);
        $this->get(route('settings.edit', 'stock'))->assertOk()->assertSee('Product Name Language')->assertSee('Normal English')->assertSee('Bamini → Tamil Unicode');
        $this->get(route('manage.create', 'products'))->assertOk()->assertSee('data-product-name-editor', false)->assertSee('data-default-mode="english"', false)->assertSee('product-name-', false);
        $this->assertStringContainsString('data-product-name-editor', view('products.partials.name-input', ['nameValue' => '', 'namePlaceholder' => 'Quick Add'])->render());
    }

    public function test_false_and_missing_flag_hide_every_control_and_ignore_setting_writes(): void
    {
        foreach ([false, null] as $flag) {
            config(['features.bamini' => $flag]);
            $this->get(route('settings.edit', 'stock'))->assertOk()->assertDontSee('product_name_typing_mode')->assertDontSee('Product Name Language');
            $this->get(route('manage.create', 'products'))->assertOk()->assertDontSee('data-product-name-editor', false)->assertDontSee('data-product-name-mode', false)->assertDontSee('data-product-name-preview', false);
            $this->assertStringNotContainsString('data-product-name-mode', view('products.partials.name-input', ['nameValue' => '', 'namePlaceholder' => 'Quick Add'])->render());
            $this->put(route('settings.update', 'stock'), ['sell_zero_stock' => false, 'negative_stock' => false, 'product_name_typing_mode' => 'bamini'])->assertSessionHasNoErrors()->assertRedirect();
            $this->assertSame('english', app(SettingsService::class)->get('product_name_typing_mode', 'english'));
        }
    }

    public function test_default_persists_and_validation_and_permissions_are_reused(): void
    {
        config(['features.bamini' => true]);
        $this->put(route('settings.update', 'stock'), ['sell_zero_stock' => false, 'negative_stock' => false, 'product_name_typing_mode' => 'bamini'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('bamini', app(SettingsService::class)->get('product_name_typing_mode'));
        $this->get(route('manage.create', 'products'))->assertOk()->assertSee('data-default-mode="bamini"', false);
        $this->putJson(route('settings.update', 'stock'), ['product_name_typing_mode' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('product_name_typing_mode');
        $this->flushSession();
        $this->actingAs(User::create(['name' => 'Cashier', 'username' => 'bamini-cashier', 'email' => 'bamini@example.test', 'password' => 'password', 'role_id' => Role::create(['name' => 'Bamini no settings', 'sales_visibility' => 'OWN'])->id, 'active' => true]));
        $this->getJson(route('settings.edit', 'stock'))->assertForbidden();
        $this->putJson(route('settings.update', 'stock'), ['product_name_typing_mode' => 'english'])->assertForbidden();
    }

    public function test_unicode_product_name_is_saved_searched_and_preserved_on_edit(): void
    {
        config(['features.bamini' => true]);
        $data = ['name' => 'வணக்கம் தமிழ்', 'sku' => 'TAMIL-TEST', 'unit_id' => Unit::firstOrFail()->id, 'cost' => '20', 'price' => '100', 'stock' => '0', 'low_stock' => '0', 'active' => true];
        $this->post(route('manage.store', 'products'), $data)->assertSessionHasNoErrors()->assertRedirect();
        $product = Product::where('sku', 'TAMIL-TEST')->firstOrFail();
        $this->assertSame($data['name'], $product->name);
        $this->get(route('manage.edit', ['products', $product->id]))->assertOk()->assertSee($data['name']);
        $this->put(route('manage.update', ['products', $product->id]), $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($data['name'], $product->fresh()->name);
        app(RegisterService::class)->open(auth()->id(), '0');
        $this->getJson(route('pos.products', ['q' => 'வணக்கம்']))->assertOk()->assertJsonFragment(['name' => $data['name']]);
        config(['features.bamini' => false]);
        $this->get(route('manage.show', ['products', $product->id]))->assertOk()->assertSee($data['name']);
        $this->get(route('manage.edit', ['products', $product->id]))->assertOk()->assertSee($data['name'])->assertDontSee('data-product-name-editor', false);
    }
}
