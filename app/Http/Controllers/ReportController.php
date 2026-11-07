<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportRequest;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\PdfReportService;
use App\Services\ProfitLossService;
use App\Services\ReportService;
use App\Services\SettingsService;
use App\Support\AuditPresentation;
use App\Support\Money;
use App\Support\SalesPrivacy;
use App\Support\SalesVisibility;

class ReportController extends Controller
{
    private function privacy(string $report): ?array
    {
        return in_array($report, ReportService::RANKED_REPORTS, true) ? SalesPrivacy::view('reports') : null;
    }

    private function profitSummary(ProfitLossService $profit, array $filters, ?array $privacy): array
    {
        $sales = Sale::visibleTo()->whereBetween('sold_at', [$filters['from'].' 00:00:00', $filters['to'].' 23:59:59']);

        return $profit->calculate($filters['from'], $filters['to'], SalesPrivacy::selectedIds($sales, $privacy));
    }

    public function index()
    {
        if (class_exists(\App\Support\SecretControl::class) && \App\Support\SecretControl::isHidden('disable_reports')) {
            abort(403, 'Reports are currently disabled.');
        }

        return view('reports.index', ['titles' => ReportService::titles()]);
    }

    public function show(ReportRequest $request, string $report, ReportService $service, ProfitLossService $profit)
    {
        if (class_exists(\App\Support\SecretControl::class) && \App\Support\SecretControl::isHidden('disable_reports')) {
            abort(403, 'Reports are currently disabled.');
        }

        if ($report === 'profit' && class_exists(\App\Support\SecretControl::class) && \App\Support\SecretControl::isHidden('hide_profit')) {
            abort(403, 'Profit & loss report is hidden.');
        }

        $titles = ReportService::titles();
        abort_unless(isset($titles[$report]), 404);
        $filters = $request->validated();
        $title = $titles[$report];
        $privacy = $this->privacy($report);
        if ($report === 'profit') {
            abort_unless($request->user()->hasPermission('products.view_cost'), 403);
            $summary = $this->profitSummary($profit, $filters, $privacy);

            return view('reports.profit', compact('filters', 'title', 'summary', 'privacy'));
        }
        $result = $service->build($report, $filters, $privacy);
        $records = $result['query']->paginate(25)->withQueryString();
        $rows = $records->getCollection()->map($result['map']);
        $auditComparisons = $report === 'audit' ? $records->getCollection()->map(fn ($record) => AuditPresentation::compare($record->before, $record->after, app(SettingsService::class)->all(), $record->action)) : collect();
        $headers = $result['headers'];
        $cards = $result['cards'];
        $methods = PaymentMethod::orderBy('display_order')->get();
        $users = User::orderBy('name')->get();
        $rules = collect(); // Deprecated

        $products = $report === 'stock' ? Product::where('track_stock', true)->orderBy('name')->get(['id', 'name']) : collect();
        $categories = $report === 'stock' ? Category::orderBy('name')->get(['id', 'name']) : collect();

        return view('reports.table', compact('report', 'filters', 'title', 'records', 'rows', 'headers', 'cards', 'methods', 'users', 'rules', 'products', 'categories', 'auditComparisons', 'privacy'));
    }

    public function pdf(ReportRequest $request, string $report, ReportService $service, ProfitLossService $profit)
    {
        if (class_exists(\App\Support\SecretControl::class) && \App\Support\SecretControl::isHidden('disable_reports')) {
            abort(403, 'Reports are currently disabled.');
        }

        if ($report === 'profit' && class_exists(\App\Support\SecretControl::class) && \App\Support\SecretControl::isHidden('hide_profit')) {
            abort(403, 'Profit & loss report is hidden.');
        }

        $titles = ReportService::titles();
        abort_unless(isset($titles[$report]), 404);
        $filters = $request->validated();
        $title = $titles[$report];
        $labels = [];
        $privacy = $this->privacy($report);
        if ($privacy && $privacy['mode'] !== 'all') {
            $labels['Sales view'] = SalesPrivacy::MODES[$privacy['mode']].' · Up to '.$privacy['bill_count'].' bills';
        }
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
            $summary = $this->profitSummary($profit, $filters, $privacy);
            $headers = ['Profit & loss statement', 'Amount'];
            $rows = collect(['grossSales' => 'Gross sales', 'salesReturns' => 'Less sales returns', 'revenue' => 'Net sales revenue', 'cogs' => 'Cost of goods sold', 'gross' => 'Gross profit', 'manual' => 'Other operating expenses', 'processing' => 'Business-paid processing expenses', 'purchaseCharges' => 'Purchase shipping & other charges', 'returnWriteoffs' => 'Return write-off expenses', 'noReceiptCredit' => 'No-receipt approved credit (unverified)', 'estimatedRecovery' => 'Estimated returned inventory / supplier claim cost', 'noReceiptAdjustments' => 'No-receipt return adjustments', 'expenses' => 'Total operating expenses', 'net' => 'Net profit'])->map(fn ($label, $key) => [$label, Money::display($summary[$key])])->values();
            $cards = ['Revenue' => $summary['revenue'], 'Gross profit' => $summary['gross'], 'Net profit' => $summary['net']];
            $notes = 'Revenue and cost of goods sold are net of returns. Voided sales and reversed expenses are excluded. Customer-paid processing fees are not product revenue.';
        } else {
            $result = $service->build($report, $filters, $privacy);
            $headers = $result['headers'];
            $rowCount = (clone $result['query'])->getCountForPagination();
            $rows = $result['query']->lazy(500)->map($result['map']);
            $cards = $result['cards'];
            if ($report === 'returns') {
                $headers = ['Date / cashier', 'Return / type', 'Original bill(s)', 'Customer', 'Credit / replacement', 'Due credit', 'Refund / payment', 'Verification / cost source', 'Status / approval'];
                $rows = $rows->map(fn ($row) => [
                    $row[0]."\n".$row[8],
                    $row[1]."\n".($row[13] === 'NO_RECEIPT' ? 'Without bill' : 'With bill'),
                    str_replace(', ', "\n", $row[2]),
                    $row[16],
                    'Credit '.$row[3]."\nReplacement ".$row[10],
                    'Original '.$row[4]."\nOther ".$row[19],
                    'Refund '.$row[5].' '.$row[6]."\nPaid ".$row[11],
                    str_replace('_', ' ', $row[14])."\n".($row[15] ? 'Estimated: '.str_replace('_', ' ', $row[15]) : 'Historical cost'),
                    $row[12].($row[17] !== '—' ? "\nApproved #".$row[17]."\n".$row[18] : ''),
                ]);
            }
            if ($report === 'audit') {
                $rows = $rows->map(fn ($row) => AuditPresentation::formatRow($row, app(SettingsService::class)->all()));
            }
            $notes = match ($report) {
                'returns' => '',
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
        if (class_exists(\App\Support\SecretControl::class) && \App\Support\SecretControl::isHidden('disable_reports')) {
            abort(403, 'Reports are currently disabled.');
        }

        $titles = ReportService::titles();
        abort_unless(isset($titles[$report]) && $report !== 'profit', 404);
        $result = $service->build($report, $request->validated(), $this->privacy($report));

        return response()->streamDownload(function () use ($result) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $result['headers']);
            foreach ($result['query']->lazy(500) as $record) {
                $cells = ($result['map'])($record);
                if ($record instanceof AuditLog) {
                    $cells = AuditPresentation::formatRow($cells, app(SettingsService::class)->all());
                }
                $cells = \App\Support\Csv::row($cells);
                fputcsv($out, $cells);
            }fclose($out);
        }, $report.'-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
