<?php

namespace App\Http\Controllers;

use App\Models\Cheque;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\ExpiryReportService;
use App\Services\ProfitLossService;
use App\Services\RegisterService;
use App\Services\ReportService;
use App\Support\Cheques;
use App\Support\Expiry;
use App\Support\SalesPrivacy;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request, ProfitLossService $profit, RegisterService $registers)
    {
        $period = $request->input('period', 'today');
        $from = $request->input('from');
        $to = $request->input('to');

        if ($period !== 'custom' || ! $from || ! $to) {
            [$from, $to] = match ($period) {
                'yesterday' => [today()->subDay()->toDateString(), today()->subDay()->toDateString()],
                'this_week' => [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()],
                'last_week' => [now()->subWeek()->startOfWeek()->toDateString(), now()->subWeek()->endOfWeek()->toDateString()],
                'this_month' => [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()],
                'last_month' => [now()->subMonth()->startOfMonth()->toDateString(), now()->subMonth()->endOfMonth()->toDateString()],
                'this_year' => [now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString()],
                'last_year' => [now()->subYear()->startOfYear()->toDateString(), now()->subYear()->endOfYear()->toDateString()],
                default => [today()->toDateString(), today()->toDateString()],
            };
        }

        $can = fn ($card) => $request->user()->hasPermission('dashboard.'.$card);
        $privacy = SalesPrivacy::view('dashboard');
        $periodSales = Sale::visibleTo()->where('status', 'ACTIVE')->whereBetween('sold_at', [$from.' 00:00:00', $to.' 23:59:59']);
        if ($ids = SalesPrivacy::selectedIds($periodSales, $privacy)) {
            $periodSales->whereIn('sales.id', $ids);
        }
        $summary = collect(['sales', 'profit', 'expenses'])->contains($can) ? $profit->calculate($from, $to, $ids) : ['revenue' => '0', 'net' => '0', 'expenses' => '0'];
        $transactions = $can('transactions') ? $periodSales->count() : 0;
        $payments = $can('collections') ? app(ReportService::class)->build('payments', ['from' => $from, 'to' => $to], $privacy)['query']->get() : collect();
        $days = $request->input('chart_days') === '30' ? 30 : 7;
        $chartDates = [today()->subDays($days - 1)->startOfDay(), today()->endOfDay()];
        $chartIds = SalesPrivacy::selectedIds(Sale::visibleTo()->whereBetween('sold_at', $chartDates), $privacy)?->pluck('id')->all();
        $overview = [];
        if ($can('sales_overview')) {
            for ($i = $days - 1; $i >= 0; $i--) {
                $date = today()->subDays($i);
                $day = $date->toDateString();
                $overview[] = ['label' => $date->format('d M'), 'amount' => $profit->calculate($day, $day, $chartIds)['revenue']];
            }
        }
        $lowStock = $can('low_stock') ? Product::with('unit')->where('active', true)->where('track_stock', true)->lowStock()->orderBy('stock')->limit(6)->get() : collect();
        $recentSalesQuery = $ids
            ? (clone $periodSales)->orderBy('customer_payable', SalesPrivacy::direction($privacy))->orderBy('sales.id')
            : Sale::visibleTo()->latest('sold_at');
        $recentSales = $can('recent_sales') ? $recentSalesQuery->with('user', 'payments')->limit(6)->get() : collect();
        $recentExpenses = $can('recent_expenses') ? Expense::visibleSales()->with('category')->where('status', 'ACTIVE')
            ->when($ids, fn ($q) => $q->where(fn ($q) => $q->whereNull('sale_id')->orWhereIn('sale_id', $ids)))
            ->latest()->limit(5)->get() : collect();
        $topProducts = $can('top_products') ? SaleItem::whereHas('sale', fn ($q) => $q->visibleTo()->where('status', 'ACTIVE')->whereBetween('sold_at', $chartDates))
            ->when($chartIds !== null, fn ($q) => $q->whereIn('sale_id', $chartIds))
            ->selectRaw('product_id, name, unit, SUM(quantity) as quantity, SUM(total) as total')->groupBy('product_id', 'name', 'unit')->orderByDesc('total')->limit(5)->get() : collect();
        $register = $can('register') ? $registers->current(auth()->id()) : null;

        $expiryRows = collect();
        $expiredBatches = 0;
        if (Expiry::enabled() && $request->user()->hasPermission('expiry.view') && Expiry::setting('expiry_dashboard')) {
            $expiryRows = app(ExpiryReportService::class)->query()->limit((int) Expiry::setting('expiry_dashboard_limit'))->get();
            $expiredBatches = app(ExpiryReportService::class)->query(['tab' => 'expired'])->count();
        }

        $chequeAlerts = config('features.cheque') && $request->user()->hasPermission('cheques.dashboard') && Cheques::setting('cheque_dashboard') ? Cheque::visibleTo()->with('customer', 'supplier')->dueSoon()->limit(6)->get() : collect();

        $repairStats = $request->user()->hasPermission('repairs.view') && ! \App\Support\SecretControl::isHidden('hide_top_summary')
            ? app(\App\Services\Repair\RepairReporting::class)->dashboard() : [];

        return view('dashboard', compact('repairStats', 'chequeAlerts', 'expiryRows', 'expiredBatches', 'period', 'from', 'to', 'summary', 'transactions', 'payments', 'days', 'overview', 'lowStock', 'recentSales', 'recentExpenses', 'topProducts', 'register', 'privacy'));
    }
}
