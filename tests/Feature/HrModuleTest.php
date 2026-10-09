<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Hr\Attendance;
use App\Models\Hr\LeaveRequest;
use App\Models\Hr\Option;
use App\Models\Hr\Payroll;
use App\Models\Hr\Staff;
use App\Models\Hr\StaffPayment;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\HrService;
use App\Services\ProfitLossService;
use App\Services\RegisterService;
use App\Support\Hr;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class HrModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hr.enabled' => true]);
        $this->seed();
        $this->travelTo(Carbon::parse('2026-10-10 12:00:00', 'Asia/Colombo'));
        $this->admin = User::create(['name' => 'HR test admin', 'email' => 'hr-admin@example.test', 'username' => 'hr.test.admin', 'password' => 'HrTestAdminPassword', 'role_id' => Role::where('name', 'Administrator')->value('id')]);
        $this->actingAs($this->admin);
    }

    private function data(array $changes = []): array
    {
        return array_merge(['name' => 'Test employee', 'joined_on' => '2026-09-01', 'employment_type' => 'PERMANENT', 'salary_basis' => 'MONTHLY', 'salary_rate' => '30000', 'overtime_rate' => '100'], $changes);
    }

    private function staff(array $changes = []): Staff
    {
        return app(HrService::class)->saveStaff($this->data($changes));
    }

    private function payroll(Staff $staff, array $changes = []): Payroll
    {
        return app(HrService::class)->preparePayroll(array_merge(['staff_id' => $staff->id, 'month' => '2026-09'], $changes));
    }

    private function payment(Staff $staff, array $changes = []): array
    {
        return array_merge(['staff_id' => $staff->id, 'kind' => 'ADVANCE', 'amount' => '1000', 'date' => '2026-10-10', 'payment_method_id' => PaymentMethod::where('type', 'BANK_TRANSFER')->value('id'), 'token' => (string) Str::uuid()], $changes);
    }

    public function test_disabled_module_hides_navigation_permissions_reports_and_blocks_every_hr_route(): void
    {
        $staff = $this->staff();
        config(['hr.enabled' => false]);
        foreach (['hr.index', 'hr.staff.index', 'hr.attendance', 'hr.leave', 'hr.payroll.index', 'hr.payments', 'hr.setup', 'hr.reports.index'] as $route) {
            $this->get(route($route))->assertNotFound();
        }
        $this->get(route('hr.reports.download', ['kind' => 'staff', 'format' => 'pdf']))->assertNotFound();
        $this->post(route('hr.staff.store'), $this->data())->assertNotFound();
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Human resources');
        $this->get(route('reports.index'))->assertOk()->assertDontSee('Human resources');
        $this->get(route('manage.edit', ['roles', Role::where('name', 'Cashier')->value('id')]))->assertOk()->assertDontSee('HR ·');
        $this->get(route('manage.show', ['roles', Role::where('name', 'Administrator')->value('id')]))->assertOk()->assertDontSee('HR ·');
        $this->assertFalse($this->admin->hasPermission('hr.staff.view'));
        $this->assertDatabaseHas('hr_staff', ['id' => $staff->id]);
    }

    public function test_staff_crud_optional_login_and_password_changes_use_existing_account_protections(): void
    {
        $role = Role::where('name', 'Cashier')->firstOrFail();
        $response = $this->post(route('hr.staff.store'), $this->data(['role_id' => $role->id, 'username' => 'staff.demo', 'password' => 'StrongTestPassword', 'login_active' => true]));
        $response->assertRedirect()->assertSessionHasNoErrors();
        $staff = Staff::firstOrFail();
        $this->assertSame('staff.demo', $staff->user->username);
        $this->assertTrue(Hash::check('StrongTestPassword', $staff->user->password));
        $this->put(route('hr.staff.update', $staff), $this->data(['role_id' => $role->id, 'username' => 'staff.demo', 'password' => 'ChangedTestPassword', 'login_active' => false]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse($staff->user->fresh()->active);
        $this->assertTrue(Hash::check('ChangedTestPassword', $staff->user->fresh()->password));
        $this->assertStringNotContainsString('ChangedTestPassword', AuditLog::latest()->first()->toJson());
        $this->postJson(route('hr.staff.store'), $this->data(['role_id' => $role->id, 'username' => 'staff.demo', 'password' => 'OtherTestPassword', 'login_active' => true]))->assertUnprocessable();
        $this->assertDatabaseCount('hr_staff', 1);
    }

    public function test_staff_view_cannot_mark_attendance_or_create_login_or_read_payroll(): void
    {
        $role = Role::create(['name' => 'HR reader']);
        $role->permissions()->sync(Permission::whereIn('name', ['hr.staff.view', 'hr.staff.create', 'hr.staff.edit'])->pluck('id'));
        $user = User::create(['name' => 'HR reader', 'email' => 'reader@hr.test', 'password' => 'ReaderTestPassword', 'role_id' => $role->id]);
        $staff = $this->staff();
        $this->flushSession();
        $this->actingAs($user);
        $this->get(route('hr.staff.show', $staff))->assertOk()->assertDontSee('30,000.00');
        $this->postJson(route('hr.attendance.mark'), [])->assertForbidden();
        $this->get(route('hr.payroll.index'))->assertForbidden();
        $this->postJson(route('hr.staff.store'), $this->data(['role_id' => $this->admin->role_id, 'username' => 'bad.admin', 'password' => 'BadAdminPassword', 'login_active' => true]))->assertForbidden();
        $this->assertDatabaseCount('hr_staff', 1);
    }

    public function test_exit_and_rehire_retain_records_deactivate_login_and_respect_employment_dates(): void
    {
        $staff = $this->staff(['role_id' => Role::where('name', 'Cashier')->value('id'), 'username' => 'exit.staff', 'password' => 'ExitStaffPassword', 'login_active' => true]);
        app(HrService::class)->employment($staff, ['type' => 'EXIT', 'date' => '2026-09-30', 'reason' => 'Contract complete']);
        $staff->refresh();
        $this->assertSame('EXITED', $staff->status);
        $this->assertFalse($staff->user->active);
        $this->assertSame(2, $staff->events()->count());
        $this->assertFalse(app(HrService::class)->employed($staff, '2026-10-01'));
        $this->assertTrue(app(HrService::class)->employed($staff, '2026-09-15'));
        app(HrService::class)->employment($staff, ['type' => 'REHIRE', 'date' => '2026-10-05', 'reason' => 'New contract']);
        $staff->refresh();
        $this->assertTrue(app(HrService::class)->employed($staff, '2026-10-05'));
        $this->assertFalse($staff->user->fresh()->active);
        $this->assertSame(3, $staff->events()->count());
        $this->get(route('hr.reports.show', ['kind' => 'employment', 'range' => 'all']))->assertOk()->assertSee('EXIT')->assertSee('REHIRE');
    }

    public function test_attendance_upsert_overnight_validation_and_unmarked_rows(): void
    {
        $staff = $this->staff();
        $payload = ['date' => '2026-09-10', 'rows' => [$staff->id => ['status' => 'PRESENT', 'check_in' => '22:00', 'check_out' => '06:00', 'overnight' => 1, 'overtime_hours' => '2']]];
        $this->post(route('hr.attendance.mark'), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('hr_attendances', 1);
        $this->assertSame('2026-09-11', Attendance::first()->check_out->toDateString());
        $this->post(route('hr.attendance.mark'), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('hr_attendances', 1);
        $payload['rows'][$staff->id]['overnight'] = 0;
        $this->postJson(route('hr.attendance.mark'), $payload)->assertUnprocessable();
        $this->post(route('hr.attendance.mark'), ['date' => '2026-09-11', 'rows' => [$staff->id => ['status' => '']]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('hr_attendances', 1);
    }

    public function test_leave_overlap_allowance_approval_and_attendance_conflicts(): void
    {
        $staff = $this->staff();
        $type = Option::where('kind', 'leave_type')->where('name', 'Annual leave')->firstOrFail();
        $type->update(['settings' => ['paid' => true, 'annual_days' => 2]]);
        $data = ['staff_id' => $staff->id, 'leave_type_id' => $type->id, 'from' => '2026-09-12', 'to' => '2026-09-13', 'reason' => 'Personal leave'];
        $this->post(route('hr.leave.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $leave = LeaveRequest::first();
        $this->postJson(route('hr.leave.store'), $data)->assertUnprocessable();
        $this->post(route('hr.leave.decide', $leave), ['status' => 'APPROVED', 'decision_note' => 'Approved'])->assertRedirect()->assertSessionHasNoErrors();
        $this->postJson(route('hr.attendance.mark'), ['date' => '2026-09-12', 'rows' => [$staff->id => ['status' => 'PRESENT']]])->assertUnprocessable();
        $this->post(route('hr.leave.store'), array_merge($data, ['from' => '2026-09-20', 'to' => '2026-09-20']))->assertRedirect()->assertSessionHasNoErrors();
        $second = LeaveRequest::latest('id')->first();
        $this->postJson(route('hr.leave.decide', $second), ['status' => 'APPROVED', 'decision_note' => 'Extra day'])->assertUnprocessable();
        $summary = app(HrService::class)->attendanceSummary($staff, '2026-09-01');
        $this->assertSame('2.00', $summary['paid_days']);
    }

    public function test_payroll_snapshots_proration_overtime_deductions_and_duplicates(): void
    {
        $staff = $this->staff();
        app(HrService::class)->markAttendance('2026-09-10', [$staff->id => ['status' => 'ABSENT']]);
        app(HrService::class)->markAttendance('2026-09-11', [$staff->id => ['status' => 'PRESENT', 'overtime_hours' => 2]]);
        $p = $this->payroll($staff, ['deduct_unpaid' => true, 'lines' => [['kind' => 'EARNING', 'name' => 'Bonus', 'amount' => '500'], ['kind' => 'DEDUCTION', 'name' => 'Other deduction', 'amount' => '100']]]);
        $this->assertSame('30000.00', $p->basic);
        $this->assertSame('30700.00', $p->earnings);
        $this->assertSame('1100.00', $p->deductions);
        $this->assertSame('29600.00', $p->net);
        $this->postJson(route('hr.payroll.store'), ['staff_id' => $staff->id, 'month' => '2026-09'])->assertUnprocessable();
        app(HrService::class)->approve($p);
        $this->assertSame('APPROVED', $p->fresh()->status);
        $this->assertDatabaseHas('expenses', ['reference' => $p->reference, 'type' => 'HR_PAYROLL', 'amount' => '29600.00']);
        $this->postJson(route('hr.attendance.mark'), ['date' => '2026-09-15', 'rows' => [$staff->id => ['status' => 'PRESENT']]])->assertUnprocessable();
        $summary = app(ProfitLossService::class)->calculate('2026-09-01', '2026-09-30');
        $this->assertSame('29600.00', $summary['expenses']);
    }

    public function test_daily_pay_and_mid_month_intake_proration(): void
    {
        $staff = $this->staff(['joined_on' => '2026-09-16']);
        $p = $this->payroll($staff);
        $this->assertSame('15000.00', $p->basic);
        $daily = $this->staff(['name' => 'Daily staff', 'salary_basis' => 'DAILY', 'salary_rate' => '2000']);
        app(HrService::class)->markAttendance('2026-09-10', [$daily->id => ['status' => 'PRESENT']]);
        app(HrService::class)->markAttendance('2026-09-11', [$daily->id => ['status' => 'HALF_DAY']]);
        $this->assertSame('3000.00', $this->payroll($daily)->net);
    }

    public function test_changed_rates_or_attendance_block_stale_draft_approval(): void
    {
        $staff = $this->staff();
        $p = $this->payroll($staff);
        app(HrService::class)->markAttendance('2026-09-10', [$staff->id => ['status' => 'ABSENT']]);
        $this->postJson(route('hr.payroll.approve', $p))->assertUnprocessable();
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_salary_partial_payments_advance_recovery_idempotency_and_ledger(): void
    {
        $staff = $this->staff();
        $advance = $this->payment($staff, ['amount' => '5000']);
        $this->post(route('hr.payments.store'), $advance)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('hr.payments.store'), $advance)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('hr_payments', 1);
        $p = $this->payroll($staff, ['advance_recovery' => '2000']);
        app(HrService::class)->approve($p);
        $this->assertSame('28000.00', $p->net);
        $salary = $this->payment($staff, ['kind' => 'SALARY', 'payroll_id' => $p->id, 'amount' => '10000']);
        $this->post(route('hr.payments.store'), $salary)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('18000.00', $p->due);
        $this->postJson(route('hr.payments.store'), $this->payment($staff, ['kind' => 'SALARY', 'payroll_id' => $p->id, 'amount' => '18001']))->assertUnprocessable();
        $ledger = app(HrService::class)->ledger($staff);
        $this->assertSame('18000.00', $ledger['salary_due']);
        $this->assertSame('3000.00', $ledger['advances']);
        $this->assertSame('15000.00', $ledger['balance']);
        $this->postJson(route('hr.payroll.void', $p), ['reason' => 'Test'])->assertUnprocessable();
        $this->get(route('hr.staff.ledger', ['staff' => $staff, 'from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk()->assertViewHas('opening', '30000.00')->assertViewHas('closing', '15000.00');
    }

    public function test_cash_payout_changes_register_once_and_closed_register_prevents_reversal(): void
    {
        $staff = $this->staff();
        $cash = PaymentMethod::where('type', 'CASH')->first();
        $data = $this->payment($staff, ['payment_method_id' => $cash->id]);
        $this->postJson(route('hr.payments.store'), $data)->assertUnprocessable();
        $this->assertDatabaseCount('hr_payments', 0);
        $r = app(RegisterService::class)->open($this->admin->id, '10000');
        $this->post(route('hr.payments.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('hr.payments.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('9000.00', app(RegisterService::class)->summary($r)['expected']);
        $p = StaffPayment::first();
        $this->post(route('hr.payments.reverse', $p), ['reason' => 'Cash returned'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('10000.00', app(RegisterService::class)->summary($r)['expected']);
        $this->post(route('hr.payments.store'), $this->payment($staff, ['payment_method_id' => $cash->id]))->assertRedirect()->assertSessionHasNoErrors();
        $p = StaffPayment::latest('id')->first();
        app(RegisterService::class)->close($r, '9000', null);
        $this->postJson(route('hr.payments.reverse', $p), ['reason' => 'Correction'])->assertUnprocessable();
        $this->assertSame('ACTIVE', $p->fresh()->status);
    }

    public function test_reports_pdf_csv_filters_and_all_screens_render(): void
    {
        $staff = $this->staff();
        foreach (['hr.index', 'hr.staff.index', 'hr.staff.create', 'hr.attendance', 'hr.leave', 'hr.payroll.index', 'hr.payroll.create', 'hr.payments', 'hr.setup', 'hr.reports.index'] as $r) {
            $this->get(route($r))->assertOk();
        }
        $this->get(route('hr.staff.show', $staff))->assertOk();
        $this->get(route('hr.staff.edit', $staff))->assertOk();
        $this->get(route('hr.staff.ledger', $staff))->assertOk();
        $p = $this->payroll($staff);
        app(HrService::class)->approve($p);
        $this->get(route('hr.payroll.show', $p))->assertOk();
        $this->get(route('hr.payroll.payslip', $p))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        foreach (Hr::REPORTS as $kind => $label) {
            $this->get(route('hr.reports.show', ['kind' => $kind, 'range' => 'all']))->assertOk();
            $this->get(route('hr.reports.download', ['kind' => $kind, 'format' => 'pdf', 'range' => 'all']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->get(route('hr.reports.download', ['kind' => $kind, 'format' => 'csv', 'range' => 'all']))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        }
        $this->getJson(route('hr.reports.show', ['kind' => 'payroll', 'range' => 'custom']))->assertUnprocessable();
        $this->get(route('hr.reports.show', ['kind' => 'payroll', 'range' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30']))->assertOk()->assertViewHas('cards', fn ($c) => $c['Due'] === '30000.00');
    }

    public function test_disabling_hr_does_not_remove_existing_role_grants_on_normal_role_edits(): void
    {
        $role = Role::where('name', 'Cashier')->first();
        $hr = Permission::where('name', 'hr.staff.view')->first();
        $role->permissions()->syncWithoutDetaching([$hr->id]);
        config(['hr.enabled' => false]);
        $ids = $role->permissions->reject(fn ($p) => str_starts_with($p->name, 'hr.'))->pluck('id')->all();
        $this->put(route('manage.update', ['roles', $role->id]), ['name' => $role->name, 'sales_visibility' => $role->sales_visibility, 'permissions' => $ids])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($role->fresh()->permissions->contains('id', $hr->id));
    }

    public function test_leave_cancellation_keeps_history_and_restores_unmarked_days(): void
    {
        $staff = $this->staff();
        $type = Option::where('kind', 'leave_type')->first();
        $leave = app(HrService::class)->leave(['staff_id' => $staff->id, 'leave_type_id' => $type->id, 'from' => '2026-09-12', 'to' => '2026-09-12', 'reason' => 'Leave']);
        app(HrService::class)->decideLeave($leave, ['status' => 'APPROVED', 'decision_note' => 'Approved']);
        app(HrService::class)->markAttendance('2026-09-12', [$staff->id => ['status' => 'PAID_LEAVE']]);
        $this->post(route('hr.leave.cancel', $leave), ['reason' => 'Staff returned to work'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('CANCELLED', $leave->fresh()->status);
        $this->assertSame('UNMARKED', Attendance::first()->status);
        $this->post(route('hr.attendance.mark'), ['date' => '2026-09-12', 'rows' => [$staff->id => ['status' => 'PRESENT']]])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_current_month_approval_is_blocked_and_voided_draft_can_be_reprepared(): void
    {
        $staff = $this->staff();
        $p = $this->payroll($staff, ['month' => '2026-10']);
        $this->postJson(route('hr.payroll.approve', $p))->assertUnprocessable();
        $this->post(route('hr.payroll.void', $p), ['reason' => 'Recalculate'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('hr.payroll.store'), ['staff_id' => $staff->id, 'month' => '2026-10'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('VOIDED', $p->fresh()->status);
        $this->assertDatabaseCount('hr_payrolls', 2);
    }

    public function test_report_exports_require_their_own_permission_and_preserve_filtered_data(): void
    {
        $staff = $this->staff();
        $role = Role::create(['name' => 'HR report reader']);
        $role->permissions()->sync(Permission::where('name', 'hr.reports.staff.view')->pluck('id'));
        $user = User::create(['name' => 'Report reader', 'email' => 'report-reader@hr.test', 'password' => 'ReaderTestPassword', 'role_id' => $role->id]);
        $this->flushSession();
        $this->actingAs($user);
        $this->get(route('hr.reports.show', ['kind' => 'staff', 'range' => 'all']))->assertOk()->assertSee($staff->name);
        foreach (['pdf', 'csv'] as $format) {
            $this->get(route('hr.reports.download', ['kind' => 'staff', 'format' => $format, 'range' => 'all']))->assertForbidden();
        }
        $this->get(route('hr.staff.index'))->assertForbidden();
    }

    public function test_payroll_cost_and_cash_reports_count_salary_once_and_payments_only_when_paid(): void
    {
        $staff = $this->staff();
        $p = $this->payroll($staff);
        app(HrService::class)->approve($p);
        $this->post(route('hr.payments.store'), $this->payment($staff, ['kind' => 'SALARY', 'payroll_id' => $p->id, 'amount' => '10000']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('30000.00', app(ProfitLossService::class)->calculate('2026-09-01', '2026-09-30')['expenses']);
        $this->get(route('reports.show', ['report' => 'cash', 'from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk()->assertViewHas('cards', fn ($c) => (float) $c['Period Money Out'] === 10000.0);
    }

    public function test_large_attendance_lists_are_paginated_without_losing_employment_history(): void
    {
        for ($i = 1; $i <= 105; $i++) {
            $this->staff(['name' => sprintf('Employee %03d', $i)]);
        }
        $exited = Staff::where('name', 'Employee 001')->firstOrFail();
        app(HrService::class)->employment($exited, ['type' => 'EXIT', 'date' => '2026-09-30', 'reason' => 'Completed contract']);
        $this->get(route('hr.attendance', ['date' => '2026-10-10']))->assertOk()
            ->assertViewHas('staff', fn ($s) => $s->total() === 104 && $s->count() === 50)
            ->assertDontSee('Employee 001');
        $this->get(route('hr.attendance', ['date' => '2026-10-10', 'page' => 3]))->assertOk()
            ->assertViewHas('staff', fn ($s) => $s->count() === 4)->assertSee('Employee 105');
        $this->get(route('hr.attendance', ['date' => '2026-09-15']))->assertOk()
            ->assertViewHas('staff', fn ($s) => $s->total() === 105)->assertSee('Employee 001');
    }

    public function test_staff_profile_changes_keep_employment_events_when_login_is_unchanged(): void
    {
        $values = ['role_id' => Role::where('name', 'Cashier')->value('id'), 'username' => 'history.staff', 'password' => 'HistoryStaffPassword', 'login_active' => true];
        $staff = $this->staff($values);
        $this->put(route('hr.staff.update', $staff), $this->data(array_merge($values, ['phone' => '0771234567', 'password' => null])))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, $staff->events()->where('type', 'PROFILE_UPDATE')->count());
        $this->assertSame('0771234567', $staff->events()->where('type', 'PROFILE_UPDATE')->first()->snapshot['phone']);
    }

    public function test_cash_report_includes_hr_payments_on_the_first_filter_day(): void
    {
        $staff = $this->staff();
        $this->post(route('hr.payments.store'), $this->payment($staff, ['date' => '2026-10-01']))->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('reports.show', ['report' => 'cash', 'from' => '2026-10-01', 'to' => '2026-10-01']))
            ->assertOk()->assertViewHas('cards', fn ($c) => (float) $c['Period Money Out'] === 1000.0);
        $this->get(route('reports.show', ['report' => 'cash', 'from' => '2026-10-02', 'to' => '2026-10-10']))
            ->assertOk()->assertViewHas('cards', fn ($c) => (float) $c['Period Money Out'] === 0.0);
    }

    public function test_approved_payroll_blocks_backdated_employment_changes_in_its_month(): void
    {
        $staff = $this->staff();
        $p = $this->payroll($staff);
        app(HrService::class)->approve($p);
        $this->postJson(route('hr.staff.employment', $staff), ['type' => 'EXIT', 'date' => '2026-09-15', 'reason' => 'Backdated exit'])->assertUnprocessable();
        $this->assertSame('ACTIVE', $staff->fresh()->status);
        $this->post(route('hr.staff.employment', $staff), ['type' => 'EXIT', 'date' => '2026-09-30', 'reason' => 'Month complete'])->assertRedirect()->assertSessionHasNoErrors();

        $other = $this->staff(['name' => 'Rehired employee']);
        app(HrService::class)->employment($other, ['type' => 'EXIT', 'date' => '2026-09-10', 'reason' => 'Contract ended']);
        app(HrService::class)->approve($this->payroll($other));
        $this->postJson(route('hr.staff.employment', $other), ['type' => 'REHIRE', 'date' => '2026-09-20', 'reason' => 'Backdated rehire'])->assertUnprocessable();
        $this->assertSame('EXITED', $other->fresh()->status);
    }

    public function test_attendance_screen_locks_only_staff_with_approved_payroll(): void
    {
        $locked = $this->staff(['name' => 'Approved staff']);
        $editable = $this->staff(['name' => 'Unapproved staff']);
        app(HrService::class)->approve($this->payroll($locked));
        $this->get(route('hr.attendance', ['date' => '2026-09-15']))->assertOk()
            ->assertViewHas('lockedStaff', fn ($ids) => $ids->contains($locked->id) && ! $ids->contains($editable->id))
            ->assertSee('Payroll locked');
        $this->post(route('hr.attendance.mark'), ['date' => '2026-09-15', 'rows' => [$editable->id => ['status' => 'PRESENT']]])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('hr_attendances', 1);
    }

    public function test_ledger_and_payment_dates_accept_single_bounds_and_reject_reversed_ranges(): void
    {
        $staff = $this->staff();
        foreach (['from', 'to'] as $bound) {
            $this->get(route('hr.staff.ledger', ['staff' => $staff, $bound => '2026-10-10']))->assertOk();
            $this->get(route('hr.payments', [$bound => '2026-10-10']))->assertOk();
        }
        $this->getJson(route('hr.staff.ledger', ['staff' => $staff, 'from' => '2026-10-10', 'to' => '2026-10-01']))->assertUnprocessable();
        $this->getJson(route('hr.payments', ['from' => '2026-10-10', 'to' => '2026-10-01']))->assertUnprocessable();
    }
}
