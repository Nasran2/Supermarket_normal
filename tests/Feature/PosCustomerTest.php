<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\RegisterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosCustomerTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->cashier = User::create(['name' => 'Customer cashier', 'username' => 'customer-test', 'email' => 'customer-cashier@example.test', 'password' => 'test-password', 'role_id' => Role::where('name', 'Cashier')->value('id')]);
        $this->actingAs($this->cashier);
        app(RegisterService::class)->open($this->cashier->id, '0');
    }

    public function test_cashier_can_create_an_active_customer_with_audit_and_contact_details(): void
    {
        $data = ['name' => '  New Customer  ', 'phone' => '0000000000', 'email' => 'customer@example.test', 'address' => 'Test address', 'active' => false];
        $response = $this->postJson(route('pos.customers.store'), $data)->assertCreated()->assertJsonPath('customer.name', 'New Customer');
        $customer = Customer::findOrFail($response->json('customer.id'));
        $this->assertTrue($customer->active);
        $this->assertSame($data['phone'], $customer->phone);
        $this->assertSame($data['email'], $customer->email);
        $this->assertSame($data['address'], $customer->address);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customers.save', 'user_id' => $this->cashier->id, 'subject_id' => $customer->id, 'subject_type' => Customer::class]);
        $this->get(route('pos.index'))->assertOk()->assertSee('id="add-customer"', false)->assertSee('id="customer-dialog"', false)->assertSee('New Customer');
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_name_only_is_allowed_but_invalid_names_and_email_are_rejected(): void
    {
        $this->postJson(route('pos.customers.store'), ['name' => 'Name only'])->assertCreated();
        $this->postJson(route('pos.customers.store'), ['name' => '  ', 'email' => 'invalid-email'])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email']);
        $this->postJson(route('pos.customers.store'), ['name' => ['Invalid'], 'phone' => str_repeat('1', 256)])->assertUnprocessable()->assertJsonValidationErrors(['name', 'phone']);
        $this->assertDatabaseCount('customers', 1);
        $this->assertNull(Customer::first()->email);
    }

    public function test_customer_creation_requires_sales_create_permission(): void
    {
        $role = Role::create(['name' => 'POS viewer']);
        $role->permissions()->attach(Permission::where('name', 'pos.access')->value('id'));
        $viewer = User::create(['name' => 'Viewer', 'username' => 'customer-viewer', 'email' => 'viewer@example.test', 'password' => 'test-password', 'role_id' => $role->id]);
        $this->actingAs($viewer);
        app(RegisterService::class)->open($viewer->id, '0');
        $this->get(route('pos.index'))->assertOk()->assertDontSee('id="add-customer"', false)->assertDontSee('id="customer-dialog"', false);
        $this->postJson(route('pos.customers.store'), ['name' => 'Not permitted'])->assertForbidden();
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_closed_register_blocks_pos_customer_creation(): void
    {
        $register = app(RegisterService::class)->current($this->cashier->id);
        app(RegisterService::class)->close($register, '0', null);
        $this->postJson(route('pos.customers.store'), ['name' => 'Closed shift'])->assertUnprocessable()->assertJsonValidationErrors('register');
        $this->assertDatabaseCount('customers', 0);
    }
}
