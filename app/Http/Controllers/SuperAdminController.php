<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Supplier;
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

        // Calculate what normal users see with the current percentages
        $normalSummary = [];
        $sellMult = SecretControl::sellingPriceMultiplier();
        $costMult = SecretControl::costMultiplier();
        $genMult  = SecretControl::multiplier();
        foreach ($realSummary as $k => $v) {
            $mult = match ($k) {
                'grossSales', 'salesReturns', 'revenue' => $sellMult,
                'cogs', 'estimatedRecovery' => $costMult,
                default => $genMult,
            };
            $normalSummary[$k] = round((float) $v * $mult, 2);
        }
        if (isset($normalSummary['revenue'], $normalSummary['cogs'])) {
            $normalSummary['gross'] = round($normalSummary['revenue'] - $normalSummary['cogs'], 2);
            $normalSummary['net'] = round($normalSummary['gross'] - ($normalSummary['expenses'] ?? 0), 2);
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
            $dayRev = (float) ($profit->calculate($day, $day)['revenue'] ?? 0);
            $overview[] = [
                'label'       => $date->format('d M'),
                'real_amount' => $dayRev,
                'user_amount' => round($dayRev * $sellMult, 2),
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
        $costPercentage  = SecretControl::costPercentage();

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

        return view('superadmin.products', compact('products', 'stats', 'systemActive', 'percentage', 'pricePercentage', 'costPercentage'));
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

        $stockPercentage        = SecretControl::stockPercentage();
        $sellingPricePercentage = SecretControl::sellingPricePercentage();
        $costPercentage         = SecretControl::costPercentage();
        $pricePercentage        = $sellingPricePercentage; // backward compatibility

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
            'sellingPricePercentage',
            'costPercentage',
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
            'visible_stock_percentage'         => ['required', 'numeric', 'min:0', 'max:100'],
            'visible_selling_price_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'visible_price_percentage'         => ['nullable', 'numeric', 'min:0', 'max:100'],
            'visible_cost_percentage'          => ['required', 'numeric', 'min:0', 'max:100'],
            'hide_cost_price_ranges'           => ['nullable', 'string', 'max:255'],
            'hide_selling_price_ranges'        => ['nullable', 'string', 'max:255'],
        ]);

        $sellingPct = (float) ($request->input('visible_selling_price_percentage', $request->input('visible_price_percentage', 100)));

        SecretControl::set('visible_stock_percentage', (float) $validated['visible_stock_percentage']);
        SecretControl::set('visible_selling_price_percentage', $sellingPct);
        SecretControl::set('visible_price_percentage', $sellingPct);
        SecretControl::set('visible_cost_percentage', (float) $validated['visible_cost_percentage']);
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

        $switches = [
            'mask_supplier_customer_names' => (bool) SecretControl::get('mask_supplier_customer_names', 0),
            'hide_all_customers'           => (bool) SecretControl::get('hide_all_customers', 0),
            'hide_all_suppliers'           => (bool) SecretControl::get('hide_all_suppliers', 0),
            'mask_invoice_numbers'         => (bool) SecretControl::get('mask_invoice_numbers', 0),
            'mask_payment_amounts'         => (bool) SecretControl::get('mask_payment_amounts', 0),
            'hide_weekly_purchase_info'    => (bool) SecretControl::get('hide_weekly_purchase_info', 0),
            'hide_monthly_purchase_info'   => (bool) SecretControl::get('hide_monthly_purchase_info', 0),
            'hide_genuine_stock_values'    => (bool) SecretControl::get('hide_genuine_stock_values', 0),
            'hide_own_cheques'             => (bool) SecretControl::get('hide_own_cheques', 0),
            'hide_customer_cheques'        => (bool) SecretControl::get('hide_customer_cheques', 0),
            'hide_party_cheques'           => (bool) SecretControl::get('hide_party_cheques', 0),
        ];

        $customerPercentage = SecretControl::customerAmountPercentage();
        $chequePercentage   = SecretControl::chequeAmountPercentage();
        $chequeCountRule    = SecretControl::get('cheque_count_rule', 'NONE');
        $chequeRuleLimit    = (int) SecretControl::get('cheque_rule_limit', 0);

        $hiddenSuppliers    = SecretControl::hiddenSuppliersList();
        $hiddenCustomers    = SecretControl::hiddenCustomersList();
        $hiddenSalesBills   = SecretControl::hiddenSalesList();

        $hideSalesAmountRanges       = SecretControl::get('hide_sales_amount_ranges', '');
        $hidePurchaseAmountRanges    = SecretControl::get('hide_purchase_amount_ranges', '');
        $hideCustomerPurchaseRanges  = SecretControl::get('hide_customer_purchase_ranges', '');

        return view('superadmin.hide-records', compact(
            'systemActive',
            'percentage',
            'switches',
            'customerPercentage',
            'chequePercentage',
            'chequeCountRule',
            'chequeRuleLimit',
            'hiddenSuppliers',
            'hiddenCustomers',
            'hiddenSalesBills',
            'hideSalesAmountRanges',
            'hidePurchaseAmountRanges',
            'hideCustomerPurchaseRanges'
        ));
    }

    /**
     * Save Hide Records settings.
     */
    public function saveHideRecords(Request $request)
    {
        $validated = $request->validate([
            'visible_customer_amount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'visible_cheque_amount_percentage'   => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cheque_count_rule'                  => ['nullable', 'string', 'max:50'],
            'cheque_rule_limit'                  => ['nullable', 'integer', 'min:0'],
            'hide_sales_amount_ranges'           => ['nullable', 'string', 'max:255'],
            'hide_purchase_amount_ranges'        => ['nullable', 'string', 'max:255'],
            'hide_customer_purchase_ranges'      => ['nullable', 'string', 'max:255'],
        ]);

        $switches = [
            'mask_supplier_customer_names',
            'hide_all_customers',
            'hide_all_suppliers',
            'mask_invoice_numbers',
            'mask_payment_amounts',
            'hide_weekly_purchase_info',
            'hide_monthly_purchase_info',
            'hide_genuine_stock_values',
            'hide_own_cheques',
            'hide_customer_cheques',
            'hide_party_cheques',
        ];

        foreach ($switches as $key) {
            SecretControl::set($key, $request->has($key) ? 1 : 0);
        }

        SecretControl::set('visible_customer_amount_percentage', (float) ($validated['visible_customer_amount_percentage'] ?? 100));

        if (config('features.cheque')) {
            SecretControl::set('visible_cheque_amount_percentage', (float) ($validated['visible_cheque_amount_percentage'] ?? 100));
            SecretControl::set('cheque_count_rule', $validated['cheque_count_rule'] ?? 'NONE');
            SecretControl::set('cheque_rule_limit', (int) ($validated['cheque_rule_limit'] ?? 0));
        }

        SecretControl::set('hide_sales_amount_ranges', trim((string) ($validated['hide_sales_amount_ranges'] ?? '')));
        SecretControl::set('hide_purchase_amount_ranges', trim((string) ($validated['hide_purchase_amount_ranges'] ?? '')));
        SecretControl::set('hide_customer_purchase_ranges', trim((string) ($validated['hide_customer_purchase_ranges'] ?? '')));

        return redirect()->route('superadmin.hide-records')
            ->with('success', 'Hide Records controls saved successfully!');
    }

    public function searchSuppliers(Request $request)
    {
        $q = trim((string) $request->input('q', ''));
        if (strlen($q) < 1) {
            return response()->json([]);
        }

        $suppliers = Supplier::where(function ($sub) use ($q) {
            $sub->where('name', 'like', "%{$q}%")
                ->orWhere('phone', 'like', "%{$q}%");
        })->limit(10)->get(['id', 'name', 'phone']);

        return response()->json($suppliers);
    }

    public function addHiddenSupplier(Request $request)
    {
        $input = trim((string) $request->input('supplier', ''));
        if ($input === '') {
            return back()->with('error', 'Please enter a supplier name or phone.');
        }

        $supplier = is_numeric($input) ? Supplier::find((int) $input) : null;
        if (! $supplier) {
            $supplier = Supplier::where('phone', $input)
                ->orWhere('name', 'like', "%{$input}%")
                ->first();
        }

        if (! $supplier) {
            return back()->with('error', "No supplier found matching '{$input}'.");
        }

        SecretControl::addHiddenSupplier($supplier->id);

        if ($request->wantsJson()) {
            return response()->json([
                'success'  => true,
                'supplier' => ['id' => $supplier->id, 'name' => $supplier->name, 'phone' => $supplier->phone ?? ''],
                'hidden'   => SecretControl::hiddenSuppliersList(),
            ]);
        }

        return back()->with('success', "Supplier '{$supplier->name}' hidden from normal users.");
    }

    public function removeHiddenSupplier(Request $request, $id)
    {
        SecretControl::removeHiddenSupplier((int) $id);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'hidden'  => SecretControl::hiddenSuppliersList(),
            ]);
        }

        return back()->with('success', 'Supplier removed from hidden list.');
    }

    public function searchCustomers(Request $request)
    {
        $q = trim((string) $request->input('q', ''));
        if (strlen($q) < 1) {
            return response()->json([]);
        }

        $customers = Customer::where(function ($sub) use ($q) {
            $sub->where('name', 'like', "%{$q}%")
                ->orWhere('phone', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%");
        })->limit(10)->get(['id', 'name', 'phone']);

        return response()->json($customers);
    }

    public function addHiddenCustomer(Request $request)
    {
        $input = trim((string) $request->input('customer', ''));
        if ($input === '') {
            return back()->with('error', 'Please enter a customer name or phone.');
        }

        $customer = is_numeric($input) ? Customer::find((int) $input) : null;
        if (! $customer) {
            $customer = Customer::where('phone', $input)
                ->orWhere('name', 'like', "%{$input}%")
                ->first();
        }

        if (! $customer) {
            return back()->with('error', "No customer found matching '{$input}'.");
        }

        SecretControl::addHiddenCustomer($customer->id);

        if ($request->wantsJson()) {
            return response()->json([
                'success'  => true,
                'customer' => ['id' => $customer->id, 'name' => $customer->name, 'phone' => $customer->phone ?? ''],
                'hidden'   => SecretControl::hiddenCustomersList(),
            ]);
        }

        return back()->with('success', "Customer '{$customer->name}' hidden from normal users.");
    }

    public function removeHiddenCustomer(Request $request, $id)
    {
        SecretControl::removeHiddenCustomer((int) $id);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'hidden'  => SecretControl::hiddenCustomersList(),
            ]);
        }

        return back()->with('success', 'Customer removed from hidden list.');
    }

    public function searchSales(Request $request)
    {
        $q = trim((string) $request->input('q', ''));
        if (strlen($q) < 1) {
            return response()->json([]);
        }

        $sales = Sale::with('customer')->where(function ($sub) use ($q) {
            $sub->where('invoice', 'like', "%{$q}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$q}%"));
        })->limit(10)->get(['id', 'invoice', 'customer_id', 'customer_payable']);

        $res = $sales->map(fn ($s) => [
            'id'            => $s->id,
            'invoice'       => $s->invoice,
            'customer_name' => $s->customer?->name ?? 'Walk-in',
            'amount'        => (float) $s->customer_payable,
        ]);

        return response()->json($res);
    }

    public function addHiddenSale(Request $request)
    {
        $input = trim((string) $request->input('sale', ''));
        if ($input === '') {
            return back()->with('error', 'Please enter an invoice number or sale ID.');
        }

        $sale = is_numeric($input) ? Sale::find((int) $input) : null;
        if (! $sale) {
            $sale = Sale::where('invoice', $input)->first();
        }

        if (! $sale) {
            return back()->with('error', "No sale found matching '{$input}'.");
        }

        SecretControl::addHiddenSale($sale->id);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'sale'    => ['id' => $sale->id, 'invoice' => $sale->invoice],
                'hidden'  => SecretControl::hiddenSalesList(),
            ]);
        }

        return back()->with('success', "Sale invoice '{$sale->invoice}' hidden from normal users.");
    }

    public function removeHiddenSale(Request $request, $id)
    {
        SecretControl::removeHiddenSale((int) $id);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'hidden'  => SecretControl::hiddenSalesList(),
            ]);
        }

        return back()->with('success', 'Sale removed from hidden list.');
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
