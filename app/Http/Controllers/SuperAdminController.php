<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\ProfitLossService;
use App\Support\SecretControl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SuperAdminController extends Controller
{
    /**
     * Show the Super Admin Dashboard (Overview).
     * Shows full real metrics + preview of what normal users see.
     */
    public function dashboard(Request $request, ProfitLossService $profit)
    {
        $systemActive = (bool) SecretControl::get('system_active', 1);
        $percentage   = SecretControl::percentage();
        $multiplier   = max(0, min(100, $percentage)) / 100;

        // Date range (defaults to this month or today)
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to   = $request->input('to', today()->toDateString());

        // Real calculations (Super Admin has 100% unrestricted multiplier)
        $realSummary = $profit->calculate($from, $to);

        // Calculate what normal users see with the current percentage
        $normalSummary = [];
        foreach ($realSummary as $k => $v) {
            $normalSummary[$k] = round((float) $v * $multiplier, 2);
        }

        // Stats
        $stats = [
            'users'        => User::count(),
            'products'     => Product::count(),
            'sales_count'  => Sale::where('status', 'ACTIVE')->count(),
            'period_sales' => Sale::where('status', 'ACTIVE')->whereBetween('sold_at', [$from . ' 00:00:00', $to . ' 23:59:59'])->count(),
        ];

        // Recent sales
        $recentSales = Sale::with('user', 'payments')->latest('sold_at')->limit(8)->get();

        // 7-day sales overview
        $days = 7;
        $overview = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = today()->subDays($i);
            $day = $date->toDateString();
            $overview[] = [
                'label'       => $date->format('d M'),
                'real_amount' => $profit->calculate($day, $day)['revenue'],
                'user_amount' => round((float) $profit->calculate($day, $day)['revenue'] * $multiplier, 2),
            ];
        }

        return view('superadmin.dashboard', compact(
            'systemActive',
            'percentage',
            'multiplier',
            'from',
            'to',
            'realSummary',
            'normalSummary',
            'stats',
            'recentSales',
            'overview'
        ));
    }

    /**
     * Show the Secret Dashboard Controls (matching Image 3).
     */
    public function controls()
    {
        $systemActive = (bool) SecretControl::get('system_active', 1);
        $percentage   = SecretControl::percentage();

        $switches = [
            'hide_top_summary'     => (bool) SecretControl::get('hide_top_summary', 0),
            'hide_sales_value'     => (bool) SecretControl::get('hide_sales_value', 0),
            'hide_purchases_value' => (bool) SecretControl::get('hide_purchases_value', 0),
            'hide_profit'          => (bool) SecretControl::get('hide_profit', 0),
            'hide_stock_money'     => (bool) SecretControl::get('hide_stock_money', 0),
            'hide_stock_counts'    => (bool) SecretControl::get('hide_stock_counts', 0),
            'hide_charts'          => (bool) SecretControl::get('hide_charts', 0),
            'hide_tables'          => (bool) SecretControl::get('hide_tables', 0),
            'hide_small_widgets'   => (bool) SecretControl::get('hide_small_widgets', 0),
            'disable_reports'      => (bool) SecretControl::get('disable_reports', 0),
        ];

        return view('superadmin.controls', compact('systemActive', 'percentage', 'switches'));
    }

    /**
     * Save the Secret Dashboard Controls.
     */
    public function saveControls(Request $request)
    {
        $validated = $request->validate([
            'visible_profit_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        SecretControl::set('visible_profit_percentage', (float) $validated['visible_profit_percentage']);

        $toggleKeys = [
            'hide_top_summary',
            'hide_sales_value',
            'hide_purchases_value',
            'hide_profit',
            'hide_stock_money',
            'hide_stock_counts',
            'hide_charts',
            'hide_tables',
            'hide_small_widgets',
            'disable_reports',
        ];

        foreach ($toggleKeys as $key) {
            SecretControl::set($key, $request->has($key) ? 1 : 0);
        }

        $pct = $validated['visible_profit_percentage'];

        return redirect()->route('superadmin.controls')
            ->with('success', "Dashboard controls saved! Normal users and admins will now see {$pct}% of profit, price and cost.");
    }

    /**
     * Toggle the entire system online/offline (Kill Switch).
     */
    public function toggleSystem(Request $request)
    {
        $current = (bool) SecretControl::get('system_active', 1);
        $new     = ! $current;

        SecretControl::set('system_active', $new ? 1 : 0);

        $status = $new ? 'enabled' : 'disabled';

        return back()->with('success', "System has been {$status} successfully.");
    }

    /**
     * Super Admin Real Sales view.
     * Shows 100% REAL figures for all sales.
     */
    public function sales(Request $request)
    {
        $systemActive = (bool) SecretControl::get('system_active', 1);
        $percentage   = SecretControl::percentage();

        $query = Sale::with(['user', 'customer', 'payments', 'register']);
        if ($request->filled('q')) {
            $q = $request->input('q');
            $query->where(fn ($sub) => $sub->where('invoice', 'like', "%{$q}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$q}%")));
        }
        if ($request->filled('from')) {
            $query->whereDate('sold_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('sold_at', '<=', $request->input('to'));
        }

        $totals = (clone $query)->where('status', 'ACTIVE')
            ->selectRaw('COUNT(*) as invoices, SUM(sale_amount) as amount, SUM(customer_payable) as payable')
            ->first();

        $sales = $query->latest('sold_at')->paginate(20)->withQueryString();

        return view('superadmin.sales', compact('sales', 'totals', 'systemActive', 'percentage'));
    }

    /**
     * Super Admin Real Products view.
     * Shows 100% REAL costs, real prices, and inventory value.
     */
    public function products(Request $request)
    {
        $systemActive    = (bool) SecretControl::get('system_active', 1);
        $percentage      = SecretControl::percentage();
        $pricePercentage = SecretControl::pricePercentage();

        $query = Product::with(['categories', 'unit', 'stockLayers']);
        if ($request->filled('q')) {
            $q = $request->input('q');
            $query->where(fn ($sub) => $sub->where('name', 'like', "%{$q}%")
                ->orWhere('sku', 'like', "%{$q}%")
                ->orWhere('barcode', 'like', "%{$q}%"));
        }

        $products = $query->orderBy('name')->paginate(20)->withQueryString();

        $stats = Product::selectRaw('COUNT(*) as total, SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) as active_count, 0 as stock_value')->first();
        $stats->stock_value = \App\Models\ProductStockLayer::available()
            ->selectRaw('SUM(COALESCE(remaining_cost_total, remaining_quantity * cost_price)) as value')
            ->value('value') ?? '0';

        return view('superadmin.products', compact('products', 'stats', 'systemActive', 'percentage', 'pricePercentage'));
    }

    /**
     * Super Admin Real Profit & Loss view.
     * Shows 100% REAL figures, real income statement and gross/net profit.
     */
    public function profit(Request $request, ProfitLossService $profitService)
    {
        $systemActive = (bool) SecretControl::get('system_active', 1);
        $percentage   = SecretControl::percentage();

        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to   = $request->input('to', today()->toDateString());

        $summary = $profitService->calculate($from, $to);

        return view('superadmin.profit', compact('summary', 'from', 'to', 'systemActive', 'percentage'));
    }

    /**
     * Show Product & Stock Visibility controls (matching Image).
     */
    public function stock()
    {
        $systemActive = (bool) SecretControl::get('system_active', 1);

        $stockPercentage = SecretControl::stockPercentage();
        $pricePercentage = SecretControl::pricePercentage();

        $switches = [
            'enable_pos_price_percentage' => (bool) SecretControl::get('enable_pos_price_percentage', 0),
            'hide_exact_stock_quantity'   => (bool) SecretControl::get('hide_exact_stock_quantity', 0),
            'hide_exact_selling_price'    => (bool) SecretControl::get('hide_exact_selling_price', 0),
            'hide_exact_purchase_cost'    => (bool) SecretControl::get('hide_exact_purchase_cost', 0),
            'hide_all_price_data'         => (bool) SecretControl::get('hide_all_price_data', 0),
            'hide_all_quantity_data'      => (bool) SecretControl::get('hide_all_quantity_data', 0),
            'hide_product_level_data'     => (bool) SecretControl::get('hide_product_level_data', 0),
        ];

        $hiddenProducts = SecretControl::hiddenProductsList();
        $hideCostPriceRanges = SecretControl::get('hide_cost_price_ranges', '');
        $hideSellingPriceRanges = SecretControl::get('hide_selling_price_ranges', '');

        return view('superadmin.stock', compact(
            'systemActive',
            'stockPercentage',
            'pricePercentage',
            'switches',
            'hiddenProducts',
            'hideCostPriceRanges',
            'hideSellingPriceRanges'
        ));
    }

    /**
     * Save Product & Stock Visibility settings.
     */
    public function saveStock(Request $request)
    {
        $validated = $request->validate([
            'visible_stock_percentage'  => ['required', 'numeric', 'min:0', 'max:100'],
            'visible_price_percentage'  => ['required', 'numeric', 'min:0', 'max:100'],
            'hide_cost_price_ranges'    => ['nullable', 'string', 'max:255'],
            'hide_selling_price_ranges' => ['nullable', 'string', 'max:255'],
        ]);

        SecretControl::set('visible_stock_percentage', (float) $validated['visible_stock_percentage']);
        SecretControl::set('visible_price_percentage', (float) $validated['visible_price_percentage']);
        SecretControl::set('hide_cost_price_ranges', trim((string) ($validated['hide_cost_price_ranges'] ?? '')));
        SecretControl::set('hide_selling_price_ranges', trim((string) ($validated['hide_selling_price_ranges'] ?? '')));

        $switches = [
            'enable_pos_price_percentage',
            'hide_exact_stock_quantity',
            'hide_exact_selling_price',
            'hide_exact_purchase_cost',
            'hide_all_price_data',
            'hide_all_quantity_data',
            'hide_product_level_data',
        ];

        foreach ($switches as $key) {
            SecretControl::set($key, $request->has($key) ? 1 : 0);
        }

        return redirect()->route('superadmin.stock')
            ->with('success', 'Product & Stock visibility controls saved successfully!');
    }

    /**
     * Search products for autocomplete in "Hide products by search".
     */
    public function searchProducts(Request $request)
    {
        $q = trim((string) $request->input('q', ''));
        if (strlen($q) < 1) {
            return response()->json([]);
        }

        $products = Product::where(function ($sub) use ($q) {
            $sub->where('name', 'like', "%{$q}%")
                ->orWhere('sku', 'like', "%{$q}%")
                ->orWhere('barcode', 'like', "%{$q}%");
        })->limit(10)->get(['id', 'name', 'sku', 'barcode', 'price', 'stock']);

        return response()->json($products);
    }

    /**
     * Add a hidden product via search / input.
     */
    public function addHiddenProduct(Request $request)
    {
        $input = trim((string) $request->input('product', ''));
        if ($input === '') {
            return back()->with('error', 'Please enter a product name, SKU, or barcode.');
        }

        // Try find by ID first
        $product = is_numeric($input) ? Product::find((int) $input) : null;

        // Try find by SKU / barcode / name
        if (! $product) {
            $product = Product::where('sku', $input)
                ->orWhere('barcode', $input)
                ->orWhere('name', 'like', "%{$input}%")
                ->first();
        }

        if (! $product) {
            return back()->with('error', "No product found matching '{$input}'.");
        }

        SecretControl::addHiddenProduct($product->id);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'product' => [
                    'id'      => $product->id,
                    'name'    => $product->name,
                    'sku'     => $product->sku,
                    'barcode' => $product->barcode,
                ],
                'hidden' => SecretControl::hiddenProductsList(),
            ]);
        }

        return back()->with('success', "Product '{$product->name}' hidden from normal users.");
    }

    /**
     * Remove a product from hidden products.
     */
    public function removeHiddenProduct(Request $request, $id)
    {
        SecretControl::removeHiddenProduct((int) $id);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'hidden'  => SecretControl::hiddenProductsList(),
            ]);
        }

        return back()->with('success', 'Product removed from hidden list.');
    }

    /**
     * Hide Records view.
     */
    public function hideRecords()
    {
        $systemActive = (bool) SecretControl::get('system_active', 1);
        $percentage   = SecretControl::percentage();
        return view('superadmin.hide-records', compact('systemActive', 'percentage'));
    }

    /**
     * Privacy Mode view.
     */
    public function privacyMode()
    {
        $systemActive = (bool) SecretControl::get('system_active', 1);
        $percentage   = SecretControl::percentage();
        return view('superadmin.privacy-mode', compact('systemActive', 'percentage'));
    }
}
