<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\RegisterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerDueTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->cashier = User::create(['name' => 'Customer account admin', 'username' => 'due-admin', 'email' => 'due-admin@example.test', 'password' => 'test-password', 'role_id' => Role::where('name', 'Administrator')->value('id')]);
        $this->actingAs($this->cashier);
        app(RegisterService::class)->open($this->cashier->id, '1000');
    }

    private function contact(array $extra = []): array
    {
        return $extra + ['name' => 'Opening due customer', 'phone' => '0000000000', 'email' => 'due@example.test', 'address' => 'QA address', 'active' => true];
    }

    public function test_pos_creation_records_old_balance_as_due_without_cash_or_sales(): void
    {
        $response = $this->postJson(route('pos.customers.store'), $this->contact(['opening_due' => '750.5']))->assertCreated()->assertJsonPath('customer.due_balance', '750.50');
        $customer = Customer::findOrFail($response->json('customer.id'));
        $this->assertSame('750.50', $customer->opening_due);
        $this->assertSame('750.50', $customer->due_balance);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('expenses', 0);
        $summary = app(RegisterService::class)->summary(app(RegisterService::class)->current($this->cashier->id));
        $this->assertSame('1000.00', $summary['expected']);
        $this->assertSame('0.00', $summary['collections']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customers.save', 'subject_id' => $customer->id]);
        $this->get(route('pos.index'))->assertOk()->assertSee('data-due="750.50"', false)->assertSee('Customer balance');
    }

    public function test_optional_opening_due_defaults_to_zero_for_new_and_existing_style_submissions(): void
    {
        $this->postJson(route('pos.customers.store'), ['name' => 'No old debt', 'opening_due' => null])->assertCreated()->assertJsonPath('customer.due_balance', '0.00');
        $this->post(route('manage.store', 'customers'), $this->contact())->assertRedirect();
        $this->assertSame(['0.00', '0.00'], Customer::orderBy('id')->get()->pluck('opening_due')->all());
    }

    public function test_manage_customer_forms_save_and_edit_due_with_audit_and_preserve_omitted_balance(): void
    {
        $this->get(route('manage.create', 'customers'))->assertOk()->assertSee('Opening due')->assertSee('name="opening_due"', false);
        $this->post(route('manage.store', 'customers'), $this->contact(['opening_due' => '1234.56']))->assertRedirect(route('manage.index', 'customers'));
        $customer = Customer::firstOrFail();
        $this->assertSame('1234.56', $customer->due_balance);
        $this->put(route('manage.update', ['customers', $customer->id]), $this->contact(['name' => 'Updated contact']))->assertRedirect();
        $this->assertSame('1234.56', $customer->fresh()->due_balance);
        $this->get(route('manage.edit', ['customers', $customer->id]))->assertOk()->assertSee('value="1234.56"', false);
        $this->put(route('manage.update', ['customers', $customer->id]), $this->contact(['opening_due' => '900.25']))->assertRedirect();
        $this->assertSame('900.25', $customer->fresh()->due_balance);
        $this->get(route('manage.show', ['customers', $customer->id]))->assertOk()->assertSee('OUTSTANDING DUE', false)->assertSee('900.25')->assertSee('QA address');
        $this->assertSame(3, AuditLog::where('action', 'customers.save')->where('subject_id', $customer->id)->count());
    }

    public function test_negative_excessive_or_overprecise_opening_dues_are_rejected_on_both_forms(): void
    {
        foreach (['-1', '10.001', '1000000000', ['50']] as $invalid) {
            $data = $this->contact(['opening_due' => $invalid]);
            $this->postJson(route('pos.customers.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('opening_due');
            $this->postJson(route('manage.store', 'customers'), $data)->assertUnprocessable()->assertJsonValidationErrors('opening_due');
        }
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_customer_directory_filters_dues_and_searches_contact_information(): void
    {
        $due = Customer::create($this->contact(['name' => 'Owing customer', 'email' => 'owing@example.test', 'opening_due' => '1200.50']));
        $clear = Customer::create($this->contact(['name' => 'Clear customer', 'email' => 'clear@example.test']));
        $this->get(route('manage.index', ['resource' => 'customers', 'balance' => 'due']))->assertOk()->assertSee('Owing customer')->assertDontSee('Clear customer')->assertSee('1,200.50');
        $this->get(route('manage.index', ['resource' => 'customers', 'balance' => 'clear']))->assertOk()->assertSee('Clear customer')->assertDontSee('Owing customer');
        $this->get(route('manage.index', ['resource' => 'customers', 'q' => 'owing@example.test']))->assertOk()->assertSee($due->name)->assertDontSee($clear->name);
        $this->get(route('manage.show', ['customers', $due->id]))->assertOk()->assertSee('Purchase history')->assertSee('Old balance / opening due');
    }

    public function test_paid_new_pos_purchase_does_not_charge_or_reduce_old_due(): void
    {
        $customer = Customer::create($this->contact(['opening_due' => '350']));
        $product = Product::create(['name' => 'Due separation test', 'sku' => 'DUE-TEST', 'category_id' => Category::first()->id, 'unit_id' => Unit::where('short_name', 'pcs')->value('id'), 'price' => '1000', 'cost' => '400', 'stock' => '10']);
        $data = ['items' => [['product_id' => $product->id, 'quantity' => '1']], 'customer_id' => $customer->id, 'payment_method_id' => PaymentMethod::where('type', 'CASH')->value('id')];
        $quote = $this->postJson(route('pos.quote'), $data)->assertOk()->assertJsonPath('customer_payable', '1000.00')->json();
        $this->postJson(route('pos.complete'), $data + ['amount_paid' => '1000', 'quote_hash' => $quote['quote_hash'], 'checkout_token' => Str::uuid()->toString()])->assertOk();
        $this->assertSame('350.00', $customer->fresh()->due_balance);
        $this->get(route('manage.show', ['customers', $customer->id]))->assertOk()->assertSee('350.00')->assertSee('1,000.00')->assertSee('Invoiced purchases')->assertSee('INV-000001');
        $this->assertSame('2000.00', app(RegisterService::class)->summary(app(RegisterService::class)->current($this->cashier->id))['expected']);
    }

    public function test_a_customer_with_outstanding_due_cannot_be_deleted(): void
    {
        $customer = Customer::create($this->contact(['opening_due' => '125']));
        $this->deleteJson(route('manage.destroy', ['customers', $customer->id]))->assertUnprocessable()->assertJsonValidationErrors('delete');
        $this->assertSame('125.00', $customer->fresh()->due_balance);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'customers.delete', 'subject_id' => $customer->id]);
        $clear = Customer::create($this->contact(['name' => 'Unused clear customer']));
        $this->delete(route('manage.destroy', ['customers', $clear->id]))->assertRedirect();
        $this->assertNull($clear->fresh());
    }
}
