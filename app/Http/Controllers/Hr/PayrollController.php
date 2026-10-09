<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\Payroll;
use App\Models\Hr\Staff;
use App\Models\Hr\StaffPayment;
use App\Models\PaymentMethod;
use App\Services\HrService;
use App\Services\PdfReportService;
use Illuminate\Http\Request;

class PayrollController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['month' => 'nullable|date_format:Y-m', 'status' => 'nullable|in:DRAFT,APPROVED,VOIDED', 'staff_id' => 'nullable|integer|exists:hr_staff,id']);
        $rows = Payroll::with('staff')->withSum(['payments as paid_total' => fn ($q) => $q->where('status', 'ACTIVE')], 'amount')->when($filters['month'] ?? null, fn ($q, $v) => $q->where('period', $v.'-01'))->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->when($filters['staff_id'] ?? null, fn ($q, $v) => $q->where('staff_id', $v))->latest('period')->latest('id')->paginate(20)->withQueryString();

        return view('hr.payroll-index', compact('rows', 'filters'));
    }

    public function create()
    {
        $staff = Staff::orderBy('name')->get();

        return view('hr.payroll-form', compact('staff'));
    }

    public function store(Request $request, HrService $service)
    {
        $data = $request->validate(['staff_id' => 'required|integer|exists:hr_staff,id', 'month' => 'required|date_format:Y-m', 'deduct_unpaid' => 'nullable|boolean', 'advance_recovery' => 'nullable|numeric|min:0|max:99999999|decimal:0,2', 'notes' => 'nullable|string|max:2000', 'lines' => 'nullable|array|max:30', 'lines.*.kind' => 'required|in:EARNING,DEDUCTION', 'lines.*.name' => 'nullable|string|max:100', 'lines.*.amount' => 'required|numeric|min:0|max:99999999|decimal:0,2']);
        if ($data['month'] > today()->format('Y-m')) {
            $service->fail('month', 'Future payroll cannot be prepared.');
        }$row = $service->preparePayroll($data);

        return redirect()->route('hr.payroll.show', $row)->with('success', 'Payroll draft prepared. Review the salary breakdown before approval.');
    }

    public function show(Payroll $payroll)
    {
        $payroll->load('staff', 'payments.user');
        $methods = PaymentMethod::where('active', true)->orderBy('display_order')->get();

        return view('hr.payroll-show', compact('payroll', 'methods'));
    }

    public function approve(Payroll $payroll, HrService $service)
    {
        $service->approve($payroll);

        return back()->with('success', 'Payroll approved and salary cost recorded.');
    }

    public function void(Request $request, Payroll $payroll, HrService $service)
    {
        $data = $request->validate(['reason' => 'required|string|max:1000']);
        $service->voidPayroll($payroll, $data['reason']);

        return back()->with('success', 'Payroll voided. Its history is retained.');
    }

    public function payslip(Payroll $payroll)
    {
        abort_unless($payroll->status === 'APPROVED', 422);

        return app(PdfReportService::class)->download($payroll->reference, ['title' => 'Salary payslip', 'subtitle' => $payroll->staff_code.' · '.$payroll->staff_name.' · '.$payroll->period->format('F Y'), 'headers' => ['Description', 'Type', 'Amount'], 'rows' => collect($payroll->lines)->map(fn ($l) => [$l['name'], $l['kind'], $l['amount']])->push(['Advance recovery', 'DEDUCTION', $payroll->advance_recovery])->push(['Net salary', 'TOTAL', $payroll->net]), 'cards' => ['Net salary' => $payroll->net, 'Paid' => $payroll->paid, 'Due' => $payroll->due], 'notes' => 'Approved payroll snapshot. Deductions are explicitly entered or based on marked unpaid attendance; no statutory deductions are assumed.']);
    }

    public function payments(Request $request)
    {
        $filters = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])], 'kind' => 'nullable|in:SALARY,ADVANCE']);
        $rows = StaffPayment::with('staff', 'payroll', 'user')->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('date', '>=', $v))->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('date', '<=', $v))->when($filters['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))->latest('date')->latest('id')->paginate(20)->withQueryString();
        $staff = Staff::where('status', '!=', 'EXITED')->orderBy('name')->get();
        $methods = PaymentMethod::where('active', true)->orderBy('display_order')->get();

        return view('hr.payments', compact('rows', 'staff', 'methods', 'filters'));
    }

    public function pay(Request $request, HrService $service)
    {
        $data = $request->validate(['staff_id' => 'required|integer|exists:hr_staff,id', 'payroll_id' => 'required_if:kind,SALARY|nullable|integer|exists:hr_payrolls,id', 'kind' => 'required|in:SALARY,ADVANCE', 'date' => 'required|date_format:Y-m-d|before_or_equal:today', 'amount' => 'required|numeric|gt:0|max:99999999|decimal:0,2', 'payment_method_id' => 'required|integer|exists:payment_methods,id', 'reference' => 'nullable|string|max:150', 'notes' => 'nullable|string|max:1000', 'token' => 'required|uuid']);
        $service->pay($data);

        return back()->with('success', 'Staff payment recorded.');
    }

    public function reverse(Request $request, StaffPayment $payment, HrService $service)
    {
        $data = $request->validate(['reason' => 'required|string|max:1000']);
        $service->reversePayment($payment, $data['reason']);

        return back()->with('success', 'Payment reversed. The original record remains in history.');
    }
}
