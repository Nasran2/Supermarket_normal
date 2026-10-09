<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\Attendance;
use App\Models\Hr\EmploymentEvent;
use App\Models\Hr\LeaveRequest;
use App\Models\Hr\Option;
use App\Models\Hr\Payroll;
use App\Models\Hr\Staff;
use App\Models\Hr\StaffPayment;
use App\Services\PdfReportService;
use App\Support\Hr;
use App\Support\Money;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function index()
    {
        abort_unless(collect(array_keys(Hr::REPORTS))->contains(fn ($kind) => auth()->user()->hasPermission('hr.reports.'.$kind.'.view')), 403);

        return view('hr.reports');
    }

    public function show(Request $request, string $kind)
    {
        abort_unless(isset(Hr::REPORTS[$kind]), 404);
        $format = $request->route('format');
        abort_unless(auth()->user()->hasPermission('hr.reports.'.$kind.'.view') && (! $format || auth()->user()->hasPermission('hr.reports.'.$kind.'.'.($format === 'pdf' ? 'pdf' : 'export'))), 403);
        $filters = $request->validate(['range' => 'nullable|in:today,yesterday,week,month,last_month,year,all,custom', 'from' => 'nullable|date_format:Y-m-d', 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])], 'staff_id' => 'nullable|integer|exists:hr_staff,id', 'department_id' => 'nullable|integer|exists:hr_options,id', 'status' => 'nullable|in:ACTIVE,INACTIVE,EXITED,PRESENT,LATE,ABSENT,HALF_DAY,PAID_LEAVE,UNPAID_LEAVE,HOLIDAY,OFF,PENDING,APPROVED,REJECTED,CANCELLED,UNMARKED,DRAFT,VOIDED,REVERSED,INTAKE,EXIT,REHIRE,PROFILE_UPDATE']);
        $range = $filters['range'] ?? 'month';
        if ($range === 'custom' && (empty($filters['from']) || empty($filters['to']))) {
            $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);
        }
        [$from,$to] = match ($range) {
            'today' => [today(), today()],'yesterday' => [today()->subDay(), today()->subDay()],'week' => [today()->startOfWeek(), today()],'last_month' => [today()->subMonthNoOverflow()->startOfMonth(), today()->subMonthNoOverflow()->endOfMonth()],'year' => [today()->startOfYear(), today()],'all' => [null, null],'custom' => [$filters['from'], $filters['to']],default => [today()->startOfMonth(), today()]
        };
        $filters['range'] = $range;
        $filters['from'] = $from ? (is_string($from) ? $from : $from->toDateString()) : null;
        $filters['to'] = $to ? (is_string($to) ? $to : $to->toDateString()) : null;
        [$query,$headers,$map,$date] = match ($kind) {
            'staff' => [Staff::with('department', 'position'), ['Staff code', 'Name', 'Department', 'Job title', 'Joined', 'Exited', 'Status'], fn ($s) => [$s->code, $s->name, $s->department?->name ?? '—', $s->position?->name ?? '—', $s->joined_on->toDateString(), $s->left_on?->toDateString() ?? '—', $s->status], 'joined_on'],
            'attendance' => [Attendance::with('staff'), ['Date', 'Staff code', 'Name', 'Status', 'Check in', 'Check out', 'Overtime hours'], fn ($a) => [$a->date->toDateString(), $a->staff->code, $a->staff->name, $a->status, $a->check_in?->format('d M H:i') ?? '—', $a->check_out?->format('d M H:i') ?? '—', $a->overtime_hours], 'date'],
            'leave' => [LeaveRequest::with('staff', 'leaveType'), ['Staff', 'Leave type', 'From', 'To', 'Calendar days', 'Paid', 'Status'], fn ($l) => [$l->staff->code.' · '.$l->staff->name, $l->leaveType->name, $l->from->toDateString(), $l->to->toDateString(), $l->days, $l->paid ? 'Yes' : 'No', $l->status], 'from'],
            'payroll' => [Payroll::with('staff')->withSum(['payments as paid_total' => fn ($q) => $q->where('status', 'ACTIVE')], 'amount'), ['Period', 'Payroll', 'Staff', 'Earnings', 'Deductions', 'Advance recovery', 'Net salary', 'Paid', 'Due', 'Status'], fn ($p) => [$p->period->format('Y-m'), $p->reference, $p->staff_name, $p->earnings, $p->deductions, $p->advance_recovery, $p->net, Money::round((string) ($p->paid_total ?? 0)), $p->status === 'VOIDED' ? '0.00' : Money::sub($p->net, (string) ($p->paid_total ?? 0)), $p->status], 'period'],
            'payments' => [StaffPayment::with('staff', 'payroll'), ['Date', 'Reference', 'Staff', 'Kind', 'Payroll', 'Method', 'Amount', 'Status'], fn ($p) => [$p->date->toDateString(), 'HRP-'.$p->id, $p->staff->code.' · '.$p->staff->name, $p->kind, $p->payroll?->reference ?? '—', $p->method_name, $p->amount, $p->status], 'date'],
            'employment' => [EmploymentEvent::with('staff', 'user'), ['Date', 'Staff code', 'Name at event', 'Event', 'Reason', 'Recorded by'], fn ($e) => [$e->event_date->toDateString(), $e->snapshot['code'] ?? $e->staff->code, $e->snapshot['name'] ?? $e->staff->name, $e->type, $e->reason ?? '—', $e->user->name], 'event_date'],
        };
        if ($kind === 'staff') {
            $query->when($filters['staff_id'] ?? null, fn ($q, $v) => $q->whereKey($v))->when($filters['department_id'] ?? null, fn ($q, $v) => $q->where('department_id', $v));
        } else {
            $query->when($filters['staff_id'] ?? null, fn ($q, $v) => $q->where('staff_id', $v))->when($filters['department_id'] ?? null, fn ($q, $v) => $q->whereHas('staff', fn ($s) => $s->where('department_id', $v)));
        }
        $query->when($filters['status'] ?? null, fn ($q, $v) => $q->where($kind === 'employment' ? 'type' : 'status', $v));
        if ($filters['from']) {
            if ($kind === 'leave') {
                $query->whereDate('to', '>=', $filters['from'])->whereDate('from', '<=', $filters['to']);
            } elseif ($kind === 'payroll') {
                $query->whereBetween($date, [substr($filters['from'], 0, 7).'-01', substr($filters['to'], 0, 7).'-01']);
            } elseif ($kind !== 'staff') {
                $query->whereBetween($date, [$filters['from'], $filters['to']]);
            }
            // Staff register is a complete directory; dates apply only to event-based reports.
        }
        $cards = [];
        if ($kind === 'payroll') {
            $approved = (clone $query)->where('status', 'APPROVED')->get();
            $cards = ['Net salaries' => Money::sum($approved->pluck('net')), 'Paid' => Money::sum($approved->pluck('paid_total')), 'Due' => Money::sub(Money::sum($approved->pluck('net')), Money::sum($approved->pluck('paid_total'))), 'Advance recovery' => Money::sum($approved->pluck('advance_recovery'))];
        }
        if ($kind === 'payments') {
            $cards = ['Salary payments' => (string) (clone $query)->where('status', 'ACTIVE')->where('kind', 'SALARY')->sum('amount'), 'Advances' => (string) (clone $query)->where('status', 'ACTIVE')->where('kind', 'ADVANCE')->sum('amount')];
        }
        $title = Hr::REPORTS[$kind];
        $query->orderByDesc($date)->orderByDesc('id');
        if ($format) {
            $rows = $query->get()->map($map);
            if ($format === 'csv') {
                return $this->csv('hr-'.$kind, $headers, $rows);
            }

            return app(PdfReportService::class)->download('hr-'.$kind, ['title' => $title.' report', 'headers' => $headers, 'rows' => $rows, 'filters' => $filters, 'cards' => $cards, 'filterLabels' => ['Staff' => ! empty($filters['staff_id']) ? Staff::find($filters['staff_id'])->name : 'All staff', 'Department' => ! empty($filters['department_id']) ? Option::find($filters['department_id'])->name : 'All departments', 'Status' => $filters['status'] ?? 'All statuses'], 'notes' => $kind === 'staff' ? 'Complete staff directory, including inactive and exited records. Dates do not restrict the directory.' : 'All matching records are included. Exited staff history is retained. Payroll amounts use approved snapshots; voided and reversed records remain identifiable.']);
        }
        $records = $query->paginate(25)->withQueryString();
        $rows = $records->getCollection()->map($map);
        $staff = Staff::orderBy('name')->get();
        $departments = Option::where('kind', 'department')->get();

        return view('hr.report', compact('kind', 'title', 'filters', 'headers', 'records', 'rows', 'cards', 'staff', 'departments'));
    }

    public function csv(string $name, array $headers, $rows)
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+@\-\t\r]/', $v) ? "'".$v : $v, $row));
            }fclose($out);
        }, $name.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
