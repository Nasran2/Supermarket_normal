<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Services\ProfitLossService;
use App\Services\RegisterService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request, ProfitLossService $profit, RegisterService $registers)
    {
        $today = today()->toDateString();
        $summary = $profit->calculate($today, $today);
        $todaySales = Sale::where('status', 'ACTIVE')->whereBetween('sold_at', [$today.' 00:00:00', $today.' 23:59:59']);
        $transactions = $todaySales->count();
        $payments = SalePayment::whereHas('sale', fn ($q) => $q->where('status', 'ACTIVE')->whereBetween('sold_at', [$today.' 00:00:00', $today.' 23:59:59']))->selectRaw('payment_method_id, method_name, SUM(customer_payable) as collections')->groupBy('payment_method_id', 'method_name')->get();
        $days = $request->input('period') === '30' ? 30 : 7;
        $dailyTotals = Sale::where('status', 'ACTIVE')->whereBetween('sold_at', [today()->subDays($days - 1)->startOfDay(), today()->endOfDay()])->selectRaw('DATE(sold_at) as day, SUM(sale_amount) as amount')->groupByRaw('DATE(sold_at)')->pluck('amount', 'day');
        $overview = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = today()->subDays($i);
            $overview[] = ['label' => $date->format('d M'), 'amount' => (string) ($dailyTotals[$date->toDateString()] ?? 0)];
        }
        $lowStock = Product::with('unit')->where('active', true)->whereColumn('stock', '<=', 'low_stock')->orderBy('stock')->limit(6)->get();
        $recentSales = Sale::with('user', 'payment')->latest('sold_at')->limit(6)->get();
        $recentExpenses = Expense::with('category')->where('status', 'ACTIVE')->latest()->limit(5)->get();
        $topProducts = SaleItem::whereHas('sale', fn ($q) => $q->where('status', 'ACTIVE')->whereBetween('sold_at', [today()->subDays($days - 1)->startOfDay(), today()->endOfDay()]))->selectRaw('product_id, name, unit, SUM(quantity) as quantity, SUM(total) as total')->groupBy('product_id', 'name', 'unit')->orderByDesc('total')->limit(5)->get();
        $register = $registers->current(auth()->id());

        return view('dashboard', compact('today', 'summary', 'transactions', 'payments', 'days', 'overview', 'lowStock', 'recentSales', 'recentExpenses', 'topProducts', 'register'));
    }
}
