<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\RegisterMovement;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\ProfitLossService;
use App\Services\RegisterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchasePaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Supplier $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::create(['name' => 'Purchase QA', 'username' => 'purchase-qa', 'email' => 'purchase-qa@example.test', 'password' => 'test-password', 'role_id' => Role::where('name', 'Administrator')->value('id')]);
        $this->actingAs($this->admin);
        $this->supplier = Supplier::create(['name' => 'Supplier QA', 'active' => true]);
        $this->product = Product::create(['name' => 'Soap QA', 'sku' => 'SOAP-PAY-QA', 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'cost' => '100', 'price' => '130', 'stock' => '0', 'low_stock' => '1', 'active' => true]);
        app(RegisterService::class)->open($this->admin->id, '5000');
    }

    private function method(string $type): int
    {
        return PaymentMethod::where('type', $type)->value('id');
    }

    private function data(array $extra = []): array
    {
        return array_replace(['reference' => 'PUR-'.Str::random(8), 'supplier_id' => $this->supplier->id, 'purchase_date' => today()->toDateString(), 'items' => [['product_id' => $this->product->id, 'quantity' => '10', 'cost' => '100', 'selling_price' => '130']]], $extra);
    }

    private function purchase(array $extra = []): Purchase
    {
        $this->post(route('purchases.store'), $this->data($extra))->assertRedirect()->assertSessionHasNoErrors();

        return Purchase::latest('id')->firstOrFail();
    }

    private function payment(string $amount, string $type = 'CASH'): array
    {
        return ['token' => (string) Str::uuid(), 'amount' => $amount, 'payment_method_id' => $this->method($type), 'reference' => 'Supplier receipt QA'];
    }

    public function test_unpaid_purchase_receives_stock_and_can_be_paid_later(): void
    {
        $p = $this->purchase(['payment_mode' => 'UNPAID']);
        $this->assertSame('Unpaid', $p->payment_status);
        $this->assertSame('1000.00', $p->due_amount);
        $this->assertSame('10.000', $this->product->fresh()->stock);
        $this->assertDatabaseCount('purchase_payments', 0);
        $this->get(route('purchases.show', $p))->assertOk()->assertSee('Pay due');
        $this->get(route('purchases.index'))->assertOk()->assertSee('Outstanding due');
    }

    public function test_cash_partial_payment_reduces_due_and_register_without_an_expense(): void
    {
        $p = $this->purchase(['payment_mode' => 'PARTIAL', 'amount_paid' => '250', 'payment_method_id' => $this->method('CASH')]);
        $this->assertSame('Partial', $p->payment_status);
        $this->assertSame('250.00', $p->paid_amount);
        $this->assertSame('750.00', $p->due_amount);
        $this->assertSame('4750.00', app(RegisterService::class)->summary(app(RegisterService::class)->current($this->admin->id))['expected']);
        $this->assertDatabaseCount('register_movements', 1);
        $this->assertSame('OUT', RegisterMovement::first()->type);
        $this->assertSame(0, Expense::count());
        $this->assertSame('0.00', app(ProfitLossService::class)->calculate(today()->toDateString(), today()->toDateString())['net']);
    }

    public function test_bank_full_payment_works_without_open_register_and_uses_server_total(): void
    {
        app(RegisterService::class)->close(app(RegisterService::class)->current($this->admin->id), '5000', null);
        $p = $this->purchase(['payment_mode' => 'FULL', 'amount_paid' => '1', 'payment_method_id' => $this->method('BANK_TRANSFER')]);
        $this->assertSame('Paid', $p->payment_status);
        $this->assertSame('1000.00', $p->paid_amount);
        $this->assertSame('0.00', $p->due_amount);
        $this->assertDatabaseCount('register_movements', 0);
        $this->assertNull(PurchasePayment::first()->register_id);
    }

    public function test_cash_payment_without_register_rolls_back_the_delivery(): void
    {
        app(RegisterService::class)->close(app(RegisterService::class)->current($this->admin->id), '5000', null);
        $this->postJson(route('purchases.store'), $this->data(['payment_mode' => 'FULL', 'payment_method_id' => $this->method('CASH')]))->assertUnprocessable();
        $this->assertDatabaseCount('purchases', 0);
        $this->assertDatabaseCount('product_stock_layers', 0);
        $this->assertSame('0.000', $this->product->fresh()->stock);
    }

    public function test_overpayment_and_zero_partial_payment_roll_back_stock_and_money(): void
    {
        foreach (['0', '1000', '1001'] as $amount) {
            $this->postJson(route('purchases.store'), $this->data(['payment_mode' => 'PARTIAL', 'amount_paid' => $amount, 'payment_method_id' => $this->method('CARD')]))->assertUnprocessable();
        }
        $this->assertDatabaseCount('purchases', 0);
        $this->assertDatabaseCount('purchase_payments', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame('0.000', $this->product->fresh()->stock);
    }

    public function test_later_payment_retry_is_idempotent_even_after_register_closes(): void
    {
        $p = $this->purchase();
        $data = $this->payment('400');
        $this->post(route('purchases.payments.store', $p), $data)->assertRedirect()->assertSessionHasNoErrors();
        app(RegisterService::class)->close(app(RegisterService::class)->current($this->admin->id), '4600', null);
        $this->post(route('purchases.payments.store', $p), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('purchase_payments', 1);
        $this->assertDatabaseCount('register_movements', 1);
        $this->assertSame('600.00', $p->fresh()->due_amount);
        $this->post(route('purchases.payments.store', $p), $this->payment('600', 'QR'))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Paid', $p->fresh()->payment_status);
    }

    public function test_inactive_method_and_cross_invoice_token_are_rejected(): void
    {
        $p = $this->purchase();
        $method = PaymentMethod::findOrFail($this->method('CARD'));
        $method->update(['active' => false]);
        $this->postJson(route('purchases.payments.store', $p), ['token' => (string) Str::uuid(), 'amount' => '50', 'payment_method_id' => $method->id])->assertUnprocessable();
        $data = $this->payment('100', 'BANK_TRANSFER');
        $this->post(route('purchases.payments.store', $p), $data)->assertRedirect()->assertSessionHasNoErrors();
        $other = $this->purchase();
        $this->postJson(route('purchases.payments.store', $other), $data)->assertForbidden();
        $this->assertSame('900.00', $p->fresh()->due_amount);
        $this->assertSame('1000.00', $other->fresh()->due_amount);
    }

    public function test_edit_preserves_payments_and_rejects_lower_total_or_supplier_change(): void
    {
        $p = $this->purchase(['payment_mode' => 'PARTIAL', 'amount_paid' => '250', 'payment_method_id' => $this->method('CASH')]);
        $data = $this->data(['reference' => $p->reference, 'items' => [['product_id' => $this->product->id, 'quantity' => '2', 'cost' => '100', 'selling_price' => '130']]]);
        $this->putJson(route('purchases.update', $p), $data)->assertUnprocessable();
        $this->assertSame('10.000', $this->product->fresh()->stock);
        $other = Supplier::create(['name' => 'Other supplier', 'active' => true]);
        $this->putJson(route('purchases.update', $p), $this->data(['reference' => $p->reference, 'supplier_id' => $other->id]))->assertUnprocessable();
        $data['items'][0]['quantity'] = '15';
        $this->put(route('purchases.update', $p), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('1500.00', $p->fresh()->total);
        $this->assertSame('1250.00', $p->fresh()->due_amount);
        $this->assertDatabaseCount('purchase_payments', 1);
        $this->assertDatabaseCount('register_movements', 1);
    }

    public function test_paid_purchase_requires_refund_before_void_and_keeps_history(): void
    {
        $p = $this->purchase(['payment_mode' => 'FULL', 'payment_method_id' => $this->method('CASH')]);
        $this->postJson(route('purchases.void', $p), ['reason' => 'Reverse this delivery'])->assertUnprocessable();
        $this->postJson(route('purchases.refunds.store', $p), $this->payment('1001'))->assertUnprocessable();
        $refund = $this->payment('1000');
        $this->post(route('purchases.refunds.store', $p), $refund)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('purchases.refunds.store', $p), $refund)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('5000.00', app(RegisterService::class)->summary(app(RegisterService::class)->current($this->admin->id))['expected']);
        $this->post(route('purchases.void', $p), ['reason' => 'Supplier refunded unused delivery'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('VOIDED', $p->fresh()->status);
        $this->assertSame('0.00', $p->fresh()->due_amount);
        $this->assertSame('0.000', $this->product->fresh()->stock);
        $this->assertDatabaseCount('purchase_payments', 2);
        $this->assertDatabaseCount('register_movements', 2);
        $this->postJson(route('purchases.payments.store', $p), $this->payment('1', 'CARD'))->assertUnprocessable();
    }

    public function test_old_purchase_remains_unrecorded_until_previous_balance_is_set(): void
    {
        $p = Purchase::create(['reference' => 'LEGACY-PUR', 'supplier_id' => $this->supplier->id, 'user_id' => $this->admin->id, 'purchase_date' => today(), 'total' => '1000']);
        $this->assertSame('Unrecorded', $p->fresh()->payment_status);
        $this->assertNull($p->fresh()->due_amount);
        $this->postJson(route('purchases.payments.store', $p), $this->payment('10'))->assertUnprocessable();
        $this->postJson(route('purchases.balance', $p), ['previously_paid' => '1001', 'confirmed' => true])->assertUnprocessable();
        $this->post(route('purchases.balance', $p), ['previously_paid' => '300', 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('700.00', $p->fresh()->due_amount);
        $this->assertSame('OPENING', PurchasePayment::first()->kind);
        $this->assertDatabaseCount('register_movements', 0);
        $this->postJson(route('purchases.balance', $p), ['previously_paid' => '0', 'confirmed' => true])->assertUnprocessable();
        $this->post(route('purchases.payments.store', $p), $this->payment('200', 'CARD'))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('500.00', $p->fresh()->due_amount);
        $this->get(route('reports.show', 'purchases'))->assertOk()->assertSee('Paid less refunds')->assertSee('500.00');
    }

    public function test_read_only_purchase_user_cannot_record_payments_or_refunds(): void
    {
        $p = $this->purchase();
        $role = Role::create(['name' => 'Purchase reader']);
        $role->permissions()->attach(Permission::where('name', 'purchases.view')->value('id'));
        $reader = User::create(['name' => 'Reader', 'username' => 'purchase-reader', 'email' => 'reader@example.test', 'password' => 'test-password', 'role_id' => $role->id]);
        $this->flushSession();
        $this->actingAs($reader);
        $this->get(route('purchases.show', $p))->assertOk()->assertDontSee('>Pay due<', false);
        $this->postJson(route('purchases.payments.store', $p), $this->payment('100'))->assertForbidden();
        $this->postJson(route('purchases.refunds.store', $p), $this->payment('100'))->assertForbidden();
        $this->postJson(route('purchases.balance', $p), ['previously_paid' => 0, 'confirmed' => true])->assertForbidden();
    }

    public function test_decimal_purchase_total_and_full_payment_match_rounded_lines(): void
    {
        $this->product->update(['unit_id' => Unit::where('short_name', 'kg')->value('id')]);
        $p = $this->purchase(['payment_mode' => 'FULL', 'payment_method_id' => $this->method('BANK_TRANSFER'), 'items' => [['product_id' => $this->product->id, 'quantity' => '0.001', 'cost' => '5', 'selling_price' => '130'], ['product_id' => $this->product->id, 'quantity' => '0.001', 'cost' => '5', 'selling_price' => '130']]]);
        $this->assertSame('0.02', $p->total);
        $this->assertSame('0.02', $p->paid_amount);
        $this->assertSame('0.00', $p->due_amount);
    }
}
