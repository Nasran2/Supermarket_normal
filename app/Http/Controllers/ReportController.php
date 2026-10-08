<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportRequest;
use App\Models\Category;
use App\Models\PaymentChargeRule;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use App\Services\ProfitLossService;
use App\Services\ReportService;

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
        $rules = PaymentChargeRule::orderBy('name')->get();

        $products = $report === 'stock' ? Product::orderBy('name')->get(['id', 'name']) : collect();
        $categories = $report === 'stock' ? Category::orderBy('name')->get(['id', 'name']) : collect();

        return view('reports.table', compact('report', 'filters', 'title', 'records', 'rows', 'headers', 'cards', 'methods', 'users', 'rules', 'products', 'categories'));
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
