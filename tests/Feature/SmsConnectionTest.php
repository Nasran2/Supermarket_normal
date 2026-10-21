<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\SmsSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SmsConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::firstOrFail());
        config(['sms.enabled' => true, 'sms.username' => '94770000001', 'sms.password' => 'test-secret']);
        Http::preventStrayRequests();
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + ['token' => (string) Str::uuid(), 'phone' => '0771234567', 'message' => 'Connection test'];
    }

    public function test_test_sms_uses_saved_credentials_sends_immediately_and_is_logged_without_creating_a_customer(): void
    {
        app(SmsSettingsService::class)->save(['username' => '94770000002', 'password' => 'saved-secret', 'admin_numbers' => '', 'cheque_reminder_time' => '08:00', 'custom_enabled' => false]);
        Http::fake(['textit.biz/*' => Http::response('OK:test-123')]);
        $before = Customer::count();
        $data = $this->payload();
        $this->postJson(route('settings.sms.test'), $data)->assertOk()->assertJsonPath('status', 'SENT')->assertDontSee('saved-secret');
        $this->postJson(route('settings.sms.test'), $data)->assertOk()->assertJsonPath('status', 'SENT');
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['id'] === '94770000002' && $request['pw'] === 'saved-secret' && $request['to'] === '94771234567' && $request['text'] === 'Connection test');
        $this->assertDatabaseHas('sms_messages', ['kind' => 'TEST', 'status' => 'SENT', 'provider_id' => 'test-123', 'user_id' => auth()->id()]);
        $this->assertDatabaseCount('sms_messages', 1);
        $this->assertSame($before, Customer::count());
        $this->get(route('settings.sms'))->assertOk()->assertSee('Send a test SMS')->assertSee('form="sms-test-form"', false);
        $this->get(route('sms.index'))->assertOk()->assertSee('Test SMS');
    }

    public function test_test_sms_requires_feature_and_sending_to_be_enabled(): void
    {
        config(['sms.enabled' => false]);
        $this->postJson(route('settings.sms.test'), $this->payload())->assertNotFound();
        config(['sms.enabled' => true]);
        app(SmsSettingsService::class)->save(['username' => '94770000001', 'password' => '', 'admin_numbers' => '', 'cheque_reminder_time' => '08:00', 'sending_enabled' => false]);
        $this->postJson(route('settings.sms.test'), $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('sms_messages', 0);
        Http::assertNothingSent();
    }

    public function test_message_operators_cannot_use_the_settings_test_action(): void
    {
        $role = Role::create(['name' => 'Messages only', 'sales_visibility' => 'OWN']);
        $role->permissions()->sync(Permission::where('name', 'sms.manage')->pluck('id'));
        $user = User::create(['name' => 'Operator', 'username' => 'test-sms-operator', 'email' => 'test-sms-operator@example.test', 'password' => 'password', 'role_id' => $role->id]);
        $this->actingAs($user)->postJson(route('settings.sms.test'), $this->payload())->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_invalid_phone_message_and_missing_credentials_are_rejected_without_sending(): void
    {
        $this->postJson(route('settings.sms.test'), $this->payload(['phone' => 'bad']))->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->postJson(route('settings.sms.test'), $this->payload(['message' => '']))->assertUnprocessable()->assertJsonValidationErrors('message');
        $this->postJson(route('settings.sms.test'), $this->payload(['token' => 'bad']))->assertUnprocessable()->assertJsonValidationErrors('token');
        config(['sms.password' => '']);
        $this->postJson(route('settings.sms.test'), $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('sms_messages', 0);
        Http::assertNothingSent();
    }

    public function test_uncertain_gateway_result_is_reported_and_repeated_requests_do_not_resend(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('Do not expose credentials');
        });
        $data = $this->payload();
        $this->postJson(route('settings.sms.test'), $data)->assertOk()->assertJsonPath('status', 'UNKNOWN')->assertDontSee('Do not expose credentials');
        $this->postJson(route('settings.sms.test'), $data)->assertOk()->assertJsonPath('status', 'UNKNOWN');
        $this->assertSame(1, $calls);
    }

    public function test_test_sms_is_rate_limited(): void
    {
        Http::fake(['textit.biz/*' => Http::response('OK:test-throttle')]);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(route('settings.sms.test'), $this->payload())->assertOk();
        }
        $this->postJson(route('settings.sms.test'), $this->payload())->assertStatus(429);
        Http::assertSentCount(5);
    }
}
