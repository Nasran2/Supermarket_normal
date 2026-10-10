<?php

namespace Tests\Feature;

use App\Models\Category;
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
use App\Services\RegisterService;
use App\Services\ReportService;
use App\Support\Permissions;
use App\Support\Resources;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RolePermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function user(array $permissions = [], ?Role $role = null): User
    {
        $role ??= Role::create(['name' => 'Role '.Role::count()]);
        if ($permissions) {
            $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));
        }
        $user = User::create(['name' => 'Team member', 'email' => 'member'.User::count().'@example.test', 'password' => 'test-password', 'role_id' => $role->id]);
        $this->flushSession();
        $this->actingAs($user);

        return $user;
    }

    public function test_catalog_covers_all_resource_actions_report_formats_and_dashboard_cards(): void
    {
        $names = array_keys(Permissions::all());
        foreach (Resources::all() as $resource => $definition) {
            foreach (['view', 'create', 'edit', 'delete'] as $action) {
                $this->assertContains(Resources::permission($resource, $action), $names);
            }
        }
        $this->assertCount(count(ReportService::TITLES), array_unique(array_map([ReportService::class, 'permission'], array_keys(ReportService::TITLES))));
        foreach (ReportService::TITLES as $report => $title) {
            $permission = ReportService::permission($report);
            $this->assertContains($permission, $names);
            $this->assertContains($permission.'.pdf', $names);
            if ($report !== 'profit') {
                $this->assertContains($permission.'.export', $names);
            }
        }
        $this->assertCount(12, array_filter($names, fn ($name) => str_starts_with($name, 'dashboard.')));
    }

    public function test_administrator_has_full_access_with_an_empty_permission_pivot_and_inactive_admin_does_not(): void
    {
        $role = Role::where('name', 'Administrator')->firstOrFail();
        $role->permissions()->detach();
        $user = $this->user([], $role);
        foreach (array_keys(Permissions::all()) as $name) {
            $this->assertTrue($user->hasPermission($name), $name);
        }
        $this->get(route('manage.index', 'roles'))->assertOk()->assertSee('Administrator always has full access');
        $this->get(route('manage.show', ['roles', $role->id]))->assertOk()->assertSee('Every permission is granted automatically.');
        Permission::create(['name' => 'future-feature.view']);
        $this->assertTrue($user->hasPermission('future-feature.view'));
        $this->assertFalse($user->hasPermission('unknown.permission'));
        $user->active = false;
        $this->assertFalse($user->hasPermission('roles.edit'));
    }

    public function test_dashboard_card_access_is_independent_and_hidden_data_is_not_loaded(): void
    {
        $user = $this->user(['dashboard.view']);
        $response = $this->get(route('dashboard'))->assertOk()->assertSee('Your dashboard is ready');
        foreach (['Recent sales', 'Sales overview', 'Opening cash', 'Recent expenses', 'Low stock', 'Collections'] as $label) {
            $response->assertDontSee($label);
        }
        $response->assertViewHas('recentSales', fn ($rows) => $rows->isEmpty())->assertViewHas('payments', fn ($rows) => $rows->isEmpty());
        $user->role->permissions()->attach(Permission::where('name', 'dashboard.low_stock')->value('id'));
        $this->actingAs($user->fresh());
        $this->get(route('dashboard'))->assertOk()->assertSee('Low stock')->assertDontSee('Recent sales')->assertDontSee('Sales overview');
    }

    public function test_each_dashboard_card_can_be_enabled_without_enabling_other_cards(): void
    {
        $labels = ['sales' => 'Sales', 'profit' => 'Profit', 'expenses' => 'Expenses', 'transactions' => 'Transactions', 'collections' => 'Collections', 'sales_overview' => 'Sales overview', 'register' => 'Register status', 'recent_sales' => 'Recent sales', 'low_stock' => 'Low stock', 'top_products' => 'Original product sales', 'recent_expenses' => 'Recent expenses'];
        foreach ($labels as $card => $label) {
            $this->user(['dashboard.view', 'dashboard.'.$card, 'products.view_cost']);
            $this->get(route('dashboard'))->assertOk()->assertSee($label);
        }
    }

    public function test_module_crud_is_independent_and_cannot_be_reached_through_old_shared_permissions(): void
    {
        $this->user(['products.view', 'products.create', 'purchases.view', 'sales.create', 'expenses.create', 'units.edit']);
        foreach (['categories', 'suppliers', 'customers', 'expense-categories', 'unit-presets', 'payment-methods'] as $resource) {
            $this->get(route('manage.index', $resource))->assertForbidden();
            $this->postJson(route('manage.store', $resource), [])->assertForbidden();
        }
        $this->postJson(route('units.default', Unit::first()), [])->assertForbidden();
        $this->user(['categories.view', 'categories.create']);
        $this->get(route('manage.index', 'categories'))->assertOk();
        $this->postJson(route('manage.store', 'categories'), ['name' => 'Independent category'])->assertCreated();
    }

    public function test_customer_payments_and_ledger_require_explicit_access_before_validation_or_writes(): void
    {
        $customer = Customer::create(['name' => 'Due account', 'active' => true, 'opening_due' => '100']);
        $this->user(['customers.view', 'customers.edit']);
        $this->postJson(route('manage.customers.payments.store', $customer), [])->assertForbidden();
        $this->get(route('manage.customers.ledger', $customer))->assertForbidden();
        $this->get(route('manage.show', ['customers', $customer->id]))->assertOk()->assertDontSee('Collect Payment')->assertDontSee('id="payment-modal"', false);
        $this->assertDatabaseCount('customer_payments', 0);
        $this->user(['customers.view', 'customers.collect_payment', 'customers.ledger']);
        $this->get(route('manage.show', ['customers', $customer->id]))->assertOk()->assertSee('Collect Payment');
        $this->get(route('manage.customers.ledger', $customer))->assertOk();
        $this->get(route('manage.customers.ledger', ['customer' => $customer, 'export' => 'csv']))->assertForbidden();
        $this->postJson(route('manage.customers.payments.store', $customer), [])->assertUnprocessable();
    }

    public function test_read_report_permission_does_not_allow_pdf_or_csv_downloads(): void
    {
        $this->user(['reports.sales']);
        $this->get(route('reports.show', 'sales'))->assertOk()->assertDontSee('Download PDF')->assertDontSee('Export CSV');
        $this->get(route('reports.pdf', 'sales'))->assertForbidden();
        $this->get(route('reports.export', 'sales'))->assertForbidden();
        $this->user(['reports.sales', 'reports.sales.pdf']);
        $this->get(route('reports.pdf', 'sales'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('reports.export', 'sales'))->assertForbidden();
        $this->user(['reports.sales.pdf']);
        $this->get(route('reports.pdf', 'sales'))->assertForbidden();
    }

    public function test_supplier_ledger_view_and_export_are_separate_from_purchase_access(): void
    {
        $supplier = Supplier::create(['name' => 'Supplier account', 'active' => true]);
        $this->user(['suppliers.view', 'purchases.view']);
        $this->get(route('manage.show', ['suppliers', $supplier->id]))->assertOk()->assertDontSee('Supplier ledger')->assertDontSee('Download ledger PDF');
        $this->get(route('manage.suppliers.ledger', $supplier))->assertForbidden();
        $this->user(['suppliers.view', 'suppliers.ledger', 'suppliers.export']);
        $this->get(route('manage.suppliers.ledger', $supplier))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_stock_and_register_mutations_do_not_use_product_edit_or_register_close_permissions(): void
    {
        $unit = Unit::first();
        $product = Product::create(['name' => 'Stock', 'sku' => 'ACCESS-STOCK', 'unit_id' => $unit->id, 'stock' => 5, 'cost' => 10, 'price' => 20, 'active' => true]);
        $this->user(['products.edit', 'register.close']);
        $this->get(route('adjustments.create'))->assertForbidden();
        $this->postJson(route('adjustments.store'), [])->assertForbidden();
        $this->postJson(route('register.movement'), [])->assertForbidden();
        $this->putJson(route('stock.update', $product), [])->assertForbidden();
        $this->user(['stock-adjustments.create', 'register.movement']);
        $this->get(route('adjustments.create'))->assertOk();
        $this->postJson(route('adjustments.store'), [])->assertUnprocessable();
        $this->postJson(route('register.movement'), [])->assertUnprocessable();
    }

    public function test_non_admin_cannot_grant_beyond_own_permissions_or_assign_empty_pivot_administrator(): void
    {
        $adminRole = Role::where('name', 'Administrator')->firstOrFail();
        $adminRole->permissions()->detach();
        $this->user(['roles.create', 'users.create']);
        $this->postJson(route('manage.store', 'roles'), ['name' => 'Escalation', 'permissions' => [Permission::where('name', 'products.delete')->value('id')]])->assertUnprocessable()->assertJsonValidationErrors('permissions');
        $this->postJson(route('manage.store', 'users'), ['name' => 'Escalation user', 'username' => 'escalation', 'email' => 'escalation@example.test', 'password' => 'test-password', 'role_id' => $adminRole->id, 'active' => true])->assertUnprocessable()->assertJsonValidationErrors('role_id');
        $this->assertDatabaseMissing('roles', ['name' => 'Escalation']);
        $this->assertDatabaseMissing('users', ['email' => 'escalation@example.test']);
    }

    public function test_role_editor_displays_all_granular_permissions_with_clear_descriptions(): void
    {
        $this->user([], Role::where('name', 'Administrator')->firstOrFail());
        $this->get(route('manage.create', 'roles'))->assertOk()->assertSee('Sales overview chart')->assertSee('Collect customer payment')->assertSee('Download PDF')->assertSee('Reverse a stock correction.');
        $id = Permission::where('name', 'dashboard.low_stock')->value('id');
        $this->post(route('manage.store', 'roles'), ['name' => 'Stock monitor', 'permissions' => [$id]])->assertRedirect()->assertSessionHasNoErrors();
        $role = Role::where('name', 'Stock monitor')->firstOrFail();
        $this->assertSame(['dashboard.low_stock'], $role->permissions->pluck('name')->all());
        $this->postJson(route('manage.store', 'roles'), ['name' => 'administrator'])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_permission_install_preserves_old_role_access_once_and_respects_later_revocation(): void
    {
        Permission::where('name', 'categories.view')->delete();
        $user = $this->user(['products.view']);
        Permissions::install();
        $user = $user->fresh();
        $this->assertTrue($user->hasPermission('categories.view'));
        $user->role->permissions()->detach(Permission::where('name', 'categories.view')->value('id'));
        Permissions::install();
        $this->assertFalse($user->fresh()->hasPermission('categories.view'));
        Permission::where('name', 'reports.returns')->delete();
        $reportUser = $this->user(['reports.sales']);
        Permissions::install();
        $this->assertTrue($reportUser->fresh()->hasPermission('reports.returns'));
    }

    public function test_sale_and_purchase_actions_require_their_specific_permissions(): void
    {
        $owner = $this->user([], Role::where('name', 'Administrator')->firstOrFail());
        $register = app(RegisterService::class)->open($owner->id, '0');
        $sale = Sale::create(['invoice' => 'ACCESS-SALE', 'checkout_token' => (string) Str::uuid(), 'user_id' => $owner->id, 'register_id' => $register->id, 'sold_at' => now(), 'subtotal' => 0, 'discount' => 0, 'sale_amount' => 0, 'processing_charge' => 0, 'customer_payable' => 0, 'cost_total' => 0]);
        $supplier = Supplier::create(['name' => 'Action supplier', 'active' => true]);
        $purchase = Purchase::create(['reference' => 'ACCESS-PURCHASE', 'supplier_id' => $supplier->id, 'user_id' => $owner->id, 'purchase_date' => today(), 'total' => 0]);
        $actions = [
            ['POST', 'sales.returns.store', $sale, 'sales_returns.create'],
            ['POST', 'sales.collections.store', $sale, 'sales.collect_payment'],
            ['DELETE', 'sales.destroy', $sale, 'sales.delete'],
            ['POST', 'sales.void', $sale, 'sales.void'],
            ['POST', 'purchases.payments.store', $purchase, 'purchases.pay'],
            ['POST', 'purchases.refunds.store', $purchase, 'purchases.refund'],
            ['POST', 'purchases.balance', $purchase, 'purchases.set_balance'],
            ['POST', 'purchases.void', $purchase, 'purchases.void'],
            ['DELETE', 'purchases.destroy', $purchase, 'purchases.delete'],
        ];
        $this->user(['sales.edit', 'purchases.edit']);
        foreach ($actions as [$method, $route, $record, $permission]) {
            $this->json($method, route($route, $record), [])->assertForbidden();
        }
        foreach ($actions as [$method, $route, $record, $permission]) {
            $this->user([$permission]);
            $this->json($method, route($route, $record), [])->assertUnprocessable();
        }
        $this->assertSame('ACTIVE', $sale->fresh()->status);
        $this->assertSame('ACTIVE', $purchase->fresh()->status);
    }

    public function test_pos_price_discount_split_and_due_permissions_are_enforced_in_checkout(): void
    {
        $user = $this->user(['pos.access', 'sales.create']);
        app(RegisterService::class)->open($user->id, '0');
        $product = Product::create(['name' => 'Checkout access product', 'sku' => 'CHECKOUT-ACCESS', 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'stock' => 10, 'cost' => 10, 'price' => 20, 'active' => true]);
        $cash = PaymentMethod::where('type', 'CASH')->value('id');
        $card = PaymentMethod::where('type', 'CARD')->value('id');
        $data = ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'payment_method_id' => $cash];
        $quote = $this->postJson(route('pos.quote'), $data)->assertOk()->json();
        $this->postJson(route('pos.quote'), array_replace_recursive($data, ['items' => [['unit_price' => '19']]]))->assertForbidden();
        $this->postJson(route('pos.quote'), $data + ['bill_discount_type' => 'AMOUNT', 'bill_discount_value' => '1'])->assertForbidden();
        $split = ['items' => $data['items'], 'payments' => [['payment_method_id' => $cash, 'amount' => '10'], ['payment_method_id' => $card, 'amount' => '10']]];
        $this->postJson(route('pos.quote'), $split)->assertForbidden();
        $customer = Customer::create(['name' => 'Credit customer', 'active' => true]);
        $this->postJson(route('pos.complete'), $data + ['customer_id' => $customer->id, 'allow_due' => true, 'amount_paid' => 0, 'checkout_token' => (string) Str::uuid(), 'quote_hash' => $quote['quote_hash']])->assertForbidden();
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_non_admin_cannot_manage_an_existing_administrator_or_more_privileged_role(): void
    {
        $adminRole = Role::where('name', 'Administrator')->firstOrFail();
        $administrator = $this->user([], $adminRole);
        $adminRole->permissions()->detach();
        $this->user(['users.edit', 'users.delete', 'roles.edit']);
        $this->getJson(route('manage.edit', ['users', $administrator->id]))->assertUnprocessable();
        $this->deleteJson(route('manage.destroy', ['users', $administrator->id]))->assertUnprocessable();
        $this->getJson(route('manage.edit', ['roles', Role::where('name', 'Manager')->value('id')]))->assertUnprocessable();
        $this->assertDatabaseHas('users', ['id' => $administrator->id]);
    }

    public function test_create_and_edit_without_list_access_redirect_to_an_allowed_page(): void
    {
        $this->user(['categories.create']);
        $this->post(route('manage.store', 'categories'), ['name' => 'Create without list'])
            ->assertRedirect(route('manage.create', 'categories'))->assertSessionHasNoErrors();
        $category = Category::where('name', 'Create without list')->firstOrFail();
        $this->user(['categories.edit']);
        $this->put(route('manage.update', ['categories', $category->id]), ['name' => 'Edit without list'])
            ->assertRedirect(route('manage.edit', ['categories', $category->id]))->assertSessionHasNoErrors();
        $this->get(route('manage.edit', ['categories', $category->id]))->assertOk()
            ->assertDontSee('href="'.route('manage.index', 'categories').'"', false);
    }

    public function test_checkout_without_receipt_access_completes_without_a_forbidden_receipt_link(): void
    {
        $user = $this->user(['pos.access', 'sales.create', 'register.close']);
        app(RegisterService::class)->open($user->id, '0');
        $this->getJson(route('register.current-summary'))->assertOk();
        $this->get(route('pos.index'))->assertOk()->assertSee('close-register-dialog', false);
        $product = Product::create(['name' => 'Receipt access product', 'sku' => 'RECEIPT-ACCESS', 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'stock' => 10, 'cost' => 10, 'price' => 20, 'active' => true]);
        $data = ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'payment_method_id' => PaymentMethod::where('type', 'CASH')->value('id'), 'amount_paid' => '20'];
        $quote = $this->postJson(route('pos.quote'), $data)->assertOk()->json();
        $data += ['checkout_token' => (string) Str::uuid(), 'quote_hash' => $quote['quote_hash']];
        $this->postJson(route('pos.complete'), $data)->assertOk()->assertJsonPath('receipt_url', null)->assertJsonPath('show_receipt', false)->assertJsonPath('auto_print', false);
        $this->get(route('sales.receipt', Sale::firstOrFail()))->assertForbidden();
        $this->getJson(route('pos.checkout-status', ['checkout_token' => $data['checkout_token']]))->assertOk()->assertJsonPath('completed', true)->assertJsonPath('receipt_url', null);
    }
}
