<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\PaymentChargeRule;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use App\Services\ProfitLossService;
use App\Services\RegisterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DueCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Product $product;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->cashier = User::create(['name' => 'Due cashier', 'username' => 'duecashier', 'email' => 'due@example.test', 'password' => 'test-password', 'role_id' => Role::where('name', 'Administrator')->value('id')]);
        $this->actingAs($this->cashier);
        app(RegisterService::class)->open($this->cashier->id, '1000');
        $this->product = Product::create(['name' => 'Due groceries', 'sku' => 'DUE1', 'category_id' => Category::first()->id, 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'price' => '810', 'cost' => '400', 'stock' => '10']);
        $this->customer = Customer::create(['name' => 'Credit customer', 'opening_due' => '100']);
    }

    private function method(string $type = 'CASH'): int
    {
        return PaymentMethod::where('type', $type)->value('id');
    }

    private function data(string $allocated = '810', string $paid = '10'): array
    {
        return ['customer_id' => $this->customer->id, 'allow_due' => true, 'items' => [['product_id' => $this->product->id, 'quantity' => '1']], 'payments' => [['payment_method_id' => $this->method(), 'amount' => $allocated, 'amount_paid' => $paid]]];
    }

    private function quoted(array $data): array
    {
        $quote = $this->postJson(route('pos.quote'), $data)->assertOk()->json();

        return $data + ['checkout_token' => (string) Str::uuid(), 'quote_hash' => $quote['quote_hash']];
    }

    public function test_partial_cash_checkout_tracks_customer_due_actual_cash_and_later_payment(): void
    {
        $data = $this->quoted($this->data());
        $this->postJson(route('pos.complete'), $data)->assertOk()->assertJsonPath('due_balance', '800.00')->assertJsonPath('customer_balance', '900.00');
        $sale = Sale::firstOrFail();
        $this->assertSame('800.00', $sale->due_balance);
        $this->assertSame('10.00', $sale->collected_total);
        $this->assertSame('0.00', $sale->payments->first()->change);
        $this->assertSame('9.000', $this->product->fresh()->stock);
        $this->assertSame('1010.00', app(RegisterService::class)->summary($sale->register)['expected']);
        $this->assertSame('410.00', app(ProfitLossService::class)->calculate(today()->toDateString(), today()->toDateString())['net']);
        $this->assertSame('900.00', $this->customer->fresh()->due_balance);
        $this->get(route('manage.index', ['customers', 'balance' => 'due']))->assertOk()->assertSee('Credit customer')->assertSee('900.00');
        $this->get(route('manage.show', ['customers', $this->customer->id]))->assertOk()->assertSee('Due')->assertSee('800.00');
        $this->get(route('sales.receipt', $sale))->assertOk()->assertSee('BALANCE DUE')->assertSee('800.00');
        $this->postJson(route('pos.complete'), $data)->assertOk();
        $this->assertDatabaseCount('sales', 1);
        $this->post(route('sales.collections.store', $sale), ['token' => (string) Str::uuid(), 'payment_method_id' => $this->method(), 'amount' => '300', 'amount_paid' => '500'])->assertRedirect();
        $this->assertSame('500.00', $sale->fresh()->due_balance);
        $this->assertSame('600.00', $this->customer->fresh()->due_balance);
        $this->assertSame('1310.00', app(RegisterService::class)->summary($sale->register)['expected']);
    }

    public function test_fully_unpaid_checkout_has_no_fake_payment_or_fees_and_stock_is_sold_once(): void
    {
        $this->postJson(route('pos.complete'), $this->quoted($this->data('0', '0')))->assertOk()->assertJsonPath('due_balance', '810.00');
        $sale = Sale::firstOrFail();
        $this->assertSame(0, $sale->payments()->count());
        $this->assertSame('0.00', $sale->processing_charge);
        $this->assertSame('1000.00', app(RegisterService::class)->summary($sale->register)['expected']);
        $this->get(route('sales.show', $sale))->assertOk()->assertSee('Pay due')->assertSee('No payment received yet.');
        $this->get(route('sales.receipt', $sale))->assertOk()->assertSee('On account (unpaid)');
        $this->delete(route('sales.destroy', $sale), ['reason' => 'Cancel unpaid bill'])->assertRedirect();
        $this->assertSame('10.000', $this->product->fresh()->stock);
        $this->assertSame('100.00', $this->customer->fresh()->due_balance);
    }

    public function test_due_requires_active_customer_and_normal_checkout_still_requires_full_payment(): void
    {
        $data = $this->quoted($this->data());
        $data['customer_id'] = null;
        $this->postJson(route('pos.complete'), $data)->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $data['customer_id'] = $this->customer->id;
        $data['allow_due'] = false;
        $this->postJson(route('pos.complete'), $data)->assertUnprocessable()->assertJsonValidationErrors('payments.0.amount_paid');
        $this->customer->update(['active' => false]);
        $data['allow_due'] = true;
        $this->postJson(route('pos.complete'), $data)->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame('10.000', $this->product->fresh()->stock);
        $this->get(route('pos.index'))->assertOk()->assertSee('id="checkout-customer-dialog"', false)->assertSee('Complete with due')->assertSee('No payment · leave bill due');
    }

    public function test_partial_card_allocation_charges_only_the_paid_share_and_leaves_rest_due(): void
    {
        PaymentChargeRule::create(['payment_method_id' => $this->method('CARD'), 'name' => 'Due card fee', 'minimum_amount' => '0', 'comparison_operator' => 'GTE', 'charge_type' => 'PERCENTAGE', 'charge_value' => '3', 'charge_bearer' => 'CUSTOMER', 'active' => true]);
        $data = $this->data();
        $data['payments'] = [['payment_method_id' => $this->method('CARD'), 'amount' => '100', 'amount_paid' => '103']];
        $data = $this->quoted($data);
        $this->postJson(route('pos.complete'), $data)->assertOk()->assertJsonPath('due_balance', '710.00');
        $sale = Sale::firstOrFail();
        $this->assertSame('813.00', $sale->customer_payable);
        $this->assertSame('3.00', $sale->processing_charge);
        $this->assertSame('103.00', $sale->collected_total);
        $this->assertSame('1000.00', app(RegisterService::class)->summary($sale->register)['expected']);
    }

    public function test_non_cash_underpayment_unused_tenders_stale_quote_and_closed_register_are_rejected(): void
    {
        $data = $this->data();
        $data['payments'][0]['payment_method_id'] = $this->method('CARD');
        $data = $this->quoted($data);
        $this->postJson(route('pos.complete'), $data)->assertUnprocessable()->assertJsonValidationErrors('payments.0.amount_paid');
        $unused = $this->quoted($this->data('0', '10'));
        $this->postJson(route('pos.complete'), $unused)->assertUnprocessable()->assertJsonValidationErrors('payments.0.amount_paid');
        $stale = $this->quoted($this->data());
        $this->product->stockLayers()->update(['selling_price' => '811']);
        $this->postJson(route('pos.complete'), $stale)->assertUnprocessable()->assertJsonValidationErrors('payment');
        app(RegisterService::class)->close(app(RegisterService::class)->current($this->cashier->id), '1000', null);
        $this->postJson(route('pos.complete'), $stale)->assertUnprocessable()->assertJsonValidationErrors('register');
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame('10.000', $this->product->fresh()->stock);
    }
}
