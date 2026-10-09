<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\Attendance;
use App\Models\Hr\LeaveRequest;
use App\Models\Hr\Option;
use App\Models\Hr\Payroll;
use App\Models\Hr\Staff;
use App\Services\HrService;
use App\Support\Audit;
use App\Support\Hr;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkforceController extends Controller
{
    public function index()
    {
        $counts = ['active' => auth()->user()->hasPermission('hr.staff.view') ? Staff::where('status', 'ACTIVE')->count() : null, 'present' => auth()->user()->hasPermission('hr.attendance.view') ? Attendance::whereDate('date', today())->whereIn('status', ['PRESENT', 'LATE', 'HALF_DAY'])->count() : null, 'pending' => auth()->user()->hasPermission('hr.leave.view') ? LeaveRequest::where('status', 'PENDING')->count() : null, 'payroll' => auth()->user()->hasPermission('hr.payroll.view') ? Payroll::where('status', 'DRAFT')->count() : null];

        return view('hr.index', compact('counts'));
    }

    public function attendance(Request $request, HrService $service)
    {
        $date = $request->validate(['date' => 'nullable|date_format:Y-m-d|before_or_equal:today'])['date'] ?? today()->toDateString();
        $eligible = Staff::with('events')->get()->filter(fn ($s) => $service->employed($s, $date))->modelKeys();
        // Keep each submission below PHP's default input limit, even with large teams.
        $staff = Staff::with('department', 'shift')->whereIn('id', $eligible)->orderBy('name')->paginate(50)->withQueryString();
        $records = Attendance::whereDate('date', $date)->get()->keyBy('staff_id');
        $lockedStaff = Payroll::where('period', Carbon::parse($date)->startOfMonth()->toDateString())->where('status', 'APPROVED')->pluck('staff_id');
        $leaves = LeaveRequest::where('status', 'APPROVED')->whereDate('from', '<=', $date)->whereDate('to', '>=', $date)->get()->keyBy('staff_id');
        $holiday = Option::where('kind', 'holiday')->where('active', true)->get()->first(fn ($o) => ($o->settings['date'] ?? null) === $date);

        return view('hr.attendance', compact('date', 'staff', 'records', 'leaves', 'holiday', 'lockedStaff'));
    }

    public function mark(Request $request, HrService $service)
    {
        $data = $request->validate(['date' => 'required|date_format:Y-m-d|before_or_equal:today', 'rows' => 'required|array|max:1000', 'rows.*.status' => 'nullable|in:PRESENT,LATE,ABSENT,HALF_DAY,PAID_LEAVE,UNPAID_LEAVE,HOLIDAY,OFF,UNMARKED', 'rows.*.check_in' => 'nullable|date_format:H:i', 'rows.*.check_out' => 'nullable|date_format:H:i', 'rows.*.overnight' => 'nullable|boolean', 'rows.*.overtime_hours' => 'nullable|numeric|min:0|max:24|decimal:0,2', 'rows.*.notes' => 'nullable|string|max:500']);
        $service->markAttendance($data['date'], $data['rows']);

        return back()->with('success', 'Attendance saved. Unmarked rows were left unchanged.');
    }

    public function leaves(Request $request)
    {
        $filters = $request->validate(['status' => 'nullable|in:PENDING,APPROVED,REJECTED,CANCELLED', 'staff_id' => 'nullable|integer|exists:hr_staff,id']);
        $rows = LeaveRequest::with('staff', 'leaveType')->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->when($filters['staff_id'] ?? null, fn ($q, $v) => $q->where('staff_id', $v))->latest()->paginate(20)->withQueryString();
        $staff = Staff::where('status', '!=', 'EXITED')->orderBy('name')->get();
        $types = Option::where('kind', 'leave_type')->where('active', true)->get();

        return view('hr.leaves', compact('rows', 'staff', 'types', 'filters'));
    }

    public function leaveStore(Request $request, HrService $service)
    {
        $data = $request->validate(['staff_id' => 'required|integer|exists:hr_staff,id', 'leave_type_id' => 'required|integer|exists:hr_options,id', 'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from', 'reason' => 'required|string|max:1000']);
        if (Carbon::parse($data['from'])->diffInDays($data['to']) > 366) {
            $service->fail('to', 'A leave request cannot exceed one year.');
        }$service->leave($data);

        return back()->with('success', 'Leave request recorded.');
    }

    public function decide(Request $request, LeaveRequest $leave, HrService $service)
    {
        $data = $request->validate(['status' => 'required|in:APPROVED,REJECTED', 'decision_note' => 'required|string|max:1000']);
        $service->decideLeave($leave, $data);

        return back()->with('success', 'Leave decision saved.');
    }

    public function cancel(Request $request, LeaveRequest $leave, HrService $service)
    {
        $data = $request->validate(['reason' => 'required|string|max:1000']);
        $service->cancelLeave($leave, $data['reason']);

        return back()->with('success', 'Leave cancelled; its history is retained.');
    }

    public function setup()
    {
        $options = Option::orderBy('kind')->orderBy('name')->get()->groupBy('kind');

        return view('hr.setup', compact('options'));
    }

    public function setupSave(Request $request, HrService $service)
    {
        $id = $request->input('id');
        $data = $request->validate(['id' => 'nullable|integer|exists:hr_options,id', 'kind' => ['required', Rule::in(array_keys(Hr::SETUP))], 'name' => ['required', 'string', 'max:100', Rule::unique('hr_options', 'name')->where('kind', $request->kind)->ignore($id)], 'active' => 'nullable|boolean', 'date' => 'required_if:kind,holiday|nullable|date_format:Y-m-d', 'start' => 'required_if:kind,shift|nullable|date_format:H:i', 'end' => 'required_if:kind,shift|nullable|date_format:H:i', 'annual_days' => 'nullable|integer|min:0|max:366', 'paid' => 'nullable|boolean']);
        $row = $id ? Option::findOrFail($id) : new Option;
        if ($id && $row->kind !== $data['kind']) {
            $service->fail('kind', 'The setup type cannot change.');
        }$before = $row->getAttributes();
        $settings = match ($data['kind']) {
            'holiday' => ['date' => $data['date']],'shift' => ['start' => $data['start'], 'end' => $data['end']],'leave_type' => ['annual_days' => (int) ($data['annual_days'] ?? 0), 'paid' => $request->boolean('paid')],default => []
        };
        $row->fill(['kind' => $data['kind'], 'name' => $data['name'], 'active' => $request->boolean('active'), 'settings' => $settings])->save();
        Audit::record('hr.setup.save', $row, $before, $row->toArray());

        return back()->with('success', 'HR setup saved. Existing transaction snapshots are preserved.');
    }
}
