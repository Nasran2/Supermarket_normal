<?php

namespace Tests\Feature;

use App\Models\Cheque;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\ChequeService;
use App\Services\CustomerLedgerService;
use App\Services\CustomerPaymentService;
use App\Services\PurchasePaymentService;
use App\Services\PurchaseService;
use App\Services\RegisterService;
use App\Services\SettingsService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChequeRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private Supplier $supplier;

    private Product $product;

    private int $method;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.cheque' => true]);
        Carbon::setTestNow('2026-10-14 12:00:00');
        $this->seed();
        $this->admin = User::where('username', 'admin')->firstOrFail();
        $this->actingAs($this->admin);
        app(RegisterService::class)->open($this->admin->id, '10000');
        $this->customer = Customer::create(['name' => 'Recovery customer', 'opening_due' => 10000, 'active' => true]);
        $this->supplier = Supplier::create(['name' => 'Recovery supplier', 'active' => true]);
        $this->product = Product::create(['name' => 'Recovery product', 'sku' => 'RECOVERY', 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'price' => 1000, 'cost' => 100, 'stock' => 10, 'active' => true]);
        $this->method = PaymentMethod::where('type', 'CHEQUE')->value('id');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function entry(string $amount = '1000'): array
    {
        return ['amount' => $amount, 'cheque_date' => '2026-10-15', 'cheque_number' => 'REC-'.Str::random(10), 'bank_name' => 'Recovery bank'];
    }

    private function account(): Cheque
    {
        app(CustomerPaymentService::class)->record($this->customer, ['amount' => '1000', 'payment_method_id' => $this->method, 'payment_date' => '2026-10-14', 'mode' => 'auto', 'cheques' => [$this->entry()]], $this->admin->id);

        return Cheque::latest('id')->firstOrFail();
    }

    private function purchase(bool $cheque = false): Purchase
    {
        return app(PurchaseService::class)->save(['supplier_id' => $this->supplier->id, 'purchase_date' => '2026-10-14', 'auto_reference' => true, 'items' => [['product_id' => $this->product->id, 'quantity' => '1', 'cost' => '1000']], 'payment_mode' => $cheque ? 'FULL' : 'UNPAID', 'payment_method_id' => $this->method, 'cheques' => $cheque ? [$this->entry()] : []], $this->admin->id);
    }

    private function recovery(array $extra = []): array
    {
        return $extra + ['confirmed' => 1, 'token' => (string) Str::uuid(), 'resolution' => 'DUE', 'recovery_side' => 'CUSTOMER'];
    }

    private function replacements(array $amounts): array
    {
        return $this->recovery(['resolution' => 'REPLACE', 'amount' => Money::sum($amounts), 'cheques' => array_map(fn ($amount) => $this->entry($amount), $amounts)]);
    }

    private function edit(Cheque $c, array $extra = []): array
    {
        return $extra + ['version' => app(ChequeService::class)->editVersion($c), 'cheque_number' => $c->cheque_number, 'cheque_date' => $c->cheque_date->toDateString(), 'bank_name' => $c->bank_name, 'branch' => $c->branch, 'drawer_name' => $c->drawer_name, 'account_number' => $c->account_number, 'bank_account_id' => $c->bank_account_id, 'reference' => $c->reference, 'notes' => $c->notes];
    }

    public function test_return_reason_is_optional_and_balance_is_due_without_changing_payment_amounts(): void
    {
        $c = $this->account();
        $payment = $c->customerPayments()->first();
        $this->postJson(route('cheques.return', $c), $this->recovery(['recovery_side' => 'SUPPLIER']))->assertUnprocessable();
        $this->assertSame('PENDING', $c->fresh()->status);
        $this->post(route('cheques.return', $c), $this->recovery())->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('RETURNED', $c->fresh()->status);
        $this->assertNull($c->fresh()->return_reason);
        $this->assertSame('10000.00', $this->customer->fresh()->due_balance);
        $this->assertSame('0.00', $this->customer->fresh()->cheque_hold);
        $this->assertSame('1000.00', $payment->fresh()->amount);
        $this->assertSame('RETURNED', $payment->fresh()->settlement_status);
        $this->get(route('cheques.show', $c))->assertOk()->assertSee('Resolve return')->assertSee('Balance left due');
    }

    public function test_multiple_replacements_preserve_old_cheque_and_idempotent_retries(): void
    {
        $c = $this->account();
        $data = $this->replacements(['400', '600']);
        $this->post(route('cheques.return', $c), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('cheques.return', $c), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('RETURNED', $c->fresh()->status);
        $this->assertDatabaseCount('cheques', 3);
        $this->assertDatabaseCount('cheque_resolutions', 1);
        $this->assertCount(2, $c->fresh()->resolutions->first()->replacements);
        $this->assertSame('9000.00', $this->customer->fresh()->due_balance);
        $this->assertSame('1000.00', $this->customer->fresh()->cheque_hold);
        $this->assertSame('9000.00', app(CustomerLedgerService::class)->build($this->customer->fresh())->last()['balance']);
        $this->postJson(route('cheques.return', $c), array_replace($data, ['notes' => 'Changed request']))->assertUnprocessable();
        $this->assertDatabaseCount('cheques', 3);
    }

    public function test_partial_replacement_then_cash_payment_leaves_remaining_due_and_caps_recovery(): void
    {
        $c = $this->account();
        $this->post(route('cheques.return', $c), $this->replacements(['300', '200']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('9500.00', $this->customer->fresh()->due_balance);
        $payment = $this->recovery(['resolution' => 'PAYMENT', 'amount' => '400', 'payment_method_id' => PaymentMethod::where('type', 'CASH')->value('id')]);
        $this->post(route('cheques.resolve-return', $c), $payment)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('cheques.resolve-return', $c), $payment)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('9100.00', $this->customer->fresh()->due_balance);
        $this->assertSame('100.00', $c->fresh()->recoveryRemaining('CUSTOMER'));
        $this->postJson(route('cheques.resolve-return', $c), $this->recovery(['resolution' => 'PAYMENT', 'amount' => '101', 'payment_method_id' => $payment['payment_method_id']]))->assertUnprocessable();
        $this->assertSame('9100.00', $this->customer->fresh()->due_balance);
    }

    public function test_invalid_replacements_roll_back_the_return_and_all_new_instruments(): void
    {
        $c = $this->account();
        $cases = [$this->replacements(['600', '500']), array_replace($this->replacements(['400', '600']), ['amount' => '999']), array_replace($this->replacements(['1000']), ['cheques' => [$this->entry('500'), $this->entry('500')]])];
        $cases[2]['cheques'][1] = $cases[2]['cheques'][0];
        foreach ($cases as $data) {
            $this->postJson(route('cheques.return', $c), $data)->assertUnprocessable();
            $this->assertSame('PENDING', $c->fresh()->status);
            $this->assertSame('9000.00', $this->customer->fresh()->due_balance);
            $this->assertDatabaseCount('cheques', 1);
            $this->assertDatabaseCount('cheque_resolutions', 0);
            $this->assertSame(1, $c->events()->count());
        }
    }

    public function test_pending_details_are_editable_with_audit_duplicate_and_stale_edit_protection(): void
    {
        $c = $this->account();
        $data = $this->edit($c, ['cheque_number' => 'CORRECTED-123', 'cheque_date' => '2026-10-20', 'bank_name' => 'Correct bank', 'notes' => 'Corrected bank details']);
        $this->get(route('cheques.edit', $c))->assertOk()->assertSee('Save changes');
        $this->put(route('cheques.update', $c), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('CORRECTED-123', $c->fresh()->cheque_number);
        $this->assertSame('1000.00', $c->fresh()->amount);
        $this->assertSame('9000.00', $this->customer->fresh()->due_balance);
        $this->assertDatabaseHas('cheque_events', ['cheque_id' => $c->id, 'event' => 'EDITED']);
        $this->putJson(route('cheques.update', $c), $data)->assertUnprocessable();
        $this->putJson(route('cheques.update', $c), $this->edit($c->fresh(), ['amount' => 2000]))->assertUnprocessable()->assertJsonValidationErrors('amount');
        $other = $this->account();
        $this->putJson(route('cheques.update', $other), $this->edit($other, ['cheque_number' => $c->fresh()->cheque_number, 'bank_name' => $c->fresh()->bank_name]))->assertUnprocessable();
        $this->assertSame('CORRECTED-123', $c->fresh()->cheque_number);
        $this->post(route('cheques.return', $other), $this->recovery())->assertRedirect();
        $this->putJson(route('cheques.update', $other), $this->edit($other->fresh()))->assertUnprocessable();
    }

    public function test_customer_invoice_replacements_reopen_and_pay_only_the_original_invoice(): void
    {
        $this->customer->update(['opening_due' => 10000]);
        $data = ['customer_id' => $this->customer->id, 'items' => [['product_id' => $this->product->id, 'quantity' => 1]], 'payments' => [['payment_method_id' => $this->method, 'amount' => '1000', 'amount_paid' => '1000', 'cheques' => [$this->entry()]]]];
        $quote = $this->postJson(route('pos.quote'), $data)->assertOk()->json();
        $this->postJson(route('pos.complete'), $data + ['checkout_token' => (string) Str::uuid(), 'quote_hash' => $quote['quote_hash']])->assertOk();
        $sale = Sale::latest('id')->firstOrFail();
        $c = Cheque::where('sale_id', $sale->id)->firstOrFail();
        $this->post(route('cheques.return', $c), $this->replacements(['400', '600']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('0.00', $sale->fresh()->due_balance);
        $this->assertSame('1000.00', $sale->fresh()->cheque_hold);
        $this->assertSame('10000.00', $this->customer->fresh()->due_balance);
        $this->assertSame('RETURNED', $sale->payments()->first()->settlement_status);
        $this->assertSame('9.000', $this->product->fresh()->stock);
    }

    public function test_supplier_replacements_and_cash_payment_do_not_change_purchase_or_inventory(): void
    {
        $purchase = $this->purchase(true);
        $c = Cheque::where('purchase_id', $purchase->id)->firstOrFail();
        $data = $this->replacements(['300', '400']);
        $data['recovery_side'] = 'SUPPLIER';
        $this->post(route('cheques.return', $c), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('300.00', $purchase->fresh()->due_amount);
        $this->assertSame('700.00', $purchase->fresh()->cheque_hold);
        $this->post(route('cheques.resolve-return', $c), $this->recovery(['resolution' => 'PAYMENT', 'recovery_side' => 'SUPPLIER', 'amount' => '300', 'payment_method_id' => PaymentMethod::where('type', 'CASH')->value('id'), 'take_from_register' => true]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('0.00', $purchase->fresh()->due_amount);
        $this->assertSame('1000.00', $purchase->fresh()->total);
        $this->assertSame('11.000', $this->product->fresh()->stock);
        $this->assertDatabaseHas('register_movements', ['type' => 'OUT', 'amount' => '300.00']);
    }

    public function test_account_recovery_keeps_original_opening_and_invoice_allocation_limits(): void
    {
        $sale = Sale::create(['invoice' => 'RECOVERY-DUE', 'checkout_token' => Str::uuid(), 'user_id' => $this->admin->id, 'customer_id' => $this->customer->id, 'register_id' => app(RegisterService::class)->current($this->admin->id)->id, 'subtotal' => '1000', 'sale_amount' => '1000', 'customer_payable' => '1000', 'cost_total' => '100', 'status' => 'ACTIVE', 'sold_at' => now()]);
        app(CustomerPaymentService::class)->record($this->customer, ['amount' => '1000', 'payment_method_id' => $this->method, 'payment_date' => '2026-10-14', 'mode' => 'manual', 'allocations' => ['opening_balance' => '300', 'sales' => [$sale->id => '700']], 'cheques' => [$this->entry()]], $this->admin->id);
        $c = Cheque::latest('id')->firstOrFail();
        $this->post(route('cheques.return', $c), $this->replacements(['300']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('300.00', $this->customer->fresh()->opening_due_paid);
        $this->assertSame('1000.00', $sale->fresh()->due_balance);
        $data = $this->recovery(['resolution' => 'PAYMENT', 'amount' => '700', 'payment_method_id' => PaymentMethod::where('type', 'CASH')->value('id')]);
        $this->post(route('cheques.resolve-return', $c), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('300.00', $this->customer->fresh()->opening_due_paid);
        $this->assertSame('300.00', $sale->fresh()->due_balance);
        $this->assertSame('10000.00', $this->customer->fresh()->due_balance);
    }

    public function test_recovery_cannot_shift_money_to_unrelated_due_when_original_invoice_is_already_paid(): void
    {
        $sale = Sale::create(['invoice' => 'RECOVERY-PAID', 'checkout_token' => Str::uuid(), 'user_id' => $this->admin->id, 'customer_id' => $this->customer->id, 'register_id' => app(RegisterService::class)->current($this->admin->id)->id, 'subtotal' => '1000', 'sale_amount' => '1000', 'customer_payable' => '1000', 'cost_total' => '100', 'status' => 'ACTIVE', 'sold_at' => now()]);
        app(CustomerPaymentService::class)->record($this->customer, ['amount' => '1000', 'payment_method_id' => $this->method, 'payment_date' => '2026-10-14', 'mode' => 'manual', 'allocations' => ['sales' => [$sale->id => '1000']], 'cheques' => [$this->entry()]], $this->admin->id);
        $c = Cheque::latest('id')->firstOrFail();
        $this->post(route('cheques.return', $c), $this->recovery())->assertRedirect()->assertSessionHasNoErrors();
        app(CustomerPaymentService::class)->record($this->customer, ['amount' => '1000', 'payment_method_id' => PaymentMethod::where('type', 'CASH')->value('id'), 'payment_date' => '2026-10-14', 'mode' => 'manual', 'allocations' => ['sales' => [$sale->id => '1000']]], $this->admin->id);
        $this->postJson(route('cheques.resolve-return', $c), $this->replacements(['1000']))->assertUnprocessable();
        $this->assertSame('10000.00', $this->customer->fresh()->due_balance);
        $this->assertDatabaseCount('cheques', 1);
        $this->assertDatabaseCount('cheque_resolutions', 1);
    }

    public function test_transferred_cheque_restores_both_accounts_and_recovers_each_side_separately(): void
    {
        $c = $this->account();
        $purchase = $this->purchase();
        app(PurchasePaymentService::class)->record($purchase, ['token' => (string) Str::uuid(), 'amount' => '1000', 'payment_method_id' => $this->method, 'cheques' => [['source' => 'THIRD_PARTY', 'cheque_id' => $c->id]]], $this->admin->id);
        $this->post(route('cheques.return', $c), $this->replacements(['400']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('9600.00', $this->customer->fresh()->due_balance);
        $this->assertSame('1000.00', $purchase->fresh()->due_amount);
        $this->post(route('cheques.resolve-return', $c), $this->recovery(['resolution' => 'PAYMENT', 'recovery_side' => 'SUPPLIER', 'amount' => '600', 'payment_method_id' => PaymentMethod::where('type', 'BANK_TRANSFER')->value('id')]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('400.00', $purchase->fresh()->due_amount);
        $this->assertSame('9600.00', $this->customer->fresh()->due_balance);
        $this->assertSame('600.00', $c->fresh()->recoveryRemaining('CUSTOMER'));
        $this->assertSame('400.00', $c->fresh()->recoveryRemaining('SUPPLIER'));
    }

    public function test_multiple_setting_permissions_visibility_and_feature_routes_are_enforced(): void
    {
        $c = $this->account();
        app(SettingsService::class)->put('cheques', ['cheque_allow_multiple' => false]);
        $this->postJson(route('cheques.return', $c), $this->replacements(['500', '500']))->assertUnprocessable();
        $this->assertSame('PENDING', $c->fresh()->status);
        app(SettingsService::class)->put('cheques', ['cheque_allow_multiple' => true]);
        $role = Role::create(['name' => 'Return only', 'sales_visibility' => 'ALL']);
        $role->permissions()->sync(Permission::whereIn('name', ['cheques.view', 'cheques.return'])->pluck('id'));
        $user = User::factory()->create(['role_id' => $role->id]);
        $this->flushSession();
        $this->actingAs($user);
        $this->putJson(route('cheques.update', $c), $this->edit($c))->assertForbidden();
        $this->postJson(route('cheques.return', $c), $this->replacements(['1000']))->assertForbidden();
        $this->assertSame('PENDING', $c->fresh()->status);
        $this->post(route('cheques.return', $c), $this->recovery())->assertRedirect()->assertSessionHasNoErrors();
        $this->postJson(route('cheques.resolve-return', $c), $this->replacements(['1000']))->assertForbidden();
        config(['features.cheque' => false]);
        $this->get(route('cheques.edit', $c))->assertNotFound();
        $this->putJson(route('cheques.update', $c), $this->edit($c->fresh()))->assertNotFound();
        $this->postJson(route('cheques.return', $c), $this->recovery())->assertNotFound();
        $this->postJson(route('cheques.resolve-return', $c), $this->replacements(['1000']))->assertNotFound();
    }
}
