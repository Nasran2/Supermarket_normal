<?php

namespace App\Http\Controllers;

use App\Http\Requests\BarcodePrintRequest;
use App\Http\Requests\BarcodeSettingsRequest;
use App\Models\BarcodeLabelPreset;
use App\Models\BarcodePrintJob;
use App\Models\Category;
use App\Models\Product;
use App\Models\Purchase;
use App\Services\BarcodePresetService;
use App\Services\BarcodePrintService;
use App\Services\BarcodeService;
use App\Services\SettingsService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BarcodeController extends Controller
{
    public function index(Request $request, BarcodePrintService $printing, BarcodePresetService $presets)
    {
        $request->validate(['product' => 'nullable|integer|exists:products,id', 'purchase' => 'nullable|integer|exists:purchases,id']);
        $initial = [];
        if ($request->filled('purchase')) {
            abort_unless($request->user()->hasPermission('purchases.view') && $request->user()->hasPermission('barcode.print_bulk'), 403);
            $purchase = Purchase::findOrFail($request->purchase);
            abort_unless($purchase->status === 'ACTIVE', 422, 'Only active purchases can supply label quantities.');
            $initial = $printing->purchase($purchase);
        } elseif ($request->filled('product')) {
            $p = Product::findOrFail($request->product);
            $initial = [['product' => $printing->product($p), 'quantity' => app(BarcodeService::class)->setting('barcode_default_quantity', 1), 'option' => '']];
        }
        $saved = BarcodeLabelPreset::orderByDesc('is_default')->orderBy('name')->get();
        $categories = Category::orderBy('name')->get();
        $configuration = $saved->first()?->configuration ?? $presets->defaults();

        return view('barcode.index', compact('initial', 'saved', 'categories', 'configuration'));
    }

    public function products(Request $request, BarcodePrintService $printing)
    {
        $data = $request->validate(['q' => 'nullable|string|max:150', 'category' => 'nullable|integer|exists:categories,id', 'active' => 'nullable|boolean', 'has_barcode' => 'nullable|in:yes,no', 'low_stock' => 'nullable|boolean']);
        $search = trim($data['q'] ?? '');
        if ($search === '') {
            return response()->json([]);
        }
        $q = Product::with('unit', 'conversions.unit', 'stockLayers')
            ->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('sku', 'like', '%'.$search.'%')->orWhere('barcode', 'like', '%'.$search.'%'));
        if (! empty($data['category'])) {
            $q->whereHas('categories', fn ($q) => $q->where('categories.id', $data['category']));
        }
        if (isset($data['active'])) {
            $q->where('active', $data['active']);
        }
        if (! empty($data['has_barcode'])) {
            $data['has_barcode'] === 'yes' ? $q->whereNotNull('barcode')->where('barcode', '!=', '') : $q->where(fn ($q) => $q->whereNull('barcode')->orWhere('barcode', ''));
        }
        if (! empty($data['low_stock'])) {
            $q->where('track_stock', true)->lowStock();
        }

        return response()->json($q->orderBy('name')->limit(60)->get()->map(fn ($p) => $printing->product($p)));
    }

    public function generate(Request $request, BarcodeService $barcodes)
    {
        $data = $request->validate(['barcode_type' => ['nullable', Rule::in(BarcodeService::TYPES)]]);
        $type = $data['barcode_type'] ?? $barcodes->setting('barcode_default_type', 'CODE128');

        return response()->json(['barcode' => $barcodes->generate($type), 'barcode_type' => $type]);
    }

    public function assign(Request $request, Product $product, BarcodeService $barcodes, BarcodePrintService $printing)
    {
        $data = $request->validate(['barcode' => 'nullable|string|max:80', 'barcode_type' => ['nullable', Rule::in(BarcodeService::TYPES)]]);
        abort_unless($request->user()->hasPermission(empty($data['barcode']) ? 'barcode.generate' : 'barcode.edit_product_barcode'), 403);
        if ($product->barcode) {
            abort_unless($request->user()->hasPermission('barcode.edit_product_barcode'), 403);
        }

        return response()->json($printing->product($barcodes->assign($product, $data['barcode'] ?? null, $data['barcode_type'] ?? null)));
    }

    public function preview(BarcodePrintRequest $request, BarcodePrintService $printing)
    {
        $resolved = $printing->resolve($request->validated());

        return response()->json(['html' => view('barcode.sheet', ['labels' => $resolved['labels'], 'c' => $resolved['configuration'], 'preview' => true])->render(), 'total' => $resolved['total'], 'rows' => $resolved['rows'], 'warnings' => $resolved['warnings']]);
    }

    public function store(BarcodePrintRequest $request, BarcodePrintService $printing)
    {
        $job = DB::transaction(function () use ($request, $printing) {
            $r = $printing->resolve($request->validated());
            $job = BarcodePrintJob::create(['created_by' => $request->user()->id, 'preset_name' => $request->input('preset_name', 'Custom layout') ?: 'Custom layout', 'configuration' => $r['configuration'], 'labels' => $r['labels'], 'total_labels' => $r['total']]);
            Audit::record('barcode.print', $job, [], ['total_labels' => $r['total'], 'preset_name' => $job->preset_name]);

            return $job;
        });

        return response()->json(['url' => route('barcode.jobs.show', $job), 'total' => $job->total_labels]);
    }

    public function show(BarcodePrintJob $job)
    {
        abort_unless($job->created_by === auth()->id() || auth()->user()->hasPermission('barcode.view_history'), 403);
        abort_if(collect($job->labels)->pluck('product_id')->unique()->count() > 1 && ! auth()->user()->hasPermission('barcode.print_bulk'), 403);
        $c = $job->configuration;
        if (! config('features.expiry')) {
            $c['show_expiry'] = false;
            $c['show_batch'] = false;
        }

        return view('barcode.print', ['job' => $job, 'labels' => $job->labels, 'c' => $c]);
    }

    public function history()
    {
        return view('barcode.history', ['jobs' => BarcodePrintJob::latest()->paginate(25)]);
    }

    public function settings()
    {
        return view('barcode.settings', ['fields' => config('barcode.settings'), 'values' => app(SettingsService::class)->all()]);
    }

    public function updateSettings(BarcodeSettingsRequest $request, SettingsService $settings)
    {
        DB::transaction(fn () => $settings->put('barcode', $request->validated()));

        return back()->with('success', 'Barcode settings saved.');
    }

    public function calibration(BarcodePresetService $presets)
    {
        $c = $presets->normalize(BarcodeLabelPreset::where('is_default', true)->first()?->configuration ?? $presets->defaults());
        // Calibration always uses CODE128, regardless of the format selected in a saved preset.
        $c['barcode_type'] = 'CODE128';
        $c['skip_labels'] = 0;
        $label = ['name' => 'TEST PRODUCT', 'sku' => 'TEST-001', 'barcode' => '123456789012', 'barcode_type' => 'CODE128', 'price' => '100.00', 'unit' => 'pcs', 'expiry' => null, 'batch' => null, 'quantity' => 4, 'shop_name' => app(SettingsService::class)->get('business_name', ''), 'currency' => app(SettingsService::class)->get('currency_symbol', 'Rs.')];

        return view('barcode.print', ['job' => null, 'labels' => [$label], 'c' => $c]);
    }
}
