<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Expense;
use App\Models\PaymentChargeRule;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\RegisterService;
use App\Services\StockService;
use Database\Seeders\SampleProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RegisterCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->cashier = User::create(['name' => 'Register cashier', 'username' => 'register-test', 'email' => 'register@example.test', 'password' => 'test-password', 'role_id' => Role::where('name', 'Administrator')->value('id')]);
        $this->actingAs($this->cashier);
        app(RegisterService::class)->open($this->cashier->id, '1000');
        $this->product = Product::create(['name' => 'Register test groceries', 'sku' => 'REGISTER-TEST', 'category_id' => Category::first()->id, 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'price' => '1000', 'cost' => '400', 'stock' => '20']);
        foreach ([['CARD', 'PERCENTAGE', '3', 'CUSTOMER'], ['QR', 'PERCENTAGE', '2', 'BUSINESS'], ['BANK_TRANSFER', 'FIXED', '10', 'CUSTOMER']] as [$type, $chargeType, $rate, $bearer]) {
            PaymentChargeRule::create(['payment_method_id' => $this->method($type), 'name' => $type.' test fee', 'minimum_amount' => '0', 'comparison_operator' => 'GTE', 'charge_type' => $chargeType, 'charge_value' => $rate, 'charge_bearer' => $bearer, 'priority' => 10, 'active' => true]);
        }
    }

    private function method(string $type): int
    {
        return PaymentMethod::where('type', $type)->value('id');
    }

    private function data(array $entries): array
    {
        return ['items' => [['product_id' => $this->product->id, 'quantity' => '1']], 'payments' => array_map(fn ($p) => ['payment_method_id' => $this->method($p[0]), 'amount' => $p[1], 'reference' => $p[2] ?? null], $entries)];
    }

    private function complete(array $data, array $tenders = []): Sale
    {
        $quote = $this->postJson(route('pos.quote'), $data)->assertOk()->json();
        foreach ($data['payments'] as $i => &$payment) {
            $payment['amount_paid'] = $tenders[$i] ?? $quote['payments'][$i]['customer_payable'];
        }
        unset($payment);
        $this->postJson(route('pos.complete'), $data + ['checkout_token' => Str::uuid()->toString(), 'quote_hash' => $quote['quote_hash']])->assertOk();

        return Sale::latest('id')->firstOrFail();
    }

    public function test_repeated_card_entries_keep_independent_amounts_fees_and_references(): void
    {
        $sale = $this->complete($this->data([['CARD', '400', 'CARD-ONE'], ['CARD', '600', 'CARD-TWO']]));
        $this->assertCount(2, $sale->payments);
        $this->assertSame(['12.00', '18.00'], $sale->payments->pluck('processing_charge')->all());
        $this->assertSame(['412.00', '618.00'], $sale->payments->pluck('customer_payable')->all());
        $this->assertSame(['CARD-ONE', 'CARD-TWO'], $sale->payments->pluck('reference')->all());
        $this->assertSame('1030.00', $sale->customer_payable);
        $this->assertSame('19.000', $this->product->fresh()->stock);
        $this->get(route('sales.receipt', $sale))->assertOk()->assertSee('Card + Card')->assertSee('412.00')->assertSee('618.00');
        $summary = app(RegisterService::class)->summary($sale->register, true);
        $card = collect($summary['payment_totals'])->firstWhere('type', 'CARD');
        $this->assertSame(2, $card['entries']);
        $this->assertSame('1030.00', $card['collected']);
        $this->assertSame('30.00', $card['customer_fees']);
        $this->assertSame(1, $summary['transactions']);
    }

    public function test_repeated_cash_entries_reconcile_after_each_entries_change(): void
    {
        $sale = $this->complete($this->data([['CASH', '200'], ['CASH', '800']]), ['250', '900']);
        $this->assertSame('150.00', $sale->change_total);
        $this->assertSame('1150.00', $sale->paid_total);
        $summary = app(RegisterService::class)->summary($sale->register, true);
        $this->assertSame('2000.00', $summary['expected']);
        $this->assertSame('1000.00', $summary['cash']);
        $this->assertSame(2, $summary['payment_totals'][0]['entries']);
    }

    public function test_repeated_fixed_business_fees_create_an_expense_for_each_entry(): void
    {
        PaymentChargeRule::where('payment_method_id', $this->method('CARD'))->update(['charge_type' => 'FIXED', 'charge_value' => '12', 'charge_bearer' => 'BUSINESS']);
        $sale = $this->complete($this->data([['CARD', '400'], ['CARD', '600']]));
        $this->assertSame('1000.00', $sale->customer_payable);
        $this->assertSame('24.00', $sale->processing_charge);
        $this->assertCount(2, Expense::where('sale_id', $sale->id)->get());
        $card = collect(app(RegisterService::class)->summary($sale->register, true)['payment_totals'])->firstWhere('type', 'CARD');
        $this->assertSame('24.00', $card['business_fees']);
        $this->assertSame('0.00', $card['customer_fees']);
    }

    public function test_closing_summary_lists_all_sales_methods_fees_and_cash_difference(): void
    {
        $sale = $this->complete($this->data([['CASH', '100'], ['CARD', '200'], ['QR', '300'], ['BANK_TRANSFER', '400']]), ['150', '206', '300', '410']);
        $voided = $this->complete($this->data([['CASH', '1000']]));
        $this->post(route('sales.void', $voided), ['reason' => 'QA void'])->assertRedirect();
        $response = $this->getJson(route('register.current-summary'))->assertOk()->assertJsonPath('summary.transactions', 1)->assertJsonPath('summary.voided_transactions', 1)->assertJsonPath('expected_cash', '1100.00');
        $this->assertStringContainsString($sale->invoice, $response->json('html'));
        $this->assertStringContainsString($voided->invoice, $response->json('html'));
        $totals = collect($response->json('summary.payment_totals'))->keyBy('type');
        $this->assertSame('100.00', $totals['CASH']['collected']);
        $this->assertSame('206.00', $totals['CARD']['collected']);
        $this->assertSame('6.00', $totals['CARD']['customer_fees']);
        $this->assertSame('300.00', $totals['QR']['collected']);
        $this->assertSame('6.00', $totals['QR']['business_fees']);
        $this->assertSame('410.00', $totals['BANK_TRANSFER']['collected']);
        $this->assertSame('10.00', $totals['BANK_TRANSFER']['customer_fees']);
        $register = $sale->register;
        $register->update(['opened_at' => now()->subHours(3)]);
        $openedAt = $register->fresh()->opened_at->toDateTimeString();
        $result = $this->postJson(route('register.close'), ['register_id' => $register->id, 'actual_cash' => '1090', 'notes' => 'Short by ten'])->assertOk();
        $this->assertStringContainsString('Cash difference', $result->json('html'));
        $this->assertStringContainsString('-10.00', $result->json('html'));
        $this->assertSame('-10.00', $register->fresh()->difference);
        $this->assertSame($openedAt, $register->fresh()->opened_at->toDateTimeString());
        $this->get(route('register.show', $register))->assertOk()->assertSee('Online transfer')->assertSee($sale->invoice)->assertSee('Short by ten');
        $this->assertNull(app(RegisterService::class)->current($this->cashier->id));
    }

    public function test_closed_register_locks_workspace_products_and_quotes_until_popup_open(): void
    {
        $register = app(RegisterService::class)->current($this->cashier->id);
        app(RegisterService::class)->close($register, '1000', null);
        $this->get(route('pos.index'))->assertOk()->assertSee('id="open-register-dialog"', false)->assertSee('data-required="1"', false)->assertDontSee('id="pos"', false);
        $data = $this->data([['CASH', '1000']]);
        $this->getJson(route('pos.products'))->assertUnprocessable()->assertJsonValidationErrors('register');
        $this->postJson(route('pos.quote'), $data)->assertUnprocessable()->assertJsonValidationErrors('register');
        $data['payments'][0]['amount_paid'] = '1000';
        $this->postJson(route('pos.complete'), $data + ['checkout_token' => Str::uuid()->toString(), 'quote_hash' => str_repeat('0', 64)])->assertUnprocessable()->assertJsonValidationErrors('register');
        $this->postJson(route('register.open'), ['opening_cash' => '500'])->assertOk();
        $this->get(route('pos.index'))->assertOk()->assertSee('id="pos"', false)->assertSee('data-required="0"', false);
        $this->getJson(route('pos.products'))->assertOk();
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_stale_closing_popup_cannot_close_a_different_register(): void
    {
        $register = app(RegisterService::class)->current($this->cashier->id);
        $this->postJson(route('register.close'), ['register_id' => $register->id + 1, 'actual_cash' => '1000'])->assertUnprocessable()->assertJsonValidationErrors('register');
        $this->assertNull($register->fresh()->closed_at);
    }

    public function test_sample_products_seed_stock_once_and_preserve_subsequent_stock_changes(): void
    {
        $this->seed(SampleProductSeeder::class);
        $this->assertSame(16, Product::where('sku', 'like', 'DEMO-%')->count());
        $this->assertSame(16, StockMovement::where('reason', 'SAMPLE OPENING STOCK')->count());
        $rice = Product::where('sku', 'DEMO-001')->firstOrFail();
        app(StockService::class)->move($rice, '-1', 'QA STOCK CHANGE', 'SAMPLE-QA', $this->cashier->id);
        $this->seed(SampleProductSeeder::class);
        $this->assertSame('79.000', $rice->fresh()->stock);
        $this->assertSame(16, StockMovement::where('reason', 'SAMPLE OPENING STOCK')->count());
    }
}
