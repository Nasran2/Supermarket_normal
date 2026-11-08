<?php

namespace App\Services\Repair;

use App\Models\RepairCost;
use App\Models\RepairJob;
use App\Models\RepairPayment;
use App\Support\Money;
use App\Support\Repairs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RepairReporting
{
    public const VIEWS = ['jobs' => 'Jobs & intake', 'technicians' => 'Technician performance', 'status' => 'Status breakdown',
        'overdue' => 'Overdue jobs', 'parts' => 'Parts consumption', 'payments' => 'Payments',
        'dues' => 'Customer dues', 'profit' => 'Revenue & profit', 'warranty' => 'Warranty', 'cancellations' => 'Cancellation history'];

    public function filters(Request $request): array
    {
        return $request->validate([
            'q' => 'nullable|string|max:100', 'status' => ['nullable', Rule::in(array_keys(Repairs::STATUS))],
            'technician_id' => 'nullable|integer', 'customer_id' => 'nullable|integer', 'payment_method_id' => 'nullable|integer', 'bank_account_id' => 'nullable|integer',
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d'.($request->filled('from') ? '|after_or_equal:from' : ''),
            'due' => 'nullable|in:overdue,unpaid', 'sort' => 'nullable|in:recent,oldest,due',
            'view' => ['nullable', Rule::in(array_keys(self::VIEWS))], 'export' => 'nullable|in:csv',
        ]);
    }

    public function jobs(array $filters = [], ?string $date = 'received_at'): Builder
    {
        $jobs = RepairJob::visibleTo();
        foreach (['status', 'technician_id', 'customer_id'] as $key) {
            if (filled($filters[$key] ?? null)) {
                $jobs->where($key, $filters[$key]);
            }
        }
        if (filled($filters['payment_method_id'] ?? null)) {
            $jobs->whereHas('payments', fn ($q) => $q->where('payment_method_id', $filters['payment_method_id']));
        }
        if (filled($filters['q'] ?? null)) {
            $term = '%'.$filters['q'].'%';
            $jobs->where(fn ($q) => $q->where('tracking', 'like', $term)
                ->orWhereHas('customer', fn ($customer) => $customer->where(fn ($c) => $c->where('name', 'like', $term)->orWhere('phone', 'like', $term)))
                ->orWhereHas('device', fn ($device) => $device->where('brand', 'like', $term)->orWhere('model', 'like', $term)->orWhere('serial', 'like', $term)));
        }
        if ($date) {
            $this->dates($jobs, $filters, $date);
        }
        match ($filters['due'] ?? null) {
            'overdue' => $this->overdue($jobs),
            'unpaid' => $this->unpaid($jobs),
            default => null,
        };
        match ($filters['sort'] ?? 'recent') {
            'due' => $jobs->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')->orderBy('due_at')->latest('id'),
            'oldest' => $jobs->oldest('received_at')->oldest('id'),
            default => $jobs->latest('received_at')->latest('id'),
        };

        return $jobs;
    }

    public function dates(Builder $query, array $filters, string $column): Builder
    {
        return $query->when($filters['from'] ?? null, fn ($q, $date) => $q->where($column, '>=', $date.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($q, $date) => $q->where($column, '<=', $date.' 23:59:59'));
    }

    public function overdue(Builder $query): Builder
    {
        return $query->whereNotIn('status', Repairs::CLOSED)->where('due_at', '<', today()->toDateString());
    }

    public function unpaid(Builder $query): Builder
    {
        return $query->whereNotNull('invoiced_at')->whereRaw('total - credited > paid');
    }

    public function payments(): Builder
    {
        return RepairPayment::whereHas('job', fn ($q) => $q->visibleTo());
    }

    public function financial(array $filters = [], ?Builder $jobs = null): array
    {
        $jobs = ($jobs ?? $this->jobs($filters, null))->reorder();
        $ids = (clone $jobs)->select('id');
        $invoices = $this->dates((clone $jobs)->whereNotNull('invoiced_at'), $filters, 'invoiced_at');
        $closed = $this->dates((clone $jobs)->whereNotNull('closed_at'), $filters, 'closed_at');
        $payments = $this->dates(RepairPayment::whereIn('repair_job_id', clone $ids), $filters, 'created_at');
        $costs = $this->dates(RepairCost::whereIn('repair_job_id', $ids), $filters, 'created_at');
        $billed = Money::round((string) $invoices->sum('net_revenue'));
        $credits = Money::round((string) (clone $payments)->selectRaw('COALESCE(SUM(credit - tax_credit), 0) as net_credit')->value('net_credit'));
        $revenue = Money::sub($billed, $credits);
        $cogs = Money::round((string) (clone $closed)->whereNotNull('invoiced_at')->sum('part_cost'));
        $writeoff = Money::round((string) (clone $closed)->whereNull('invoiced_at')->sum('part_cost'));
        $expenses = Money::add((string) $costs->sum('amount'), $writeoff);
        $gross = Money::sub($revenue, $cogs);
        $net = Money::sub($gross, $expenses);
        $collections = Money::round((string) $payments->sum('amount'));

        return compact('billed', 'credits', 'revenue', 'cogs', 'writeoff', 'expenses', 'gross', 'net', 'collections');
    }

    public function dashboard(): array
    {
        $jobs = RepairJob::visibleTo();
        $day = today()->toDateString();

        return [
            'Pending' => (clone $jobs)->whereIn('status', ['RECEIVED', 'DIAGNOSING', 'WAITING_APPROVAL', 'WAITING_PARTS'])->count(),
            'In progress' => (clone $jobs)->where('status', 'IN_PROGRESS')->count(),
            'Overdue' => $this->overdue(clone $jobs)->count(),
            'Ready' => (clone $jobs)->where('status', 'READY')->count(),
            'Unpaid' => $this->unpaid(clone $jobs)->count(),
            'Revenue today' => Money::displayPrice($this->financial(['from' => $day, 'to' => $day])['revenue']),
            'Collected today' => Money::displayPayment((string) $this->dates($this->payments(), ['from' => $day, 'to' => $day], 'created_at')->sum('amount')),
        ];
    }
}
