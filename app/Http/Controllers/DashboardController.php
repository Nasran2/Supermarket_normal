<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Services\ProfitLossService;
use App\Services\RegisterService;
use App\Services\ReportService;
use App\Support\Money;
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
        $summary = collect(['sales', 'profit', 'expenses'])->contains($can) ? $profit->calculate($from, $to) : ['revenue' => '0', 'net' => '0', 'expenses' => '0'];
        $periodSales = Sale::where('status', 'ACTIVE')->whereBetween('sold_at', [$from.' 00:00:00', $to.' 23:59:59']);
        $transactions = $can('transactions') ? $periodSales->count() : 0;
        $payments = $can('collections') ? app(ReportService::class)->build('payments', ['from' => $from, 'to' => $to])['query']->get() : collect();
        $days = $request->input('chart_days') === '30' ? 30 : 7;
        $overview = [];
        if ($can('sales_overview')) {
            $dailyTotals = Sale::where('status', 'ACTIVE')->whereBetween('sold_at', [today()->subDays($days - 1)->startOfDay(), today()->endOfDay()])->selectRaw('DATE(sold_at) as day, SUM(sale_amount) as amount')->groupByRaw('DATE(sold_at)')->pluck('amount', 'day');
            $dailyReturns = SaleReturn::whereBetween('returned_at', [today()->subDays($days - 1)->startOfDay(), today()->endOfDay()])->selectRaw('DATE(returned_at) as day, SUM(amount) as amount')->groupByRaw('DATE(returned_at)')->pluck('amount', 'day');
            $overview = [];
            for ($i = $days - 1; $i >= 0; $i--) {
                $date = today()->subDays($i);
                $overview[] = ['label' => $date->format('d M'), 'amount' => Money::sub((string) ($dailyTotals[$date->toDateString()] ?? 0), (string) ($dailyReturns[$date->toDateString()] ?? 0))];
            }
        }
        $lowStock = $can('low_stock') ? Product::with('unit')->where('active', true)->whereColumn('stock', '<=', 'low_stock')->orderBy('stock')->limit(6)->get() : collect();
        $recentSales = $can('recent_sales') ? Sale::with('user', 'payments')->latest('sold_at')->limit(6)->get() : collect();
        $recentExpenses = $can('recent_expenses') ? Expense::with('category')->where('status', 'ACTIVE')->latest()->limit(5)->get() : collect();
        $topProducts = $can('top_products') ? SaleItem::whereHas('sale', fn ($q) => $q->where('status', 'ACTIVE')->whereBetween('sold_at', [today()->subDays($days - 1)->startOfDay(), today()->endOfDay()]))->selectRaw('product_id, name, unit, SUM(quantity) as quantity, SUM(total) as total')->groupBy('product_id', 'name', 'unit')->orderByDesc('total')->limit(5)->get() : collect();
        $register = $can('register') ? $registers->current(auth()->id()) : null;

        return view('dashboard', compact('period', 'from', 'to', 'summary', 'transactions', 'payments', 'days', 'overview', 'lowStock', 'recentSales', 'recentExpenses', 'topProducts', 'register'));
    }
}
