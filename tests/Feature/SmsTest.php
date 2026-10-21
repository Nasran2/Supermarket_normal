<?php

namespace Tests\Feature;

use App\Models\Cheque;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\ChequeSmsReminderService;
use App\Services\RegisterService;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SmsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::firstOrFail();
        $this->actingAs($this->admin);
        config(['sms.enabled' => true, 'sms.username' => 'test-account', 'sms.password' => 'test-secret']);
        Http::preventStrayRequests();
        $this->gateway(['textit.biz/*' => Http::response('OK:gateway-123')]);
    }

    private function gateway($callback): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($callback);
    }

    private function sale(?Customer $customer = null, ?User $owner = null): Sale
    {
        $owner ??= $this->admin;
        $register = app(RegisterService::class)->current($owner->id) ?? app(RegisterService::class)->open($owner->id, '0');

        return Sale::create(['invoice' => 'SMS-'.Str::random(10), 'checkout_token' => Str::uuid(), 'user_id' => $owner->id, 'customer_id' => $customer?->id,
            'register_id' => $register->id, 'subtotal' => '100', 'sale_amount' => '100', 'customer_payable' => '100', 'cost_total' => '20', 'status' => 'ACTIVE', 'sold_at' => now()]);
    }

    private function customer(array $attributes = []): Customer
    {
        return Customer::create($attributes + ['name' => 'SMS Customer', 'phone' => '0771234567', 'active' => true]);
    }

    public function test_false_hides_sms_and_blocks_every_route_commands_and_public_links(): void
    {
        $sale = $this->sale($this->customer());
        $link = app(SmsService::class)->receiptLink($sale);
        config(['sms.enabled' => false]);
        $this->get(route('pos.index'))->assertOk()->assertDontSee('id="sms-receipt"', false)->assertDontSee('SMS &amp; reminders', false);
        $this->get(route('sales.show', $sale))->assertOk()->assertDontSee('Send receipt SMS');
        $this->get(route('sms.index'))->assertNotFound();
        $this->get(route('sms.customers'))->assertNotFound();
        $this->get(route('sms.receipt.context', $sale))->assertNotFound();
        $this->postJson(route('sms.receipt', $sale), ['token' => Str::uuid()])->assertNotFound();
        $this->postJson(route('sms.preview'))->assertNotFound();
        $this->postJson(route('sms.send'))->assertNotFound();
        $this->get($link)->assertNotFound();
        $this->artisan('sms:send-pending')->assertSuccessful();
        $this->assertFalse($this->admin->hasPermission('sms.manage'));
        Http::assertNothingSent();
    }

    public function test_receipt_uses_saved_customer_and_public_link_without_authentication(): void
    {
        $customer = $this->customer();
        $sale = $this->sale($customer);
        $token = (string) Str::uuid();
        $url = route('sms.receipt', $sale);
        $this->get($url)->assertOk()->assertJsonPath('can_send', true);
        $this->postJson($url, ['token' => $token])->assertOk()->assertJsonPath('status', 'SENT');
        $this->postJson($url, ['token' => $token])->assertOk()->assertJsonPath('status', 'SENT');
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['id'] === 'test-account' && $request['pw'] === 'test-secret' && $request['to'] === '94771234567' && str_contains($request['text'], '/r/'.$sale->fresh()->receipt_token) && str_contains($request['text'], 'Due: 100.00'));
        $this->assertSame(64, strlen($sale->fresh()->receipt_token));
        auth()->logout();
        $this->get(app(SmsService::class)->receiptLink($sale))->assertOk()->assertSee($sale->invoice)->assertSee('Print receipt')->assertDontSee('Back to POS')->assertDontSee('Sale details')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/r/'.str_repeat('x', 64))->assertNotFound();
    }

    public function test_default_cashier_can_send_receipts_only_when_sms_is_enabled(): void
    {
        $cashier = User::create(['name' => 'Default cashier', 'username' => 'sms-default-cashier', 'email' => 'sms-default-cashier@example.test', 'password' => 'password', 'role_id' => Role::where('name', 'Cashier')->value('id')]);
        $this->assertTrue($cashier->hasPermission('sms.receipt'));
        $this->assertFalse($cashier->hasPermission('sms.manage'));
        config(['sms.enabled' => false]);
        $this->assertFalse($cashier->hasPermission('sms.receipt'));
    }

    public function test_public_links_use_the_configured_public_origin_and_subdirectory(): void
    {
        config(['app.url' => 'https://shop.example.test/market']);
        $sale = $this->sale();
        $link = app(SmsService::class)->receiptLink($sale);
        $this->assertSame('https://shop.example.test/market/r/'.$sale->fresh()->receipt_token, $link);
    }

    public function test_walkin_can_select_existing_customer_or_create_and_reuse_matching_phone(): void
    {
        $existing = $this->customer();
        $sale = $this->sale();
        $this->postJson(route('sms.receipt', $sale), ['token' => Str::uuid(), 'customer_id' => $existing->id])->assertOk();
        $this->assertSame($existing->id, $sale->fresh()->customer_id);
        $next = $this->sale();
        $this->postJson(route('sms.receipt', $next), ['token' => Str::uuid(), 'name' => 'Do not duplicate', 'phone' => '+94 77 123 4567'])->assertOk();
        $this->assertSame($existing->id, $next->fresh()->customer_id);
        $new = $this->sale();
        $this->postJson(route('sms.receipt', $new), ['token' => Str::uuid(), 'name' => 'New Buyer', 'phone' => '0779876543'])->assertOk();
        $this->assertDatabaseHas('customers', ['name' => 'New Buyer', 'phone' => '94779876543']);
        $this->assertNotNull($new->fresh()->customer_id);
    }

    public function test_missing_customer_details_are_rejected_without_sending_and_existing_customer_cannot_be_replaced(): void
    {
        $sale = $this->sale();
        $this->postJson(route('sms.receipt', $sale), ['token' => Str::uuid()])->assertUnprocessable();
        $this->postJson(route('sms.receipt', $sale), ['token' => Str::uuid(), 'name' => 'Buyer', 'phone' => 'bad'])->assertUnprocessable();
        Http::assertNothingSent();
        $original = $this->customer();
        $other = $this->customer(['name' => 'Other', 'phone' => '0779876543']);
        $sale->update(['customer_id' => $original->id]);
        $this->postJson(route('sms.receipt', $sale), ['token' => Str::uuid(), 'customer_id' => $other->id])->assertOk();
        $this->assertSame($original->id, $sale->fresh()->customer_id);
    }

    public function test_missing_saved_phone_can_be_added_and_voided_sales_cannot_be_sent(): void
    {
        $customer = $this->customer(['phone' => null]);
        $sale = $this->sale($customer);
        $this->get(route('sms.receipt.context', $sale))->assertJsonPath('can_send', false);
        $this->postJson(route('sms.receipt', $sale), ['token' => Str::uuid(), 'phone' => '0771234567'])->assertOk();
        $this->assertSame('94771234567', $customer->fresh()->phone);
        $sale->update(['status' => 'VOIDED']);
        $this->postJson(route('sms.receipt', $sale), ['token' => Str::uuid()])->assertUnprocessable();
        Http::assertSentCount(1);
    }

    public function test_bulk_due_preview_skips_paid_and_invalid_customers_and_confirmation_is_idempotent(): void
    {
        $due = $this->customer(['opening_due' => '750']);
        $this->customer(['name' => 'Paid', 'phone' => '0772222222']);
        $this->customer(['name' => 'No phone', 'phone' => null, 'opening_due' => '100']);
        $preview = $this->post(route('sms.preview'), ['kind' => 'DUE', 'audience' => 'all'])->assertOk()->assertViewHas('skipped', 2)->assertViewHas('recipients', fn ($rows) => count($rows) === 1 && str_contains($rows[0]['message'], '750.00'));
        Http::assertNothingSent();
        $token = $preview->viewData('token');
        $this->post(route('sms.send'), ['token' => $token])->assertRedirect(route('sms.index'));
        $this->postJson(route('sms.send'), ['token' => $token])->assertUnprocessable();
        $this->assertDatabaseCount('sms_messages', 1);
        $this->assertDatabaseHas('sms_messages', ['customer_id' => $due->id, 'kind' => 'DUE', 'status' => 'PENDING']);
        $this->artisan('sms:send-pending')->assertSuccessful();
        $this->artisan('sms:send-pending')->assertSuccessful();
        Http::assertSentCount(1);
        $this->get(route('sms.index'))->assertOk()->assertSee('gateway-123')->assertSee('SENT');
    }

    public function test_individual_custom_message_interpolates_only_selected_customer_and_validates_content(): void
    {
        $one = $this->customer(['name' => 'First Buyer']);
        $other = $this->customer(['name' => 'Second Buyer']);
        $this->postJson(route('sms.preview'), ['kind' => 'CUSTOM', 'audience' => 'selected', 'customers' => [$one->id]])->assertUnprocessable();
        $response = $this->post(route('sms.preview'), ['kind' => 'CUSTOM', 'audience' => 'selected', 'customers' => [$one->id], 'message' => 'Hello {name}, new offers at {business}!'])->assertOk();
        $this->assertCount(1, $response->viewData('recipients'));
        $this->assertStringContainsString('Hello First Buyer', $response->viewData('recipients')[0]['message']);
        $this->post(route('sms.send'), ['token' => $response->viewData('token')])->assertRedirect();
        $this->assertDatabaseMissing('sms_messages', ['customer_id' => $other->id]);
    }

    public function test_authorization_and_sales_visibility_apply_to_receipt_sms(): void
    {
        $sale = $this->sale($this->customer());
        $role = Role::create(['name' => 'Limited SMS', 'sales_visibility' => 'OWN']);
        $user = User::create(['name' => 'Cashier', 'username' => 'sms-cashier', 'email' => 'sms-cashier@example.test', 'password' => 'password', 'role_id' => $role->id]);
        $this->actingAs($user);
        $this->get(route('sms.index'))->assertForbidden();
        $role->permissions()->sync(Permission::whereIn('name', ['sms.receipt', 'sales.receipt'])->pluck('id'));
        $user->unsetRelation('role');
        $this->postJson(route('sms.receipt', $sale), ['token' => Str::uuid()])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_non_admin_message_history_does_not_expose_other_senders_receipt_links(): void
    {
        app(SmsService::class)->enqueue(['dedupe_key' => 'private-history', 'kind' => 'RECEIPT', 'user_id' => $this->admin->id, 'phone' => '0771234567', 'message' => 'Private receipt link marker']);
        $role = Role::create(['name' => 'SMS operator', 'sales_visibility' => 'OWN']);
        $role->permissions()->sync(Permission::where('name', 'sms.manage')->pluck('id'));
        $user = User::create(['name' => 'SMS Operator', 'username' => 'sms-operator', 'email' => 'sms-operator@example.test', 'password' => 'password', 'role_id' => $role->id]);
        $this->actingAs($user)->get(route('sms.index'))->assertOk()->assertDontSee('Private receipt link marker');
        $this->flushSession();
        $this->actingAs($this->admin)->get(route('sms.index'))->assertOk()->assertSee('Private receipt link marker');
    }

    public function test_gateway_failures_and_timeouts_are_logged_without_automatic_retry_or_secrets(): void
    {
        $sms = app(SmsService::class);
        $this->gateway(['textit.biz/*' => Http::response('ERROR:test-secret')]);
        $failed = $sms->deliver($sms->enqueue(['dedupe_key' => 'failure', 'kind' => 'CUSTOM', 'phone' => '0771234567', 'message' => 'Test']));
        $this->assertSame('FAILED', $failed->status);
        $this->assertStringNotContainsString('test-secret', $failed->error);
        $this->gateway(fn () => throw new ConnectionException('test-secret'));
        $unknown = $sms->deliver($sms->enqueue(['dedupe_key' => 'timeout', 'kind' => 'CUSTOM', 'phone' => '0771234567', 'message' => 'Test']));
        $this->assertSame('UNKNOWN', $unknown->status);
        $this->assertStringNotContainsString('test-secret', $unknown->error);
        $this->gateway(['textit.biz/*' => Http::response('OK:should-not-send')]);
        $sms->deliver($unknown);
        $this->artisan('sms:send-pending')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_queued_messages_do_not_send_when_disabled_and_missing_credentials_block_sending(): void
    {
        app(SmsService::class)->enqueue(['dedupe_key' => 'pending', 'kind' => 'CUSTOM', 'phone' => '0771234567', 'message' => 'Test']);
        config(['sms.enabled' => false]);
        $this->artisan('sms:send-pending')->assertSuccessful();
        $this->assertDatabaseHas('sms_messages', ['dedupe_key' => 'pending', 'status' => 'PENDING']);
        config(['sms.enabled' => true, 'sms.password' => '']);
        $sale = $this->sale($this->customer());
        $this->postJson(route('sms.receipt', $sale), ['token' => Str::uuid()])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_cheque_reminders_are_daily_deduplicated_and_resolved_cheques_are_skipped(): void
    {
        config(['features.cheque' => true, 'sms.admin_numbers' => '0771234567,+94771234567']);
        $cheque = Cheque::create(['instrument_key' => Str::random(64), 'cheque_number' => 'SMS-CHEQUE', 'type' => 'OWN_CHEQUE', 'amount' => '500', 'cheque_date' => today(), 'status' => 'PENDING', 'bank_name' => 'Test Bank', 'created_by' => $this->admin->id]);
        $reminders = app(ChequeSmsReminderService::class);
        $this->assertSame(1, $reminders->queue(app(SmsService::class)));
        $this->assertSame(0, $reminders->queue(app(SmsService::class)));
        $cheque->update(['status' => 'PASSED']);
        $this->artisan('sms:send-pending')->assertSuccessful();
        $this->assertDatabaseHas('sms_messages', ['cheque_id' => $cheque->id, 'status' => 'CANCELLED']);
        Http::assertNothingSent();
        $cheque->update(['status' => 'PENDING']);
        $this->travel(1)->days();
        $this->assertSame(1, $reminders->queue(app(SmsService::class)));
        $this->artisan('sms:send-pending')->assertSuccessful();
        Http::assertSentCount(1);
    }
}
