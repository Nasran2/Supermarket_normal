<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\RegisterService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentMethodActivityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $registerId;

    private PaymentMethod $cash;

    private PaymentMethod $card;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        app(SettingsService::class)->put('business', ['timezone' => 'Asia/Colombo']);
        $this->travelTo(Carbon::parse('2026-10-09 12:00:00', 'Asia/Colombo'));
        $this->admin = User::create(['name' => 'Payment viewer', 'email' => 'viewer@example.test', 'password' => 'test-password', 'role_id' => Role::where('name', 'Administrator')->value('id')]);
        $this->actingAs($this->admin);
        $this->registerId = app(RegisterService::class)->open($this->admin->id, '0')->id;
        $this->cash = PaymentMethod::where('type', 'CASH')->firstOrFail();
        $this->card = PaymentMethod::where('type', 'CARD')->firstOrFail();
    }

    private function sale(string $date, ?string $invoice = null, string $status = 'ACTIVE'): Sale
    {
        return Sale::create(['invoice' => $invoice ?? 'INV-'.Str::uuid(), 'checkout_token' => Str::uuid(), 'user_id' => $this->admin->id, 'register_id' => $this->registerId, 'subtotal' => '1000', 'sale_amount' => '1000', 'customer_payable' => '1000', 'cost_total' => '500', 'sold_at' => $date, 'status' => $status]);
    }

    private function payment(Sale $sale, PaymentMethod $method, string $received, string $change = '0', string $charge = '0', string $bearer = 'BUSINESS'): void
    {
        $sale->payments()->create(['payment_method_id' => $method->id, 'method_name' => $method->name, 'method_type' => $method->type, 'charge_bearer' => $bearer, 'sale_amount' => '1000', 'processing_charge' => $charge, 'customer_payable' => '1000', 'amount_paid' => $received, 'change' => $change]);
    }

    private function url(PaymentMethod $method, array $filters = []): string
    {
        return route('manage.show', ['resource' => 'payment-methods', 'id' => $method->id] + $filters);
    }

    public function test_today_is_default_and_totals_use_saved_fees_actual_collections_and_selected_method(): void
    {
        $sale = $this->sale('2026-10-09 00:00:00', 'INV-TODAY');
        $this->payment($sale, $this->cash, '1500', '500', '10', 'CUSTOMER');
        $this->payment($sale, $this->cash, '200', '0', '5');
        $this->payment($sale, $this->card, '700', '0', '20');
        $this->payment($this->sale('2026-10-08 23:59:59', 'INV-YESTERDAY'), $this->cash, '900');
        $this->payment($this->sale('2026-10-10 00:00:00', 'INV-TOMORROW'), $this->cash, '800');
        $this->payment($this->sale('2026-10-09 11:00:00', 'INV-VOIDED', 'VOIDED'), $this->cash, '5000', '0', '100');
        $this->cash->update(['has_charge' => true, 'charge_value' => '99']);
        $response = $this->get($this->url($this->cash))->assertOk()->assertSee('INV-TODAY')->assertDontSee('INV-YESTERDAY')->assertDontSee('INV-TOMORROW')->assertDontSee('INV-VOIDED')->assertSee('Customer paid')->assertSee('Business paid');
        $response->assertViewHas('filters', fn ($f) => $f['range'] === 'today' && $f['from'] === '2026-10-09' && $f['to'] === '2026-10-09');
        $response->assertViewHas('totals', fn ($t) => $t['collected'] === '1200.00' && $t['charges'] === '15.00' && $t['customer_charges'] === '10.00' && $t['business_charges'] === '5.00' && (int) $t['bills'] === 1);
        $response->assertViewHas('activity', fn ($a) => $a->total() === 2);
        $this->get($this->url($this->cash, ['range' => '']))->assertOk()->assertViewHas('filters', fn ($f) => $f['range'] === 'today');
    }

    public function test_quick_and_custom_ranges_are_inclusive_and_validation_rejects_bad_dates(): void
    {
        $this->payment($this->sale('2026-10-08 23:59:59', 'INV-OLD'), $this->cash, '100');
        $this->payment($this->sale('2026-10-09 23:59:59', 'INV-LATE'), $this->cash, '200');
        $this->get($this->url($this->cash, ['range' => 'yesterday']))->assertOk()->assertSee('INV-OLD')->assertDontSee('INV-LATE')->assertViewHas('totals', fn ($t) => $t['collected'] === '100.00');
        $this->get($this->url($this->cash, ['from' => '2026-10-08', 'to' => '2026-10-09']))->assertOk()->assertSee('INV-OLD')->assertSee('INV-LATE')->assertViewHas('totals', fn ($t) => $t['collected'] === '300.00');
        foreach (['week' => ['2026-10-05', '2026-10-09'], '7days' => ['2026-10-03', '2026-10-09'], 'month' => ['2026-10-01', '2026-10-09'], 'last_month' => ['2026-09-01', '2026-09-30'], '30days' => ['2026-09-10', '2026-10-09']] as $range => [$from, $to]) {
            $this->get($this->url($this->cash, ['range' => $range]))->assertOk()->assertViewHas('filters', fn ($f) => $f['from'] === $from && $f['to'] === $to);
        }
        foreach ([['range' => 'bad'], ['range' => 'custom'], ['from' => 'invalid', 'to' => '2026-10-09'], ['from' => '2026-10-09', 'to' => '2026-10-08']] as $filters) {
            $this->getJson($this->url($this->cash, $filters))->assertUnprocessable();
        }
    }

    public function test_later_collections_refunds_and_customer_allocations_are_not_double_counted(): void
    {
        $customer = Customer::create(['name' => 'Payment customer', 'active' => true]);
        $sale = $this->sale('2026-10-08 10:00:00', 'INV-DUE-YESTERDAY');
        $sale->update(['customer_id' => $customer->id]);
        $this->payment($sale, $this->cash, '20');
        $sale->collections()->create(['register_id' => $this->registerId, 'user_id' => $this->admin->id, 'payment_method_id' => $this->cash->id, 'method_name' => $this->cash->name, 'method_type' => 'CASH', 'token' => Str::uuid(), 'amount' => '60', 'amount_paid' => '100', 'change' => '40', 'collected_at' => '2026-10-09 12:00:00']);
        $account = $customer->customerPayments()->create(['user_id' => $this->admin->id, 'payment_method_id' => $this->cash->id, 'amount' => '100', 'payment_date' => '2026-10-09 12:00:00']);
        $account->allocations()->create(['type' => 'INVOICE', 'sale_id' => $sale->id, 'amount' => '60']);
        $account->allocations()->create(['type' => 'OPENING_BALANCE', 'amount' => '40']);
        $sale->returns()->create(['register_id' => $this->registerId, 'user_id' => $this->admin->id, 'payment_method_id' => $this->cash->id, 'method_name' => 'Cash', 'method_type' => 'CASH', 'token' => Str::uuid(), 'reference' => 'RET-ACTIVITY', 'reason' => 'Test return', 'amount' => '25', 'cost_total' => '10', 'due_reduction' => '0', 'refund_amount' => '25', 'returned_at' => '2026-10-09 12:30:00']);
        $this->get($this->url($this->cash))->assertOk()->assertSee('INV-DUE-YESTERDAY')->assertSee('Due collection')->assertSee('Account payment')->assertSee('Refund')->assertViewHas('activity', fn ($a) => $a->total() === 3)->assertViewHas('totals', fn ($t) => $t['collected'] === '100.00' && $t['refunded'] === '25.00' && $t['net'] === '75.00' && $t['charges'] === '0.00');
    }

    public function test_all_method_overview_and_search_follow_the_same_period(): void
    {
        $sale = $this->sale('2026-10-09 10:00:00');
        $this->payment($sale, $this->cash, '100', '10');
        $this->payment($sale, $this->card, '200', '0', '6');
        $this->payment($this->sale('2026-10-08 10:00:00'), $this->card, '1000', '0', '30');
        $this->get(route('manage.index', 'payment-methods'))->assertOk()->assertSee('Collections by method')->assertSee('View bills')->assertViewHas('totals', fn ($t) => $t['collected'] === '290.00' && $t['charges'] === '6.00')->assertViewHas('byMethod', fn ($m) => (string) $m[$this->cash->id]->collected === '90' && (string) $m[$this->card->id]->charges === '6');
        $this->get(route('manage.index', ['resource' => 'payment-methods', 'q' => $this->card->code]))->assertOk()->assertViewHas('rows', fn ($r) => $r->total() === 1)->assertViewHas('totals', fn ($t) => $t['collected'] === '200.00' && $t['charges'] === '6.00');
        $this->card->update(['active' => false]);
        $this->get(route('manage.index', ['resource' => 'payment-methods', 'active' => '0']))->assertOk()->assertSee('Inactive')->assertViewHas('totals', fn ($t) => $t['collected'] === '200.00');
    }

    public function test_totals_cover_every_page_and_method_links_preserve_custom_dates(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->payment($this->sale('2026-10-09 10:00:00'), $this->cash, '0.10', '0', '0.01');
        }
        $this->get($this->url($this->cash, ['range' => 'custom', 'from' => '2026-10-09', 'to' => '2026-10-09', 'page' => 2]))->assertOk()->assertViewHas('activity', fn ($a) => $a->count() === 5 && $a->total() === 25)->assertViewHas('totals', fn ($t) => $t['collected'] === '2.50' && $t['charges'] === '0.25');
        $this->get(route('manage.index', ['resource' => 'payment-methods', 'from' => '2026-10-01', 'to' => '2026-10-09']))->assertOk()->assertSee('range=custom', false)->assertSee('from=2026-10-01', false);
    }

    public function test_access_and_bill_links_respect_permissions_and_empty_ranges_are_clear(): void
    {
        $sale = $this->sale('2026-10-09 10:00:00', 'INV-READABLE');
        $this->payment($sale, $this->cash, '100');
        $role = Role::create(['name' => 'Payment viewer only']);
        $role->permissions()->sync(Permission::where('name', 'payment-methods.view')->pluck('id'));
        $user = User::create(['name' => 'Limited viewer', 'email' => 'limited@example.test', 'password' => 'test-password', 'role_id' => $role->id]);
        $this->actingAs($user)->get($this->url($this->cash))->assertOk()->assertSee('INV-READABLE')->assertDontSee('href="'.route('sales.show', $sale).'"', false)->assertDontSee('Edit payment method');
        $this->get($this->url($this->cash, ['range' => 'last_month']))->assertOk()->assertSee('No payment activity in this period')->assertViewHas('totals', fn ($t) => $t['collected'] === '0.00' && $t['charges'] === '0.00');
        $role->permissions()->detach();
        $user->unsetRelation('role');
        $this->get($this->url($this->cash))->assertForbidden();
        $this->get(route('manage.index', 'payment-methods'))->assertForbidden();
    }
}
