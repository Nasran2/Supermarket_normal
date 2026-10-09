<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Services\PdfReportService;
use App\Services\SupplierLedgerService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class SupplierAccountController extends Controller
{
    private function filters(Request $request): array
    {
        abort_unless($request->user()->hasPermission('suppliers.view'), 403);

        return $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d'.($request->filled('from') ? '|after_or_equal:from' : ''), 'export' => 'nullable|in:pdf,csv']);
    }

    public function show(Request $request, Supplier $record)
    {
        $filters = $this->filters($request);
        $record = Supplier::withDueBalances()->findOrFail($record->id);
        $account = $request->user()->hasPermission('suppliers.ledger') ? app(SupplierLedgerService::class)->build($record, $filters) : ['entries' => collect(), 'opening' => '0', 'debits' => '0', 'credits' => '0', 'closing' => '0'];
        $entries = $account['entries'];
        $page = LengthAwarePaginator::resolveCurrentPage('ledger_page');
        $ledger = new LengthAwarePaginator($entries->forPage($page, 20)->values(), $entries->count(), 20, $page, ['path' => $request->url(), 'pageName' => 'ledger_page', 'query' => $request->query()]);
        $purchases = $record->purchases()->withPaymentTotals()->with('user')->when(! empty($filters['from']), fn ($q) => $q->whereDate('purchase_date', '>=', $filters['from']))->when(! empty($filters['to']), fn ($q) => $q->whereDate('purchase_date', '<=', $filters['to']))->latest('purchase_date')->latest('id')->paginate(10, ['*'], 'purchases_page')->withQueryString();

        return view('suppliers.show', compact('record', 'filters', 'account', 'ledger', 'purchases'));
    }

    public function ledger(Request $request, Supplier $supplier)
    {
        abort_unless($request->user()->hasPermission('suppliers.ledger') && $request->user()->hasPermission('suppliers.export'), 403);
        $filters = $this->filters($request);
        $account = app(SupplierLedgerService::class)->build($supplier, $filters);
        $headers = ['Date', 'Type', 'Purchase reference', 'Details', 'Debit', 'Credit', 'Balance'];
        $rows = $account['entries']->map(fn ($row) => [$row['date']->format('Y-m-d H:i'), $row['type'], $row['reference'], $row['description'].($row['type'] === 'Unrecorded' ? ' · Invoice total: '.Money::display($row['amount']) : ''), Money::display($row['debit']), Money::display($row['credit']), Money::display($row['balance'])]);
        if (($filters['export'] ?? 'pdf') === 'csv') {
            return response()->streamDownload(function () use ($headers, $rows) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, $headers);
                foreach ($rows as $row) {
                    fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+@\-\t\r]/', $v) ? "'".$v : $v, $row));
                }
                fclose($out);
            }, 'supplier-'.$supplier->id.'-ledger.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return app(PdfReportService::class)->download('supplier-'.$supplier->id.'-ledger', [
            'title' => 'Supplier ledger', 'subtitle' => $supplier->name, 'filters' => $filters, 'headers' => $headers, 'rows' => $rows, 'cards' => ['Opening balance' => $account['opening'], 'Period debits' => $account['debits'], 'Period credits' => $account['credits'], 'Closing balance' => $account['closing']],
            'notes' => 'Debit increases the amount owed; credit reduces it. Purchases without recorded payment balances are shown with zero ledger impact. Opening payments are historical balances, not new cash movements.',
            'rowCount' => $account['entries']->count(),
            'contact' => ['Supplier' => $supplier->name, 'Address' => $supplier->address, 'Phone' => $supplier->phone, 'Email' => $supplier->email],
        ]);
    }
}
