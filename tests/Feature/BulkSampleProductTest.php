<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\RegisterService;
use App\Services\StockLayerService;
use Database\Seeders\BulkSampleProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BulkSampleProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_sample_catalog_has_consistent_stock_and_is_safe_to_rerun(): void
    {
        $this->seed();
        $admin = User::create(['name' => 'Sample Admin', 'username' => 'sample-admin', 'email' => 'samples@example.test', 'password' => 'test-password', 'role_id' => Role::where('name', 'Administrator')->value('id')]);
        $this->actingAs($admin);
        $this->seed(BulkSampleProductSeeder::class);
        $products = Product::with('stockLayers', 'categories')->where('sku', 'like', 'SAMPLE-%')->get();
        $this->assertCount(120, $products);
        $this->assertSame(120, $products->pluck('barcode')->unique()->count());
        foreach ($products as $product) {
            $this->assertEquals((float) $product->stock, (float) $product->stockLayers->sum('remaining_quantity'));
            $this->assertCount(1, $product->categories);
            $this->assertMatchesRegularExpression('/^29\d{11}$/', $product->barcode);
        }
        $this->assertSame(8, $products->where('stock', '0.000')->count());
        $samePrice = $products->firstWhere('sku', 'SAMPLE-005');
        $this->assertCount(2, $samePrice->stockLayers);
        $this->assertCount(1, app(StockLayerService::class)->groups($samePrice));
        $differentPrice = $products->firstWhere('sku', 'SAMPLE-010');
        $this->assertCount(2, app(StockLayerService::class)->groups($differentPrice));
        // Simulate stock already consumed before rerunning the sample loader.
        $first = $products->firstWhere('sku', 'SAMPLE-001');
        $first->stockLayers()->update(['remaining_quantity' => '1.000']);
        $first->update(['stock' => '1.000', 'name' => 'Staff renamed sample']);
        $layerCount = $products->sum(fn ($product) => $product->stockLayers->count());
        $this->seed(BulkSampleProductSeeder::class);
        $this->assertSame(120, Product::count());
        $this->assertSame('1.000', $first->fresh()->stock);
        $this->assertSame('Staff renamed sample', $first->fresh()->name);
        $this->assertSame($layerCount, \App\Models\ProductStockLayer::count());
        $this->assertSame($admin->id, auth()->id());

        app(RegisterService::class)->open($admin->id, '0');
        $this->getJson(route('pos.products'))->assertOk()->assertJsonCount(60);
        $last = $products->firstWhere('sku', 'SAMPLE-120');
        $this->getJson(route('pos.products', ['q' => $last->barcode]))->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $last->id);
        $this->getJson(route('pos.products', ['q' => 'SAMPLE-119']))->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'Sanitary Pads 10 Pack (Sample)');
        $this->get(route('manage.index', 'products'))->assertOk()->assertSee('Next');
    }
}
