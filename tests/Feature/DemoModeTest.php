<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\DemoDataService;
use App\Services\PdfReportService;
use App\Services\RegisterService;
use Database\Seeders\SampleProductSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DemoModeTest extends TestCase
{
    // A reset controls its own transaction and foreign-key scope. Do not wrap
    // the test in RefreshDatabase's outer SQLite transaction.

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
        $this->beforeApplicationDestroyed(fn () => RefreshDatabaseState::$migrated = false);
        $this->seed();
        $this->admin = User::where('username', 'admin')->firstOrFail();
        $this->actingAs($this->admin);
    }

    private function refreshDemo(): array
    {
        $service = app(DemoDataService::class);

        return $service->synchronized(fn () => $service->refresh());
    }

    public function test_normal_mode_has_no_labels_restrictions_or_reset_even_after_due_date(): void
    {
        config(['demo.enabled' => true]);
        $this->refreshDemo();
        $category = Category::create(['name' => 'Keep this real data']);
        $this->travel(11)->days();
        config(['demo.enabled' => false]);
        $this->assertFalse($this->refreshDemo()['reset']);
        $this->assertDatabaseHas('categories', ['id' => $category->id]);
        $this->get(route('settings.edit', 'business'))->assertOk()->assertDontSee('DEMO MODE')->assertDontSee('demo-readonly');
        $this->putJson(route('settings.update', 'business'), [])->assertUnprocessable();
        $this->artisan('demo:reset')->expectsOutput('Demo mode is disabled. No data was changed.')->assertSuccessful();
        $this->assertSame(1, DB::table('demo_cycles')->value('cycle'));
    }

    public function test_all_settings_and_account_configuration_are_protected_even_for_admin(): void
    {
        config(['demo.enabled' => true]);
        $before = DB::table('settings')->get()->toJson();
        $password = $this->admin->password;
        foreach (array_keys(config('pos')) as $group) {
            $this->putJson(route('settings.update', $group), ['business_name' => 'Changed'])->assertForbidden();
        }
        foreach (['users', 'roles', 'payment-methods', 'payment-rules', 'units', 'unit-presets'] as $resource) {
            $this->postJson(route('manage.store', $resource), [])->assertForbidden();
            $this->putJson(route('manage.update', [$resource, 1]), [])->assertForbidden();
            $this->deleteJson(route('manage.destroy', [$resource, 1]))->assertForbidden();
        }
        $this->putJson(route('profile.update'), ['password' => 'changed-password'])->assertForbidden();
        $this->postJson(route('units.default', 1), [])->assertForbidden();
        $this->postJson(route('hr.setup.save'), [])->assertForbidden();
        $this->postJson(route('hr.staff.store'), ['role_id' => 1])->assertForbidden();
        DB::table('hr_staff')->insert(['id' => 1, 'name' => 'QA staff', 'joined_on' => today()->toDateString()]);
        $this->putJson(route('hr.staff.update', 1), ['account_present' => 1])->assertForbidden();
        $this->postJson(route('hr.staff.employment', 1), [])->assertForbidden();
        $this->assertSame($before, DB::table('settings')->get()->toJson());
        $this->assertSame($password, $this->admin->fresh()->password);
    }

    public function test_demo_pages_label_and_disable_protected_forms_but_allow_category_creation(): void
    {
        config(['demo.enabled' => true]);
        $this->get(route('settings.edit', 'business'))->assertOk()->assertSee('DEMO MODE')->assertSee('Next reset:')->assertSee('Read-only in demo mode.')->assertSee('<fieldset disabled class="demo-readonly">', false);
        $this->get(route('profile'))->assertOk()->assertSee('demo-readonly');
        $this->get(route('manage.create', 'users'))->assertOk()->assertSee('demo-readonly');
        $this->get(route('manage.create', 'products'))->assertOk()->assertDontSee('Read-only in demo mode.');
        $this->post(route('manage.store', 'categories'), ['name' => 'Demo new category'])->assertRedirect();
        $this->assertDatabaseHas('categories', ['name' => 'Demo new category']);
        $this->assertSame(1, DB::table('demo_cycles')->count());
    }

    public function test_ten_day_reset_restores_starting_inventory_and_transactions_and_preserves_setup(): void
    {
        $this->seed(SampleProductSeeder::class);
        config(['demo.enabled' => true]);
        $initialStock = Product::where('sku', 'DEMO-001')->value('stock');
        $settings = DB::table('settings')->get()->toJson();
        $users = DB::table('users')->get()->toJson();
        $roles = DB::table('permission_role')->get()->toJson();
        $this->refreshDemo();
        app(RegisterService::class)->open($this->admin->id, '1000.00');
        $product = Product::where('sku', 'DEMO-001')->firstOrFail();
        $payload = ['items' => [['product_id' => $product->id, 'quantity' => '2']], 'payment_method_id' => PaymentMethod::where('type', 'CASH')->value('id'), 'amount_paid' => '520.00'];
        $quote = $this->postJson(route('pos.quote'), $payload)->assertOk()->json();
        $this->postJson(route('pos.complete'), $payload + ['checkout_token' => (string) Str::uuid(), 'quote_hash' => $quote['quote_hash']])->assertOk();
        $sale = Sale::firstOrFail();
        $this->get(route('sales.receipt', $sale))->assertOk()->assertSee('DEMO — Sample receipt')->assertSee('demo-watermark');
        $product->refresh()->update(['name' => 'Changed name']);
        Category::create(['name' => 'Remove trial category']);
        $this->travel(9)->days();
        $this->assertFalse($this->refreshDemo()['reset']);
        $this->assertDatabaseCount('sales', 1);
        $this->travel(1)->days();
        $state = $this->refreshDemo();
        $this->assertTrue($state['reset']);
        $this->assertSame(2, $state['cycle']);
        $this->assertEquals(now()->addDays(10)->startOfSecond(), $state['next_reset_at']);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('registers', 0);
        $this->assertDatabaseCount('products', 16);
        $this->assertDatabaseMissing('categories', ['name' => 'Remove trial category']);
        $this->assertSame('White Rice 1kg', $product->fresh()->name);
        $this->assertEquals($initialStock, $product->fresh()->stock);
        $this->assertSame($settings, DB::table('settings')->get()->toJson());
        $this->assertSame($users, DB::table('users')->get()->toJson());
        $this->assertSame($roles, DB::table('permission_role')->get()->toJson());
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertFalse($this->refreshDemo()['reset']);
        $this->get(route('dashboard'))->assertOk()->assertSee('DEMO MODE');
        $this->travel(10)->days();
        $this->assertTrue($this->refreshDemo()['reset']);
        $this->assertSame(3, DB::table('demo_cycles')->value('cycle'));
    }

    public function test_first_visit_resets_overdue_data_without_requiring_scheduler(): void
    {
        config(['demo.enabled' => true]);
        $this->get(route('dashboard'))->assertOk();
        Category::create(['name' => 'Overdue demo change']);
        $this->travel(10)->days();
        $this->get(route('dashboard'))->assertOk();
        $this->assertDatabaseMissing('categories', ['name' => 'Overdue demo change']);
        $this->assertSame(2, DB::table('demo_cycles')->value('cycle'));
    }

    public function test_request_that_triggers_reset_cannot_submit_stale_data(): void
    {
        config(['demo.enabled' => true]);
        $this->refreshDemo();
        $this->travel(10)->days();
        $this->postJson(route('manage.store', 'categories'), ['name' => 'Stale form'])->assertStatus(409)->assertJsonPath('message', 'The demo data has just reset. Reload the page and try again.');
        $this->assertDatabaseMissing('categories', ['name' => 'Stale form']);
        $this->postJson(route('manage.store', 'categories'), ['name' => 'Fresh form'])->assertSuccessful();
    }

    public function test_scheduler_initializes_and_resets_only_when_due(): void
    {
        config(['demo.enabled' => true]);
        $this->artisan('demo:reset')->assertSuccessful();
        Category::create(['name' => 'Scheduler trial']);
        $this->artisan('demo:reset')->assertSuccessful();
        $this->assertDatabaseHas('categories', ['name' => 'Scheduler trial']);
        $this->travel(10)->days();
        $this->artisan('demo:reset')->assertSuccessful();
        $this->assertDatabaseMissing('categories', ['name' => 'Scheduler trial']);
        $this->assertSame(2, DB::table('demo_cycles')->value('cycle'));
    }

    public function test_failed_restore_rolls_back_data_and_does_not_advance_cycle(): void
    {
        config(['demo.enabled' => true]);
        $this->refreshDemo();
        $state = DB::table('demo_cycles')->first();
        $snapshot = json_decode($state->baseline, true);
        $snapshot['categories'][] = $snapshot['categories'][0]; // A duplicate PK fails during reinsertion.
        DB::table('demo_cycles')->update(['baseline' => json_encode($snapshot)]);
        $trial = Category::create(['name' => 'Must survive a failed reset']);
        $this->travel(10)->days();
        try {
            $this->refreshDemo();
            $this->fail('Expected the invalid baseline restore to fail.');
        } catch (QueryException) {
            $this->assertDatabaseHas('categories', ['id' => $trial->id]);
            $this->assertSame(1, DB::table('demo_cycles')->value('cycle'));
            $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
        }
        DB::table('demo_cycles')->update(['baseline' => $state->baseline]);
        $this->assertTrue($this->refreshDemo()['reset']);
    }

    public function test_both_pdf_renderers_generate_valid_demo_reports(): void
    {
        config(['demo.enabled' => true]);
        $data = ['title' => 'Demo report', 'headers' => ['Product', 'Amount'], 'rows' => collect([['Sample', '10.00']])];
        foreach ([1, 501] as $count) {
            $pdf = app(PdfReportService::class)->render($data + ['rowCount' => $count]);
            $this->assertStringStartsWith('%PDF-', $pdf);
            $this->assertGreaterThan(1000, strlen($pdf));
        }
    }

    public function test_demo_login_notice_and_existing_credentials_work(): void
    {
        config(['demo.enabled' => true]);
        auth()->logout();
        $this->flushSession();
        $this->get(route('login'))->assertOk()->assertSee('DEMO MODE');
        $this->post('/login', ['login' => 'admin', 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticatedAs($this->admin);
        $this->get(route('dashboard'))->assertOk()->assertSee('DEMO MODE');
    }

    public function test_reset_deadline_uses_utc_even_if_request_and_scheduler_timezones_differ(): void
    {
        config(['demo.enabled' => true, 'app.timezone' => 'Asia/Colombo']);
        $state = $this->refreshDemo();
        $deadline = $state['next_reset_at']->copy();
        config(['app.timezone' => 'America/New_York']);
        $this->travelTo($deadline->copy()->subSecond());
        $this->assertFalse($this->refreshDemo()['reset']);
        $this->travelTo($deadline);
        $this->assertTrue($this->refreshDemo()['reset']);
    }
}
