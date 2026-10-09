<?php

namespace App\Http\Controllers;

use App\Models\PaymentMethod;
use App\Services\PaymentActivityService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PaymentActivityController extends Controller
{
    public const RANGES = ['today' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'This week', '7days' => 'Last 7 days', 'month' => 'This month', 'last_month' => 'Last month', '30days' => 'Last 30 days'];

    private function filters(Request $request): array
    {
        $default = $request->filled('from') || $request->filled('to') ? 'custom' : 'today';
        $range = $request->input('range') ?: $default;
        $validated = $request->validate([
            'range' => 'nullable|in:'.implode(',', array_keys(self::RANGES)).',custom',
            'from' => ($range === 'custom' ? 'required' : 'nullable').'|date_format:Y-m-d',
            'to' => ($range === 'custom' ? 'required' : 'nullable').'|date_format:Y-m-d|after_or_equal:from',
            'q' => 'nullable|string|max:150', 'active' => 'nullable|boolean',
        ]);
        $today = today();
        [$from, $to] = match ($range) {
            'yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()],
            'week' => [$today->copy()->startOfWeek(), $today],
            '7days' => [$today->copy()->subDays(6), $today],
            'month' => [$today->copy()->startOfMonth(), $today],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()],
            '30days' => [$today->copy()->subDays(29), $today],
            'custom' => [Carbon::parse($validated['from']), Carbon::parse($validated['to'])],
            default => [$today, $today],
        };

        return ['range' => $range, 'from' => $from->toDateString(), 'to' => $to->toDateString()] + array_intersect_key($validated, array_flip(['q', 'active']));
    }

    public function index(Request $request, PaymentActivityService $service)
    {
        abort_unless($request->user()->hasPermission('payment-methods.view'), 403);
        $filters = $this->filters($request);
        $result = $service->overview($filters);

        return view('payment-methods.index', $result + ['filters' => $filters, 'ranges' => self::RANGES]);
    }

    public function show(Request $request, PaymentMethod $method, PaymentActivityService $service)
    {
        abort_unless($request->user()->hasPermission('payment-methods.view'), 403);
        $filters = $this->filters($request);
        $query = $service->query($filters, $method->id);
        $totals = $service->totals($query);
        $activity = $query->orderByDesc('occurred_at')->orderBy('kind')->orderByDesc('entry_id')->paginate(20)->appends($filters);

        return view('payment-methods.show', compact('method', 'filters', 'totals', 'activity') + ['ranges' => self::RANGES]);
    }
}
