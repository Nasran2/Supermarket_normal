<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ProfitLossService;
use App\Services\RegisterService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseEditingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::create(['name' => 'Expense QA', 'email' => 'expense@example.test', 'password' => 'test-password', 'role_id' => Role::where('name', 'Administrator')->value('id')]);
        $this->actingAs($this->admin);
        app(RegisterService::class)->open($this->admin->id, '1000.00');
    }

    private function data(array $changes = []): array
    {
        return array_replace(['expense_category_id' => ExpenseCategory::where('name', 'General')->value('id'), 'expense_date' => today()->toDateString(), 'payment_method_id' => PaymentMethod::where('type', 'CASH')->value('id'), 'reference' => 'EXP-QA', 'description' => 'Cleaning supplies', 'amount' => '100.00'], $changes);
    }

    private function expense(): Expense
    {
        $this->post(route('manage.store', 'expenses'), $this->data())->assertRedirect()->assertSessionHasNoErrors();

        return Expense::sole();
    }

    public function test_edit_link_and_form_update_the_existing_expense_and_financial_totals(): void
    {
        $expense = $this->expense();
        $this->get(route('manage.index', 'expenses'))->assertOk()->assertSee('aria-label="Edit expense EXP-QA"', false);
        $this->get(route('manage.show', ['expenses', $expense->id]))->assertOk()->assertSee('Edit expense');
        $this->get(route('manage.edit', ['expenses', $expense->id]))->assertOk()->assertSee('Cleaning supplies')->assertSee('Save changes')->assertSee('value="'.today()->toDateString().'"', false);

        $category = ExpenseCategory::create(['name' => 'Shop maintenance']);
        $changes = $this->data(['expense_category_id' => $category->id, 'expense_date' => today()->subDay()->toDateString(), 'reference' => 'EXP-CORRECTED', 'description' => 'Shop cleaning and repair', 'amount' => '175.50']);
        $this->put(route('manage.update', ['expenses', $expense->id]), $changes)->assertRedirect()->assertSessionHasNoErrors();
        $expense->refresh();
        $this->assertDatabaseCount('expenses', 1);
        $this->assertSame('175.50', $expense->amount);
        $this->assertSame($category->id, $expense->expense_category_id);
        $this->assertSame($changes['expense_date'], $expense->expense_date->toDateString());
        $this->assertSame($changes['reference'], $expense->reference);
        $this->assertSame($changes['description'], $expense->description);
        $this->assertSame($this->admin->id, $expense->user_id);
        $register = app(RegisterService::class)->current($this->admin->id);
        $this->assertSame('824.50', app(RegisterService::class)->summary($register)['expected']);
        $this->assertSame('175.50', app(ProfitLossService::class)->calculate(today()->subDay()->toDateString(), today()->toDateString())['expenses']);
        $audit = AuditLog::where('action', 'expenses.save')->where('subject_id', $expense->id)->latest('id')->firstOrFail();
        $this->assertSame('100.00', Money::round((string) $audit->before['amount']));
        $this->assertSame('175.50', Money::round((string) $audit->after['amount']));
    }

    public function test_changing_the_payment_method_recalculates_register_cash_without_duplicate_expenses(): void
    {
        $expense = $this->expense();
        $card = PaymentMethod::where('type', 'CARD')->firstOrFail();
        $this->putJson(route('manage.update', ['expenses', $expense->id]), $this->data(['payment_method_id' => $card->id]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($expense->fresh()->register_id);
        $register = app(RegisterService::class)->current($this->admin->id);
        $this->assertSame('1000.00', app(RegisterService::class)->summary($register)['expected']);
        $this->putJson(route('manage.update', ['expenses', $expense->id]), $this->data(['amount' => '125.00']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($register->id, $expense->fresh()->register_id);
        $this->assertSame('875.00', app(RegisterService::class)->summary($register)['expected']);
        $this->assertDatabaseCount('expenses', 1);
    }

    public function test_invalid_edits_and_protected_expenses_leave_the_original_record_unchanged(): void
    {
        $expense = $this->expense();
        $url = route('manage.update', ['expenses', $expense->id]);
        $this->putJson($url, $this->data(['amount' => '0']))->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertSame('100.00', $expense->fresh()->amount);
        foreach (['AUTOMATIC', 'PURCHASE_CHARGE', 'HR_PAYROLL'] as $type) {
            $expense->update(['type' => $type]);
            $this->get(route('manage.index', 'expenses'))->assertDontSee('aria-label="Edit expense EXP-QA"', false);
            $this->getJson(route('manage.edit', ['expenses', $expense->id]))->assertUnprocessable();
            $this->putJson($url, $this->data(['amount' => '200']))->assertUnprocessable();
            $this->assertSame('100.00', $expense->fresh()->amount);
        }
        $expense->update(['type' => 'MANUAL', 'status' => 'REVERSED']);
        $this->putJson($url, $this->data(['amount' => '200']))->assertUnprocessable();
        $this->assertSame('100.00', $expense->fresh()->amount);
    }

    public function test_expense_edit_requires_permission(): void
    {
        $expense = $this->expense();
        $role = Role::create(['name' => 'Expense viewer']);
        $role->permissions()->sync(Permission::where('name', 'expenses.view')->pluck('id'));
        $user = User::create(['name' => 'Expense viewer', 'email' => 'expense-viewer@example.test', 'password' => 'test-password', 'role_id' => $role->id]);
        $this->flushSession();
        $this->actingAs($user->fresh());
        $this->get(route('manage.index', 'expenses'))->assertOk()->assertDontSee('aria-label="Edit expense EXP-QA"', false);
        $this->get(route('manage.edit', ['expenses', $expense->id]))->assertForbidden();
        $this->putJson(route('manage.update', ['expenses', $expense->id]), $this->data(['amount' => '200']))->assertForbidden();
        $this->assertSame('100.00', $expense->fresh()->amount);
    }

    public function test_closed_or_other_users_register_cannot_be_changed_by_an_expense_edit(): void
    {
        $expense = $this->expense();
        $other = User::create(['name' => 'Other Admin', 'email' => 'expense-other@example.test', 'password' => 'test-password', 'role_id' => $this->admin->role_id]);
        app(RegisterService::class)->open($other->id, '500.00');
        $this->flushSession();
        $this->actingAs($other->fresh())->putJson(route('manage.update', ['expenses', $expense->id]), $this->data(['amount' => '200']))->assertUnprocessable()->assertJsonValidationErrors('expense');
        $this->flushSession();
        $this->actingAs($this->admin);
        app(RegisterService::class)->close(app(RegisterService::class)->current($this->admin->id), '900.00', null);
        $this->putJson(route('manage.update', ['expenses', $expense->id]), $this->data(['amount' => '200']))->assertUnprocessable()->assertJsonValidationErrors('expense');
        $this->assertSame('100.00', $expense->fresh()->amount);
    }
}
