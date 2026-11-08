<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\PaymentMethod;
use App\Models\RepairJob;
use App\Models\RepairPart;
use App\Models\RepairPayment;
use App\Models\User;
use App\Services\Repair\RepairReporting;
use App\Support\Csv;
use App\Support\Money;
use App\Support\Repairs;
use App\Support\SecretControl;
use Illuminate\Http\Request;

class RepairReportController extends Controller
{
    public function __invoke(Request $request, RepairReporting $reports)
    {
        abort_if(SecretControl::isHidden('disable_reports'), 404);
        $filters = $reports->filters($request);
        $kind = $filters['view'] ?? 'jobs';
        $canProfit = $request->user()->hasPermission('products.view_cost') && ! SecretControl::isHidden('hide_profit');
        abort_if($kind === 'profit' && ! $canProfit, 403);
        $summary = [];
        $jobIds = $reports->jobs($filters, null)->reorder()->select('id');
        $jobUrl = fn ($id) => $request->user()->hasPermission('repairs.view') ? route('repairs.show', $id) : null;
        $url = fn ($row) => $jobUrl($row->id);

        if ($kind === 'payments') {
            $query = $reports->dates(RepairPayment::whereIn('repair_job_id', $jobIds)->with('bankAccount'), $filters, 'created_at')
                ->when($filters['payment_method_id'] ?? null, fn ($q, $id) => $q->where('payment_method_id', $id))
                ->when($filters['bank_account_id'] ?? null, fn ($q, $id) => $q->where('bank_account_id', $id))->latest('id');
            $columns = ['Date', 'Job', 'Type', 'Method', 'Bank account', 'Reference', 'Net amount'];
            $map = fn ($row) => [$row->created_at, $row->repair_job_id, $row->kind, $row->method_name, $row->bankAccount?->account_name, $row->reference, Money::displayPayment($row->amount)];
            $url = fn ($row) => $jobUrl($row->repair_job_id);
            $summary = ['Net collections' => Money::displayPayment((string) (clone $query)->sum('amount'))];
        } elseif ($kind === 'parts') {
            $query = $reports->dates(RepairPart::whereIn('repair_job_id', $jobIds)->whereNotNull('consumed_at'), $filters, 'consumed_at')->latest('id');
            $columns = ['Consumed at', 'Job', 'Product', 'Quantity', 'State', ...($canProfit ? ['Historical cost'] : [])];
            $map = fn ($row) => [$row->consumed_at, $row->repair_job_id, $row->name, $row->quantity, $row->state, ...($canProfit ? [Money::displayCost($row->cost)] : [])];
            $url = fn ($row) => $jobUrl($row->repair_job_id);
        } elseif (in_array($kind, ['technicians', 'status'], true)) {
            $column = $kind === 'technicians' ? 'technician_id' : 'status';
            $query = $reports->jobs($filters)->reorder()->select($column)
                ->selectRaw('COUNT(*) AS jobs, SUM(CASE WHEN invoiced_at IS NOT NULL THEN 1 ELSE 0 END) AS finished, SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) AS cancelled', ['CANCELLED', 'UNREPAIRABLE'])
                ->groupBy($column)->orderByDesc('jobs')->orderBy($column);
            $technicianNames = User::pluck('name', 'id');
            $columns = [$kind === 'technicians' ? 'Technician' : 'Status', 'Jobs', 'Completed', 'Cancelled or unrepairable'];
            $map = fn ($row) => [$kind === 'technicians' ? ($technicianNames[$row->technician_id] ?? 'Unassigned') : Repairs::STATUS[$row->status], $row->jobs, $row->finished, $row->cancelled];
            $url = fn () => null;
        } else {
            $date = match ($kind) {
                'profit' => null, 'cancellations' => 'closed_at', 'dues' => 'invoiced_at', default => 'received_at',
            };
            $query = $reports->jobs($filters, $date)->with('device', 'customer', 'technician');
            $columns = ['Job', 'Received', 'Customer', 'Device', 'Technician', 'Status', 'Due date'];
            $map = fn ($row) => [$row->invoiceNumber(), $row->received_at->toDateString(), $row->customer->name, $row->device->brand.' '.$row->device->model, $row->technician?->name, Repairs::STATUS[$row->status], $row->due_at?->toDateString()];
            $extra = null;
            if ($kind === 'overdue') {
                $reports->overdue($query);
            } elseif ($kind === 'dues') {
                $reports->unpaid($query);
                $columns[] = 'Balance';
                $extra = fn ($row) => [Money::displayCustomerAmount($row->due())];
                $summary = ['Outstanding' => Money::displayCustomerAmount((string) (clone $query)->reorder()->selectRaw('COALESCE(SUM(total - credited - paid), 0) AS balance')->value('balance'))];
            } elseif ($kind === 'warranty') {
                $query->whereNotNull('warranty_until');
                $columns[] = 'Warranty until';
                $extra = fn ($row) => [$row->warranty_until->toDateString()];
            } elseif ($kind === 'cancellations') {
                $query->whereIn('status', ['CANCELLED', 'UNREPAIRABLE'])->with(['events' => fn ($q) => $q->whereIn('public_status', ['CANCELLED', 'UNREPAIRABLE'])]);
                array_push($columns, 'Closed', 'Reason');
                $extra = fn ($row) => [$row->closed_at, $row->events->last()?->note];
            } elseif ($kind === 'profit') {
                $financial = $reports->financial($filters);
                $summary = [
                    'Net revenue' => Money::displayPrice($financial['revenue']), 'Parts COGS' => Money::displayCost($financial['cogs']),
                    'Expenses and write-offs' => Money::display($financial['expenses']), 'Gross profit' => Money::display($financial['gross']),
                    'Net profit' => Money::display($financial['net']), 'Net collections' => Money::displayPayment($financial['collections']),
                ];
                $query->where(fn ($q) => $q->whereHas('payments', fn ($pay) => $reports->dates($pay->where('credit', '>', 0), $filters, 'created_at'))
                    ->orWhereHas('costs', fn ($cost) => $reports->dates($cost, $filters, 'created_at'))
                    ->orWhere(fn ($close) => $reports->dates($close->whereNotNull('closed_at'), $filters, 'closed_at')))
                    ->withSum(['costs as period_costs' => fn ($q) => $reports->dates($q, $filters, 'created_at')], 'amount')
                    ->withSum(['payments as period_credits' => fn ($q) => $reports->dates($q, $filters, 'created_at')], 'credit')
                    ->withSum(['payments as period_tax_credits' => fn ($q) => $reports->dates($q, $filters, 'created_at')], 'tax_credit');
                array_push($columns, 'Revenue', 'Parts COGS', 'Expenses', 'Net profit');
                $extra = function (RepairJob $row) use ($filters) {
                    $day = $row->closed_at?->toDateString();
                    $closedInPeriod = $day && $day >= ($filters['from'] ?? '0000-01-01') && $day <= ($filters['to'] ?? '9999-12-31');
                    $revenue = Money::sub($closedInPeriod ? $row->net_revenue : '0', Money::sub((string) ($row->period_credits ?? 0), (string) ($row->period_tax_credits ?? 0)));
                    $cogs = $closedInPeriod && $row->invoiced_at ? $row->part_cost : '0';
                    $cost = Money::add((string) ($row->period_costs ?? 0), $closedInPeriod && ! $row->invoiced_at ? $row->part_cost : '0');

                    return [Money::displayPrice($revenue), Money::displayCost($cogs), Money::display($cost), Money::display(Money::sub(Money::sub($revenue, $cogs), $cost))];
                };
            }
            if ($extra) {
                $map = fn ($row) => [...$map($row), ...$extra($row)];
            }
        }
        if (($filters['export'] ?? null) === 'csv') {
            return response()->streamDownload(function () use ($query, $columns, $map) {
                $stream = fopen('php://output', 'w');
                fputcsv($stream, $columns, escape: '');
                foreach ($query->lazy(250) as $row) {
                    fputcsv($stream, Csv::row($map($row)), escape: '');
                }
                fclose($stream);
            }, 'repair-'.$kind.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $rows = $query->paginate(25)->withQueryString();
        $technicians = User::where('active', true)->orderBy('name')->get(['id', 'name']);
        $methods = PaymentMethod::orderBy('display_order')->get();
        $banks = $kind === 'payments' ? BankAccount::orderBy('bank_name')->get() : collect();

        return view('repairs.report', compact('rows', 'columns', 'map', 'url', 'kind', 'summary', 'technicians', 'methods', 'banks', 'canProfit'));
    }
}
