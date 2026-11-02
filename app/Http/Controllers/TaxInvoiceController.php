<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\TaxInvoice;
use App\Services\SettingsService;
use App\Services\TaxInvoiceService;
use App\Support\Audit;
use App\Support\Demo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TaxInvoiceController extends Controller
{
    private function authorizeSale(Sale $sale): void
    {
        abort_unless(config('features.customer_tax'), 404);
        abort_unless(auth()->user()->hasPermission('sales.receipt') && (auth()->user()->hasPermission('sales.view') || ($sale->user_id === auth()->id() && auth()->user()->hasPermission('sales.create'))), 403);
        if ($sale->status !== 'ACTIVE' || ! $sale->tax) {
            throw ValidationException::withMessages(['sale' => 'Tax invoices are available for active sales made with customer tax enabled.']);
        }
    }

    public function context(Request $request, Sale $sale, SettingsService $settings)
    {
        $this->authorizeSale($sale);
        $issued = TaxInvoice::where('sale_id', $sale->id)->exists();
        $data = $request->validate(['customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('active', true)]]);
        $customer = Customer::find($sale->customer_id ?: ($data['customer_id'] ?? null));
        $missingSupplier = [];
        foreach (['tax_number' => 'TIN / VAT number', 'business_name' => 'name', 'address' => 'address', 'phone' => 'telephone'] as $key => $label) {
            if (! trim((string) $settings->get($key, ''))) {
                $missingSupplier[] = $label;
            }
        }

        return response()->json(['issued' => $issued, 'preview_url' => route('sales.tax.preview', $sale), 'download_url' => route('sales.tax.download', $sale), 'customer' => $customer?->only('id', 'name', 'tax_number', 'address', 'phone'),
            'customers' => $sale->customer_id ? [] : Customer::where('active', true)->orderBy('name')->get(['id', 'name']), 'fixed_customer' => (bool) $sale->customer_id,
            'can_create' => auth()->user()->hasPermission('customers.create'), 'can_edit' => auth()->user()->hasPermission('customers.edit'),
            'supplier' => array_intersect_key($settings->all(), array_flip(['business_name', 'tax_number', 'address', 'phone'])),
            'can_configure_supplier' => auth()->user()->hasPermission('settings.business') && ! Demo::enabled(),
            'business_settings_url' => route('settings.edit', 'business'), 'missing_supplier' => $missingSupplier, 'delivery_date' => $sale->sold_at->format('Y-m-d'), 'place_of_supply' => trim((string) $customer?->address)]);
    }

    public function store(Request $request, Sale $sale, TaxInvoiceService $service)
    {
        $this->authorizeSale($sale);
        // A retry returns the original invoice without changing customer or invoice details.
        if (TaxInvoice::where('sale_id', $sale->id)->exists()) {
            return response()->json(['preview_url' => route('sales.tax.preview', $sale), 'download_url' => route('sales.tax.download', $sale), 'customer' => $sale->fresh()->customer?->only('id', 'name', 'phone', 'tax_number', 'address')]);
        }
        abort_unless(auth()->user()->hasPermission('sales.create') || auth()->user()->hasPermission('sales.edit'), 403);
        if (! $request->filled('place_of_supply') && is_string($request->input('address'))) {
            $request->merge(['place_of_supply' => trim($request->input('address'))]);
        }
        if ($request->has('supplier')) {
            abort_unless(auth()->user()->hasPermission('settings.business') && ! Demo::enabled(), 403);
        }
        $data = $request->validate([
            'supplier' => ['sometimes', 'array:business_name,tax_number,address,phone'],
            'supplier.business_name' => ['required_with:supplier', 'string', 'max:150'],
            'supplier.tax_number' => ['required_with:supplier', 'string', 'max:100'],
            'supplier.address' => ['required_with:supplier', 'string', 'max:500'],
            'supplier.phone' => ['required_with:supplier', 'string', 'max:40'],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('active', true)],
            'name' => ['required', 'string', 'max:255'], 'tax_number' => ['required', 'string', 'max:100'],
            'address' => ['required', 'string', 'max:2000'], 'phone' => ['required', 'string', 'max:255'],
            'delivery_date' => ['required', 'date_format:Y-m-d'], 'place_of_supply' => ['required', 'string', 'max:2000'], 'additional_information' => ['nullable', 'string', 'max:1000'],
        ]);
        try {
            DB::transaction(function () use ($sale, $data, $service) {
                $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
                $this->authorizeSale($sale);
                if (TaxInvoice::where('sale_id', $sale->id)->exists()) {
                    return;
                }
                $settings = app(SettingsService::class);
                if (isset($data['supplier'])) {
                    $before = array_intersect_key($settings->all(), $data['supplier']);
                    $settings->put('business', $data['supplier']);
                    Audit::record('settings.business', Setting::where('key', 'business_name')->firstOrFail(), $before, $data['supplier']);
                }
                foreach (['tax_number', 'business_name', 'address', 'phone'] as $key) {
                    if (! trim((string) $settings->get($key, ''))) {
                        throw ValidationException::withMessages(['supplier' => 'Your shop details are incomplete. Add your shop TIN, name, address and telephone in the Your shop details section below.']);
                    }
                }
                if ($sale->customer_id && (int) ($data['customer_id'] ?? 0) !== (int) $sale->customer_id) {
                    throw ValidationException::withMessages(['customer_id' => 'Use the customer already linked to this sale.']);
                }
                $details = array_intersect_key($data, array_flip(['name', 'tax_number', 'address', 'phone']));
                $customerId = $sale->customer_id ?: ($data['customer_id'] ?? null);
                if ($customerId) {
                    $customer = Customer::whereKey($customerId)->where('active', true)->lockForUpdate()->firstOrFail();
                    $before = $customer->getAttributes();
                    foreach ($details as $key => $value) {
                        if (trim((string) $customer->$key) && $value !== $customer->$key) {
                            abort_unless(auth()->user()->hasPermission('customers.edit'), 403);
                        }
                        if (! trim((string) $customer->$key)) {
                            abort_unless(auth()->user()->hasPermission('customers.create') || auth()->user()->hasPermission('customers.edit'), 403);
                        }
                    }
                    $customer->update($details);
                } else {
                    abort_unless(auth()->user()->hasPermission('customers.create'), 403);
                    $before = [];
                    $customer = Customer::create($details + ['active' => true]);
                }
                Audit::record('customers.tax_details', $customer, $before, $customer->getAttributes());
                if (! $sale->customer_id) {
                    $sale->update(['customer_id' => $customer->id]);
                    Audit::record('sale.tax_customer', $sale, ['customer_id' => null], ['customer_id' => $customer->id]);
                }
                $invoice = TaxInvoice::create(['sale_id' => $sale->id, 'snapshot' => $service->snapshot($sale, $details, array_intersect_key($data, array_flip(['delivery_date', 'place_of_supply', 'additional_information'])))]);
                Audit::record('sale.tax_invoice', $sale, [], ['tax_invoice_id' => $invoice->id]);
            }, 3);
        } finally {
            if (isset($data['supplier'])) {
                Cache::forget('business_settings');
            }
        }

        return response()->json(['preview_url' => route('sales.tax.preview', $sale), 'download_url' => route('sales.tax.download', $sale), 'customer' => $sale->fresh()->customer?->only('id', 'name', 'phone', 'tax_number', 'address')]);
    }

    public function preview(Sale $sale)
    {
        $this->authorizeSale($sale);
        $invoice = TaxInvoice::where('sale_id', $sale->id)->firstOrFail()->snapshot;

        return view('pdf.tax-invoice', ['invoice' => $invoice, 'browserPreview' => true, 'downloadUrl' => route('sales.tax.download', $sale)]);
    }

    public function download(Sale $sale, TaxInvoiceService $service)
    {
        $this->authorizeSale($sale);
        $invoice = TaxInvoice::where('sale_id', $sale->id)->firstOrFail();

        return response($service->render($invoice->snapshot), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="tax-invoice-'.preg_replace('/[^A-Za-z0-9_-]/', '-', $sale->invoice).'.pdf"', 'Cache-Control' => 'private, no-store']);
    }
}
