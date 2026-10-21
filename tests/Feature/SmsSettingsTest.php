<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Cheque;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\ChequeSmsReminderService;
use App\Services\SettingsService;
use App\Services\SmsService;
use App\Services\SmsSettingsService;
use App\Support\Permissions;
use App\Support\Sidebar;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SmsSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::firstOrFail());
        config(['sms.enabled' => true, 'sms.username' => '94770000001', 'sms.password' => 'environment-secret', 'sms.admin_numbers' => '', 'sms.cheque_reminder_time' => '08:00']);
        Http::preventStrayRequests();
    }

    private function values(array $overrides = []): array
    {
        return $overrides + ['username' => '94770000002', 'password' => 'new-account-secret', 'admin_numbers' => '0771234567, +94 77 987 6543', 'cheque_reminder_time' => '10:30'];
    }

    public function test_disabled_sms_hides_settings_navigation_and_blocks_direct_reads_and_updates(): void
    {
        app(SmsSettingsService::class)->save($this->values());
        config(['sms.enabled' => false]);
        $this->get(route('settings.index'))->assertOk()->assertDontSee('href="'.route('settings.sms').'"', false);
        $this->get(route('settings.sms'))->assertNotFound();
        $this->putJson(route('settings.sms.update'), $this->values())->assertNotFound();
        $this->assertFalse(auth()->user()->hasPermission('sms.settings'));
        $this->assertArrayNotHasKey('sms.settings', Permissions::visible());
        $settings = collect(Sidebar::sections(auth()->user())['MANAGEMENT'])->firstWhere('label', 'Settings');
        $this->assertNotContains(route('settings.sms'), array_column($settings['children'], 'url'));
        $this->assertSame('new-account-secret', app(SmsSettingsService::class)->credentials()['password']);
        Http::assertNothingSent();
    }

    public function test_enabled_sms_settings_appear_in_settings_and_show_fallback_values_without_the_password(): void
    {
        $this->get(route('settings.index'))->assertOk()->assertSee('href="'.route('settings.sms').'"', false)->assertSee('SMS settings');
        $settings = collect(Sidebar::sections(auth()->user())['MANAGEMENT'])->firstWhere('label', 'Settings');
        $this->assertContains(route('settings.sms'), array_column($settings['children'], 'url'));
        $this->get(route('settings.sms'))->assertOk()->assertSee('94770000001')->assertSee('Password configured')->assertDontSee('environment-secret')->assertViewHas('values', fn ($values) => ! array_key_exists('password', $values));
        Http::assertNothingSent();
    }

    public function test_saved_password_is_encrypted_not_shared_or_audited_and_form_never_echoes_it(): void
    {
        // Warm the general settings cache before saving, as ordinary web requests do.
        app(SettingsService::class)->all();
        $this->put(route('settings.sms.update'), $this->values())->assertRedirect(route('settings.sms'))->assertSessionHasNoErrors();
        $ciphertext = json_decode(Setting::where('key', 'sms_password')->value('value'), true);
        $this->assertNotSame('new-account-secret', $ciphertext);
        $this->assertSame('new-account-secret', Crypt::decryptString($ciphertext));
        $this->assertSame('94771234567,94779876543', app(SmsSettingsService::class)->adminNumbers());
        $this->assertArrayNotHasKey('sms_password', app(SettingsService::class)->all());
        $this->assertArrayNotHasKey('sms_username', app(SettingsService::class)->all());
        $audit = AuditLog::where('action', 'settings.sms')->firstOrFail();
        $this->assertStringNotContainsString('new-account-secret', $audit->toJson());
        $this->assertStringNotContainsString($ciphertext, $audit->toJson());
        $this->get(route('settings.sms'))->assertOk()->assertSee('94770000002')->assertDontSee('new-account-secret')->assertDontSee('environment-secret')->assertDontSee($ciphertext);
        $this->get(route('sms.index'))->assertOk()->assertDontSee('new-account-secret');
        Http::assertNothingSent();
    }

    public function test_blank_password_keeps_existing_password_and_captures_legacy_credentials_on_first_save(): void
    {
        $this->put(route('settings.sms.update'), $this->values(['password' => '']))->assertSessionHasNoErrors();
        $this->assertSame('environment-secret', app(SmsSettingsService::class)->credentials()['password']);
        $this->put(route('settings.sms.update'), $this->values(['password' => 'replacement-secret']))->assertSessionHasNoErrors();
        $this->put(route('settings.sms.update'), $this->values(['password' => '', 'cheque_reminder_time' => '12:00']))->assertSessionHasNoErrors();
        $this->assertSame('replacement-secret', app(SmsSettingsService::class)->credentials()['password']);
        $this->assertSame('12:00', app(SmsSettingsService::class)->reminderTime());
    }

    public function test_gateway_sending_uses_saved_credentials_instead_of_environment_defaults(): void
    {
        $this->put(route('settings.sms.update'), $this->values())->assertSessionHasNoErrors();
        Http::fake(['textit.biz/*' => Http::response('OK:saved-settings-test')]);
        $sms = app(SmsService::class);
        $message = $sms->enqueue(['dedupe_key' => 'saved-credentials', 'kind' => 'CUSTOM', 'phone' => '0771234567', 'message' => 'Test settings']);
        $this->assertSame('SENT', $sms->deliver($message)->status);
        Http::assertSent(fn ($request) => $request['id'] === '94770000002' && $request['pw'] === 'new-account-secret');
    }

    public function test_saved_reminder_time_and_admin_numbers_apply_to_scheduler_and_cheque_queue(): void
    {
        config(['features.cheque' => true]);
        $this->put(route('settings.sms.update'), $this->values())->assertSessionHasNoErrors();
        $this->travelTo(Carbon::parse('2026-10-22 10:29:00', 'Asia/Colombo'));
        $this->assertFalse(app(SmsSettingsService::class)->reminderDue());
        $this->travel(1)->minutes();
        $this->assertTrue(app(SmsSettingsService::class)->reminderDue());
        Cheque::create(['instrument_key' => Str::random(64), 'cheque_number' => 'SMS-SETTINGS', 'type' => 'OWN_CHEQUE', 'amount' => '500', 'cheque_date' => today(), 'status' => 'PENDING', 'bank_name' => 'Test Bank', 'created_by' => auth()->id()]);
        $this->assertSame(2, app(ChequeSmsReminderService::class)->queue(app(SmsService::class)));
        $this->assertDatabaseHas('sms_messages', ['phone' => '94771234567', 'kind' => 'CHEQUE']);
        $this->assertDatabaseHas('sms_messages', ['phone' => '94779876543', 'kind' => 'CHEQUE']);
        Http::assertNothingSent();
    }

    public function test_blank_admin_list_defaults_to_saved_account_phone(): void
    {
        $this->put(route('settings.sms.update'), $this->values(['admin_numbers' => '']))->assertSessionHasNoErrors();
        $this->assertSame('94770000002', app(SmsSettingsService::class)->adminNumbers());
    }

    public function test_cashiers_and_message_operators_cannot_access_credentials_or_change_settings(): void
    {
        $role = Role::create(['name' => 'SMS message operator', 'sales_visibility' => 'OWN']);
        $role->permissions()->sync(Permission::where('name', 'sms.manage')->pluck('id'));
        $user = User::create(['name' => 'Operator', 'username' => 'settings-operator', 'email' => 'settings-operator@example.test', 'password' => 'password', 'role_id' => $role->id]);
        $this->actingAs($user)->get(route('settings.sms'))->assertForbidden();
        $this->putJson(route('settings.sms.update'), $this->values())->assertForbidden();
        $this->get(route('sms.index'))->assertOk()->assertDontSee('href="'.route('settings.sms').'"', false)->assertDontSee('environment-secret');
        $this->assertDatabaseMissing('settings', ['group' => 'sms']);
    }

    public function test_invalid_credentials_phones_and_times_are_rejected_and_password_is_never_flashed(): void
    {
        $this->putJson(route('settings.sms.update'), $this->values(['username' => 'bad']))->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->putJson(route('settings.sms.update'), $this->values(['admin_numbers' => 'not a number']))->assertUnprocessable()->assertJsonValidationErrors('admin_numbers');
        $this->putJson(route('settings.sms.update'), $this->values(['cheque_reminder_time' => '25:60']))->assertUnprocessable()->assertJsonValidationErrors('cheque_reminder_time');
        $this->put(route('settings.sms.update'), $this->values(['username' => 'bad', 'password' => 'do-not-flash']))->assertSessionHasErrors('username');
        $this->assertNull(session()->getOldInput('password'));
        $this->assertDatabaseMissing('settings', ['group' => 'sms']);
        Http::assertNothingSent();
    }

    public function test_first_setup_requires_a_password_and_disabled_settings_preserve_saved_details(): void
    {
        config(['sms.password' => '']);
        $this->putJson(route('settings.sms.update'), $this->values(['password' => '']))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->put(route('settings.sms.update'), $this->values())->assertSessionHasNoErrors();
        config(['sms.enabled' => false]);
        $this->get(route('settings.sms'))->assertNotFound();
        config(['sms.enabled' => true]);
        $this->get(route('settings.sms'))->assertOk()->assertSee('94770000002')->assertSee('Password configured');
    }
}
