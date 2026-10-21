<?php

namespace Tests\Feature;

use App\Models\BankAccount;
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
use App\Services\PaymentActivityService;
use App\Services\ProfitLossService;
use App\Services\PurchasePaymentService;
use App\Services\PurchaseService;
use App\Services\RegisterService;
use App\Services\ReportService;
use App\Services\SettingsService;
use App\Services\SupplierLedgerService;
use App\Support\Cheques;
use App\Support\Money;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ChequeManagementTest extends TestCase
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
        $this->seed();
        config(['features.cheque' => true]);
        Carbon::setTestNow('2026-10-14 12:00:00');
        $this->admin = User::whereHas('role', fn ($q) => $q->where('name', 'Administrator'))->firstOrFail();
        $this->actingAs($this->admin);
        app(RegisterService::class)->open($this->admin->id, '1000');
        $this->customer = Customer::create(['name' => 'Mohamed', 'opening_due' => 0, 'active' => true]);
        $this->supplier = Supplier::create(['name' => 'ABC Wholesale', 'active' => true]);
        $this->product = Product::create(['name' => 'Cheque groceries', 'sku' => 'CHQ-SKU', 'unit_id' => Unit::first()->id, 'price' => 25000, 'cost' => 100, 'stock' => 100, 'active' => true]);
        $this->method = PaymentMethod::where('type', 'CHEQUE')->value('id');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function entry(string $amount = '10000', string $date = '2026-10-18'): array
    {
        return ['amount' => $amount, 'cheque_date' => $date, 'cheque_number' => 'CHQ-'.Str::random(10), 'bank_name' => 'Commercial Bank'];
    }

    private function sale(array $cheques, string $total = '25000', ?string $cash = null): Sale
    {
        $this->product->update(['price' => $total]);
        $chequeTotal = Money::sum(array_column($cheques, 'amount'));
        $parts = [['payment_method_id' => $this->method, 'amount' => $chequeTotal, 'amount_paid' => $chequeTotal, 'cheques' => $cheques]];
        if ($cash !== null) {
            $parts[] = ['payment_method_id' => PaymentMethod::where('type', 'CASH')->value('id'), 'amount' => $cash, 'amount_paid' => $cash];
        }
        $data = ['customer_id' => $this->customer->id, 'allow_due' => true, 'items' => [['product_id' => $this->product->id, 'quantity' => '1']], 'payments' => $parts];
        $quote = $this->postJson(route('pos.quote'), $data)->assertOk()->json();
        $data += ['checkout_token' => (string) Str::uuid(), 'quote_hash' => $quote['quote_hash']];
        $this->postJson(route('pos.complete'), $data)->assertOk();

        return Sale::latest('id')->firstOrFail();
    }

    private function purchase(string $total = '25000', array $cheques = []): Purchase
    {
        return app(PurchaseService::class)->save(['supplier_id' => $this->supplier->id, 'reference' => 'PUR-CHQ-'.Str::random(8), 'purchase_date' => '2026-10-14', 'items' => [['product_id' => $this->product->id, 'quantity' => '1', 'cost' => $total]], 'payment_mode' => $cheques ? 'FULL' : 'UNPAID', 'payment_method_id' => $this->method, 'cheques' => $cheques], $this->admin->id);
    }

    private function account(string $due = '25000', array $entries = []): Cheque
    {
        $this->customer->update(['opening_due' => $due]);
        app(CustomerPaymentService::class)->record($this->customer, ['amount' => Money::sum(array_column($entries, 'amount')), 'payment_method_id' => $this->method, 'payment_date' => '2026-10-14', 'mode' => 'auto', 'cheques' => $entries], $this->admin->id);

        return Cheque::latest('id')->firstOrFail();
    }

    private function action(Cheque $c, string $action): void
    {
        $this->post(route('cheques.action', [$c, $action]), ['confirmed' => 1, 'reason' => 'Insufficient funds'])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_due_to_hold_pass_and_return_do_not_double_count(): void
    {
        $c = $this->account('25000', [$this->entry()]);
        $this->assertSame('15000.00', $this->customer->fresh()->due_balance);
        $this->assertSame('10000.00', $this->customer->fresh()->cheque_hold);
        $this->action($c, 'return');
        $this->assertSame('25000.00', $this->customer->fresh()->due_balance);
        $this->assertSame('0.00', $this->customer->fresh()->cheque_hold);
        $c = $this->account('25000', [$this->entry()]);
        $this->action($c, 'pass');
        $this->assertSame('15000.00', $this->customer->fresh()->due_balance);
        $this->assertSame('0.00', $this->customer->fresh()->cheque_hold);
    }

    public function test_multiple_sale_cheques_independent_dates_and_statuses(): void
    {
        $sale = $this->sale([$this->entry('10000'), $this->entry('10000', '2026-11-02'), $this->entry('5000', '2026-11-25')]);
        $this->assertSame('0.00', $sale->due_balance);
        $this->assertSame('25000.00', $this->customer->fresh()->cheque_hold);
        $this->assertDatabaseCount('cheques', 3);
        $this->assertSame('Cheque Hold', $sale->payment_status);
        $cheques = Cheque::orderBy('id')->get();
        $this->action($cheques[0], 'pass');
        $this->action($cheques[1], 'return');
        $this->assertSame('10000.00', $sale->fresh()->due_balance);
        $this->assertSame('5000.00', $this->customer->fresh()->cheque_hold);
        $this->assertSame('10000.00', $sale->fresh()->settled_total);
        $this->get(route('sales.receipt', $sale))->assertOk()->assertSee('Cheque Hold')->assertSee('3 cheques');
    }

    public function test_mixed_cash_cheque_due_register_and_profit(): void
    {
        $sale = $this->sale([$this->entry('10000'), $this->entry('10000')], '30000', '5000');
        $this->assertSame('5000.00', $sale->due_balance);
        $this->assertSame('20000.00', $sale->cheque_hold);
        $this->assertSame('5000.00', $sale->settled_total);
        $summary = app(RegisterService::class)->summary($sale->register, true);
        $this->assertSame('6000.00', $summary['expected']);
        $this->assertSame('5000.00', $summary['collections']);
        $this->assertSame('20000.00', $summary['cheque_pending']);
        $before = app(ProfitLossService::class)->calculate('2026-10-14', '2026-10-14');
        $this->action(Cheque::first(), 'return');
        $this->assertSame($before, app(ProfitLossService::class)->calculate('2026-10-14', '2026-10-14'));
    }

    public function test_multiple_own_cheques_and_return_restore_supplier_due(): void
    {
        $purchase = $this->purchase('25000', [$this->entry('10000'), $this->entry('10000', '2026-11-02'), $this->entry('5000', '2026-11-25')]);
        $this->assertSame('0.00', $purchase->due_amount);
        $this->assertSame('25000.00', $this->supplier->fresh()->cheque_hold);
        $this->action(Cheque::orderBy('id')->skip(1)->first(), 'return');
        $this->assertSame('10000.00', $purchase->fresh()->due_amount);
        $this->assertSame('15000.00', $this->supplier->fresh()->cheque_hold);
        $this->assertSame('1000.00', app(RegisterService::class)->summary(app(RegisterService::class)->current($this->admin->id))['expected']);
    }

    public function test_third_party_pass_settles_both_sides_without_bank_movement(): void
    {
        $c = $this->account('10000', [$this->entry()]);
        $p = $this->purchase('10000');
        $token = (string) Str::uuid();
        $data = ['token' => $token, 'amount' => '10000', 'payment_method_id' => $this->method, 'cheques' => [['source' => 'THIRD_PARTY', 'cheque_id' => $c->id]]];
        app(PurchasePaymentService::class)->record($p, $data, $this->admin->id);
        app(PurchasePaymentService::class)->record($p, $data, $this->admin->id);
        $this->assertDatabaseCount('cheques', 1);
        $this->assertDatabaseCount('purchase_payments', 1);
        $this->assertSame('Mohamed → ABC Wholesale', $c->fresh()->parties);
        $this->assertSame('10000.00', $this->customer->fresh()->cheque_hold);
        $this->assertSame('10000.00', $this->supplier->fresh()->cheque_hold);
        $this->action($c, 'pass');
        $this->assertSame('0.00', $this->customer->fresh()->cheque_hold);
        $this->assertSame('0.00', $this->supplier->fresh()->cheque_hold);
        $cash = app(ReportService::class)->build('cash', ['from' => '2026-10-14', 'to' => '2026-10-14']);
        $this->assertCount(0, $cash['query']->get());
    }

    public function test_third_party_return_restores_both_accounts_atomically(): void
    {
        $c = $this->account('10000', [$this->entry()]);
        $p = $this->purchase('10000');
        app(PurchasePaymentService::class)->record($p, ['token' => (string) Str::uuid(), 'amount' => '10000', 'payment_method_id' => $this->method, 'cheques' => [['source' => 'THIRD_PARTY', 'cheque_id' => $c->id]]], $this->admin->id);
        $this->action($c, 'return');
        $this->assertSame('10000.00', $this->customer->fresh()->due_balance);
        $this->assertSame('10000.00', $this->supplier->fresh()->due_balance);
        $this->assertSame('0.00', $this->customer->fresh()->cheque_hold);
        $this->assertSame('0.00', $this->supplier->fresh()->cheque_hold);
    }

    public function test_duplicate_transfer_and_excess_amount_are_blocked_with_rollback(): void
    {
        $c = $this->account('10000', [$this->entry()]);
        $p = $this->purchase('15000');
        $second = $this->purchase('15000');
        $entry = ['source' => 'THIRD_PARTY', 'cheque_id' => $c->id];
        app(PurchasePaymentService::class)->record($p, ['token' => (string) Str::uuid(), 'amount' => '10000', 'payment_method_id' => $this->method, 'cheques' => [$entry]], $this->admin->id);
        $this->postJson(route('purchases.payments.store', $second), ['token' => (string) Str::uuid(), 'amount' => '10000', 'payment_method_id' => $this->method, 'cheques' => [$entry]])->assertUnprocessable();
        $this->assertDatabaseCount('purchase_payments', 1);
    }

    public function test_transfer_reversal_does_not_reactivate_old_supplier_allocation(): void
    {
        $c = $this->account('10000', [$this->entry()]);
        $p = $this->purchase('10000');
        $second = $this->purchase('10000');
        $data = ['token' => (string) Str::uuid(), 'amount' => '10000', 'payment_method_id' => $this->method, 'cheques' => [['source' => 'THIRD_PARTY', 'cheque_id' => $c->id]]];
        app(PurchasePaymentService::class)->record($p, $data, $this->admin->id);
        app(ChequeService::class)->reverseTransfer($c, $this->admin->id, 'Physical cheque returned');
        $this->assertSame('10000.00', $p->fresh()->due_amount);
        $data['token'] = (string) Str::uuid();
        app(PurchasePaymentService::class)->record($second, $data, $this->admin->id);
        $this->action($c, 'pass');
        $this->assertSame('10000.00', $p->fresh()->due_amount);
        $this->assertSame('0.00', $second->fresh()->due_amount);
        $this->assertSame('CANCELLED', $p->payments()->first()->settlement_status);
    }

    public function test_cancel_pending_own_cheque_restores_due_and_audit(): void
    {
        $p = $this->purchase('10000', [$this->entry()]);
        $c = Cheque::first();
        $this->action($c, 'cancel');
        $this->assertSame('10000.00', $p->fresh()->due_amount);
        $this->assertDatabaseHas('cheque_events', ['cheque_id' => $c->id, 'event' => 'CANCELLED']);
    }

    public function test_action_dates_permissions_and_duplicate_settlement_are_enforced(): void
    {
        $c = $this->account('10000', [$this->entry()]);
        $role = Role::create(['name' => 'Cheque operator', 'sales_visibility' => 'ALL']);
        $role->permissions()->sync(Permission::whereIn('name', ['cheques.view', 'cheques.pass'])->pluck('id'));
        $u = User::factory()->create(['role_id' => $role->id]);
        $this->flushSession();
        $this->actingAs($u);
        $this->get(route('cheques.show', $c))->assertOk()->assertDontSee('Mark Passed');
        $this->postJson(route('cheques.action', [$c, 'pass']), ['confirmed' => 1])->assertUnprocessable();
        Carbon::setTestNow('2026-10-15 12:00:00');
        $this->get(route('cheques.show', $c))->assertOk()->assertSee('Mark Passed')->assertDontSee('Mark Returned');
        $this->postJson(route('cheques.action', [$c, 'return']), ['confirmed' => 1, 'reason' => 'Bounced'])->assertForbidden();
        $this->post(route('cheques.action', [$c, 'pass']), ['confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->postJson(route('cheques.action', [$c, 'pass']), ['confirmed' => 1])->assertUnprocessable();
    }

    public function test_due_alerts_overdue_first_and_no_auto_passing(): void
    {
        $this->account('50000', [$this->entry('10000', '2026-10-13'), $this->entry('10000', '2026-10-15'), $this->entry('10000', '2026-10-17'), $this->entry('10000', '2026-10-24')]);
        $this->assertSame(['2026-10-13', '2026-10-15', '2026-10-17'], Cheque::dueSoon()->get()->map(fn ($c) => $c->cheque_date->toDateString())->all());
        $this->get(route('dashboard'))->assertOk()->assertSee('Cheques Due Soon');
        $this->assertSame(4, Cheque::held()->count());
    }

    public function test_dashboard_hides_cheque_alert_when_no_pending_instrument_is_due(): void
    {
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Cheques Due Soon');
        $this->account('10000', [$this->entry('10000', '2026-12-01')]);
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Cheques Due Soon');
        $cheque = Cheque::firstOrFail();
        $cheque->update(['cheque_date' => '2026-10-15']);
        $this->get(route('dashboard'))->assertOk()->assertSee('Cheques Due Soon');
        $this->post(route('cheques.action', [$cheque, 'pass']), ['confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Cheques Due Soon');
    }

    public function test_feature_disabled_hides_every_entry_and_preserves_role_and_data(): void
    {
        $c = $this->account('10000', [$this->entry()]);
        $role = Role::create(['name' => 'Stored cheque role']);
        $pid = Permission::where('name', 'cheques.pass')->value('id');
        $role->permissions()->sync([$pid]);
        config(['features.cheque' => false]);
        $this->assertFalse($this->admin->hasPermission('cheques.pass'));
        $this->assertArrayNotHasKey('cheques.pass', Permissions::visible());
        $this->assertNull(PaymentMethod::find($this->method));
        foreach (['cheques.index', 'cheques.create', 'cheques.available', 'cheques.settings', 'cheques.report'] as $route) {
            $this->get(route($route))->assertNotFound();
        }$this->get(route('cheques.show', $c))->assertNotFound();
        $this->post(route('cheques.action', [$c, 'pass']), ['confirmed' => 1])->assertNotFound();
        foreach ([route('pos.index'), route('purchases.create'), route('dashboard'), route('manage.create', 'roles'), route('manage.edit', ['roles', $role->id]), route('manage.show', ['customers', $this->customer->id]), route('manage.show', ['suppliers', $this->supplier->id]), route('settings.index'), route('reports.index')] as $url) {
            $this->get($url)->assertOk()->assertDontSee('Cheque Hold')->assertDontSee('Cheques Due Soon')->assertDontSee('cheques.pass')->assertDontSee('>Cheque<', false);
        }
        $this->put(route('manage.update', ['roles', $role->id]), ['name' => $role->name, 'permissions' => []])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($role->fresh()->permissions->contains('id', $pid));
        config(['features.cheque' => true]);
        $this->get(route('cheques.show', $c))->assertOk()->assertSee($c->cheque_number);
        $this->assertSame('10000.00', $this->customer->fresh()->cheque_hold);
    }

    public function test_ledger_balances_and_reports_do_not_count_pending_cheques_as_cash(): void
    {
        $sale = $this->sale([$this->entry('10000')]);
        $c = Cheque::first();
        $p = $this->purchase('10000', [$this->entry()]);
        $customerLedger = app(CustomerLedgerService::class)->build($this->customer);
        $supplierLedger = app(SupplierLedgerService::class)->build($this->supplier);
        $this->assertSame('15000.00', $customerLedger->last()['balance']);
        $this->assertSame('0.00', $supplierLedger['closing']);
        $activity = app(PaymentActivityService::class)->totals(app(PaymentActivityService::class)->query(['from' => '2026-10-14', 'to' => '2026-10-14']));
        $this->assertSame('0.00', $activity['collected']);
        $this->action($c, 'pass');
        $activity = app(PaymentActivityService::class)->totals(app(PaymentActivityService::class)->query(['from' => '2026-10-14', 'to' => '2026-10-14']));
        $this->assertSame('10000.00', $activity['collected']);
        $this->action(Cheque::where('type', 'OWN_CHEQUE')->first(), 'return');
        $this->assertSame('10000.00', app(SupplierLedgerService::class)->build($this->supplier)['closing']);
        $this->get(route('cheques.export', ['format' => 'csv']))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->get(route('cheques.export', ['format' => 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_pending_cheques_block_unsafe_returns_voids_and_edits(): void
    {
        $sale = $this->sale([$this->entry('25000')]);
        $this->deleteJson(route('sales.destroy', $sale), ['reason' => 'Return purchase'])->assertUnprocessable();
        $this->get(route('sales.edit', $sale))->assertRedirect();
        $this->expectException(ValidationException::class);
        app(ChequeService::class)->assertDocumentSafe($sale);
    }

    public function test_invalid_total_duplicates_and_required_fields_roll_back(): void
    {
        $entry = $this->entry('10000');
        $this->customer->update(['opening_due' => '25000']);
        $data = ['amount' => '25000', 'payment_method_id' => $this->method, 'payment_date' => '2026-10-14', 'mode' => 'auto', 'cheques' => [$entry]];
        $this->postJson(route('manage.customers.payments.store', $this->customer), $data)->assertUnprocessable();
        $this->assertDatabaseCount('cheques', 0);
        $data['amount'] = '10000';
        $this->post(route('manage.customers.payments.store', $this->customer), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->postJson(route('manage.customers.payments.store', $this->customer), $data)->assertUnprocessable();
        $this->assertDatabaseCount('cheques', 1);
    }

    public function test_customer_cheques_allocate_across_opening_and_invoice_and_restore_exact_due(): void
    {
        $this->customer->update(['opening_due' => '5000']);
        $sale = $this->sale([$this->entry('10000')]);
        $this->post(route('manage.customers.payments.store', $this->customer), [
            'amount' => '15000', 'payment_method_id' => $this->method, 'payment_date' => '2026-10-14', 'mode' => 'manual',
            'allocations' => ['opening_balance' => '5000', 'sales' => [$sale->id => '10000']],
            'cheques' => [$this->entry('7000'), $this->entry('8000')],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('5000.00', $this->customer->fresh()->due_balance);
        $this->assertSame('25000.00', $this->customer->fresh()->cheque_hold);
        $this->action(Cheque::orderBy('id')->skip(1)->first(), 'return');
        $this->assertSame('12000.00', $this->customer->fresh()->due_balance);
        $this->assertSame('18000.00', $this->customer->fresh()->cheque_hold);
        $this->assertSame('12000.00', app(CustomerLedgerService::class)->build($this->customer)->last()['balance']);
    }

    public function test_existing_sale_collection_supports_multiple_cheques_and_idempotent_retry(): void
    {
        $sale = $this->sale([$this->entry('5000')]);
        $data = ['token' => (string) Str::uuid(), 'amount' => '20000', 'amount_paid' => '20000', 'payment_method_id' => $this->method, 'cheques' => [$this->entry('10000'), $this->entry('10000')]];
        $this->post(route('sales.collections.store', $sale), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('sales.collections.store', $sale), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('cheques', 3);
        $this->assertDatabaseCount('sale_collections', 2);
        $this->assertSame('0.00', $sale->fresh()->due_balance);
        $this->action(Cheque::latest('id')->first(), 'return');
        $this->assertSame('10000.00', $sale->fresh()->due_balance);
    }

    public function test_purchase_mixed_own_and_third_party_cheques_and_atomic_failure(): void
    {
        $c = $this->account('10000', [$this->entry()]);
        $p = $this->purchase('25000', [['source' => 'THIRD_PARTY', 'cheque_id' => $c->id], $this->entry('15000')]);
        $this->assertSame('0.00', $p->due_amount);
        $this->assertSame('25000.00', $this->supplier->fresh()->cheque_hold);
        $this->action(Cheque::where('type', 'OWN_CHEQUE')->first(), 'pass');
        $this->assertSame('10000.00', $p->fresh()->cheque_hold);
        $before = Cheque::count();
        $q = $this->purchase('20000');
        $this->postJson(route('purchases.payments.store', $q), ['token' => (string) Str::uuid(), 'amount' => '20000', 'payment_method_id' => $this->method, 'cheques' => [$this->entry('5000'), ['source' => 'THIRD_PARTY', 'cheque_id' => $c->id]]])->assertUnprocessable();
        $this->assertSame($before, Cheque::count());
        $this->assertSame('20000.00', $q->fresh()->due_amount);
    }

    public function test_settings_bank_accounts_private_images_and_disabled_direct_mutations(): void
    {
        $values = [];
        foreach (config('cheques.settings') as $key => $field) {
            $values[$key] = $field[2];
        }$values['cheque_alert_days'] = 5;
        $this->put(route('cheques.settings.update'), $values)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(5, (int) app(SettingsService::class)->get('cheque_alert_days'));
        $this->post(route('cheques.banks.store'), ['bank_name' => 'Our Bank', 'account_name' => 'Store', 'account_number' => 'BANK-001', 'branch' => 'Colombo'])->assertRedirect()->assertSessionHasNoErrors();
        $account = BankAccount::first();
        $entry = $this->entry();
        $entry['bank_account_id'] = $account->id;
        $p = $this->purchase('10000', [$entry]);
        $c = Cheque::first();
        $this->assertSame('Our Bank', $c->bank_name);
        Storage::fake('local');
        $this->post(route('cheques.image', $c), ['image' => UploadedFile::fake()->image('scan.png')])->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('cheques.image.download', $c))->assertOk();
        $this->action($c, 'pass');
        $this->post(route('cheques.image', $c), ['image' => UploadedFile::fake()->image('scan2.png')])->assertStatus(409);
        config(['features.cheque' => false]);
        foreach ([['PUT', route('cheques.settings.update'), $values], ['POST', route('cheques.banks.store'), []], ['POST', route('cheques.store'), []], ['POST', route('cheques.transfer', $c), []], ['POST', route('cheques.reverse-transfer', $c), []], ['POST', route('cheques.image', $c), []]] as [$method,$url,$data]) {
            $this->json($method, $url, $data)->assertNotFound();
        }
        $this->get(route('cheques.image.download', $c))->assertNotFound();
        $this->get(route('cheques.export', ['format' => 'csv']))->assertNotFound();
    }

    public function test_disabled_permissions_cannot_be_forged_and_role_details_hide_historical_permissions(): void
    {
        $role = Role::create(['name' => 'Historical cheque permissions']);
        $id = Permission::where('name', 'cheques.pass')->value('id');
        $role->permissions()->sync([$id]);
        config(['features.cheque' => false]);
        $this->get(route('manage.show', ['roles', $role->id]))->assertOk()->assertDontSee('cheques.pass');
        $this->putJson(route('manage.update', ['roles', $role->id]), ['name' => $role->name, 'permissions' => [$id]])->assertUnprocessable();
        $this->assertTrue($role->fresh()->permissions->contains('id', $id));
    }

    public function test_source_settings_required_fields_excess_cheques_and_unsafe_purchase_refunds_are_blocked(): void
    {
        $p = $this->purchase('10000', [$this->entry()]);
        $this->postJson(route('purchases.refunds.store', $p), ['token' => (string) Str::uuid(), 'amount' => '10000', 'payment_method_id' => PaymentMethod::where('type', 'CASH')->value('id')])->assertUnprocessable();
        $this->postJson(route('manage.customers.payments.store', $this->customer), ['amount' => '1', 'payment_method_id' => $this->method, 'payment_date' => '2026-10-14', 'mode' => 'auto', 'cheques' => [['amount' => '1']]])->assertUnprocessable();
        app(SettingsService::class)->put('cheques', ['cheque_allow_multiple' => false]);
        $q = $this->purchase('10000');
        $this->postJson(route('purchases.payments.store', $q), ['token' => (string) Str::uuid(), 'amount' => '10000', 'payment_method_id' => $this->method, 'cheques' => [$this->entry('5000'), $this->entry('5000')]])->assertUnprocessable();
        $this->assertSame('10000.00', $q->fresh()->due_amount);
    }

    public function test_customer_origin_cheques_follow_original_invoice_sales_visibility(): void
    {
        $sale = $this->sale([$this->entry('10000')]);
        $c = Cheque::first();
        $role = Role::create(['name' => 'Own cheque viewer', 'sales_visibility' => 'OWN']);
        $role->permissions()->sync(Permission::whereIn('name', ['cheques.view', 'cheques.pass'])->pluck('id'));
        $u = User::factory()->create(['role_id' => $role->id]);
        $c->update(['created_by' => $u->id]);
        $this->flushSession();
        $this->actingAs($u);
        $this->get(route('cheques.show', $c))->assertNotFound();
        $this->postJson(route('cheques.action', [$c, 'pass']), ['confirmed' => 1])->assertNotFound();
        $this->assertSame('PENDING', $c->fresh()->status);
    }

    public function test_action_window_uses_business_calendar_date_at_local_midnight(): void
    {
        $c = $this->account('10000', [$this->entry('10000', '2026-10-18')]);
        $zone = date_default_timezone_get();
        try {
            date_default_timezone_set('UTC');
            config(['app.timezone' => 'UTC']);
            Carbon::setTestNow('2026-10-14 19:00:00+00:00');
            $this->assertSame('2026-10-15', Cheques::today()->toDateString());
            $this->assertTrue($c->fresh()->action_available);
        } finally {
            date_default_timezone_set($zone);
        }
    }

    public function test_returned_instruments_keep_immutable_invoice_payment_snapshots_and_source_settings_hide_choices(): void
    {
        $sale = $this->sale([$this->entry('25000')]);
        $this->action(Cheque::first(), 'return');
        $this->get(route('sales.edit', $sale))->assertRedirect();
        $this->assertSame(1, $sale->payments()->count());
        app(SettingsService::class)->put('cheques', ['cheque_allow_customer' => false, 'cheque_allow_own' => false, 'cheque_allow_third_party' => false]);
        $this->get(route('pos.index'))->assertOk()->assertDontSee('data-method-type="CHEQUE"', false);
        $this->get(route('purchases.create'))->assertOk()->assertDontSee('data-type="CHEQUE"', false);
    }
}
