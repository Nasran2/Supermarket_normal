<?php

namespace Tests\Feature;

use App\Http\Middleware\DemoMode;
use App\Models\User;
use App\Services\DocumentationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentationFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private const OPTIONAL = [
        'colors' => ['appearance-and-branding'],
        'cheque' => ['cheque-settings-and-banks', 'manage-cheques'],
        'barcode' => ['print-barcode-labels'],
        'scode' => ['secret-cost-codes'],
        'expiry' => ['expiry-batches'],
        'hr' => ['staff-and-payroll'],
        'demo' => ['demo-mode'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::firstOrFail());
        $this->features([]);
    }

    private function features(array $enabled): void
    {
        foreach (['colors', 'cheque', 'barcode', 'scode', 'expiry', 'unit'] as $feature) {
            config(['features.'.$feature => in_array($feature, $enabled, true)]);
        }
        config(['hr.enabled' => in_array('hr', $enabled, true), 'demo.enabled' => in_array('demo', $enabled, true)]);
    }

    public function test_disabled_features_are_absent_from_library_search_and_direct_guides(): void
    {
        $response = $this->get(route('documentation.index'))->assertOk();
        foreach (self::OPTIONAL as $slugs) {
            foreach ($slugs as $slug) {
                $response->assertDontSee(route('documentation.show', $slug), false);
                $this->get(route('documentation.show', $slug))->assertNotFound();
            }
        }
        foreach (app(DocumentationService::class)->guides() as $slug => $guide) {
            $this->assertDoesNotMatchRegularExpression('/\b(barcode|scan|scanning|expiry|expired|cheque|unit|units|payroll|branding|human resources|DEMO mode)\b/i', $guide['search'], $slug);
            $this->assertFalse($guide['screens_enabled']);
            $this->get(route('documentation.show', $slug))->assertOk()->assertDontSee('/help-assets/screens/', false);
        }
        $this->get('/documentation/units-and-categories')->assertOk()->assertSee('Organize product categories')->assertDontSee('Set up a counting unit');
    }

    public function test_optional_guides_reappear_with_their_feature_and_respect_dependencies(): void
    {
        foreach (self::OPTIONAL as $feature => $slugs) {
            $this->features([$feature]);
            if ($feature === 'scode') {
                $this->get('/documentation/secret-cost-codes')->assertNotFound();
                $this->features(['scode', 'barcode']);
            }
            if ($feature === 'demo') {
                $this->withoutMiddleware(DemoMode::class);
            }
            $library = $this->get(route('documentation.index'))->assertOk();
            foreach ($slugs as $slug) {
                $library->assertSee(route('documentation.show', $slug), false);
                $this->get(route('documentation.show', $slug))->assertOk();
            }
        }
    }

    public function test_settings_notes_and_related_links_follow_flags_after_a_toggle(): void
    {
        $url = '/documentation/store-settings';
        $this->get($url)->assertOk()->assertDontSee('Personalize appearance and login')->assertDontSee('Find cheque settings and bank accounts')->assertDontSee('Set label printing preferences');
        $this->features(['colors', 'cheque', 'barcode']);
        $this->get($url)->assertOk()->assertSee('Personalize appearance and login')->assertSee('Find cheque settings and bank accounts')->assertSee('Set label printing preferences');
        $this->get('/documentation/appearance-and-branding')->assertOk()->assertSee('2 MB')->assertSee('Reset or remove an image')->assertSee(route('settings.edit', 'branding'), false);
        $this->features([]);
        $this->get($url)->assertOk()->assertDontSee('Personalize appearance and login')->assertDontSee('Find cheque settings and bank accounts')->assertDontSee('Set label printing preferences');
    }

    public function test_feature_combinations_filter_notes_and_keep_step_frames_attached_to_the_right_step(): void
    {
        $service = app(DocumentationService::class);
        $flags = ['colors', 'cheque', 'barcode', 'scode', 'expiry', 'unit', 'hr', 'demo'];
        for ($mask = 0; $mask < 256; $mask++) {
            $this->features(array_values(array_filter($flags, fn ($feature) => ($mask & (1 << array_search($feature, $flags))) !== 0)));
            $guides = $service->guides();
            foreach (self::OPTIONAL as $feature => $slugs) {
                foreach ($slugs as $slug) {
                    $this->assertSame($service->featureEnabled($feature), isset($guides[$slug]), $feature.' combination '.$mask);
                }
            }
            foreach ($guides as $guide) {
                $this->assertNotEmpty($guide['steps']);
                if (! $service->featureEnabled('expiry')) {
                    $this->assertStringNotContainsString('expiry', $guide['search']);
                }
                if (! $service->featureEnabled('cheque')) {
                    $this->assertStringNotContainsString('cheque', $guide['search']);
                }
            }
            $last = end($guides['add-a-product']['steps']);
            $this->assertSame('product-save', $last['frames'][0]['screen']);
        }
    }

    public function test_all_visible_feature_variants_have_tamil_and_sinhala_copies(): void
    {
        config(['documentation-languages.tamil' => true, 'documentation-languages.sinhala' => true]);
        $service = app(DocumentationService::class);
        foreach ([[], ['unit'], ['barcode'], ['expiry'], ['unit', 'barcode', 'expiry', 'colors', 'cheque', 'scode', 'hr', 'demo']] as $enabled) {
            $this->features($enabled);
            foreach ($service->guides() as $guide) {
                $strings = [$guide['title'], $guide['description'], $guide['category'], $guide['path'], $guide['before'], $guide['tip']];
                foreach ($guide['steps'] as $step) {
                    array_push($strings, $step['title'], $step['text'], ...$step['notes']);
                }
                foreach (array_filter($strings) as $text) {
                    $copies = $service->copies($text);
                    $this->assertSame(['en', 'ta', 'si'], array_keys($copies), $text);
                }
            }
        }
    }
}
