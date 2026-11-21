<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurchaseProductPopupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.bamini' => true]);
        $this->seed();
        $this->actingAs(User::firstOrFail());
    }

    private function data(): array
    {
        return ['name' => 'தமிழ் புதிய', 'sku' => 'PURCHASE-POPUP', 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'low_stock' => '0', 'active' => true, 'track_stock' => true, 'default_discount_type' => 'PERCENT', 'default_discount_value' => '5', 'opening_layers' => [['quantity' => '0', 'cost' => '20', 'selling_price' => '50']]];
    }

    private function asUser(array $permissions): void
    {
        $role = Role::create(['name' => 'Popup test role', 'sales_visibility' => 'OWN']);
        $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));
        $this->flushSession();
        $this->actingAs(User::create(['name' => 'Popup test user', 'username' => 'popup-test', 'email' => 'popup@example.test', 'password' => 'password', 'role_id' => $role->id, 'active' => true]));
    }

    public function test_purchase_plus_opens_the_complete_existing_product_form_in_a_minimal_layout(): void
    {
        $this->get(route('purchases.create'))->assertOk()->assertSee('data-add-purchase-product', false)->assertSee('data-create-missing-product', false)->assertSee('purchase-product-dialog');
        $this->get(route('manage.create', ['resource' => 'products', 'purchase_popup' => 1]))->assertOk()
            ->assertSee('data-product-popup', false)->assertSee('data-product-name-mode', false)
            ->assertSee('name="image"', false)->assertSee('name="categories[]"', false)->assertSee('name="supplier_id"', false)
            ->assertSee('opening_layers[0][quantity]', false)->assertSee('Additional units &amp; conversions', false)
            ->assertSee('data-product-popup-cancel', false)->assertDontSee('class="sidebar"', false);
        $this->get(route('manage.create', 'products'))->assertOk()->assertSee('class="sidebar"', false)->assertDontSee('data-product-popup-cancel', false);
    }

    public function test_popup_saves_full_product_details_and_returns_usable_purchase_units_and_prices(): void
    {
        Storage::fake('public');
        $supplier = Supplier::create(['name' => 'Popup supplier', 'active' => true]);
        $category = Category::firstOrFail();
        $box = Unit::where('short_name', 'box')->firstOrFail();
        $data = $this->data() + ['supplier_id' => $supplier->id, 'categories' => [$category->id], 'conversions_present' => true,
            'conversions' => [['unit_id' => $box->id, 'base_quantity' => '12', 'converted_quantity' => '1', 'price' => '550']],
            'image' => UploadedFile::fake()->image('product.jpg')];
        $response = $this->postJson(route('manage.store', ['resource' => 'products', 'purchase_popup' => 1]), $data)
            ->assertCreated()->assertJsonPath('name', $data['name'])->assertJsonPath('active', true)->assertJsonPath('cost', '20.00')->assertJsonPath('price', '50.00');
        $product = Product::findOrFail($response->json('id'));
        $this->assertSame('0.000', $product->stock);
        $this->assertSame('PERCENT', $product->default_discount_type);
        $this->assertSame($supplier->id, $product->supplier_id);
        $this->assertTrue($product->categories->contains($category));
        $this->assertTrue($product->conversions->contains('unit_id', $box->id));
        $this->assertContains($box->id, array_column($response->json('units'), 'id'));
        Storage::disk('public')->assertExists($product->image);
    }

    public function test_popup_reuses_validation_without_creating_duplicate_products(): void
    {
        $url = route('manage.store', ['resource' => 'products', 'purchase_popup' => 1]);
        $this->postJson($url, $this->data())->assertCreated();
        $this->postJson($url, $this->data())->assertUnprocessable()->assertJsonValidationErrors('sku');
        $this->assertSame(1, Product::where('sku', 'PURCHASE-POPUP')->count());
    }

    public function test_purchase_access_alone_does_not_allow_product_creation(): void
    {
        $this->asUser(['purchases.create']);
        $this->get(route('purchases.create'))->assertOk()->assertDontSee('data-add-purchase-product', false)->assertDontSee('purchase-product-dialog');
        $this->get(route('manage.create', ['resource' => 'products', 'purchase_popup' => 1]))->assertForbidden();
        $this->postJson(route('manage.store', ['resource' => 'products', 'purchase_popup' => 1]), $this->data())->assertForbidden();
    }

    public function test_popup_requires_purchase_access_as_well_as_product_creation_access(): void
    {
        $this->asUser(['products.create']);
        $this->get(route('manage.create', 'products'))->assertOk();
        $this->get(route('manage.create', ['resource' => 'products', 'purchase_popup' => 1]))->assertForbidden();
        $this->postJson(route('manage.store', ['resource' => 'products', 'purchase_popup' => 1]), $this->data())->assertForbidden();
        $this->assertDatabaseMissing('products', ['sku' => 'PURCHASE-POPUP']);
    }
}
