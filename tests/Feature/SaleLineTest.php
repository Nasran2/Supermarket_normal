<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\PaymentChargeRule;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use App\Services\RegisterService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaleLineTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::create(['name' => 'Line QA', 'username' => 'line-qa', 'email' => 'line@example.test', 'password' => 'test-password', 'role_id' => Role::where('name', 'Administrator')->value('id')]);
        $this->actingAs($this->admin);
        app(RegisterService::class)->open($this->admin->id, '0');
        $this->product = Product::create(['name' => 'Line test biscuits', 'sku' => 'LINE-TEST', 'category_id' => Category::first()->id, 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'price' => '100', 'cost' => '40', 'stock' => '50', 'active' => true]);
    }

    private function order(array $line = []): array
    {
        return ['items' => [$line + ['product_id' => $this->product->id, 'quantity' => '2']], 'payment_method_id' => PaymentMethod::where('type', 'CASH')->value('id')];
    }

    private function complete(array $data): Sale
    {
        $quote = $this->postJson(route('pos.quote'), $data)->assertOk()->json();
        $this->postJson(route('pos.complete'), $data + ['amount_paid' => $quote['customer_payable'], 'checkout_token' => Str::uuid()->toString(), 'quote_hash' => $quote['quote_hash']])->assertOk();

        return Sale::latest('id')->firstOrFail();
    }

    public function test_price_change_is_saved_and_audited_without_becoming_a_discount(): void
    {
        $sale = $this->complete($this->order(['unit_price' => '90']));
        $this->assertSame('180.00', $sale->sale_amount);
        $this->assertSame('0.00', $sale->discount);
        $this->assertSame('80.00', $sale->cost_total);
        $item = $sale->items->first();
        $this->assertSame('100.00', $item->catalog_price);
        $this->assertSame('90.00', $item->price);
        $this->assertSame('0.00', $item->line_discount);
        $audit = AuditLog::where('action', 'sale.complete')->firstOrFail();
        $this->assertSame('90.00', $audit->after['items'][0]['price']);
        $this->get(route('sales.receipt', $sale))->assertOk()->assertSee('90.00')->assertDontSee('>Discount<', false);
        $this->assertSame('48.000', $this->product->fresh()->stock);
    }

    public function test_fixed_line_discount_is_applied_once_and_receipt_shows_final_amounts(): void
    {
        $sale = $this->complete($this->order(['unit_price' => '90', 'discount_type' => 'AMOUNT', 'discount_value' => '10']));
        $this->assertSame('180.00', $sale->subtotal);
        $this->assertSame('10.00', $sale->discount);
        $this->assertSame('170.00', $sale->sale_amount);
        $this->assertSame('10.00', $sale->items->first()->line_discount);
        $this->assertSame('170.00', $sale->items->first()->total);
        $this->get(route('sales.receipt', $sale))->assertOk()->assertSee('85.00')->assertSee('170.00')->assertDontSee('>Discount<', false);
        $summary = app(RegisterService::class)->summary(app(RegisterService::class)->current($this->admin->id));
        $this->assertSame('170.00', $summary['expected']);
        $this->post(route('sales.void', $sale), ['reason' => 'QA reversal'])->assertRedirect();
        $this->assertSame('50.000', $this->product->fresh()->stock);
    }

    public function test_percentage_discount_and_invoice_discount_are_combined_without_double_deduction(): void
    {
        $data = $this->order(['discount_type' => 'PERCENT', 'discount_value' => '12.5']) + ['discount' => '5'];
        $quote = $this->postJson(route('pos.quote'), $data)->assertOk()->assertJsonPath('line_discounts', '25.00')->assertJsonPath('invoice_discount', '5.00')->assertJsonPath('sale_amount', '170.00')->json();
        $sale = $this->complete($data);
        $this->assertSame('30.00', $sale->discount);
        $this->assertSame('175.00', $sale->items->first()->total);
        $this->get(route('sales.receipt', $sale))->assertOk()->assertSee('175.00')->assertSee('-5.00');
    }

    public function test_line_discount_rounds_after_multiplying_fractional_quantity(): void
    {
        $this->product->update(['unit_id' => Unit::where('short_name', 'kg')->value('id'), 'price' => '8.02']);
        $this->postJson(route('pos.quote'), $this->order(['quantity' => '1.25', 'discount_type' => 'PERCENT', 'discount_value' => '10']))->assertOk()->assertJsonPath('items.0.line_subtotal', '10.03')->assertJsonPath('items.0.line_discount', '1.00')->assertJsonPath('items.0.total', '9.03');
    }

    public function test_invalid_prices_and_excessive_or_negative_line_discounts_are_rejected(): void
    {
        foreach ([['unit_price' => '-1'], ['unit_price' => '1.001'], ['discount_value' => '201'], ['discount_type' => 'PERCENT', 'discount_value' => '100.01'], ['discount_value' => '-1'], ['discount_type' => 'HIDDEN'], ['discount_value' => ['10']]] as $line) {
            $this->postJson(route('pos.quote'), $this->order($line))->assertUnprocessable();
        }
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame('50.000', $this->product->fresh()->stock);
    }

    public function test_cashier_cannot_bypass_discount_limit_with_a_price_reduction(): void
    {
        $cashier = User::create(['name' => 'Cashier', 'username' => 'line-cashier', 'email' => 'line-cashier@example.test', 'password' => 'test-password', 'role_id' => Role::where('name', 'Cashier')->value('id')]);
        $this->flushSession();
        $this->actingAs($cashier);
        app(RegisterService::class)->open($cashier->id, '0');
        $this->postJson(route('pos.quote'), $this->order(['unit_price' => '80']))->assertUnprocessable()->assertJsonValidationErrors('discount');
        $this->postJson(route('pos.quote'), $this->order(['unit_price' => '95', 'discount_value' => '11']))->assertUnprocessable();
        $this->postJson(route('pos.quote'), $this->order(['unit_price' => '95', 'discount_value' => '10']))->assertOk()->assertJsonPath('sale_amount', '180.00');
        app(SettingsService::class)->put('pos', ['allow_discount' => false]);
        $this->postJson(route('pos.quote'), $this->order(['unit_price' => '99']))->assertUnprocessable();
        $this->postJson(route('pos.quote'), $this->order(['unit_price' => '110']))->assertOk()->assertJsonPath('discount', '0.00');
    }

    public function test_changed_adjustments_reject_old_payment_quote(): void
    {
        $data = $this->order(['discount_value' => '5']);
        $quote = $this->postJson(route('pos.quote'), $data)->assertOk()->json();
        $data['items'][0]['discount_value'] = '10';
        $this->postJson(route('pos.complete'), $data + ['amount_paid' => '195', 'checkout_token' => Str::uuid()->toString(), 'quote_hash' => $quote['quote_hash']])->assertUnprocessable()->assertJsonValidationErrors('payment');
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_recovered_checkout_status_is_scoped_to_the_current_user(): void
    {
        $sale = $this->complete($this->order());
        $this->getJson(route('pos.checkout-status', ['checkout_token' => $sale->checkout_token]))->assertOk()->assertJsonPath('completed', true)->assertJsonPath('invoice', $sale->invoice);
        $this->getJson(route('pos.checkout-status', ['checkout_token' => Str::uuid()->toString()]))->assertOk()->assertJsonPath('completed', false);
        $other = User::create(['name' => 'Other', 'username' => 'other-line', 'email' => 'other-line@example.test', 'password' => 'test-password', 'role_id' => $this->admin->role_id]);
        $this->flushSession();
        $this->actingAs($other);
        $this->getJson(route('pos.checkout-status', ['checkout_token' => $sale->checkout_token]))->assertOk()->assertJsonPath('completed', false)->assertJsonPath('invoice', null);
        $this->getJson(route('pos.checkout-status', ['checkout_token' => 'bad']))->assertUnprocessable();
    }

    public function test_processing_fee_threshold_uses_the_amount_after_line_discount(): void
    {
        $this->product->update(['price' => '6000']);
        $method = PaymentMethod::where('type', 'QR')->firstOrFail();
        PaymentChargeRule::create(['payment_method_id' => $method->id, 'name' => 'QA QR threshold', 'minimum_amount' => '5000', 'comparison_operator' => 'GT', 'charge_type' => 'PERCENTAGE', 'charge_value' => '10', 'charge_bearer' => 'CUSTOMER', 'priority' => 10, 'active' => true]);
        $data = $this->order(['quantity' => '1', 'discount_value' => '1000']);
        $data['payment_method_id'] = $method->id;
        $this->postJson(route('pos.quote'), $data)->assertOk()->assertJsonPath('sale_amount', '5000.00')->assertJsonPath('processing_charge', '0.00');
        $data['items'][0]['discount_value'] = '900';
        $this->postJson(route('pos.quote'), $data)->assertOk()->assertJsonPath('sale_amount', '5100.00')->assertJsonPath('processing_charge', '510.00');
    }

    public function test_full_bill_amount_discount_combines_with_line_discount_and_prints_once(): void
    {
        $data = $this->order(['discount_value' => '20']) + ['bill_discount_type' => 'AMOUNT', 'bill_discount_value' => '30'];
        $this->postJson(route('pos.quote'), $data)->assertOk()->assertJsonPath('line_discounts', '20.00')->assertJsonPath('invoice_discount', '30.00')->assertJsonPath('sale_amount', '150.00');
        $sale = $this->complete($data);
        $this->assertSame('50.00', $sale->discount);
        $this->assertSame('150.00', $sale->sale_amount);
        $this->get(route('sales.receipt', $sale))->assertOk()->assertSee('180.00')->assertSee('-30.00')->assertSee('150.00');
    }

    public function test_full_bill_percentage_uses_subtotal_after_line_discounts_and_recalculates_for_quantity(): void
    {
        $data = $this->order(['discount_value' => '20']) + ['bill_discount_type' => 'PERCENT', 'bill_discount_value' => '10', 'discount' => '999'];
        $this->postJson(route('pos.quote'), $data)->assertOk()->assertJsonPath('invoice_discount', '18.00')->assertJsonPath('sale_amount', '162.00');
        $data['items'][0]['quantity'] = '3';
        $this->postJson(route('pos.quote'), $data)->assertOk()->assertJsonPath('invoice_discount', '28.00')->assertJsonPath('sale_amount', '252.00');
        $this->product->update(['unit_id' => Unit::where('short_name', 'kg')->value('id'), 'price' => '8.02']);
        $data['items'][0] = ['product_id' => $this->product->id, 'quantity' => '1.25'];
        $data['bill_discount_value'] = '50';
        $this->postJson(route('pos.quote'), $data)->assertOk()->assertJsonPath('invoice_discount', '5.02')->assertJsonPath('sale_amount', '5.01');
    }

    public function test_invalid_bill_discounts_are_rejected_and_cashier_limit_still_applies(): void
    {
        foreach ([['bill_discount_type' => 'PERCENT', 'bill_discount_value' => '100.01'], ['bill_discount_type' => 'AMOUNT', 'bill_discount_value' => '201'], ['bill_discount_type' => 'AMOUNT', 'bill_discount_value' => '-1'], ['bill_discount_type' => 'AMOUNT', 'bill_discount_value' => '1.001'], ['bill_discount_type' => 'OTHER', 'bill_discount_value' => '1'], ['bill_discount_value' => '1'], ['bill_discount_type' => 'AMOUNT']] as $discount) {
            $this->postJson(route('pos.quote'), $this->order() + $discount)->assertUnprocessable();
        }
        $this->postJson(route('pos.quote'), $this->order(['discount_value' => '20']) + ['bill_discount_type' => 'AMOUNT', 'bill_discount_value' => '181'])->assertUnprocessable();
        $cashier = User::create(['name' => 'Bill cashier', 'username' => 'bill-cashier', 'email' => 'bill-cashier@example.test', 'password' => 'test-password', 'role_id' => Role::where('name', 'Cashier')->value('id')]);
        $this->flushSession();
        $this->actingAs($cashier);
        app(RegisterService::class)->open($cashier->id, '0');
        $this->postJson(route('pos.quote'), $this->order() + ['bill_discount_type' => 'PERCENT', 'bill_discount_value' => '11'])->assertUnprocessable()->assertJsonValidationErrors('discount');
        $this->postJson(route('pos.quote'), $this->order() + ['bill_discount_type' => 'PERCENT', 'bill_discount_value' => '10'])->assertOk()->assertJsonPath('sale_amount', '180.00');
        app(SettingsService::class)->put('pos', ['allow_discount' => false]);
        $this->postJson(route('pos.quote'), $this->order() + ['bill_discount_type' => 'AMOUNT', 'bill_discount_value' => '1'])->assertUnprocessable();
    }
}
