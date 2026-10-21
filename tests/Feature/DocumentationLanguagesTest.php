<?php

namespace Tests\Feature;

use App\Http\Middleware\DemoMode;
use App\Models\User;
use App\Services\DocumentationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentationLanguagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::firstOrFail());
        config(['features.expiry' => true, 'features.barcode' => true]);
    }

    public function test_english_always_appears_and_optional_languages_only_appear_when_enabled(): void
    {
        foreach ([[false, false], [true, false], [false, true], [true, true]] as [$tamil, $sinhala]) {
            config(['documentation-languages.tamil' => $tamil, 'documentation-languages.sinhala' => $sinhala]);
            foreach (['/documentation', '/documentation/add-a-product', '/documentation/default-product-discounts'] as $url) {
                $response = $this->get($url)->assertOk()->assertSee('lang="en"', false);
                foreach (['ta' => $tamil, 'si' => $sinhala] as $lang => $enabled) {
                    if ($enabled) {
                        $response->assertSee('lang="'.$lang.'"', false);
                    } else {
                        $response->assertDontSee('lang="'.$lang.'"', false);
                    }
                }
            }
            $this->assertSame('en', config('app.locale'));
        }
    }

    public function test_other_application_screens_stay_english(): void
    {
        config(['documentation-languages.tamil' => true, 'documentation-languages.sinhala' => true]);
        foreach (['/manage/products/create', '/settings', '/pos'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('lang="ta"', false)->assertDontSee('lang="si"', false)->assertDontSee('documentation-translation', false);
        }
    }

    public function test_every_guide_and_animation_caption_has_both_native_translations(): void
    {
        config(['documentation-languages.tamil' => true, 'documentation-languages.sinhala' => true]);
        config(['features.unit' => true, 'features.colors' => true, 'features.cheque' => true, 'features.scode' => true, 'hr.enabled' => true, 'demo.enabled' => true]);
        $this->withoutMiddleware(DemoMode::class);
        $service = app(DocumentationService::class);
        $strings = [];
        foreach ($service->guides() as $slug => $guide) {
            $strings = [...$strings, $guide['title'], $guide['description'], $guide['category'], $guide['path'], $guide['before'], $guide['tip']];
            $this->get('/documentation/'.$slug)->assertOk();
            foreach ($guide['steps'] as $step) {
                $strings = [...$strings, $step['title'], $step['text'], ...$step['notes'], ...array_column($step['frames'], 'caption')];
            }
        }
        foreach (glob(resource_path('views/documentation/*.blade.php')) as $view) {
            preg_match_all('/<x-documentation-text\s+text="([^"]+)"/', file_get_contents($view), $matches);
            $strings = [...$strings, ...$matches[1]];
        }
        foreach (array_unique(array_filter($strings)) as $text) {
            $copies = $service->copies($text);
            $this->assertSame(['en', 'ta', 'si'], array_keys($copies), 'Missing translation: '.$text);
            $this->assertMatchesRegularExpression('/[\x{0B80}-\x{0BFF}]/u', $copies['ta'], $text);
            $this->assertMatchesRegularExpression('/[\x{0D80}-\x{0DFF}]/u', $copies['si'], $text);
        }
        foreach ($service->labels() as $copies) {
            $this->assertSame(['en', 'ta', 'si'], array_keys($copies));
        }
    }

    public function test_product_guides_explain_line_discount_and_optional_stock(): void
    {
        config(['documentation-languages.tamil' => false, 'documentation-languages.sinhala' => false]);
        $this->get('/documentation/add-a-product')->assertOk()->assertSee('Maintain stock is on by default.')->assertSee('Rs. 190')->assertSee('Rs. 180')->assertSee('data-demo-frame-next', false)->assertSee('product-untracked.png', false)->assertSee('product-discount-percent.png', false);
        $this->get('/documentation/default-product-discounts')->assertOk()->assertSee('None')->assertSee('product-discount-none.png', false);
        $this->get('/documentation/maintain-product-stock')->assertOk()->assertSee('cost')->assertSee('selling price');
    }

    public function test_expiry_setting_filters_guides_without_mixing_up_step_frames(): void
    {
        config(['features.expiry' => false]);
        $service = app(DocumentationService::class);
        $guides = $service->guides();
        $this->assertArrayNotHasKey('expiry-batches', $guides);
        $steps = $guides['add-a-product']['steps'];
        $this->assertCount(6, $steps);
        $this->assertSame('product-save', $steps[5]['frames'][0]['screen']);
        $this->get('/documentation/expiry-batches')->assertNotFound();
        $this->get('/documentation/add-a-product')->assertOk()->assertDontSee('product-expiry.png', false)->assertDontSee('product-discount-percent.png', false)->assertSee('Set the default discount');
        config(['features.expiry' => true]);
        $this->assertCount(7, $service->guides()['add-a-product']['steps']);
        $this->get('/documentation/expiry-batches')->assertOk()->assertSee('expiry-pos.png', false);
    }

    public function test_registered_screens_and_highlights_match_their_image_geometry(): void
    {
        $screens = config('documentation-walkthroughs.screens');
        $service = app(DocumentationService::class);
        foreach (config('documentation') as $slug => $guide) {
            $sequences = config('documentation-walkthroughs.guides')[$slug];
            $this->assertCount(count($guide['steps']), $sequences, $slug);
            foreach ($sequences as $index => $frames) {
                // Written guides have complete instructions without unrelated screenshots.
                $this->assertNotEmpty($guide['steps'][$index]['title']);
                $this->assertNotEmpty($guide['steps'][$index]['text']);
                foreach ($frames as $frame) {
                    $path = public_path('help-assets/screens/'.$frame['screen'].'.png');
                    $this->assertFileExists($path);
                    $image = getimagesize($path);
                    $this->assertSame($image[0], $screens[$frame['screen']]['width']);
                    $this->assertSame($image[1], $screens[$frame['screen']]['height']);
                    $resolved = $service->frame($frame);
                    $this->assertStringEndsWith('?v='.substr(hash_file('sha256', $path), 0, 12), $resolved['url']);
                    if ($frame['target']) {
                        [$x, $y, $w, $h] = $resolved['box'];
                        $this->assertGreaterThanOrEqual(0, $x, $frame['screen']);
                        $this->assertGreaterThanOrEqual(0, $y, $frame['screen']);
                        $this->assertGreaterThan(0, $w, $frame['screen']);
                        $this->assertGreaterThan(0, $h, $frame['screen']);
                        $this->assertLessThanOrEqual(100.01, $x + $w, $frame['screen']);
                        $this->assertLessThanOrEqual(100.01, $y + $h, $frame['screen']);
                    }
                }
            }
        }
    }
}
