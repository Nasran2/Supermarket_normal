<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Services\RegisterService;
use App\Services\ReportService;
use App\Services\SettingsService;
use App\Support\Bamini;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BaminiProductSearchTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.bamini' => true, 'features.barcode' => true, 'services.phone_rep' => true]);
        $this->seed();
        $this->actingAs(User::firstOrFail());
        app(RegisterService::class)->open(auth()->id(), '0');
        $this->product = Product::create(['name' => 'வணக்கம் தமிழ்', 'sku' => 'TAMIL-SEARCH', 'barcode' => '1234567890123', 'unit_id' => Unit::firstOrFail()->id, 'cost' => '20', 'price' => '100', 'stock' => '10', 'active' => true]);
    }

    private function productEndpoints(string $query): array
    {
        return [
            route('pos.products', ['q' => $query]),
            route('pos.products', ['q' => $query, 'auto_scan' => true, 'page' => 1]),
            route('adjustments.products', ['q' => $query]),
            route('repairs.products', ['q' => $query]),
            route('barcode.products', ['q' => $query]),
            route('returns.products', ['kind' => 'sales', 'q' => $query]),
            route('returns.products', ['kind' => 'purchase', 'q' => $query]),
            route('returns.no-receipt.products', ['kind' => 'sales', 'q' => $query]),
        ];
    }

    public function test_partial_and_full_bamini_names_match_every_product_endpoint(): void
    {
        foreach (['tz', 'tzf;fk;', 'jkpo;', 'வணக்கம்', 'TAMIL-SEARCH'] as $query) {
            foreach ($this->productEndpoints($query) as $url) {
                $this->getJson($url)->assertOk()->assertJsonFragment(['id' => $this->product->id, 'name' => $this->product->name]);
            }
        }
    }

    public function test_lists_category_products_and_stock_report_match_bamini(): void
    {
        $category = Category::firstOrFail();
        $this->product->categories()->attach($category);
        $this->get(route('manage.index', ['resource' => 'products', 'q' => 'tz']))->assertOk()->assertSee($this->product->name);
        $this->get(route('manage.show', ['resource' => 'categories', 'id' => $category->id, 'q' => 'tz']))->assertOk()->assertSee($this->product->name);
        $report = app(ReportService::class)->build('stock', ['q' => 'tz', 'from' => today()->toDateString(), 'to' => today()->toDateString()]);
        $this->assertTrue($report['query']->whereKey($this->product->id)->exists());
    }

    public function test_disabled_or_missing_flag_keeps_raw_search_only(): void
    {
        foreach ([false, null] as $flag) {
            config(['features.bamini' => $flag]);
            foreach ($this->productEndpoints('tz') as $url) {
                $this->getJson($url)->assertOk()->assertJsonMissing(['name' => $this->product->name]);
            }
            $this->get(route('manage.index', ['resource' => 'products', 'q' => 'tz']))->assertOk()->assertDontSee($this->product->name);
            $this->getJson(route('pos.products', ['q' => 'வணக்கம்']))->assertOk()->assertJsonFragment(['id' => $this->product->id]);
        }
    }

    public function test_english_codes_exact_scans_and_search_modes_are_preserved(): void
    {
        $english = Product::create(['name' => 'Tea 200g', 'sku' => 'tzf;fk;', 'barcode' => '9876543210123', 'unit_id' => $this->product->unit_id, 'price' => '50', 'stock' => '5', 'active' => true]);
        foreach (['Tea', 'tzf;fk;', '9876543210123'] as $query) {
            $this->getJson(route('pos.products', ['q' => $query]))->assertOk()->assertJsonFragment(['id' => $english->id]);
        }
        $this->getJson(route('pos.products', ['q' => 'tzf;fk;', 'scan' => true]))->assertOk()->assertJsonCount(1)->assertJsonFragment(['id' => $english->id])->assertJsonMissing(['name' => $this->product->name]);
        app(SettingsService::class)->put('pos', ['search_mode' => 'sku']);
        $this->getJson(route('pos.products', ['q' => 'tz']))->assertOk()->assertJsonMissing(['name' => $this->product->name]);
        app(SettingsService::class)->put('pos', ['search_mode' => 'name']);
        $this->getJson(route('pos.products', ['q' => 'tz']))->assertOk()->assertJsonFragment(['id' => $this->product->id]);
        Category::create(['name' => 'தமிழ்']);
        $this->get(route('manage.index', ['resource' => 'categories', 'q' => 'jkpo;']))->assertOk()->assertDontSee('தமிழ்');
        $this->assertSame('வணக்கம் தமிழ்', $this->product->fresh()->name);
    }

    public function test_pos_category_and_active_filters_still_apply_to_bamini_matches(): void
    {
        $category = Category::create(['name' => 'No Tamil products']);
        $this->getJson(route('pos.products', ['q' => 'tz', 'category_id' => $category->id]))->assertOk()->assertJsonMissing(['name' => $this->product->name]);
        $this->product->update(['active' => false]);
        $this->getJson(route('pos.products', ['q' => 'tz']))->assertOk()->assertJsonMissing(['name' => $this->product->name]);
    }

    public function test_server_and_browser_use_the_same_complete_glyph_mapping(): void
    {
        foreach (json_decode(file_get_contents(resource_path('data/bamini.json')), true) as $raw => $unicode) {
            $this->assertSame($unicode, Bamini::toUnicode($raw), $raw);
        }
        $this->assertSame('வெள்ளம்', Bamini::toUnicode('nts;sk;'));
        $this->assertSame('கொ', Bamini::toUnicode('கொ'));
    }
}
