<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\PaymentChargeRule;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleCollection;
use App\Models\SaleReturn;
use App\Models\Unit;
use App\Models\User;
use App\Services\ProfitLossService;
use App\Services\RegisterService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaleAftercareTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->cashier = User::create(['name' => 'Aftercare cashier', 'username' => 'aftercare', 'email' => 'aftercare@example.test', 'password' => 'test-password', 'role_id' => Role::where('name', 'Administrator')->value('id')]);
        $this->actingAs($this->cashier);
        app(RegisterService::class)->open($this->cashier->id, '1000');
        $this->product = Product::create(['name' => 'Return groceries', 'sku' => 'RET1', 'category_id' => Category::firstOrCreate(['name' => 'Groceries'])->id, 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'price' => '100', 'cost' => '40', 'stock' => '20']);
    }

    private function method(string $type = 'CASH'): int
    {
        return PaymentMethod::where('type', $type)->value('id');
    }

    private function sale(string $qty = '3', string $discount = '10'): Sale
    {
        $data = ['items' => [['product_id' => $this->product->id, 'quantity' => $qty]], 'discount' => $discount, 'payments' => [['payment_method_id' => $this->method(), 'amount' => Money::sub(Money::mul('100', $qty), $discount)]]];
        $quote = $this->postJson(route('pos.quote'), $data)->assertOk()->json();
        $data['payments'][0]['amount_paid'] = $quote['customer_payable'];
        $this->postJson(route('pos.complete'), $data + ['checkout_token' => (string) Str::uuid(), 'quote_hash' => $quote['quote_hash']])->assertOk();

        return Sale::latest('id')->firstOrFail();
    }

    private function returnData(Sale $sale, string $qty): array
    {
        return ['token' => (string) Str::uuid(), 'reason' => 'Unopened items returned', 'payment_method_id' => $this->method(), 'items' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => $qty]]];
    }

    private function collectData(string $amount, string $paid): array
    {
        return ['token' => (string) Str::uuid(), 'payment_method_id' => $this->method(), 'amount' => $amount, 'amount_paid' => $paid, 'reference' => 'DUE-TEST'];
    }

    public function test_partial_returns_allocate_invoice_discount_restore_stock_and_update_cash_profit(): void
    {
        $sale = $this->sale();
        $data = $this->returnData($sale, '1');
        $this->post(route('sales.returns.store', $sale), $data)->assertRedirect(route('sales.show', $sale));
        $return = SaleReturn::firstOrFail();
        $this->assertSame('96.67', $return->amount);
        $this->assertSame('96.67', $return->refund_amount);
        $this->assertSame('40.00', $return->cost_total);
        $this->assertSame('18.000', $this->product->fresh()->stock);
        $summary = app(RegisterService::class)->summary($sale->register, true);
        $this->assertSame('1193.33', $summary['expected']);
        $this->assertSame('193.33', $summary['collections']);
        $this->assertSame('193.33', $summary['payment_totals'][0]['collected']);
        $this->assertSame('113.33', app(ProfitLossService::class)->calculate(today()->toDateString(), today()->toDateString())['net']);
        $this->post(route('sales.returns.store', $sale), $data)->assertRedirect();
        $this->assertDatabaseCount('sale_returns', 1);
        $this->assertSame('18.000', $this->product->fresh()->stock);
        $this->post(route('sales.returns.store', $sale), $this->returnData($sale, '2'))->assertRedirect();
        $this->assertSame('290.00', $sale->fresh()->returned_total);
        $this->assertSame('20.000', $this->product->fresh()->stock);
        $this->assertSame('0.00', app(ProfitLossService::class)->calculate(today()->toDateString(), today()->toDateString())['net']);
        $this->get(route('sales.show', $sale))->assertOk()->assertSee('Returns &amp; refunds', false)->assertDontSee('id="sale-return-dialog"', false);
    }

    public function test_return_limits_cross_invoice_items_and_whole_units_are_checked_atomically(): void
    {
        $sale = $this->sale();
        $other = $this->sale('1', '0');
        foreach (['4', '0.5', '0'] as $qty) {
            $this->postJson(route('sales.returns.store', $sale), $this->returnData($sale, $qty))->assertUnprocessable();
        }
        $data = $this->returnData($sale, '1');
        $data['items'][] = ['sale_item_id' => $other->items->first()->id, 'quantity' => '1'];
        $this->postJson(route('sales.returns.store', $sale), $data)->assertUnprocessable();
        $this->assertDatabaseCount('sale_returns', 0);
        $this->assertSame('16.000', $this->product->fresh()->stock);
    }

    public function test_return_on_closed_invoice_posts_in_current_register_and_keeps_old_drawer_unchanged(): void
    {
        $sale = $this->sale();
        $old = $sale->register;
        app(RegisterService::class)->close($old, '1290', null);
        $new = app(RegisterService::class)->open($this->cashier->id, '500');
        $this->post(route('sales.returns.store', $sale), $this->returnData($sale, '1'))->assertRedirect();
        $this->assertSame($new->id, SaleReturn::first()->register_id);
        $this->assertSame('1290.00', $old->fresh()->expected_cash);
        $this->assertSame('1290.00', app(RegisterService::class)->summary($old)['expected']);
        $this->assertSame('403.33', app(RegisterService::class)->summary($new)['expected']);
        $this->deleteJson(route('sales.destroy', $sale), ['reason' => 'Delete old invoice'])->assertUnprocessable();
    }

    public function test_due_payment_is_conditional_accepts_partial_cash_change_and_is_idempotent(): void
    {
        $sale = $this->sale('3', '0');
        // Legacy / imported invoice with only 100 collected; original obligation is retained.
        $sale->payments()->update(['amount_paid' => '100']);
        $this->assertSame('200.00', $sale->fresh()->due_balance);
        $this->get(route('sales.index'))->assertOk()->assertSee('Pay due');
        $this->get(route('sales.show', $sale))->assertOk()->assertSee('Receive due payment');
        $data = $this->collectData('80', '100');
        $this->post(route('sales.collections.store', $sale), $data)->assertRedirect();
        $collection = SaleCollection::firstOrFail();
        $this->assertSame('20.00', $collection->change);
        $this->assertSame('120.00', $sale->fresh()->due_balance);
        $this->assertSame('1180.00', app(RegisterService::class)->summary($sale->register)['expected']);
        $this->post(route('sales.collections.store', $sale), $data)->assertRedirect();
        $this->assertDatabaseCount('sale_collections', 1);
        $this->postJson(route('sales.collections.store', $sale), $this->collectData('121', '121'))->assertUnprocessable();
        $this->postJson(route('sales.collections.store', $sale), $this->collectData('120', '119'))->assertUnprocessable();
        $this->post(route('sales.collections.store', $sale), $this->collectData('120', '120'))->assertRedirect();
        $this->assertSame('0.00', $sale->fresh()->due_balance);
        $this->get(route('sales.show', $sale))->assertOk()->assertDontSee('Receive due payment');
        $this->assertSame('180.00', app(ProfitLossService::class)->calculate(today()->toDateString(), today()->toDateString())['net']);
        $this->assertSame('17.000', $this->product->fresh()->stock);
    }

    public function test_return_reduces_due_first_and_refunds_only_the_excess(): void
    {
        $sale = $this->sale('3', '0');
        $sale->payments()->update(['amount_paid' => '150']);
        $this->post(route('sales.returns.store', $sale), $this->returnData($sale, '2'))->assertRedirect();
        $return = SaleReturn::first();
        $this->assertSame('150.00', $return->due_reduction);
        $this->assertSame('50.00', $return->refund_amount);
        $this->assertSame('0.00', $sale->fresh()->due_balance);
        $this->assertSame('1100.00', app(RegisterService::class)->summary($sale->register)['expected']);
        $this->deleteJson(route('sales.destroy', $sale), ['reason' => 'Prevent double restoration'])->assertUnprocessable();
    }

    public function test_delete_reverses_sale_and_edit_updates_details_with_permission_checks(): void
    {
        $sale = $this->sale();
        $sale->update(['sold_at' => now()->subHour()]);
        $originalTime = $sale->fresh()->sold_at->toDateTimeString();
        $customer = Customer::create(['name' => 'Invoice customer']);
        $this->get(route('sales.edit', $sale))->assertOk()->assertSee('Review payment');
        $this->put(route('sales.update', $sale), ['customer_id' => $customer->id, 'notes' => 'Updated note'])->assertRedirect(route('sales.show', $sale));
        $this->assertSame('Updated note', $sale->fresh()->notes);
        $this->assertSame($originalTime, $sale->fresh()->sold_at->toDateTimeString());
        $this->assertSame('290.00', $sale->fresh()->sale_amount);
        $this->delete(route('sales.destroy', $sale), ['reason' => 'Duplicate invoice'])->assertRedirect(route('sales.index'));
        $this->assertSame('VOIDED', $sale->fresh()->status);
        $this->assertSame($originalTime, $sale->fresh()->sold_at->toDateTimeString());
        $this->assertSame('20.000', $this->product->fresh()->stock);
        $this->postJson(route('sales.returns.store', $sale), $this->returnData($sale, '1'))->assertUnprocessable();
        $this->deleteJson(route('sales.destroy', $sale), ['reason' => 'Second delete'])->assertUnprocessable();
        $restricted = User::create(['name' => 'Viewer', 'username' => 'viewer-only', 'email' => 'viewer@example.test', 'password' => 'test-password', 'role_id' => Role::create(['name' => 'No sale mutations'])->id]);
        $this->flushSession();
        $this->actingAs($restricted);
        $this->get(route('sales.edit', $sale))->assertForbidden();
        $this->delete(route('sales.destroy', $sale), ['reason' => 'Not allowed'])->assertForbidden();
        $this->post(route('sales.returns.store', $sale), $this->returnData($sale, '1'))->assertForbidden();
        $this->post(route('sales.collections.store', $sale), $this->collectData('1', '1'))->assertForbidden();
    }

    public function test_missing_register_and_inactive_refund_collection_methods_are_rejected(): void
    {
        $sale = $this->sale();
        PaymentMethod::whereKey($this->method())->update(['active' => false]);
        $this->postJson(route('sales.returns.store', $sale), $this->returnData($sale, '1'))->assertUnprocessable();
        $sale->payments()->update(['amount_paid' => '0']);
        $this->postJson(route('sales.collections.store', $sale), $this->collectData('1', '1'))->assertUnprocessable();
        app(RegisterService::class)->close($sale->register, '1000', null);
        $this->postJson(route('sales.returns.store', $sale), $this->returnData($sale, '1'))->assertUnprocessable();
        $this->postJson(route('sales.collections.store', $sale), $this->collectData('1', '1'))->assertUnprocessable();
        $this->assertDatabaseCount('sale_returns', 0);
        $this->assertDatabaseCount('sale_collections', 0);
    }

    public function test_reports_include_return_refunds_and_due_collections_without_counting_new_revenue(): void
    {
        $sale = $this->sale('3', '0');
        $sale->payments()->update(['amount_paid' => '150']);
        $this->post(route('sales.collections.store', $sale), $this->collectData('100', '100'))->assertRedirect();
        $this->post(route('sales.returns.store', $sale), $this->returnData($sale, '1'))->assertRedirect();
        $this->get(route('reports.show', 'returns'))->assertOk()->assertSee('50.00')->assertSee($sale->invoice);
        $this->get(route('reports.show', 'collections'))->assertOk()->assertSee('100.00')->assertSee($sale->invoice);
        $this->get(route('reports.show', 'payments'))->assertOk()->assertSee('200.00');
        $this->get(route('reports.export', 'payments'))->assertOk();
        $this->get(route('dashboard'))->assertOk()->assertSee('Daily revenue after returns');
        $this->assertSame('120.00', app(ProfitLossService::class)->calculate(today()->toDateString(), today()->toDateString())['net']);
    }

    public function test_converted_fractional_returns_use_original_stock_and_cost_snapshots(): void
    {
        $kg = Unit::firstOrCreate(['short_name' => 'kg'], ['name' => 'Kilogram', 'allow_decimal' => true]);
        $portion = Unit::create(['name' => 'Third kilogram', 'short_name' => 'third', 'allow_decimal' => true]);
        $this->product->update(['unit_id' => $kg->id]);
        $conversion = $this->product->conversions()->create(['unit_id' => $portion->id, 'base_quantity' => '1', 'converted_quantity' => '3', 'price' => '100']);
        $data = ['items' => [['product_id' => $this->product->id, 'unit_id' => $portion->id, 'quantity' => '1']], 'discount' => '0', 'payments' => [['payment_method_id' => $this->method(), 'amount' => '100']]];
        $quote = $this->postJson(route('pos.quote'), $data)->assertOk()->json();
        $data['payments'][0]['amount_paid'] = '100';
        $this->postJson(route('pos.complete'), $data + ['checkout_token' => (string) Str::uuid(), 'quote_hash' => $quote['quote_hash']])->assertOk();
        $sale = Sale::latest('id')->first();
        $this->product->update(['cost' => '999']);
        $conversion->update(['base_quantity' => '5']);
        $this->post(route('sales.returns.store', $sale), $this->returnData($sale, '0.5'))->assertRedirect();
        $this->assertSame('19.834', $this->product->fresh()->stock);
        $this->assertSame('6.66', SaleReturn::first()->cost_total);
        $this->post(route('sales.returns.store', $sale), $this->returnData($sale, '0.5'))->assertRedirect();
        $this->assertSame('20.000', $this->product->fresh()->stock);
        $this->assertSame('13.32', Money::sum(SaleReturn::pluck('cost_total')));
        $this->assertSame('100.00', $sale->fresh()->returned_total);
    }

    public function test_multi_product_full_return_reconciles_invoice_discount_and_retains_processing_fees(): void
    {
        $other = Product::create(['name' => 'Second return product', 'sku' => 'RET2', 'category_id' => $this->product->category_id, 'unit_id' => $this->product->unit_id, 'price' => '50', 'cost' => '20', 'stock' => '10']);
        PaymentChargeRule::create(['payment_method_id' => $this->method('CARD'), 'name' => 'Return fee', 'minimum_amount' => '0', 'comparison_operator' => 'GTE', 'charge_type' => 'PERCENTAGE', 'charge_value' => '3', 'charge_bearer' => 'CUSTOMER', 'priority' => 10, 'active' => true]);
        $data = ['items' => [['product_id' => $this->product->id, 'quantity' => '1'], ['product_id' => $other->id, 'quantity' => '1']], 'discount' => '0.01', 'payments' => [['payment_method_id' => $this->method('CARD'), 'amount' => '149.99']]];
        $quote = $this->postJson(route('pos.quote'), $data)->assertOk()->json();
        $data['payments'][0]['amount_paid'] = $quote['customer_payable'];
        $this->postJson(route('pos.complete'), $data + ['checkout_token' => (string) Str::uuid(), 'quote_hash' => $quote['quote_hash']])->assertOk();
        $sale = Sale::latest('id')->first();
        $return = $this->returnData($sale, '1');
        $return['items'] = $sale->items->map(fn ($i) => ['sale_item_id' => $i->id, 'quantity' => '1'])->all();
        $this->post(route('sales.returns.store', $sale), $return)->assertRedirect();
        $this->assertSame('149.99', $sale->fresh()->returned_total);
        $this->assertSame('4.50', $sale->fresh()->customer_fees);
        $this->assertSame('0.00', $sale->fresh()->due_balance);
        $this->assertSame('20.000', $this->product->fresh()->stock);
        $this->assertSame('10.000', $other->fresh()->stock);
        $this->get(route('sales.receipt', $sale))->assertOk()->assertSee('Items returned')->assertSee('149.99');
    }
}
