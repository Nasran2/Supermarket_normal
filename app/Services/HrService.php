<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Hr\Attendance;
use App\Models\Hr\LeaveRequest;
use App\Models\Hr\Option;
use App\Models\Hr\Payroll;
use App\Models\Hr\Staff;
use App\Models\Hr\StaffPayment;
use App\Models\PaymentMethod;
use App\Models\Register;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HrService
{
    public function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public function employed(Staff $staff, string $date): bool
    {
        $event = $staff->relationLoaded('events')
            ? $staff->events->filter(fn ($event) => in_array($event->type, ['INTAKE', 'REHIRE', 'EXIT']) && $event->event_date->toDateString() <= $date)->sortByDesc(fn ($event) => $event->event_date->toDateString().sprintf('%020d', $event->id))->first()
            : $staff->events()->whereDate('event_date', '<=', $date)->whereIn('type', ['INTAKE', 'REHIRE', 'EXIT'])->orderByDesc('event_date')->orderByDesc('id')->first();
        if ($event) {
            return $event->type !== 'EXIT' || $event->event_date->toDateString() === $date;
        }

        return $staff->joined_on->toDateString() <= $date && (! $staff->left_on || $staff->left_on->toDateString() >= $date);
    }

    public function saveStaff(array $data, ?Staff $staff = null): Staff
    {
        return DB::transaction(function () use ($data, $staff) {
            $staff = $staff ? Staff::whereKey($staff->id)->lockForUpdate()->firstOrFail() : new Staff;
            $before = $staff->getAttributes();
            foreach (['department_id' => 'department', 'position_id' => 'position', 'shift_id' => 'shift'] as $key => $kind) {
                if (! empty($data[$key]) && ! Option::whereKey($data[$key])->where('kind', $kind)->exists()) {
                    $this->fail($key, 'Choose a valid '.$kind.'.');
                }
            }
            if ($staff->exists && $data['joined_on'] !== $staff->joined_on->toDateString() && ($staff->attendances()->exists() || $staff->leaves()->exists() || $staff->payrolls()->exists() || $staff->events()->where('type', '!=', 'INTAKE')->exists())) {
                $this->fail('joined_on', 'Intake date cannot change after attendance, payroll or employment events exist.');
            }
            if ($staff->exists && $staff->status === 'EXITED') {
                unset($data['status']);
            }
            $staff->fill(collect($data)->except(['role_id', 'username', 'password', 'login_active', 'account_email'])->all());
            $new = ! $staff->exists;
            $staff->save();
            if ($new) {
                $staff->update(['code' => 'STF-'.str_pad((string) $staff->id, 5, '0', STR_PAD_LEFT)]);
                $staff->events()->create(['type' => 'INTAKE', 'event_date' => $staff->joined_on, 'snapshot' => $staff->getAttributes(), 'user_id' => auth()->id()]);
            } elseif ($before['joined_on'] !== $staff->joined_on->toDateString()) {
                $staff->events()->where('type', 'INTAKE')->update(['event_date' => $staff->joined_on]);
            }
            if (array_key_exists('role_id', $data) && $data['role_id']) {
                abort_unless(auth()->user()->hasPermission('hr.staff.accounts'), 403);
                abort_unless(auth()->user()->hasPermission($staff->user_id ? 'users.edit' : 'users.create'), 403);
                $account = $staff->user_id ? User::findOrFail($staff->user_id) : null;
                if (! $account && empty($data['password'])) {
                    $this->fail('password', 'Set a password for a new staff login.');
                }
                $values = ['name' => $staff->name, 'username' => $data['username'], 'email' => ($data['account_email'] ?? null) ?: ($account?->email ?? 'staff-'.$staff->id.'-'.Str::lower(Str::random(12)).'@internal.invalid'), 'password' => $data['password'] ?? null, 'role_id' => $data['role_id'], 'active' => (bool) ($data['login_active'] ?? false)];
                if (in_array($staff->status, ['EXITED', 'INACTIVE']) && $values['active']) {
                    $this->fail('login_active', 'Activate or rehire the staff member before activating their login.');
                }
                $user = app(ResourceService::class)->save('users', $values, $account?->id);
                $staff->update(['user_id' => $user->id]);
            } elseif (array_key_exists('role_id', $data) && $staff->user_id) {
                $this->fail('role_id', 'Select the existing login role. Deactivate access using the login status instead of deleting its history.');
            }
            if (! $new && collect($before)->except(['updated_at'])->all() !== collect($staff->getAttributes())->except(['updated_at'])->all()) {
                $staff->events()->create(['type' => 'PROFILE_UPDATE', 'event_date' => today(), 'reason' => 'Staff details updated', 'snapshot' => $staff->getAttributes(), 'user_id' => auth()->id()]);
            }
            Audit::record('hr.staff.save', $staff, $before, $staff->getAttributes());

            return $staff;
        }, 3);
    }

    public function employment(Staff $staff, array $data): void
    {
        DB::transaction(function () use ($staff, $data) {
            $staff = Staff::whereKey($staff->id)->lockForUpdate()->firstOrFail();
            $date = $data['date'];
            $type = $data['type'];
            $latest = $staff->events()->whereIn('type', ['INTAKE', 'EXIT', 'REHIRE'])->orderByDesc('event_date')->orderByDesc('id')->first();
            if ($date < ($latest?->event_date->toDateString() ?? '0000-00-00') || ($type === 'REHIRE' && $date === ($latest?->event_date->toDateString()))) {
                $this->fail('date', 'Choose a date after the last employment event.');
            }
            if (($type === 'EXIT' && $staff->status === 'EXITED') || ($type === 'REHIRE' && $staff->status !== 'EXITED')) {
                $this->fail('type', 'This employment change is not available for the current status.');
            }
            $month = Carbon::parse($date)->startOfMonth()->toDateString();
            if (($type === 'REHIRE' || $date !== Carbon::parse($date)->endOfMonth()->toDateString()) && $staff->payrolls()->where('period', $month)->where('status', 'APPROVED')->exists()) {
                $this->fail('date', 'Approved payroll covers this employment change. Reverse its payments and void it before changing the employment period.');
            }
            if ($type === 'EXIT' && ($staff->attendances()->where('status', '!=', 'UNMARKED')->whereDate('date', '>', $date)->exists() || $staff->leaves()->where('status', 'APPROVED')->whereDate('to', '>', $date)->exists() || $staff->payrolls()->where('status', '!=', 'VOIDED')->whereDate('period', '>', Carbon::parse($date)->startOfMonth())->exists())) {
                $this->fail('date', 'Later attendance, approved leave or payroll exists. Correct those records before backdating the exit.');
            }
            if ($type === 'EXIT' && $staff->user_id) {
                $user = User::with('role.permissions')->findOrFail($staff->user_id);
                // Preserve the protected Administrator and self-access checks in user management.
                app(ResourceService::class)->save('users', ['name' => $user->name, 'username' => $user->username, 'email' => $user->email, 'role_id' => $user->role_id, 'active' => false], $user->id);
            }
            $before = $staff->getAttributes();
            $staff->update(['status' => $type === 'EXIT' ? 'EXITED' : 'ACTIVE', 'left_on' => $type === 'EXIT' ? $date : null]);
            $staff->events()->create(['type' => $type, 'event_date' => $date, 'reason' => $data['reason'], 'snapshot' => $staff->getAttributes(), 'user_id' => auth()->id()]);
            Audit::record('hr.staff.'.strtolower($type), $staff, $before, $staff->getAttributes());
        }, 3);
    }

    public function markAttendance(string $date, array $rows): void
    {
        DB::transaction(function () use ($date, $rows) {
            foreach ($rows as $id => $row) {
                if (empty($row['status'])) {
                    continue;
                }
                $staff = Staff::whereKey($id)->lockForUpdate()->firstOrFail();
                if (! $this->employed($staff, $date)) {
                    $this->fail('date', $staff->name.' was not employed on this date.');
                }
                if ($staff->payrolls()->where('period', Carbon::parse($date)->startOfMonth()->toDateString())->where('status', 'APPROVED')->exists()) {
                    $this->fail('date', 'Attendance is locked by approved payroll for '.$staff->name.'.');
                }
                $leave = $staff->leaves()->where('status', 'APPROVED')->whereDate('from', '<=', $date)->whereDate('to', '>=', $date)->first();
                if ($leave && $row['status'] !== ($leave->paid ? 'PAID_LEAVE' : 'UNPAID_LEAVE')) {
                    $this->fail('rows', 'Approved leave exists for '.$staff->name.'. Use its leave status.');
                }
                if (! $leave && in_array($row['status'], ['PAID_LEAVE', 'UNPAID_LEAVE'])) {
                    $this->fail('rows', 'Approve a leave request before marking a leave day.');
                }
                $in = ! empty($row['check_in']) ? Carbon::parse($date.' '.$row['check_in']) : null;
                $out = ! empty($row['check_out']) ? Carbon::parse($date.' '.$row['check_out'])->addDays(! empty($row['overnight']) ? 1 : 0) : null;
                if ($out && (! $in || $out <= $in)) {
                    $this->fail('rows', 'Check-out must follow check-in; select overnight for a shift ending the next day.');
                }
                if (Money::compare((string) ($row['overtime_hours'] ?? 0), 0) > 0 && ! in_array($row['status'], ['PRESENT', 'LATE', 'HALF_DAY'])) {
                    $this->fail('rows', 'Overtime can only be recorded on worked days.');
                }
                if ($in && ! in_array($row['status'], ['PRESENT', 'LATE', 'HALF_DAY'])) {
                    $this->fail('rows', 'Only worked days can have clock times.');
                }
                $record = Attendance::firstOrNew(['staff_id' => $id, 'date' => $date]);
                $before = $record->getAttributes();
                $record->fill(['status' => $row['status'], 'check_in' => $in, 'check_out' => $out, 'overtime_hours' => $row['overtime_hours'] ?? 0, 'notes' => $row['notes'] ?? null, 'user_id' => auth()->id()])->save();
                Audit::record('hr.attendance.mark', $record, $before, $record->getAttributes());
            }
        }, 3);
    }

    public function leave(array $data): LeaveRequest
    {
        return DB::transaction(function () use ($data) {
            $staff = Staff::whereKey($data['staff_id'])->lockForUpdate()->firstOrFail();
            $type = Option::whereKey($data['leave_type_id'])->where('kind', 'leave_type')->where('active', true)->firstOrFail();
            $from = Carbon::parse($data['from']);
            $to = Carbon::parse($data['to']);
            foreach (new \DatePeriod($from->toDate(), new \DateInterval('P1D'), $to->copy()->addDay()->toDate()) as $day) {
                if (! $this->employed($staff, $day->format('Y-m-d'))) {
                    $this->fail('from', 'Leave must fall within an employment period.');
                }
            }
            if ($staff->leaves()->whereIn('status', ['PENDING', 'APPROVED'])->whereDate('from', '<=', $data['to'])->whereDate('to', '>=', $data['from'])->exists()) {
                $this->fail('from', 'A pending or approved leave request overlaps these dates.');
            }
            $row = LeaveRequest::create($data + ['paid' => (bool) ($type->settings['paid'] ?? false), 'days' => $from->diffInDays($to) + 1, 'user_id' => auth()->id()]);
            Audit::record('hr.leave.request', $row, [], $row->toArray());

            return $row;
        }, 3);
    }

    public function decideLeave(LeaveRequest $leave, array $data): void
    {
        DB::transaction(function () use ($leave, $data) {
            $staff = Staff::whereKey($leave->staff_id)->lockForUpdate()->firstOrFail();
            $leave = LeaveRequest::whereKey($leave->id)->lockForUpdate()->firstOrFail();
            if ($leave->status !== 'PENDING') {
                $this->fail('status', 'This leave request has already been decided.');
            }
            if ($data['status'] === 'APPROVED') {
                for ($day = $leave->from->copy(); $day <= $leave->to; $day->addDay()) {
                    if (! $this->employed($staff, $day->toDateString())) {
                        $this->fail('status', 'Leave now falls outside the employment period.');
                    }
                }
                if (Payroll::where('staff_id', $leave->staff_id)->where('status', 'APPROVED')->whereBetween('period', [Carbon::parse($leave->from)->startOfMonth()->toDateString(), Carbon::parse($leave->to)->startOfMonth()->toDateString()])->exists()) {
                    $this->fail('status', 'Payroll is already approved for this period.');
                }
                if (Attendance::where('staff_id', $leave->staff_id)->whereBetween('date', [$leave->from->toDateString(), $leave->to->toDateString()])->whereIn('status', ['PRESENT', 'LATE', 'HALF_DAY'])->exists()) {
                    $this->fail('status', 'Worked attendance conflicts with this leave request. Correct it first.');
                }
                $limit = (int) ($leave->leaveType->settings['annual_days'] ?? 0);
                if ($limit > 0) {
                    foreach (range($leave->from->year, $leave->to->year) as $year) {
                        $start = Carbon::create($year, 1, 1)->max($leave->from);
                        $end = Carbon::create($year, 12, 31)->min($leave->to);
                        $used = 0;
                        foreach (LeaveRequest::where('staff_id', $leave->staff_id)->where('leave_type_id', $leave->leave_type_id)->where('status', 'APPROVED')->where('from', '<=', "$year-12-31")->where('to', '>=', "$year-01-01")->get() as $l) {
                            $used += Carbon::parse($l->from)->max(Carbon::create($year, 1, 1))->diffInDays(Carbon::parse($l->to)->min(Carbon::create($year, 12, 31))) + 1;
                        }
                        if ($used + $start->diffInDays($end) + 1 > $limit) {
                            $this->fail('status', 'This leave exceeds the configured annual allowance.');
                        }
                    }
                }
                Attendance::where('staff_id', $leave->staff_id)->whereBetween('date', [$leave->from->toDateString(), $leave->to->toDateString()])->update(['status' => $leave->paid ? 'PAID_LEAVE' : 'UNPAID_LEAVE']);
            }
            $before = $leave->getAttributes();
            $leave->update($data + ['decided_by' => auth()->id()]);
            Audit::record('hr.leave.decide', $leave, $before, $leave->getAttributes());
        }, 3);
    }

    public function cancelLeave(LeaveRequest $leave, string $reason): void
    {
        DB::transaction(function () use ($leave, $reason) {
            Staff::whereKey($leave->staff_id)->lockForUpdate()->firstOrFail();
            $leave = LeaveRequest::whereKey($leave->id)->lockForUpdate()->firstOrFail();
            if (! in_array($leave->status, ['PENDING', 'APPROVED'])) {
                $this->fail('status', 'Only pending or approved leave can be cancelled.');
            }
            if ($leave->status === 'APPROVED') {
                if (Payroll::where('staff_id', $leave->staff_id)->where('status', 'APPROVED')->whereBetween('period', [$leave->from->copy()->startOfMonth()->toDateString(), $leave->to->copy()->startOfMonth()->toDateString()])->exists()) {
                    $this->fail('status', 'Approved payroll locks this leave period.');
                }
                foreach (Attendance::where('staff_id', $leave->staff_id)->whereBetween('date', [$leave->from->toDateString(), $leave->to->toDateString()])->whereIn('status', ['PAID_LEAVE', 'UNPAID_LEAVE'])->get() as $attendance) {
                    $before = $attendance->getAttributes();
                    $attendance->update(['status' => 'UNMARKED', 'user_id' => auth()->id()]);
                    Audit::record('hr.attendance.leave_cancel', $attendance, $before, $attendance->getAttributes());
                }
            }
            $before = $leave->getAttributes();
            $leave->update(['status' => 'CANCELLED', 'decision_note' => $reason, 'decided_by' => auth()->id()]);
            Audit::record('hr.leave.cancel', $leave, $before, $leave->getAttributes());
        }, 3);
    }

    public function attendanceSummary(Staff $staff, string $period): array
    {
        $from = Carbon::parse($period)->startOfMonth();
        $to = $from->copy()->endOfMonth();
        $records = $staff->attendances()->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get();
        $leaves = $staff->leaves()->where('status', 'APPROVED')->whereDate('from', '<=', $to)->whereDate('to', '>=', $from)->get();
        $summary = ['paid_days' => '0.00', 'unpaid_days' => '0.00', 'overtime_hours' => '0.00', 'marked_days' => $records->where('status', '!=', 'UNMARKED')->count(), 'days_in_month' => $from->daysInMonth, 'rate' => $staff->salary_rate, 'basis' => $staff->salary_basis, 'overtime_rate' => $staff->overtime_rate, 'employed_days' => 0];
        for ($day = $from->copy(); $day <= $to; $day->addDay()) {
            $date = $day->toDateString();
            if (! $this->employed($staff, $date)) {
                continue;
            }
            $summary['employed_days']++;
            $row = $records->first(fn ($r) => $r->date->toDateString() === $date);
            $leave = $leaves->first(fn ($l) => $l->from->toDateString() <= $date && $l->to->toDateString() >= $date);
            $paid = $leave ? ($leave->paid ? '1' : '0') : match ($row?->status) {
                'PRESENT','LATE','PAID_LEAVE','HOLIDAY','OFF' => '1','HALF_DAY' => '0.5',default => '0'
            };
            $unpaid = $leave ? ($leave->paid ? '0' : '1') : match ($row?->status) {
                'ABSENT','UNPAID_LEAVE' => '1','HALF_DAY' => '0.5',default => '0'
            };
            $summary['paid_days'] = Money::add($summary['paid_days'], $paid);
            $summary['unpaid_days'] = Money::add($summary['unpaid_days'], $unpaid);
            $summary['overtime_hours'] = Money::add($summary['overtime_hours'], $row?->overtime_hours ?? '0');
        }

        return $summary;
    }

    public function advanceDue(Staff $staff): string
    {
        return Money::sub((string) $staff->payments()->where('kind', 'ADVANCE')->where('status', 'ACTIVE')->sum('amount'), (string) $staff->payrolls()->where('status', 'APPROVED')->sum('advance_recovery'));
    }

    public function preparePayroll(array $data): Payroll
    {
        return DB::transaction(function () use ($data) {
            $staff = Staff::whereKey($data['staff_id'])->lockForUpdate()->firstOrFail();
            $period = Carbon::createFromFormat('!Y-m', $data['month'])->startOfMonth();
            if ($staff->payrolls()->where('period', $period->toDateString())->where('status', '!=', 'VOIDED')->exists()) {
                $this->fail('month', 'Payroll already exists for this staff member and month.');
            }
            $summary = $this->attendanceSummary($staff, $period->toDateString());
            $employedDays = $summary['employed_days'];
            if (! $employedDays) {
                $this->fail('month', 'No employment days in this month.');
            }
            // Monthly pay is prorated by calendar employment days; only explicitly marked unpaid days are deducted.
            $daily = BigDecimal::of($staff->salary_rate)->dividedBy($period->daysInMonth, 8, RoundingMode::HALF_UP);
            $basic = $staff->salary_basis === 'DAILY' ? Money::mul($staff->salary_rate, $summary['paid_days']) : Money::round((string) $daily->multipliedBy($employedDays));
            $lines = [['kind' => 'EARNING', 'name' => 'Basic salary', 'amount' => $basic]];
            $overtime = Money::mul($summary['overtime_hours'], $staff->overtime_rate);
            if (Money::compare($overtime, 0) > 0) {
                $lines[] = ['kind' => 'EARNING', 'name' => 'Overtime', 'amount' => $overtime];
            }
            if (! empty($data['deduct_unpaid']) && $staff->salary_basis === 'MONTHLY') {
                $lines[] = ['kind' => 'DEDUCTION', 'name' => 'Marked unpaid days', 'amount' => Money::round((string) $daily->multipliedBy($summary['unpaid_days']))];
            }
            foreach ($data['lines'] ?? [] as $line) {
                if (! empty($line['name']) && Money::compare((string) $line['amount'], 0) > 0) {
                    $lines[] = ['kind' => $line['kind'], 'name' => $line['name'], 'amount' => Money::round((string) $line['amount'])];
                }
            }
            $earnings = Money::sum(collect($lines)->where('kind', 'EARNING')->pluck('amount'));
            $deductions = Money::sum(collect($lines)->where('kind', 'DEDUCTION')->pluck('amount'));
            $recovery = Money::round((string) ($data['advance_recovery'] ?? 0));
            if (Money::compare($recovery, $this->advanceDue($staff)) > 0) {
                $this->fail('advance_recovery', 'Recovery exceeds outstanding advances.');
            }
            $net = Money::sub(Money::sub($earnings, $deductions), $recovery);
            if (Money::compare($net, 0) < 0) {
                $this->fail('lines', 'Deductions and recovery cannot exceed earnings.');
            }
            $row = Payroll::create(['staff_id' => $staff->id, 'period' => $period->toDateString(), 'staff_name' => $staff->name, 'staff_code' => $staff->code, 'basic' => $basic, 'earnings' => $earnings, 'deductions' => $deductions, 'advance_recovery' => $recovery, 'net' => $net, 'lines' => $lines, 'attendance_snapshot' => $summary + ['employed_days' => $employedDays], 'notes' => $data['notes'] ?? null, 'user_id' => auth()->id(), 'live_key' => $staff->id.':'.$period->format('Y-m')]);
            $row->update(['reference' => 'PAY-'.$period->format('Ym').'-'.str_pad((string) $row->id, 5, '0', STR_PAD_LEFT)]);
            Audit::record('hr.payroll.prepare', $row, [], $row->toArray());

            return $row;
        }, 3);
    }

    public function approve(Payroll $payroll): void
    {
        DB::transaction(function () use ($payroll) {
            $staff = Staff::whereKey($payroll->staff_id)->lockForUpdate()->firstOrFail();
            $payroll = Payroll::whereKey($payroll->id)->lockForUpdate()->firstOrFail();
            if ($payroll->status !== 'DRAFT') {
                $this->fail('payroll', 'Only a draft payroll can be approved.');
            }
            if (Money::compare($payroll->advance_recovery, $this->advanceDue($staff)) > 0) {
                $this->fail('advance_recovery', 'Advance balance has changed. Void this draft and prepare it again.');
            }
            if ($payroll->attendance_snapshot != $this->attendanceSummary($staff, $payroll->period->toDateString())) {
                $this->fail('payroll', 'Attendance or salary rates changed. Void this draft and prepare it again.');
            }
            if ($payroll->period->copy()->endOfMonth()->toDateString() > today()->toDateString()) {
                $this->fail('payroll', 'Approve payroll after the month has ended, so future attendance is not locked. Use an advance for early payments.');
            }
            $category = ExpenseCategory::firstOrCreate(['name' => 'Staff payroll'], ['system' => true]);
            $expense = Expense::create(['expense_category_id' => $category->id, 'user_id' => auth()->id(), 'type' => 'HR_PAYROLL', 'status' => 'ACTIVE', 'expense_date' => $payroll->period->copy()->endOfMonth(), 'reference' => $payroll->reference, 'description' => 'Approved payroll '.$payroll->reference, 'amount' => Money::add($payroll->net, $payroll->advance_recovery)]);
            $payroll->update(['status' => 'APPROVED', 'approved_by' => auth()->id(), 'approved_at' => now(), 'expense_id' => $expense->id]);
            Audit::record('hr.payroll.approve', $payroll, [], $payroll->toArray());
        }, 3);
    }

    public function voidPayroll(Payroll $payroll, string $reason): void
    {
        DB::transaction(function () use ($payroll, $reason) {
            Staff::whereKey($payroll->staff_id)->lockForUpdate()->firstOrFail();
            $row = Payroll::whereKey($payroll->id)->lockForUpdate()->firstOrFail();
            if ($row->status === 'VOIDED' || Money::compare($row->paid, 0) > 0) {
                $this->fail('payroll', 'Reverse salary payments before voiding payroll.');
            }
            if ($row->expense_id) {
                Expense::whereKey($row->expense_id)->update(['status' => 'REVERSED']);
            }
            $row->update(['status' => 'VOIDED', 'live_key' => null, 'notes' => ($row->notes ? $row->notes."\n" : '').'Voided: '.$reason]);
            Audit::record('hr.payroll.void', $row, [], $row->toArray());
        }, 3);
    }

    public function pay(array $data): StaffPayment
    {
        return DB::transaction(function () use ($data) {
            $staff = Staff::whereKey($data['staff_id'])->lockForUpdate()->firstOrFail();
            if ($old = StaffPayment::where('token', $data['token'])->first()) {
                if ($old->staff_id !== $staff->id || $old->kind !== $data['kind'] || $old->payroll_id != ($data['payroll_id'] ?? null) || Money::compare($old->amount, (string) $data['amount']) !== 0 || $old->payment_method_id != $data['payment_method_id'] || $old->date->toDateString() !== $data['date'] || $old->user_id !== auth()->id()) {
                    $this->fail('token', 'This submission token was used for a different payment.');
                }

                return $old;
            }
            if ($data['date'] < $staff->joined_on->toDateString()) {
                $this->fail('date', 'Staff payments cannot predate intake.');
            }
            if ($data['kind'] === 'SALARY') {
                $payroll = Payroll::whereKey($data['payroll_id'] ?? 0)->where('staff_id', $staff->id)->lockForUpdate()->firstOrFail();
                if ($payroll->status !== 'APPROVED') {
                    $this->fail('payroll_id', 'Approve payroll before recording salary payments.');
                }
                if (Money::compare((string) $data['amount'], $payroll->due) > 0) {
                    $this->fail('amount', 'Payment exceeds the remaining salary due.');
                }
            } else {
                if (! empty($data['payroll_id'])) {
                    $this->fail('payroll_id', 'Advances are not linked to a payroll.');
                }
                if ($staff->status === 'EXITED' || ! $this->employed($staff, $data['date'])) {
                    $this->fail('staff_id', 'Exited staff cannot receive new advances.');
                }
            }
            $method = PaymentMethod::whereKey($data['payment_method_id'])->where('active', true)->firstOrFail();
            $register = $method->type === 'CASH' ? app(RegisterService::class)->current(auth()->id(), true) : null;
            if ($method->type === 'CASH' && ! $register) {
                $this->fail('payment_method_id', 'Open your register before making a cash payment.');
            }
            $row = StaffPayment::create($data + ['method_name' => $method->name, 'user_id' => auth()->id(), 'register_id' => $register?->id]);
            if ($register) {
                $register->movements()->create(['user_id' => auth()->id(), 'type' => 'OUT', 'amount' => $row->amount, 'description' => 'HR payment #'.$row->id]);
            }
            Audit::record('hr.payment.record', $row, [], $row->toArray());

            return $row;
        }, 3);
    }

    public function reversePayment(StaffPayment $payment, string $reason): void
    {
        DB::transaction(function () use ($payment, $reason) {
            $staff = Staff::whereKey($payment->staff_id)->lockForUpdate()->firstOrFail();
            $payment = StaffPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status !== 'ACTIVE') {
                $this->fail('payment', 'Payment is already reversed.');
            }
            if ($payment->kind === 'ADVANCE' && Money::compare($payment->amount, $this->advanceDue($staff)) > 0) {
                $this->fail('payment', 'This advance has been recovered in approved payroll. Void that payroll first.');
            }
            if ($payment->register_id) {
                $r = Register::whereKey($payment->register_id)->lockForUpdate()->firstOrFail();
                if ($r->closed_at || $r->user_id !== auth()->id()) {
                    $this->fail('payment', 'Reverse cash payments only from their original open register.');
                }
                $r->movements()->create(['user_id' => auth()->id(), 'type' => 'IN', 'amount' => $payment->amount, 'description' => 'Reversal of HR payment #'.$payment->id]);
            }
            $payment->update(['status' => 'REVERSED', 'reversal_reason' => $reason]);
            Audit::record('hr.payment.reverse', $payment, [], $payment->toArray());
        }, 3);
    }

    public function ledger(Staff $staff): array
    {
        $entries = collect();
        foreach ($staff->payrolls()->where('status', 'APPROVED')->get() as $p) {
            $entries->push(['date' => $p->period->copy()->endOfMonth()->toDateString(), 'sort' => '0-'.$p->id, 'reference' => $p->reference, 'description' => 'Salary earned (before advance recovery)', 'credit' => Money::add($p->net, $p->advance_recovery), 'debit' => '0.00']);
        }
        foreach ($staff->payments()->where('status', 'ACTIVE')->get() as $p) {
            $entries->push(['date' => $p->date->toDateString(), 'sort' => '1-'.$p->id, 'reference' => 'HRP-'.$p->id, 'description' => ucfirst(strtolower($p->kind)).' · '.$p->method_name, 'credit' => '0.00', 'debit' => $p->amount]);
        }
        $running = '0.00';
        $entries = $entries->sortBy(fn ($r) => $r['date'].' '.$r['sort'])->values()->map(function ($row) use (&$running) {
            $running = Money::add($running, Money::sub($row['credit'], $row['debit']));

            return $row + ['balance' => $running];
        });
        $net = (string) $staff->payrolls()->where('status', 'APPROVED')->sum('net');
        $paid = (string) $staff->payments()->where('status', 'ACTIVE')->where('kind', 'SALARY')->sum('amount');

        return ['entries' => $entries, 'salary_due' => Money::sub($net, $paid), 'advances' => $this->advanceDue($staff), 'balance' => $running];
    }
}
