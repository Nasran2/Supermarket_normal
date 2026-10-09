# Optional HR module

HR manages employees. Customers remain in the existing customer/account module.

## Enable or hide HR

Set the requested key in `.env`:

```dotenv
hr_module=true
```

Use `hr_module=false` to disable it. After changing the flag, run:

```sh
php artisan optimize:clear
```

If deployment uses cached configuration, rebuild it with `php artisan config:cache`. New installations default to disabled. `HR_MODULE` is accepted as a fallback when the lowercase key is absent.

Disabled HR has no sidebar group, workspace tabs, report-library cards or role-editor permissions. Every HR URL, form submission and download returns 404, including direct links. Data and existing role grants are retained so re-enabling restores access. Previously posted salary expenses and cash movements remain in financial totals; disabling a module does not erase accounting history.

Run `php artisan migrate` when upgrading another installation. This installation already has the HR migration. The migration installs reference options and permissions without creating employees or changing existing passwords.

## Staff and access

**Human resources → Staff → Add staff** records intake date, permanent/contract/part-time/temporary employment, department, job title, shift, contacts, emergency contact, bank details, notes, monthly/daily salary and overtime rate. Staff codes are generated as `STF-00001`.

A staff member does not need a POS user account. Optionally choose an existing user role and provide a unique username and a password of at least 10 characters. An administrator can change the role, password and login status later. Blank passwords on edit retain the existing password. Account management also requires the existing Users create/edit permission; existing Administrator, self-access, role-grant and sales-visibility protections remain enforced. Creating a login does not invent a new role.

Use **Record exit** to record a departure with its date and reason. The linked login is deactivated; previous attendance, leave, payroll, payments and employment events remain available. **Rehire** adds a new employment event and keeps the login inactive until deliberately reactivated. Approved payroll prevents changes that would alter its employment period. Records are retained rather than physically deleted.

Staff status and login status are distinct. Inactive staff records remain in history; deactivate their linked login explicitly when suspending access. A login cannot be activated through the HR form while its staff record is inactive or exited. Exiting a staff record linked to a protected account follows the normal user-management restrictions.

## Attendance, shifts and leave

Select a day, find a staff member, choose a status and save. Up to 50 staff appear per page, keeping submissions below PHP's default input limit for larger teams. Save each page before switching dates/pages. Search filters the current page. **Mark unmarked as present** affects only visible, unmarked rows.

Statuses include Present, Late, Absent, Half day, paid/unpaid leave, Holiday and Rest day. Unmarked rows are left unchanged. **Clear saved attendance** retains an audited Unmarked record. Clock times and explicit overtime hours belong to worked days; overnight check-out is on the following day. Approved payroll locks attendance in that month. Staff who have exited can still be marked/viewed for dates during their employment.

Setup contains departments, job titles, shift schedules, holidays and leave types. A configured holiday is suggested on the attendance screen; saving attendance confirms it. Setup records can be deactivated while historical references remain intact.

Record a leave request with inclusive start/end dates and reason, then approve/reject it with a decision note. Approval checks employment periods, overlapping requests, worked attendance, locked payroll and the configured annual calendar-day allowance. Requests spanning years use the allowance in each calendar year. Zero allowance means no configured limit. Cancelling an approved request restores its saved leave attendance to Unmarked and retains the cancellation history. Configure your company policy before using the starter leave types.

## Payroll, advances and salary payments

1. Review attendance and employment dates, then prepare one payroll draft per staff member/month.
2. Add any number of earning/deduction lines up to 30, explicit overtime and advance recovery; review the saved breakdown.
3. Approve after month end. Changes to attendance, salary rates or employment days invalidate an older draft; void it and prepare a fresh one.
4. Record full or partial salary payments against the approved payroll. Due balances update automatically. Download the formatted payslip.

Monthly basic salary is prorated by calendar days employed in the month. Deducting marked unpaid days is optional and off by default. Daily basic salary uses saved paid attendance days. Present, Late, paid leave, Holiday and Rest day count as one paid day; Half day counts as half. Unmarked days are not automatically treated as absence. Overtime is entered explicitly and multiplied by the staff overtime rate.

Multiple allowances and deductions can be entered by name. Statutory taxes, EPF/ETF and jurisdiction-specific deductions are not calculated automatically; this release uses explicitly configured/entered company payroll amounts. It does not connect to a bank or biometric attendance device.

**Payments → Record advance** records money paid before salary settlement. Advance recovery cannot exceed outstanding advances. Salary payments cannot exceed salary due. Retried submissions with the same payment token do not create duplicate payments. Advance balances and salary dues remain separately visible.

Cash payouts require the operator's open register and create a cash-out movement. Reversing them requires that original register to remain open and belong to the operator, and records a compensating cash-in movement. Other methods use the saved payment-method name and reference. Reversed payments remain identifiable; an advance already recovered by payroll cannot be reversed until that recovery is released. To void paid payroll, reverse its payments first.

Approval records salary expense once, at month end: net salary plus recovered advance. Later payments settle the liability and do not create a second expense. Voiding unpaid payroll reverses that linked expense. Cash/account reports include active staff payouts; register reconciliation includes the corresponding cash movements.

## Ledgers and reports

Each staff profile links to its ledger, with date filters, opening balance, salary credits, payments/advances, running balance, salary due and outstanding advances. A positive ledger balance means the company owes staff; a negative balance means staff have an outstanding advance balance. PDF/CSV downloads preserve the selected dates.

Six HR reports cover Staff register, Attendance, Leave history, Payroll summary, Salary payments & advances, and Staff intake & exits. Quick date ranges and custom dates combine with staff, department and status filters. The staff register remains a complete directory; dates apply to event-based reports. Payroll filters select the months intersecting the chosen range. Department filters use the staff member's current department.

Every report has PDF and CSV downloads, including all matching pages. PDFs use the company logo/name/address/phone from **Business settings**, report title, filters, appropriate columns, financial totals where relevant, repeated table headers and page numbers. Payslips use the approved salary snapshot. Employment reports retain the staff name at each recorded event.

## Permissions and verification

The 41 HR permissions are independent of core POS permissions. Administrator receives them automatically whenever HR is enabled. Existing non-administrator roles receive no new HR access during upgrade; grant the desired pages, actions, salary/ledger access and individual report downloads in the role editor. Most actions require both the page's View permission and the action permission. Staff-profile salary amounts require Payroll view. HR events are kept out of the general POS audit report.

Verification on 10 October 2026 includes HR visibility and direct-route denial, optional login/password changes, permissions, employment history, attendance upsert/overnight shifts, a 105-person attendance list, leave approvals/cancellation, payroll proration/overtime/locking, advances/partial payments/idempotency, reversal and cash reconciliation, ledger filters, six PDF/CSV exports, and employment changes against approved payroll. The focused tests use disposable SQLite databases; screen/PDF previews use rolled-back fixtures in the separate QA database. No sample staff or salary transactions were left in the operational database.

The production build and compiled Blade syntax checks pass. The broader application suite still has the 28 pre-existing payment-fee expectation failures described in `PERMISSIONS.md`; those are separate from HR.
