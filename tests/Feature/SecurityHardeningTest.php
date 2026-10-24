<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Services\CustomerLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_installations_do_not_seed_a_default_account(): void
    {
        $this->app['env'] = 'production';
        try {
            $this->artisan('db:seed', ['--class' => \Database\Seeders\DatabaseSeeder::class, '--force' => true])->assertExitCode(0);
            $this->assertDatabaseCount('users', 0);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_login_response_prevents_caching_framing_and_mime_sniffing(): void
    {
        $this->seed();
        $response = $this->get('/login')->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_login_requires_csrf_outside_the_test_bypass(): void
    {
        $this->app['env'] = 'production';
        try {
            $this->post('/login', ['login' => 'admin', 'password' => 'anything'])->assertStatus(419);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_weak_new_passwords_are_rejected_without_changing_existing_accounts(): void
    {
        $this->seed();
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->actingAs($admin)->putJson('/profile', [
            'current_password' => 'password', 'password' => 'short', 'password_confirmation' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson(route('manage.store', 'users'), [
            'name' => 'New cashier', 'username' => 'new-cashier', 'email' => 'new@example.test',
            'password' => 'short', 'role_id' => Role::where('name', 'Cashier')->value('id'), 'active' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseMissing('users', ['username' => 'new-cashier']);
    }

    public function test_customer_export_escapes_formulas_and_uses_a_safe_filename(): void
    {
        $this->seed();
        $customer = Customer::create(['name' => "Customer\r\nInjected: header", 'active' => true]);
        $rows = collect([['date' => now(), 'type' => 'Payment', 'description' => "\t=HYPERLINK(\"https://example.test\")", 'debit' => '0', 'credit' => '0', 'balance' => '0']]);
        $this->mock(CustomerLedgerService::class)->shouldReceive('build')->once()->andReturn($rows);
        $response = $this->actingAs(User::where('username', 'admin')->firstOrFail())
            ->get(route('manage.customers.ledger', $customer).'?export=csv')->assertOk();
        $response->assertHeader('Content-Disposition', 'attachment; filename=ledger_'.$customer->id.'.csv');
        $this->assertStringContainsString("'\t=HYPERLINK", $response->streamedContent());
    }

    public function test_security_check_reports_unsafe_hosting_settings_without_modifying_accounts(): void
    {
        $this->seed();
        config(['app.url' => 'https://example.test', 'app.debug' => true, 'session.secure' => false]);
        $before = \Illuminate\Support\Facades\DB::table('users')->get()->toJson();
        $this->artisan('security:check')->expectsOutputToContain('Active seeded-password accounts: 1')->assertExitCode(1);
        $this->assertSame($before, \Illuminate\Support\Facades\DB::table('users')->get()->toJson());
    }

    public function test_security_check_passes_for_safe_application_settings_and_strong_accounts(): void
    {
        $this->seed();
        User::where('username', 'admin')->firstOrFail()->update(['password' => 'a-strong-test-password']);
        config(['app.url' => 'https://example.test', 'app.debug' => false, 'session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax']);
        $before = \Illuminate\Support\Facades\DB::table('users')->get()->toJson();
        $this->artisan('security:check')->expectsOutputToContain('Active seeded-password accounts: 0')->assertSuccessful();
        $this->assertSame($before, \Illuminate\Support\Facades\DB::table('users')->get()->toJson());
    }
}
