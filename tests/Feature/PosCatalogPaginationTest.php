<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\RegisterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosCatalogPaginationTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $user = User::create(['name' => 'Catalog Admin', 'username' => 'catalog-admin', 'email' => 'catalog@example.test', 'password' => 'password', 'role_id' => Role::where('name', 'Administrator')->value('id')]);
        $this->actingAs($user);
        app(RegisterService::class)->open($user->id, '0');
        $this->category = Category::create(['name' => 'Catalog category']);
        for ($i = 1; $i <= 125; $i++) {
            // Identical names exercise stable ordering at page boundaries.
            $product = Product::create(['name' => 'Catalog Item', 'sku' => "CAT-$i", 'barcode' => "290000000$i", 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'price' => '100', 'cost' => '40', 'stock' => '0', 'track_stock' => false, 'active' => true]);
            if ($i <= 110) {
                $product->categories()->attach($this->category);
            }
            $this->ids[] = $product->id;
        }
        $inactive = Product::findOrFail($this->ids[0])->replicate();
        $inactive->fill(['sku' => 'INACTIVE', 'barcode' => null, 'active' => false])->save();
    }

    public function test_every_active_product_is_reachable_without_duplicate_or_missing_rows(): void
    {
        $seen = [];
        foreach ([1 => 100, 2 => 25] as $page => $count) {
            $response = $this->getJson(route('pos.products', ['page' => $page]))->assertOk()
                ->assertJsonPath('total', 125)->assertJsonPath('current_page', $page)
                ->assertJsonPath('per_page', 100)->assertJsonPath('last_page', 2)->assertJsonCount($count, 'data');
            array_push($seen, ...array_column($response->json('data'), 'id'));
        }
        $this->assertSame($this->ids, $seen);
        $this->getJson(route('pos.products', ['page' => 3]))->assertOk()
            ->assertJsonPath('total', 125)->assertJsonCount(0, 'data');
        $this->get(route('pos.index'))->assertOk()->assertSee('id="product-pagination"', false);
    }

    public function test_search_and_category_filters_apply_to_every_page(): void
    {
        $response = $this->getJson(route('pos.products', ['page' => 2, 'q' => 'Catalog', 'category_id' => $this->category->id]))
            ->assertOk()->assertJsonPath('total', 110)->assertJsonPath('last_page', 2)->assertJsonCount(10, 'data');
        $this->assertSame(array_slice($this->ids, 100, 10), array_column($response->json('data'), 'id'));
        $this->getJson(route('pos.products', ['page' => 1, 'q' => 'No match']))->assertOk()
            ->assertJsonPath('total', 0)->assertJsonCount(0, 'data');
    }

    public function test_exact_scanning_and_saved_cart_lookups_keep_their_response_contracts(): void
    {
        $product = Product::findOrFail($this->ids[124]);
        $this->getJson(route('pos.products', ['q' => $product->barcode, 'scan' => 1]))
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $product->id);
        $this->getJson(route('pos.products', ['q' => $product->barcode, 'auto_scan' => 1, 'page' => 1, 'category_id' => $this->category->id]))
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $product->id);
        $this->getJson(route('pos.products', ['ids' => [$product->id]]))
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $product->id);
        $this->getJson(route('pos.products', ['page' => 0]))->assertUnprocessable()->assertJsonValidationErrors('page');
    }
}
