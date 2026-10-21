<?php

namespace Tests\Feature;

use App\Models\Cheque;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SmsMessage;
use App\Models\User;
use App\Services\ChequeSmsReminderService;
use App\Services\CustomerPaymentService;
use App\Services\RegisterService;
use App\Services\SaleAftercareService;
use App\Services\SmsService;
use App\Services\SmsSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SmsNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actor = User::firstOrFail();
        $this->actingAs($this->actor);
        config(['sms.enabled' => true, 'sms.username' => '94770000001', 'sms.password' => 'test-secret', 'sms.admin_numbers' => '0773333333']);
        Http::preventStrayRequests();
    }

    private function settings(array $overrides): void
    {
        $this->put(route('settings.sms.update'), $overrides + [
            'username' => '94770000001', 'password' => '', 'admin_numbers' => '0773333333', 'cheque_reminder_time' => '08:00',
        ])->assertSessionHasNoErrors()->assertRedirect(route('settings.sms'));
    }

    private function customer(array $values = []): Customer
    {
        return Customer::create($values + ['name' => 'Payment Buyer', 'phone' => '0771234567', 'active' => true]);
    }

    private function sale(Customer $customer): Sale
    {
        $register = app(RegisterService::class)->current($this->actor->id) ?? app(RegisterService::class)->open($this->actor->id, '0');

        return Sale::create(['invoice' => 'SMS-'.Str::random(10), 'checkout_token' => Str::uuid(), 'user_id' => $this->actor->id,
            'customer_id' => $customer->id, 'register_id' => $register->id, 'subtotal' => '500', 'sale_amount' => '500', 'customer_payable' => '500', 'cost_total' => '100', 'status' => 'ACTIVE', 'sold_at' => now()]);
    }

    private function accountPayment(Customer $customer, string $amount = '100', array $overrides = []): void
    {
        app(CustomerPaymentService::class)->record($customer, $overrides + ['amount' => $amount,
            'payment_method_id' => PaymentMethod::where('type', 'CASH')->value('id'), 'payment_date' => today()->toDateString(), 'mode' => 'auto',
        ], $this->actor->id);
    }

    public function test_receipt_and_due_messages_use_saved_templates_and_supported_placeholders(): void
    {
        $this->settings(['receipt_template' => 'Hi {name}. {invoice}: {currency} {total}. Paid {paid}, due {due}. {receipt_url}', 'due_template' => '{business}: dear {name}, please pay {currency} {due}.']);
        $customer = $this->customer();
        $sale = $this->sale($customer);
        Http::fake(['textit.biz/*' => Http::response('OK:custom-receipt')]);
        $this->postJson(route('sms.receipt', $sale), ['token' => Str::uuid()])->assertOk();
        $message = SmsMessage::where('kind', 'RECEIPT')->firstOrFail();
        $this->assertStringContainsString('Hi Payment Buyer. '.$sale->invoice, $message->message);
        $this->assertStringContainsString('Paid 0.00, due 500.00.', $message->message);
        $this->assertStringContainsString('/r/', $message->message);
        $this->post(route('sms.preview'), ['kind' => 'DUE', 'audience' => 'selected', 'customers' => [$customer->id]])->assertOk()
            ->assertViewHas('recipients', fn ($rows) => str_contains($rows[0]['message'], 'dear Payment Buyer, please pay Rs. 500.00.'));
    }

    public function test_individual_type_switches_block_receipt_and_bulk_api_and_hide_receipt_buttons(): void
    {
        $customer = $this->customer(['opening_due' => '100']);
        $sale = $this->sale($customer);
        $this->settings(['receipt_enabled' => false, 'due_enabled' => false, 'custom_enabled' => false]);
        $this->get(route('pos.index'))->assertOk()->assertDontSee('id="sms-receipt"', false);
        $this->postJson(route('sms.receipt', $sale), ['token' => Str::uuid()])->assertForbidden();
        foreach (['DUE', 'CUSTOM'] as $kind) {
            $this->postJson(route('sms.preview'), ['kind' => $kind, 'audience' => 'selected', 'customers' => [$customer->id], 'message' => 'Hello {name}'])->assertUnprocessable();
        }
        $this->assertDatabaseCount('sms_messages', 0);
        Http::assertNothingSent();
    }

    public function test_disabling_a_type_after_preview_blocks_confirmation_and_cancels_queued_messages(): void
    {
        $customer = $this->customer();
        $preview = $this->post(route('sms.preview'), ['kind' => 'CUSTOM', 'audience' => 'selected', 'customers' => [$customer->id], 'message' => 'Hello {name}'])->assertOk();
        $sms = app(SmsService::class);
        $sms->enqueue(['dedupe_key' => 'before-disable', 'kind' => 'CUSTOM', 'phone' => '0771234567', 'message' => 'Do not send']);
        $this->settings(['custom_enabled' => false]);
        $this->postJson(route('sms.send'), ['token' => $preview->viewData('token')])->assertUnprocessable();
        $this->assertDatabaseHas('sms_messages', ['dedupe_key' => 'before-disable', 'status' => 'CANCELLED']);
        $this->artisan('sms:send-pending')->assertSuccessful();
        $this->assertDatabaseHas('sms_messages', ['dedupe_key' => 'before-disable', 'status' => 'CANCELLED']);
        Http::assertNothingSent();
    }

    public function test_global_sending_switch_pauses_all_types_without_hiding_settings_or_history(): void
    {
        app(SmsService::class)->enqueue(['dedupe_key' => 'before-pause', 'kind' => 'DUE', 'phone' => '0771234567', 'message' => 'Do not send']);
        $this->settings(['sending_enabled' => false]);
        $this->get(route('settings.sms'))->assertOk();
        $this->get(route('sms.index'))->assertOk()->assertSee('SMS sending is paused');
        foreach (array_keys(config('sms.types')) as $kind) {
            $this->assertFalse(app(SmsSettingsService::class)->allows($kind));
        }
        $this->artisan('sms:send-pending')->assertSuccessful();
        $this->assertDatabaseHas('sms_messages', ['dedupe_key' => 'before-pause', 'status' => 'CANCELLED']);
        Http::assertNothingSent();
    }

    public function test_account_due_payment_sends_one_confirmation_with_updated_balance_and_custom_template(): void
    {
        $this->settings(['payment_template' => 'Thanks {name}: {currency} {amount} via {method}. Due {currency} {due}. {payment_status}.']);
        $customer = $this->customer(['opening_due' => '300']);
        $this->accountPayment($customer, '100');
        $this->assertDatabaseCount('customer_payments', 1);
        $this->assertDatabaseCount('sms_messages', 1);
        $this->assertDatabaseHas('sms_messages', ['kind' => 'PAYMENT', 'phone' => '94771234567', 'status' => 'PENDING']);
        $this->assertSame('Thanks Payment Buyer: Rs. 100.00 via Cash. Due Rs. 200.00. Payment confirmed.', SmsMessage::first()->message);
        Http::assertNothingSent();
        Http::fake(['textit.biz/*' => Http::response('OK:payment-confirmed')]);
        $this->artisan('sms:send-pending')->assertSuccessful();
        $this->artisan('sms:send-pending')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_full_due_payment_confirms_zero_remaining_and_account_no_longer_qualifies_for_due_reminders(): void
    {
        $customer = $this->customer(['opening_due' => '100']);
        $this->accountPayment($customer, '100');
        $this->assertSame('0.00', $customer->fresh()->due_balance);
        $this->assertStringContainsString('Remaining account balance: Rs. 0.00', SmsMessage::first()->message);
        $this->postJson(route('sms.preview'), ['kind' => 'DUE', 'audience' => 'selected', 'customers' => [$customer->id]])->assertUnprocessable();
        $this->assertDatabaseCount('sms_messages', 1);
        Http::assertNothingSent();
    }

    public function test_account_allocation_across_invoices_does_not_duplicate_payment_sms(): void
    {
        $customer = $this->customer(['opening_due' => '100']);
        $sale = $this->sale($customer);
        $this->accountPayment($customer, '300');
        $this->assertDatabaseCount('sale_collections', 1);
        $this->assertDatabaseCount('sms_messages', 1);
        $this->assertStringContainsString('Remaining account balance: Rs. 300.00', SmsMessage::first()->message);
        $this->assertSame('300.00', $sale->fresh()->due_balance);
    }

    public function test_customer_payment_form_accepts_empty_hidden_allocations_and_notifies_after_auto_or_manual_payment(): void
    {
        $customer = $this->customer(['opening_due' => '200']);
        $sale = $this->sale($customer);
        $base = ['amount' => '100', 'payment_method_id' => PaymentMethod::where('type', 'CASH')->value('id'), 'payment_date' => today()->toDateString()];
        $this->post(route('manage.customers.payments.store', $customer), $base + ['mode' => 'auto', 'allocations' => ['opening_balance' => '', 'sales' => [$sale->id => '']]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('sms_messages', 1);
        $this->post(route('manage.customers.payments.store', $customer), $base + ['mode' => 'manual', 'allocations' => ['opening_balance' => '100', 'sales' => [$sale->id => '']]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('customer_payments', 2);
        $this->assertDatabaseCount('sms_messages', 2);
        $this->assertSame('500.00', $customer->fresh()->due_balance);
    }

    public function test_invoice_due_collection_uses_net_payment_and_retry_does_not_send_twice(): void
    {
        $customer = $this->customer();
        $sale = $this->sale($customer);
        $data = ['token' => (string) Str::uuid(), 'amount' => '200', 'amount_paid' => '250', 'payment_method_id' => PaymentMethod::where('type', 'CASH')->value('id')];
        app(SaleAftercareService::class)->collect($sale, $data, $this->actor->id);
        app(SaleAftercareService::class)->collect($sale, $data, $this->actor->id);
        $this->assertDatabaseCount('sale_collections', 1);
        $this->assertDatabaseCount('sms_messages', 1);
        $this->assertStringContainsString('received Rs. 200.00 by Cash', SmsMessage::first()->message);
        $this->assertStringContainsString('Remaining account balance: Rs. 300.00', SmsMessage::first()->message);
        Http::assertNothingSent();
    }

    public function test_failed_payment_rolls_back_sms_and_notification_failure_does_not_undo_payment(): void
    {
        $customer = $this->customer(['opening_due' => '300']);
        try {
            DB::transaction(function () use ($customer) {
                $this->accountPayment($customer, '100');
                throw new \RuntimeException('Roll back the financial transaction');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Roll back the financial transaction', $exception->getMessage());
        }
        $this->assertDatabaseCount('customer_payments', 0);
        $this->assertDatabaseCount('sms_messages', 0);
        config(['sms.password' => '']);
        $this->accountPayment($customer, '100');
        $this->assertDatabaseCount('customer_payments', 1);
        $this->assertSame('200.00', $customer->fresh()->due_balance);
        $this->assertDatabaseCount('sms_messages', 0);
    }

    public function test_payment_notifications_can_be_disabled_and_invalid_phones_do_not_block_financial_work(): void
    {
        $customer = $this->customer(['opening_due' => '300']);
        $this->settings(['payment_enabled' => false]);
        $this->accountPayment($customer, '50');
        $this->assertDatabaseCount('sms_messages', 0);
        $this->settings(['payment_enabled' => true]);
        $customer->update(['phone' => 'bad']);
        $this->accountPayment($customer, '50');
        $this->assertDatabaseCount('customer_payments', 2);
        $this->assertDatabaseCount('sms_messages', 0);
        Http::assertNothingSent();
    }

    public function test_pending_cheque_payment_is_described_as_pending_and_queues_one_confirmation(): void
    {
        config(['features.cheque' => true]);
        $customer = $this->customer(['opening_due' => '300']);
        $this->accountPayment($customer, '100', ['payment_method_id' => PaymentMethod::where('type', 'CHEQUE')->value('id'), 'cheques' => [
            ['cheque_number' => 'NOTIFY-CHQ', 'cheque_date' => today()->toDateString(), 'amount' => '100', 'bank_name' => 'Test Bank'],
        ]]);
        $this->assertDatabaseCount('sms_messages', 1);
        $this->assertStringContainsString('Cheque pending clearance', SmsMessage::first()->message);
    }

    public function test_admin_and_customer_cheque_reminders_have_independent_templates_and_switches(): void
    {
        config(['features.cheque' => true]);
        $customer = $this->customer();
        Cheque::create(['instrument_key' => Str::random(64), 'cheque_number' => 'CHQ-NOTIFY', 'type' => 'CUSTOMER_RECEIVED', 'origin_customer_id' => $customer->id, 'amount' => '500', 'cheque_date' => today(), 'status' => 'PENDING', 'bank_name' => 'Test Bank', 'created_by' => $this->actor->id]);
        $this->settings(['cheque_enabled' => false, 'cheque_customer_enabled' => true, 'cheque_customer_template' => 'Dear {name}: {cheque_number} {currency} {amount}, due {date}.']);
        $this->assertSame(1, app(ChequeSmsReminderService::class)->queue(app(SmsService::class)));
        $this->assertDatabaseHas('sms_messages', ['kind' => 'CHEQUE_CUSTOMER', 'phone' => '94771234567']);
        $this->assertDatabaseMissing('sms_messages', ['kind' => 'CHEQUE']);
        $this->assertStringContainsString('Dear Payment Buyer: CHQ-NOTIFY Rs. 500.00', SmsMessage::first()->message);
        $this->assertSame(0, app(ChequeSmsReminderService::class)->queue(app(SmsService::class)));
        $this->settings(['cheque_enabled' => true, 'cheque_customer_enabled' => false, 'cheque_template' => 'Admin: {cheque_number} {bank} {currency} {amount}.']);
        $this->assertSame(1, app(ChequeSmsReminderService::class)->queue(app(SmsService::class)));
        $this->assertDatabaseHas('sms_messages', ['kind' => 'CHEQUE', 'phone' => '94773333333', 'message' => 'Admin: CHQ-NOTIFY Test Bank Rs. 500.00.']);
        Http::assertNothingSent();
    }

    public function test_template_validation_rejects_unknown_placeholders_and_empty_templates(): void
    {
        $base = ['username' => '94770000001', 'password' => '', 'cheque_reminder_time' => '08:00'];
        $this->putJson(route('settings.sms.update'), $base + ['receipt_template' => 'Hello {invalid}'])->assertUnprocessable()->assertJsonValidationErrors('receipt_template');
        $this->putJson(route('settings.sms.update'), $base + ['payment_template' => ''])->assertUnprocessable()->assertJsonValidationErrors('payment_template');
        $customer = $this->customer();
        $this->postJson(route('sms.preview'), ['kind' => 'CUSTOM', 'audience' => 'selected', 'customers' => [$customer->id], 'message' => 'Hi {invoice}'])->assertUnprocessable()->assertJsonValidationErrors('message');
        $this->assertDatabaseCount('sms_messages', 0);
    }
}
