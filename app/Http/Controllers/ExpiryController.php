<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductStockLayer;
use App\Models\Supplier;
use App\Services\ExpiryReportService;
use App\Services\ExpiryWriteoffService;
use App\Services\PdfReportService;
use App\Support\Audit;
use App\Support\Expiry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpiryController extends Controller
{
    public function index(Request $request, ExpiryReportService $service)
    {
        abort_unless(Expiry::enabled(), 404);
        abort_unless($request->user()->hasPermission('expiry.view'), 403);
        if ($request->route('format')) {
            $request->merge(['format' => $request->route('format')]);
        }
        $filters = $request->validate(['tab' => 'nullable|in:soon,expired,all', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'status' => 'nullable|in:Safe,Expiring soon,Urgent,Expired,Unknown expiry', 'product_id' => 'nullable|integer', 'category_id' => 'nullable|integer', 'supplier_id' => 'nullable|integer', 'batch' => 'nullable|string|max:100', 'purchase' => 'nullable|string|max:150', 'q' => 'nullable|string|max:150', 'positive_only' => 'nullable|in:0,1', 'format' => 'nullable|in:pdf,csv']);
        $query = $service->query($filters);
        $canCost = $request->user()->hasPermission('expiry.view_cost') && $request->user()->hasPermission('products.view_cost');
        if (! empty($filters['format'])) {
            $headers = ['Product', 'SKU', 'Supplier', 'Source', 'Expiry', 'Days remaining', 'Quantity', 'Unit'];
            if (! \App\Support\Units::enabled()) {
                array_pop($headers);
            }
            if (Expiry::setting('expiry_batch_enabled')) {
                array_splice($headers, 2, 0, ['Batch']);
            }
            if ($canCost) {
                $headers[] = 'Cost value';
            }
            $headers = [...$headers, 'Selling value', 'Status'];
            if ($filters['format'] === 'csv') {
                return response()->streamDownload(function () use ($query, $service, $canCost, $headers) {
                    $out = fopen('php://output', 'w');
                    fputcsv($out, $headers);
                    foreach ($query->lazy(300) as $layer) {
                        $row = $service->exportRow($layer, $canCost);
                        fputcsv($out, array_map(fn ($v) => preg_match('/^[=+@\-]/', (string) $v) ? "'".$v : $v, $row));
                    }
                    fclose($out);
                }, 'expiry-stock.csv', ['Content-Type' => 'text/csv']);
            }

            return app(PdfReportService::class)->download('expiry-stock', ['title' => 'Expiry stock report', 'subtitle' => ucfirst($filters['tab'] ?? 'soon').' expiry stock', 'filters' => $filters, 'headers' => $headers, 'rows' => $query->lazy(300)->map(fn ($l) => $service->exportRow($l, $canCost)), 'cards' => [], 'rowCount' => (clone $query)->count()]);
        }
        $rows = $query->paginate(30)->withQueryString();
        $products = Product::where('track_expiry', true)->orderBy('name')->get(['id', 'name']);
        $categories = Category::orderBy('name')->get(['id', 'name']);
        $suppliers = Supplier::orderBy('name')->get(['id', 'name']);

        return view('expiry.index', compact('rows', 'filters', 'canCost', 'products', 'categories', 'suppliers'));
    }

    public function layer(Request $request, int $layer)
    {
        abort_unless(Expiry::enabled(), 404);
        abort_unless($request->user()->hasPermission('purchase.expiry_edit'), 403);
        $values = $request->validate(['expires_at' => 'required|date_format:Y-m-d', 'batch_number' => 'nullable|string|max:100', 'reason' => 'required|string|min:3|max:255']);
        DB::transaction(function () use ($layer, $values) {
            $row = ProductStockLayer::findOrFail($layer);
            Product::whereKey($row->product_id)->lockForUpdate()->firstOrFail();
            $row = ProductStockLayer::whereKey($layer)->lockForUpdate()->firstOrFail();
            abort_unless(Expiry::tracks($row->product) && $row->status === 'ACTIVE', 422);
            $batchNumber = Expiry::setting('expiry_batch_enabled') ? ($values['batch_number'] ?? null) : $row->batch_number;
            if ($row->expires_at && ($row->expires_at->toDateString() !== $values['expires_at'] || $row->batch_number !== $batchNumber) && $row->allocations()->exists()) {
                throw ValidationException::withMessages(['expires_at' => 'This batch has completed sales. Its expiry cannot be changed. Use a stock adjustment with the correct date.']);
            }
            $before = $row->getAttributes();
            $row->update(['expires_at' => $values['expires_at'], 'batch_number' => $batchNumber, 'expiry_source' => $row->expiry_source ?? $row->source_type]);
            Audit::record('expiry.correct', $row, $before, $row->getAttributes() + ['reason' => $values['reason']]);
        }, 3);

        return back()->with('success', 'Batch date corrected. Existing sales keep their recorded expiry.');
    }

    public function writeoff(Request $request, int $layer, ExpiryWriteoffService $service)
    {
        abort_unless(Expiry::enabled(), 404);
        abort_unless($request->user()->hasPermission('expiry.writeoff'), 403);
        $data = $request->validate(['token' => 'required|uuid', 'quantity' => 'required|numeric|gt:0|max:999999999|decimal:0,3', 'reason' => 'required|string|min:3|max:255']);
        $expense = $service->writeoff($layer, (string) $data['quantity'], $data['reason'], $request->user(), $data['token']);

        return back()->with('success', 'Expired stock removed. Expense '.$expense->reference.' recorded at inventory cost.');
    }
}
