<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Expense;
use App\Models\PaymentChargeRule;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\ProfitLossService;
use App\Services\RegisterService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str as LaravelStr;
use Tests\TestCase;

class SplitPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->cashier = User::create(['name' => 'Split cashier', 'username' => 'split', 'email' => 'split@example.test', 'password' => 'a-secure-test-password', 'role_id' => Role::where('name', 'Administrator')->value('id')]);
        $this->actingAs($this->cashier);
        app(RegisterService::class)->open($this->cashier->id, '1000.00');
        $this->product = Product::create(['name' => 'Split payment groceries', 'sku' => 'SPLIT1', 'category_id' => Category::firstOrCreate(['name' => 'Groceries'])->id, 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'price' => '10000.00', 'cost' => '4000.00', 'stock' => '100.000']);
        foreach ([['CARD', '3', '0', 'GTE'], ['QR', '10', '5000', 'GT']] as [$type, $rate, $minimum, $operator]) {
            PaymentChargeRule::create(['payment_method_id' => $this->method($type), 'name' => $type.' fee', 'minimum_amount' => $minimum, 'comparison_operator' => $operator, 'charge_type' => 'PERCENTAGE', 'charge_value' => $rate, 'charge_bearer' => 'CUSTOMER', 'priority' => 10, 'active' => true]);
        }
    }

    private function method(string $type): int
    {
        return PaymentMethod::where('type', $type)->value('id');
    }

    private function data(array $amounts): array
    {
        return ['items' => [['product_id' => $this->product->id, 'quantity' => '1']], 'discount' => '0', 'payments' => array_map(fn ($type, $amount) => ['payment_method_id' => $this->method($type), 'amount' => $amount], array_keys($amounts), $amounts)];
    }

    private function complete(array $data, array $received = []): Sale
    {
        $quote = $this->postJson(route('pos.quote'), $data)->assertOk()->json();
        foreach ($data['payments'] as $i => &$part) {
            $part['amount_paid'] = $received[$i] ?? $quote['payments'][$i]['customer_payable'];
        }
        unset($part);
        $this->postJson(route('pos.complete'), $data + ['checkout_token' => LaravelStr::uuid()->toString(), 'quote_hash' => $quote['quote_hash']])->assertOk();

        return Sale::latest('id')->firstOrFail();
    }

    public function test_cash_and_card_save_separate_payments_change_and_one_register_transaction(): void
    {
        $sale = $this->complete($this->data(['CASH' => '6000', 'CARD' => '4000']), ['7000', '4120']);
        $this->assertSame('10120.00', $sale->customer_payable);
        $this->assertSame('120.00', $sale->processing_charge);
        $this->assertCount(2, $sale->payments);
        $this->assertSame('1000.00', $sale->change_total);
        $this->assertSame('11120.00', $sale->paid_total);
        $this->assertSame('99.000', $this->product->fresh()->stock);
        $summary = app(RegisterService::class)->summary($sale->register);
        $this->assertSame('7000.00', $summary['expected']);
        $this->assertSame('10120.00', $summary['collections']);
        $this->assertSame(1, $summary['transactions']);
        $this->assertDatabaseCount('expenses', 0);
        $this->assertSame('6000.00', app(ProfitLossService::class)->calculate(today()->toDateString(), today()->toDateString())['net']);
    }

    public function test_three_methods_charge_only_their_shares_using_full_bill_thresholds(): void
    {
        $sale = $this->complete($this->data(['CASH' => '4000', 'CARD' => '3000', 'QR' => '3000']), ['5000', '3090', '3300']);
        $this->assertSame('10390.00', $sale->customer_payable);
        $this->assertSame('390.00', $sale->processing_charge);
        $this->assertSame(['0.00', '90.00', '300.00'], $sale->payments->pluck('processing_charge')->all());
        $this->assertSame('1000.00', $sale->change_total);
        $this->get(route('sales.receipt', $sale))->assertOk()->assertSee('Cash + Card + QR')->assertSee('Card Processing Fee')->assertSee('QR Processing Fee')->assertSee('10,390.00');
        $this->get(route('sales.show', $sale))->assertOk()->assertSee('Cash')->assertSee('Card')->assertSee('QR');
        $this->get(route('sales.index'))->assertOk()->assertSee('Cash + Card + QR');
        $this->get(route('reports.show', ['report' => 'sales', 'payment_method_id' => $this->method('QR')]))->assertOk()->assertSee($sale->invoice);
        $this->get(route('reports.show', 'payments'))->assertOk()->assertSee('10,000.00')->assertSee('10,390.00');
        $this->get(route('reports.show', 'payment-charges'))->assertOk()->assertSee('390.00');
    }

    public function test_business_borne_fees_create_two_expenses_and_void_reverses_every_payment(): void
    {
        PaymentChargeRule::query()->update(['charge_bearer' => 'BUSINESS']);
        $sale = $this->complete($this->data(['CASH' => '4000', 'CARD' => '3000', 'QR' => '3000']));
        $this->assertSame('10000.00', $sale->customer_payable);
        $this->assertDatabaseCount('expenses', 2);
        $this->assertSame('390.00', Money::round((string) Expense::sum('amount')));
        $this->assertSame('5610.00', app(ProfitLossService::class)->calculate(today()->toDateString(), today()->toDateString())['net']);
        $this->post(route('sales.void', $sale), ['reason' => 'Reverse split payment'])->assertRedirect();
        $this->assertSame('100.000', $this->product->fresh()->stock);
        $this->assertSame(2, Expense::where('status', 'REVERSED')->count());
        $this->assertDatabaseCount('sale_payments', 3);
        $summary = app(RegisterService::class)->summary($sale->register);
        $this->assertSame('1000.00', $summary['expected']);
        $this->assertSame('0.00', $summary['collections']);
        $this->assertSame(0, $summary['transactions']);
    }

    public function test_mixed_fee_bearers_preserve_profit_and_receipt_totals(): void
    {
        PaymentChargeRule::where('payment_method_id', $this->method('CARD'))->update(['charge_bearer' => 'BUSINESS']);
        $sale = $this->complete($this->data(['CASH' => '4000', 'CARD' => '3000', 'QR' => '3000']));
        $this->assertSame('10300.00', $sale->customer_payable);
        $this->assertSame('300.00', $sale->customer_fees);
        $this->assertDatabaseCount('expenses', 1);
        $this->assertSame('5910.00', app(ProfitLossService::class)->calculate(today()->toDateString(), today()->toDateString())['net']);
        $this->get(route('sales.receipt', $sale))->assertOk()->assertSee('paid by merchant')->assertSee('QR Processing Fee')->assertSee('10,300.00');
    }

    public function test_exact_qr_bill_threshold_has_no_fee_even_with_split_payments(): void
    {
        $data = $this->data(['CASH' => '3000', 'QR' => '2000']);
        $data['discount'] = '5000';
        $sale = $this->complete($data);
        $this->assertSame('0.00', $sale->processing_charge);
        $this->assertSame('5000.00', $sale->customer_payable);
    }

    public function test_partial_quote_reports_remaining_without_charging_an_unused_method(): void
    {
        PaymentChargeRule::where('payment_method_id', $this->method('CARD'))->update(['charge_type' => 'FIXED', 'charge_value' => '50']);
        $data = $this->data(['CASH' => '4000', 'CARD' => '0']);
        $quote = $this->postJson(route('pos.quote'), $data)->assertOk()->assertJsonPath('remaining_amount', '6000.00')->assertJsonPath('processing_charge', '0.00')->json();
        $data['payments'][0]['amount_paid'] = '4000';
        $data['payments'][1]['amount_paid'] = '0';
        $this->postJson(route('pos.complete'), $data + ['checkout_token' => LaravelStr::uuid()->toString(), 'quote_hash' => $quote['quote_hash']])->assertUnprocessable()->assertJsonValidationErrors('payments');
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame('100.000', $this->product->fresh()->stock);
    }

    public function test_overallocated_inactive_and_malformed_payments_are_rejected(): void
    {
        $this->postJson(route('pos.quote'), $this->data(['CASH' => '6000', 'CARD' => '5000']))->assertUnprocessable()->assertJsonValidationErrors('payments');
        PaymentMethod::where('type', 'CARD')->update(['active' => false]);
        $this->postJson(route('pos.quote'), $this->data(['CASH' => '6000', 'CARD' => '4000']))->assertUnprocessable()->assertJsonValidationErrors('payments.1.payment_method_id');
        $data = $this->data(['CASH' => '10000']);
        $badKeys = $data;
        $badKeys['payments'] = ['unexpected' => $data['payments'][0]];
        $this->postJson(route('pos.quote'), $badKeys)->assertUnprocessable()->assertJsonValidationErrors('payments');
        $data['payments'][0]['amount'] = ['10000'];
        $this->postJson(route('pos.quote'), $data)->assertUnprocessable()->assertJsonValidationErrors('payments.0.amount');
    }

    public function test_incorrect_tender_cannot_be_hidden_by_other_methods_overpayment(): void
    {
        $data = $this->data(['CASH' => '6000', 'CARD' => '4000']);
        $quote = $this->postJson(route('pos.quote'), $data)->assertOk()->json();
        foreach ([['5999', '4120'], ['8000', '4121'], ['8000', '4000']] as [$cash, $card]) {
            $data['payments'][0]['amount_paid'] = $cash;
            $data['payments'][1]['amount_paid'] = $card;
            $this->postJson(route('pos.complete'), $data + ['checkout_token' => LaravelStr::uuid()->toString(), 'quote_hash' => $quote['quote_hash']])->assertUnprocessable();
        }
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_payments', 0);
    }

    public function test_changed_split_amounts_require_new_confirmation(): void
    {
        $data = $this->data(['CASH' => '6000', 'CARD' => '4000']);
        $quote = $this->postJson(route('pos.quote'), $data)->assertOk()->json();
        $data['payments'][0]['amount'] = '5000';
        $data['payments'][1]['amount'] = '5000';
        $data['payments'][0]['amount_paid'] = '6000';
        $data['payments'][1]['amount_paid'] = '5150';
        $this->postJson(route('pos.complete'), $data + ['checkout_token' => LaravelStr::uuid()->toString(), 'quote_hash' => $quote['quote_hash']])->assertUnprocessable()->assertJsonValidationErrors('payment');
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_retry_does_not_duplicate_split_payments_or_stock_movements(): void
    {
        $data = $this->data(['CASH' => '6000', 'CARD' => '4000']);
        $quote = $this->postJson(route('pos.quote'), $data)->assertOk()->json();
        $data['payments'][0]['amount_paid'] = '6000';
        $data['payments'][1]['amount_paid'] = '4120';
        $data += ['checkout_token' => LaravelStr::uuid()->toString(), 'quote_hash' => $quote['quote_hash']];
        $this->postJson(route('pos.complete'), $data)->assertOk();
        $this->postJson(route('pos.complete'), $data)->assertOk();
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_payments', 2);
        $this->assertSame(1, StockMovement::where('reason', 'SALE')->count());
        $this->assertSame('99.000', $this->product->fresh()->stock);
    }

    public function test_failure_on_second_processing_expense_rolls_back_the_whole_sale(): void
    {
        PaymentChargeRule::query()->update(['charge_bearer' => 'BUSINESS']);
        $data = $this->data(['CARD' => '5000', 'QR' => '5000']);
        $quote = $this->postJson(route('pos.quote'), $data)->assertOk()->json();
        foreach ($data['payments'] as &$p) {
            $p['amount_paid'] = $p['amount'];
        }
        unset($p);
        $expenseCount = 0;
        Expense::creating(function () use (&$expenseCount) {
            if (++$expenseCount === 2) {
                throw new \RuntimeException('Simulated second fee failure');
            }
        });
        $this->postJson(route('pos.complete'), $data + ['checkout_token' => LaravelStr::uuid()->toString(), 'quote_hash' => $quote['quote_hash']])->assertStatus(500);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('expenses', 0);
        $this->assertSame('100.000', $this->product->fresh()->stock);
        $this->assertSame('1', Setting::where('key', 'next_invoice_number')->value('value'));
    }

    public function test_references_are_editable_per_payment_without_changing_its_financial_amount(): void
    {
        $sale = $this->complete($this->data(['CASH' => '6000', 'CARD' => '4000']));
        $card = $sale->payments->firstWhere('method_type', 'CARD');
        $this->put(route('sales.update', $sale), ['payment_references' => [$card->id => 'CARD-AUTH-123'], 'notes' => 'Reference corrected'])->assertRedirect();
        $this->assertSame('CARD-AUTH-123', $card->fresh()->reference);
        $this->assertNull($sale->payments->firstWhere('method_type', 'CASH')->fresh()->reference);
        $this->assertSame('4120.00', $card->fresh()->customer_payable);
        $other = $this->complete($this->data(['CASH' => '10000']));
        $this->put(route('sales.update', $sale), ['payment_references' => [$other->payments->first()->id => 'Not owned'], 'notes' => 'Invalid edit'])->assertNotFound();
        $this->assertSame('Reference corrected', $sale->fresh()->notes);
        $this->assertNull($other->payments->first()->fresh()->reference);
    }
}
