<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportRequest;
use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use App\Services\PdfReportService;
use App\Services\ProfitLossService;
use App\Services\ReportService;
use App\Support\Money;
use App\Support\SalesVisibility;

class ReportController extends Controller
{
    public function index()
    {
        return view('reports.index', ['titles' => ReportService::TITLES]);
    }

    public function show(ReportRequest $request, string $report, ReportService $service, ProfitLossService $profit)
    {
        abort_unless(isset(ReportService::TITLES[$report]), 404);
        $filters = $request->validated();
        $title = ReportService::TITLES[$report];
        if ($report === 'profit') {
            abort_unless($request->user()->hasPermission('products.view_cost'), 403);
            $summary = $profit->calculate($filters['from'], $filters['to']);

            return view('reports.profit', compact('filters', 'title', 'summary'));
        }
        $result = $service->build($report, $filters);
        $records = $result['query']->paginate(25)->withQueryString();
        $rows = $records->getCollection()->map($result['map']);
        $headers = $result['headers'];
        $cards = $result['cards'];
        $methods = PaymentMethod::orderBy('display_order')->get();
        $users = User::orderBy('name')->get();
        $rules = collect(); // Deprecated

        $products = $report === 'stock' ? Product::orderBy('name')->get(['id', 'name']) : collect();
        $categories = $report === 'stock' ? Category::orderBy('name')->get(['id', 'name']) : collect();

        return view('reports.table', compact('report', 'filters', 'title', 'records', 'rows', 'headers', 'cards', 'methods', 'users', 'rules', 'products', 'categories'));
    }

    public function pdf(ReportRequest $request, string $report, ReportService $service, ProfitLossService $profit)
    {
        abort_unless(isset(ReportService::TITLES[$report]), 404);
        $filters = $request->validated();
        $title = ReportService::TITLES[$report];
        $labels = [];
        if (! in_array($report, ['purchases', 'stock']) && SalesVisibility::mode() !== 'ALL') {
            $labels['Sales access'] = SalesVisibility::OPTIONS[SalesVisibility::mode()];
        }
        foreach (['payment_method_id' => [PaymentMethod::class, 'Payment method'], 'user_id' => [User::class, 'Cashier'], 'product_id' => [Product::class, 'Product'], 'category_id' => [Category::class, 'Category']] as $key => [$model,$label]) {
            if (! empty($filters[$key])) {
                $labels[$label] = $model::find($filters[$key])?->name ?? '-';
            }
        }
        foreach (['q' => 'Search', 'charge_bearer' => 'Charge bearer', 'stock_view' => 'Stock view', 'stock_status' => 'Stock status', 'selling_price' => 'Selling price', 'cost_price' => 'Cost price', 'source_reference' => 'Source', 'received_from' => 'Received from', 'received_to' => 'Received to'] as $key => $label) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $labels[$label] = $filters[$key];
            }
        }
        if ($report === 'profit') {
            abort_unless($request->user()->hasPermission('products.view_cost'), 403);
            $summary = $profit->calculate($filters['from'], $filters['to']);
            $headers = ['Profit & loss statement', 'Amount'];
            $rows = collect(['revenue' => 'Sales revenue', 'cogs' => 'Cost of goods sold', 'gross' => 'Gross profit', 'manual' => 'Manual expenses', 'processing' => 'Business-paid processing expenses', 'purchaseCharges' => 'Purchase shipping & other charges', 'expenses' => 'Total operating expenses', 'net' => 'Net profit'])->map(fn ($label, $key) => [$label, Money::display($summary[$key])])->values();
            $cards = ['Revenue' => $summary['revenue'], 'Gross profit' => $summary['gross'], 'Net profit' => $summary['net']];
            $notes = 'Revenue and cost of goods sold are net of returns. Voided sales and reversed expenses are excluded. Customer-paid processing fees are not product revenue.';
        } else {
            $result = $service->build($report, $filters);
            $headers = $result['headers'];
            $rowCount = (clone $result['query'])->getCountForPagination();
            $rows = $result['query']->lazy(500)->map($result['map']);
            $cards = $result['cards'];
            if ($report === 'audit') {
                $rows = $rows->map(function ($row) {
                    foreach ([4, 5] as $index) {
                        $row[$index] = wordwrap(json_encode(json_decode($row[$index], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 110, "\n", true);
                    }

                    return $row;
                });
            }
            $notes = match ($report) {
                'stock' => 'Current stock snapshot at export time. The reporting period does not reconstruct historical stock balances. Receipt date and source filters apply to stock price rows.',
                'product-sales' => 'Product quantities, cost and margins use transaction snapshots. Column headings identify values calculated before invoice discounts or returns.',
                'cash' => 'Opening balance includes transactions before the selected period. Money in and money out follow the selected payment method filter.',
                'register' => 'Open registers show their current calculated cash balances. Closed registers show recorded closing balances and differences.',
                default => 'All matching records are included, across every page of the report. Amounts are in the company currency.',
            };
        }

        return app(PdfReportService::class)->download($report, ['title' => $title.' report', 'report' => $report, 'filters' => $filters, 'filterLabels' => $labels, 'headers' => $headers, 'rows' => $rows, 'cards' => $cards, 'notes' => $notes, 'rowCount' => $rowCount ?? 0]);
    }

    public function export(ReportRequest $request, string $report, ReportService $service)
    {
        abort_unless(isset(ReportService::TITLES[$report]) && $report !== 'profit', 404);
        $result = $service->build($report, $request->validated());

        return response()->streamDownload(function () use ($result) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $result['headers']);
            foreach ($result['query']->lazy(500) as $record) {
                $cells = ($result['map'])($record);
                $cells = array_map(fn ($v) => is_string($v) && preg_match('/^[=+@\-\t\r]/', $v) ? "'".$v : $v, $cells);
                fputcsv($out, $cells);
            }fclose($out);
        }, $report.'-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
