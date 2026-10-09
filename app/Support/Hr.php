<?php

namespace App\Support;

final class Hr
{
    public const REPORTS = ['staff' => 'Staff register', 'attendance' => 'Attendance', 'leave' => 'Leave history', 'payroll' => 'Payroll summary', 'payments' => 'Salary payments & advances', 'employment' => 'Staff intake & exits'];

    public const SETUP = ['department' => 'Departments', 'position' => 'Job titles', 'shift' => 'Shifts', 'leave_type' => 'Leave types', 'holiday' => 'Holidays'];

    public static function enabled(): bool
    {
        return (bool) config('hr.enabled', false);
    }

    public static function permissions(): array
    {
        $rows = ['hr.view' => ['HR overview', 'Open HR workspace'], 'hr.staff.view' => ['HR · Staff', 'View staff'], 'hr.staff.create' => ['HR · Staff', 'Add staff'], 'hr.staff.edit' => ['HR · Staff', 'Edit staff details & salary rates'], 'hr.staff.exit' => ['HR · Staff', 'Record staff exit or rehire'], 'hr.staff.accounts' => ['HR · Staff', 'Manage optional staff logins'], 'hr.attendance.view' => ['HR · Attendance', 'View attendance'], 'hr.attendance.mark' => ['HR · Attendance', 'Mark or correct attendance'], 'hr.leave.view' => ['HR · Leave', 'View leave requests'], 'hr.leave.create' => ['HR · Leave', 'Record leave requests'], 'hr.leave.cancel' => ['HR · Leave', 'Cancel leave while retaining its history'], 'hr.leave.approve' => ['HR · Leave', 'Approve or reject leave'], 'hr.payroll.view' => ['HR · Payroll', 'View payroll & salary amounts'], 'hr.payroll.create' => ['HR · Payroll', 'Prepare payroll'], 'hr.payroll.approve' => ['HR · Payroll', 'Approve payroll'], 'hr.payroll.void' => ['HR · Payroll', 'Void unpaid payroll'], 'hr.payments.view' => ['HR · Payments', 'View staff payments & advances'], 'hr.payments.create' => ['HR · Payments', 'Pay salaries or advances'], 'hr.payments.reverse' => ['HR · Payments', 'Reverse staff payments'], 'hr.ledger.view' => ['HR · Ledgers', 'View staff ledgers'], 'hr.ledger.export' => ['HR · Ledgers', 'Download staff ledgers'], 'hr.setup.view' => ['HR · Setup', 'View HR setup'], 'hr.setup.manage' => ['HR · Setup', 'Manage departments, job titles, shifts, holidays & leave types']];
        foreach (self::REPORTS as $kind => $label) {
            foreach (['view' => 'View report', 'pdf' => 'Download PDF', 'export' => 'Export CSV'] as $action => $name) {
                $rows['hr.reports.'.$kind.'.'.$action] = ['HR reports · '.$label, $name];
            }
        }

        return $rows;
    }
}
