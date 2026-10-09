<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\RegisterService;
use App\Services\SettingsService;
use App\Support\SalesVisibility;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private User $peer;

    private User $outsider;

    private Role $role;

    private Sale $own;

    private Sale $team;

    private Sale $other;

    private Customer $customer;

    private PaymentMethod $method;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        app(SettingsService::class)->put('business', ['timezone' => 'Asia/Colombo']);
        $this->travelTo(Carbon::parse('2026-10-09 12:00:00', 'Asia/Colombo'));
        $this->role = Role::create(['name' => 'Scope team', 'sales_visibility' => 'OWN']);
        $this->role->permissions()->sync(Permission::pluck('id'));
        $foreignRole = Role::create(['name' => 'Other team', 'sales_visibility' => 'ALL']);
        $this->actor = $this->user('Actor', $this->role);
        $this->peer = $this->user('Peer', $this->role);
        $this->outsider = $this->user('Outsider', $foreignRole);
        $this->customer = Customer::create(['name' => 'Shared customer', 'active' => true]);
        $this->method = PaymentMethod::where('type', 'CASH')->firstOrFail();
        $this->own = $this->sale($this->actor, 'INV-OWN-SCOPE', '100');
        $this->team = $this->sale($this->peer, 'INV-TEAM-SCOPE', '200');
        $this->other = $this->sale($this->outsider, 'INV-OUTSIDE-SCOPE', '400');
        $this->actingAs($this->actor);
    }

    private function user(string $name, Role $role): User
    {
        return User::create(['name' => $name, 'email' => strtolower($name).'@scope.example.test', 'password' => 'test-password', 'role_id' => $role->id]);
    }

    private function scope(string $mode): void
    {
        $this->role->update(['sales_visibility' => $mode]);
        $this->actor->unsetRelation('role');
        $this->actingAs($this->actor);
    }

    private function sale(User $owner, string $invoice, string $amount): Sale
    {
        $register = app(RegisterService::class)->open($owner->id, '0');
        $sale = Sale::create(['invoice' => $invoice, 'checkout_token' => Str::uuid(), 'user_id' => $owner->id, 'customer_id' => $this->customer->id, 'register_id' => $register->id, 'subtotal' => $amount, 'sale_amount' => $amount, 'customer_payable' => $amount, 'cost_total' => '10', 'status' => 'ACTIVE', 'sold_at' => now()]);
        $sale->payments()->create(['payment_method_id' => $this->method->id, 'method_name' => 'Cash', 'method_type' => 'CASH', 'charge_bearer' => 'BUSINESS', 'sale_amount' => $amount, 'processing_charge' => '1', 'customer_payable' => $amount, 'amount_paid' => $amount, 'change' => '0']);

        return $sale;
    }

    public function test_all_own_and_role_visibility_filter_invoices_and_totals(): void
    {
        foreach (['OWN' => ['100', 1], 'ROLE' => ['300', 2], 'ALL' => ['700', 3]] as $mode => [$amount,$count]) {
            $this->scope($mode);
            $response = $this->get(route('sales.index'))->assertOk()->assertSee('INV-OWN-SCOPE')->assertViewHas('sales', fn ($rows) => $rows->total() === $count)->assertViewHas('totals', fn ($totals) => (float) $totals->amount === (float) $amount);
            if ($mode === 'OWN') {
                $response->assertDontSee('INV-TEAM-SCOPE');
            }
            if ($mode !== 'ALL') {
                $response->assertDontSee('INV-OUTSIDE-SCOPE');
            }
        }
        $this->scope('OWN');
        $this->get(route('sales.index', ['q' => 'INV-OUTSIDE-SCOPE']))->assertOk()->assertViewHas('sales', fn ($rows) => $rows->total() === 0);
    }

    public function test_direct_invoice_receipt_edit_and_every_mutation_are_restricted_before_validation(): void
    {
        foreach (['OWN' => $this->team, 'ROLE' => $this->other] as $mode => $hidden) {
            $this->scope($mode);
            foreach (['sales.show', 'sales.receipt', 'sales.edit', 'sales.edit.products'] as $route) {
                $this->getJson(route($route, $hidden))->assertForbidden();
            }
            foreach ([['POST', 'sales.edit.quote'], ['POST', 'sales.revise'], ['PUT', 'sales.update'], ['POST', 'sales.void'], ['DELETE', 'sales.destroy'], ['POST', 'sales.returns.store'], ['POST', 'sales.collections.store']] as [$method,$route]) {
                $this->json($method, route($route, $hidden), [])->assertForbidden();
            }
            $this->assertSame('ACTIVE', $hidden->fresh()->status);
        }
        $this->get(route('sales.show', $this->team))->assertOk();
        $this->get(route('sales.receipt', $this->team))->assertOk();
        $this->putJson(route('sales.update', $this->own), ['customer_id' => 999999])->assertUnprocessable();
    }

    public function test_administrator_always_sees_every_bill_even_if_stored_scope_is_own(): void
    {
        $adminRole = Role::where('name', 'Administrator')->firstOrFail();
        $adminRole->update(['sales_visibility' => 'OWN']);
        $adminRole->permissions()->detach();
        $this->actingAs($this->user('Admin scope', $adminRole));
        $this->get(route('sales.index'))->assertOk()->assertViewHas('sales', fn ($rows) => $rows->total() === 3);
        $this->get(route('sales.show', $this->other))->assertOk();
        $this->assertSame('ALL', SalesVisibility::mode());
    }

    public function test_dashboard_and_reports_include_only_visible_sales_and_forged_cashier_filters_do_not_expand_scope(): void
    {
        $this->get(route('dashboard'))->assertOk()->assertViewHas('transactions', 1)->assertViewHas('summary', fn ($s) => $s['revenue'] === '100.00')->assertViewHas('recentSales', fn ($s) => $s->pluck('id')->all() === [$this->own->id]);
        $dates = ['from' => '2026-10-09', 'to' => '2026-10-09'];
        $this->get(route('reports.show', ['report' => 'sales'] + $dates))->assertOk()->assertSee('INV-OWN-SCOPE')->assertDontSee('INV-TEAM-SCOPE')->assertDontSee('INV-OUTSIDE-SCOPE');
        $this->get(route('reports.show', ['report' => 'sales', 'user_id' => $this->outsider->id] + $dates))->assertOk()->assertViewHas('records', fn ($r) => $r->total() === 0);
        $csv = $this->get(route('reports.export', ['report' => 'sales'] + $dates))->assertOk()->streamedContent();
        $this->assertStringContainsString('INV-OWN-SCOPE', $csv);
        $this->assertStringNotContainsString('INV-OUTSIDE-SCOPE', $csv);
        $this->get(route('reports.pdf', ['report' => 'sales'] + $dates))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('reports.show', ['report' => 'payments'] + $dates))->assertOk()->assertViewHas('cards', fn ($cards) => (float) $cards['Net collections'] === 100.0);
        $this->scope('ROLE');
        $this->get(route('dashboard'))->assertOk()->assertViewHas('transactions', 2)->assertViewHas('summary', fn ($s) => $s['revenue'] === '300.00');
    }

    public function test_payment_history_and_customer_invoice_history_cannot_bypass_visibility(): void
    {
        $response = $this->get(route('manage.show', ['payment-methods', $this->method->id]))->assertOk()->assertSee('INV-OWN-SCOPE')->assertDontSee('INV-TEAM-SCOPE')->assertDontSee('INV-OUTSIDE-SCOPE')->assertViewHas('totals', fn ($t) => $t['collected'] === '100.00' && $t['charges'] === '1.00');
        $this->get(route('manage.show', ['customers', $this->customer->id]))->assertOk()->assertSee('INV-OWN-SCOPE')->assertDontSee('INV-OUTSIDE-SCOPE')->assertViewHas('sales', fn ($s) => $s->total() === 1);
        $this->get(route('manage.customers.ledger', $this->customer))->assertOk()->assertSee('INV-OWN-SCOPE')->assertDontSee('INV-OUTSIDE-SCOPE');
        $this->scope('ROLE');
        $this->get(route('manage.show', ['payment-methods', $this->method->id]))->assertOk()->assertSee('INV-TEAM-SCOPE')->assertDontSee('INV-OUTSIDE-SCOPE')->assertViewHas('totals', fn ($t) => $t['collected'] === '300.00');
    }

    public function test_role_membership_changes_update_same_role_visibility(): void
    {
        $this->scope('ROLE');
        $this->assertSame(2, Sale::visibleTo()->count());
        $this->peer->update(['role_id' => $this->outsider->role_id]);
        $this->assertSame(1, Sale::visibleTo()->count());
        $this->outsider->update(['role_id' => $this->role->id]);
        $this->assertSame(2, Sale::visibleTo()->count());
        $this->get(route('sales.show', $this->team))->assertForbidden();
        $this->get(route('sales.show', $this->other))->assertOk();
    }

    public function test_role_scope_can_be_saved_by_admin_and_cannot_be_forged_or_widened_by_limited_staff(): void
    {
        $this->postJson(route('manage.store', 'roles'), ['name' => 'Escalation scope', 'sales_visibility' => 'ALL'])->assertUnprocessable()->assertJsonValidationErrors('sales_visibility');
        $this->putJson(route('manage.update', ['roles', $this->role->id]), ['name' => $this->role->name, 'sales_visibility' => 'ROLE', 'permissions' => $this->role->permissions->pluck('id')->all()])->assertUnprocessable()->assertJsonValidationErrors('sales_visibility');
        $admin = $this->user('Settings admin', Role::where('name', 'Administrator')->firstOrFail());
        $this->flushSession();
        $this->actingAs($admin)->get(route('manage.edit', ['roles', $this->role->id]))->assertOk()->assertSee('Sales visibility')->assertSee('Sales from the same role')->assertSee('Only this person');
        $this->put(route('manage.update', ['roles', $this->role->id]), ['name' => $this->role->name, 'sales_visibility' => 'ROLE', 'permissions' => $this->role->permissions->pluck('id')->all()])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('ROLE', $this->role->fresh()->sales_visibility);
        $this->postJson(route('manage.store', 'roles'), ['name' => 'Invalid scope', 'sales_visibility' => 'NOT_VALID'])->assertUnprocessable()->assertJsonValidationErrors('sales_visibility');
        $this->postJson(route('manage.store', 'roles'), ['name' => 'Safe default'])->assertCreated();
        $this->assertDatabaseHas('roles', ['name' => 'Safe default', 'sales_visibility' => 'OWN']);
    }

    public function test_scope_protects_customer_payment_allocation_register_links_and_stock_bill_history(): void
    {
        $this->postJson(route('manage.customers.payments.store', $this->customer), ['payment_method_id' => $this->method->id, 'payment_date' => '2026-10-09', 'amount' => '1', 'mode' => 'manual', 'allocations' => ['sales' => [$this->other->id => '1']]])->assertNotFound();
        $this->assertDatabaseCount('customer_payments', 0);
        $this->get(route('register.show', $this->other->register_id))->assertForbidden();
        $product = Product::create(['name' => 'Scope product', 'sku' => 'SCOPE-STOCK', 'unit_id' => Unit::first()->id, 'stock' => '1', 'price' => '1', 'cost' => '1', 'active' => true]);
        foreach ([$this->own, $this->team, $this->other] as $sale) {
            StockMovement::create(['product_id' => $product->id, 'user_id' => $sale->user_id, 'quantity' => '-1', 'balance' => '1', 'reason' => 'SALE', 'reference' => $sale->invoice]);
        }
        $this->get(route('manage.show', ['products', $product->id]))->assertOk()->assertSee('INV-OWN-SCOPE')->assertDontSee('INV-OUTSIDE-SCOPE')->assertDontSee('INV-TEAM-SCOPE');
    }

    public function test_customer_due_totals_follow_visible_invoices_and_shared_opening_balance(): void
    {
        $this->customer->update(['opening_due' => '50']);
        foreach ([$this->own, $this->team, $this->other] as $sale) {
            $sale->payments()->update(['amount_paid' => '0']);
        }
        foreach (['OWN' => '150.00', 'ROLE' => '350.00', 'ALL' => '750.00'] as $mode => $expected) {
            $this->scope($mode);
            $this->assertSame($expected, Customer::withDueBalances()->findOrFail($this->customer->id)->due_balance);
        }
    }

    public function test_collections_and_refunds_follow_the_bill_owner_not_the_staff_member_recording_them(): void
    {
        foreach ([$this->own, $this->other] as $sale) {
            $recorder = $sale->id === $this->own->id ? $this->outsider : $this->actor;
            $sale->collections()->create(['register_id' => Sale::where('user_id', $recorder->id)->value('register_id'), 'user_id' => $recorder->id, 'payment_method_id' => $this->method->id, 'token' => Str::uuid(), 'method_name' => 'Cash', 'method_type' => 'CASH', 'amount' => '10', 'amount_paid' => '10', 'change' => '0', 'reference' => 'COL-'.$sale->invoice, 'collected_at' => now()]);
            $sale->returns()->create(['register_id' => Sale::where('user_id', $recorder->id)->value('register_id'), 'user_id' => $recorder->id, 'payment_method_id' => $this->method->id, 'token' => Str::uuid(), 'method_name' => 'Cash', 'method_type' => 'CASH', 'amount' => '5', 'cost_total' => '1', 'due_reduction' => '0', 'refund_amount' => '5', 'reference' => 'RET-'.$sale->invoice, 'reason' => 'Test return', 'returned_at' => now()]);
        }
        $this->get(route('manage.show', ['payment-methods', $this->method->id]))->assertOk()->assertSee('COL-INV-OWN-SCOPE')->assertSee('RET-INV-OWN-SCOPE')->assertDontSee('INV-OUTSIDE-SCOPE')->assertViewHas('totals', fn ($t) => $t['collected'] === '110.00' && $t['refunded'] === '5.00');
        foreach (['collections', 'returns'] as $report) {
            $this->get(route('reports.show', ['report' => $report, 'from' => '2026-10-09', 'to' => '2026-10-09']))->assertOk()->assertSee('INV-OWN-SCOPE')->assertDontSee('INV-OUTSIDE-SCOPE');
        }
    }
}
