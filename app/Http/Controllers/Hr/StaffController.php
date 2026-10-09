<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\Option;
use App\Models\Hr\Staff;
use App\Models\Role;
use App\Services\HrService;
use App\Services\PdfReportService;
use App\Support\SalesVisibility;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StaffController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'status' => 'nullable|in:ACTIVE,INACTIVE,EXITED', 'department_id' => 'nullable|integer|exists:hr_options,id']);
        $rows = Staff::with('department', 'position', 'user.role')->when($filters['q'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('name', 'like', "%$v%")->orWhere('code', 'like', "%$v%")->orWhere('phone', 'like', "%$v%")))->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->when($filters['department_id'] ?? null, fn ($q, $v) => $q->where('department_id', $v))->orderBy('name')->paginate(20)->withQueryString();
        $departments = Option::where('kind', 'department')->get();

        return view('hr.staff-index', compact('rows', 'filters', 'departments'));
    }

    public function create()
    {
        return $this->form(new Staff(['joined_on' => today(), 'salary_basis' => 'MONTHLY', 'salary_rate' => 0, 'overtime_rate' => 0, 'employment_type' => 'PERMANENT', 'status' => 'ACTIVE']));
    }

    public function edit(Staff $staff)
    {
        return $this->form($staff);
    }

    private function form(Staff $staff)
    {
        $options = Option::orderBy('name')->get()->groupBy('kind');
        $roles = Role::with('permissions')->get()->filter(fn ($r) => (auth()->user()->isAdministrator() || ! ($r->system && $r->name === 'Administrator')) && SalesVisibility::canGrant($r->sales_visibility, $r) && (auth()->user()->isAdministrator() || ! $r->permissions->pluck('id')->diff(auth()->user()->role->permissions->pluck('id'))->count()));

        return view('hr.staff-form', compact('staff', 'options', 'roles'));
    }

    private function data(Request $request, ?Staff $staff = null): array
    {
        if (is_string($request->username)) {
            $request->merge(['username' => strtolower(trim($request->username))]);
        }
        $rules = ['status' => 'sometimes|in:ACTIVE,INACTIVE', 'name' => 'required|string|max:150', 'phone' => 'nullable|string|max:50', 'email' => 'nullable|email|max:150', 'address' => 'nullable|string|max:1000', 'emergency_contact' => 'nullable|string|max:150', 'emergency_phone' => 'nullable|string|max:50', 'bank_details' => 'nullable|string|max:500', 'notes' => 'nullable|string|max:2000', 'joined_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'department_id' => 'nullable|integer|exists:hr_options,id', 'position_id' => 'nullable|integer|exists:hr_options,id', 'shift_id' => 'nullable|integer|exists:hr_options,id', 'employment_type' => 'required|in:PERMANENT,CONTRACT,PART_TIME,TEMPORARY', 'salary_basis' => 'required|in:MONTHLY,DAILY', 'salary_rate' => 'required|numeric|min:0|max:99999999|decimal:0,2', 'overtime_rate' => 'required|numeric|min:0|max:999999|decimal:0,2'];
        if ($request->filled('role_id') || $request->boolean('account_present')) {
            $rules += ['role_id' => 'nullable|integer|exists:roles,id', 'username' => ['required_with:role_id', 'nullable', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9._-]*$/', Rule::unique('users', 'username')->ignore($staff?->user_id)], 'password' => 'nullable|string|min:10|max:128', 'account_email' => ['nullable', 'email', 'max:150', Rule::unique('users', 'email')->ignore($staff?->user_id)], 'login_active' => 'required|boolean'];
            $request->merge(['login_active' => $request->boolean('login_active')]);
        }

        return $request->validate($rules);
    }

    public function store(Request $request, HrService $service)
    {
        $staff = $service->saveStaff($this->data($request));

        return redirect()->route('hr.staff.show', $staff)->with('success', 'Staff member added.');
    }

    public function update(Request $request, Staff $staff, HrService $service)
    {
        $service->saveStaff($this->data($request, $staff), $staff);

        return redirect()->route('hr.staff.show', $staff)->with('success', 'Staff details saved.');
    }

    public function show(Staff $staff, HrService $service)
    {
        $staff->load('department', 'position', 'shift', 'user.role');
        $events = $staff->events()->with('user')->orderByDesc('event_date')->orderByDesc('id')->get();
        $payrolls = auth()->user()->hasPermission('hr.payroll.view') ? $staff->payrolls()->latest('period')->get() : collect();
        $balances = auth()->user()->hasPermission('hr.ledger.view') ? $service->ledger($staff) : null;

        return view('hr.staff-show', compact('staff', 'events', 'payrolls', 'balances'));
    }

    public function employment(Request $request, Staff $staff, HrService $service)
    {
        $data = $request->validate(['type' => 'required|in:EXIT,REHIRE', 'date' => 'required|date_format:Y-m-d|before_or_equal:today', 'reason' => 'required|string|max:1000']);
        $service->employment($staff, $data);

        return back()->with('success', 'Employment history updated. An exited staff login is deactivated; rehiring does not reactivate it automatically.');
    }

    public function ledger(Request $request, Staff $staff, HrService $service)
    {
        $filters = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])], 'format' => 'nullable|in:pdf,csv']);
        $data = $service->ledger($staff);
        $opening = '0.00';
        $entries = $data['entries']->filter(function ($row) use ($filters, &$opening) {
            if (! empty($filters['from']) && $row['date'] < $filters['from']) {
                $opening = $row['balance'];

                return false;
            }

            return empty($filters['to']) || $row['date'] <= $filters['to'];
        })->values();
        $closing = $entries->last()['balance'] ?? $opening;
        if (! empty($filters['format'])) {
            abort_unless(auth()->user()->hasPermission('hr.ledger.export'), 403);
            $headers = ['Date', 'Reference', 'Description', 'Salary earned', 'Paid / advanced', 'Balance'];
            $rows = $entries->map(fn ($r) => [$r['date'], $r['reference'], $r['description'], $r['credit'], $r['debit'], $r['balance']]);
            if ($filters['format'] === 'csv') {
                return app(ReportController::class)->csv('staff-ledger-'.$staff->code, $headers, $rows);
            }

            return app(PdfReportService::class)->download('staff-ledger-'.$staff->code, ['title' => 'Staff ledger', 'subtitle' => $staff->code.' · '.$staff->name, 'headers' => $headers, 'rows' => $rows, 'filters' => $filters, 'cards' => ['Opening' => $opening, 'Closing' => $closing], 'notes' => 'Positive balance: company owes staff. Negative balance: outstanding advance. Salary due and advance outstanding are tracked separately.']);
        }

        return view('hr.ledger', compact('staff', 'filters', 'entries', 'opening', 'closing', 'data'));
    }
}
