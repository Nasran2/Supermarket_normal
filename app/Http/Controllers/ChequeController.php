<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChequeEditRequest;
use App\Http\Requests\ChequeRecoveryRequest;
use App\Http\Requests\ChequeRequest;
use App\Models\BankAccount;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\Setting;
use App\Models\Supplier;
use App\Services\ChequeRecoveryService;
use App\Services\ChequeService;
use App\Services\CustomerPaymentService;
use App\Services\PdfReportService;
use App\Services\PurchasePaymentService;
use App\Services\SettingsService;
use App\Support\Audit;
use App\Support\Cheques;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class ChequeController extends Controller
{
    private function filters(Request $r): array
    {
        return $r->validate(['q' => 'nullable|string|max:150', 'type' => 'nullable|in:CUSTOMER_RECEIVED,OWN_CHEQUE,THIRD_PARTY', 'status' => 'nullable|in:PENDING,TRANSFERRED,PASSED,RETURNED,CANCELLED', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'created_from' => 'nullable|date_format:Y-m-d', 'created_to' => 'nullable|date_format:Y-m-d|after_or_equal:created_from', 'customer_id' => 'nullable|integer|exists:customers,id', 'supplier_id' => 'nullable|integer|exists:suppliers,id', 'bank' => 'nullable|string|max:150', 'due' => 'nullable|boolean', 'available' => 'nullable|boolean']);
    }

    private function query(array $f)
    {
        $q = Cheque::visibleTo()->with('customer', 'supplier', 'sale', 'purchase', 'resolutions');
        foreach (['type', 'status', 'customer_id' => 'origin_customer_id', 'supplier_id' => 'destination_supplier_id', 'bank' => 'bank_name'] as $input => $column) {
            if (is_int($input)) {
                $input = $column;
            } if (! empty($f[$input])) {
                $q->where($column, $f[$input]);
            }
        }
        if (! empty($f['from'])) {
            $q->whereDate('cheque_date', '>=', $f['from']);
        } if (! empty($f['to'])) {
            $q->whereDate('cheque_date', '<=', $f['to']);
        }
        if (! empty($f['created_from'])) {
            $q->whereDate('created_at', '>=', $f['created_from']);
        } if (! empty($f['created_to'])) {
            $q->whereDate('created_at', '<=', $f['created_to']);
        }
        if (! empty($f['due'])) {
            $q->dueSoon();
        } if (! empty($f['available'])) {
            $q->available();
        }
        if (! empty($f['q'])) {
            $text = '%'.$f['q'].'%';
            $q->where(fn ($q) => $q->where('cheque_number', 'like', $text)->orWhere('bank_name', 'like', $text)->orWhere('reference', 'like', $text)->orWhere('amount', 'like', $text)->orWhereHas('customer', fn ($q) => $q->where('name', 'like', $text))->orWhereHas('supplier', fn ($q) => $q->where('name', 'like', $text))->orWhereHas('sale', fn ($q) => $q->where('invoice', 'like', $text))->orWhereHas('purchase', fn ($q) => $q->where('reference', 'like', $text)));
        }

        return $q;
    }

    public function index(Request $r)
    {
        $filters = $this->filters($r);
        if ($r->route('tab')) {
            $filters += match ($r->route('tab')) {
                'received' => ['type' => 'CUSTOMER_RECEIVED'],'issued' => ['type' => 'OWN_CHEQUE'],'third-party' => ['type' => 'THIRD_PARTY'],'due-soon' => ['due' => 1],'returned' => ['status' => 'RETURNED'],default => []
            };
        }
        $query = $this->query($filters);
        $cards = [];
        foreach (['Pending received' => ['CUSTOMER_RECEIVED', ['PENDING']], 'Pending issued' => ['OWN_CHEQUE', ['PENDING']], 'Third-party pending' => ['THIRD_PARTY', ['TRANSFERRED']], 'Passed' => [null, ['PASSED']], 'Returned' => [null, ['RETURNED']]] as $label => [$type,$statuses]) {
            $cards[$label] = Money::round((string) (clone $query)->when($type, fn ($q) => $q->where('type', $type))->whereIn('status', $statuses)->sum('amount'));
        }
        $rows = $query->orderBy('cheque_date')->orderBy('id')->paginate(25)->withQueryString();

        return view('cheques.index', ['rows' => $rows, 'cards' => $cards, 'filters' => $filters, 'customers' => Customer::orderBy('name')->get(['id', 'name']), 'suppliers' => Supplier::orderBy('name')->get(['id', 'name']), 'report' => $r->routeIs('cheques.report')]);
    }

    public function show(Cheque $cheque)
    {
        $cheque = Cheque::visibleTo()->with('customer', 'supplier', 'sale', 'purchase', 'bankAccount', 'events.user', 'resolutions.replacements', 'resolutions.user', 'resolutions.paymentMethod', 'replacementSources.cheque')->findOrFail($cheque->id);

        Gate::authorize('view', $cheque);

        return view('cheques.show', compact('cheque'));
    }

    public function edit(Cheque $cheque, ChequeService $service)
    {
        $cheque = Cheque::visibleTo()->findOrFail($cheque->id);
        Gate::authorize('edit', $cheque);
        abort_unless(in_array($cheque->status, ['PENDING', 'TRANSFERRED']), 409, 'Only pending cheques can be edited.');

        return view('cheques.edit', ['cheque' => $cheque, 'version' => $service->editVersion($cheque), 'accounts' => BankAccount::where('active', true)->orderBy('bank_name')->get()]);
    }

    public function update(ChequeEditRequest $request, Cheque $cheque, ChequeService $service)
    {
        $service->edit($cheque, $request->validated(), $request->user()->id);

        return redirect()->route('cheques.show', $cheque)->with('success', 'Cheque details saved.');
    }

    public function returnCheque(ChequeRecoveryRequest $request, Cheque $cheque, ChequeRecoveryService $service)
    {
        $resolution = $service->record($cheque, $request->validated(), $request->user()->id);

        return back()->with('success', match ($resolution->resolution) {
            'REPLACE' => 'Cheque returned. Replacement cheques recorded; any remaining balance stays due.',
            'PAYMENT' => 'Cheque returned. Payment recorded; any remaining balance stays due.',
            default => 'Cheque returned. Its balance is now due.',
        });
    }

    public function resolveReturn(ChequeRecoveryRequest $request, Cheque $cheque, ChequeRecoveryService $service)
    {
        $service->record($cheque, $request->validated(), $request->user()->id, false);

        return back()->with('success', 'Returned cheque resolution saved. Any remaining balance stays due.');
    }

    public function create()
    {
        return view('cheques.create', ['customers' => Customer::where('active', true)->withDueBalances()->get()->filter(fn ($c) => Money::compare($c->due_balance, 0) > 0), 'purchases' => Purchase::with('supplier')->where('status', 'ACTIVE')->where('payment_tracking', true)->get()->filter(fn ($p) => Money::compare($p->due_amount, 0) > 0)]);
    }

    public function store(ChequeRequest $r, ChequeService $cheques)
    {
        $data = $r->validated();
        $method = PaymentMethod::where('type', 'CHEQUE')->where('active', true)->firstOrFail();
        DB::transaction(function () use ($data, $r, $method) {
            if ($data['type'] === 'CUSTOMER_RECEIVED') {
                abort_unless($r->user()->hasPermission('customers.collect_payment'), 403);
                app(CustomerPaymentService::class)->record(Customer::findOrFail($data['customer_id']), $data + ['payment_method_id' => $method->id, 'mode' => 'auto'], $r->user()->id);
            } else {
                abort_unless($r->user()->hasPermission('purchases.pay'), 403);
                app(PurchasePaymentService::class)->record(Purchase::findOrFail($data['purchase_id']), $data + ['payment_method_id' => $method->id, 'take_from_register' => false], $r->user()->id);
            }
        }, 3);

        return redirect()->route('cheques.index')->with('success', 'Cheques recorded.');
    }

    public function action(Request $r, Cheque $cheque, string $action, ChequeService $service)
    {
        abort_unless(in_array($action, ['pass', 'return', 'cancel']), 404);
        $data = $r->validate(['confirmed' => 'required|accepted', 'reason' => [$action === 'return' || $action === 'cancel' ? 'required' : 'nullable', 'string', 'max:255'], 'notes' => 'nullable|string|max:2000']);
        $service->transition($cheque, $action, $r->user()->id, $data);

        return back()->with('success', 'Cheque updated.');
    }

    public function transfer(Request $r, Cheque $cheque, ChequeService $service)
    {
        $data = $r->validate(['purchase_id' => 'required|integer|exists:purchases,id', 'token' => 'required|uuid', 'confirmed' => 'required|accepted']);
        abort_unless($r->user()->hasPermission('purchases.pay') && $r->user()->hasPermission('cheques.transfer'), 403);
        $purchase = Purchase::findOrFail($data['purchase_id']);
        $method = PaymentMethod::where('type', 'CHEQUE')->where('active', true)->firstOrFail();
        app(PurchasePaymentService::class)->record($purchase, ['token' => $data['token'], 'amount' => $cheque->amount, 'payment_method_id' => $method->id, 'cheques' => [['source' => 'THIRD_PARTY', 'cheque_id' => $cheque->id]]], $r->user()->id);

        return back()->with('success', 'Customer cheque transferred to supplier.');
    }

    public function reverseTransfer(Request $r, Cheque $cheque, ChequeService $service)
    {
        $data = $r->validate(['confirmed' => 'required|accepted', 'reason' => 'required|string|max:255']);
        $service->reverseTransfer($cheque, $r->user()->id, $data['reason']);

        return back()->with('success', 'Transfer reversed. Supplier Due restored; customer cheque remains pending.');
    }

    public function available()
    {
        return response()->json(Cheque::visibleTo()->available()->with('customer')->orderBy('cheque_date')->get()->map(fn ($c) => ['id' => $c->id, 'customer' => $c->customer?->name, 'number' => $c->cheque_number, 'bank' => $c->bank_name, 'date' => $c->cheque_date->toDateString(), 'amount' => $c->amount]));
    }

    public function settings(SettingsService $settings)
    {
        return view('cheques.settings', ['fields' => config('cheques.settings'), 'values' => $settings->all(), 'accounts' => BankAccount::orderBy('bank_name')->get()]);
    }

    public function updateSettings(Request $r, SettingsService $settings)
    {
        $rules = [];
        foreach (config('cheques.settings') as $key => $field) {
            $rules[$key] = $field[3];
        }
        $data = $r->validate($rules);
        DB::transaction(function () use ($data, $settings) {
            $settings->put('cheques', $data);
            Audit::record('cheque.settings', Setting::where('group', 'cheques')->firstOrFail(), [], $data);
        });

        return back()->with('success', 'Cheque settings saved.');
    }

    public function bank(Request $r)
    {
        $data = $r->validate(['bank_name' => 'required|string|max:150', 'account_name' => 'required|string|max:150', 'account_number' => 'required|string|max:80', 'branch' => 'nullable|string|max:150']);
        $account = BankAccount::firstOrCreate(['bank_name' => $data['bank_name'], 'account_number' => $data['account_number']], $data + ['active' => true]);
        Audit::record('cheque.bank_account', $account, [], $account->toArray());

        return back()->with('success', 'Bank account saved.');
    }

    public function bankActive(Request $r, BankAccount $account)
    {
        $data = $r->validate(['active' => 'required|boolean']);
        $before = $account->toArray();
        $account->update($data);
        Audit::record('cheque.bank_account', $account, $before, $account->toArray());

        return back()->with('success', 'Bank account updated.');
    }

    public function image(Request $r, Cheque $cheque)
    {
        abort_unless(Cheques::setting('cheque_allow_image'), 403);
        $r->validate(['image' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:5120']);
        DB::transaction(function () use ($r, $cheque) {
            $c = Cheque::visibleTo()->whereKey($cheque->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($c->status, ['PENDING', 'TRANSFERRED']), 409);
            $path = $r->file('image')->store('cheques', 'local');
            $c->update(['image_path' => $path]);
            Audit::record('cheque.image', $c, [], ['image_path' => $path]);
        });

        return back()->with('success', 'Cheque attachment saved.');
    }

    public function download(Cheque $cheque)
    {
        $c = Cheque::visibleTo()->findOrFail($cheque->id);
        abort_unless($c->image_path && Storage::disk('local')->exists($c->image_path), 404);

        return Storage::disk('local')->download($c->image_path, 'cheque-'.$c->id.'.'.pathinfo($c->image_path, PATHINFO_EXTENSION));
    }

    public function export(Request $r, string $format, PdfReportService $pdf)
    {
        abort_unless(in_array($format, ['csv', 'pdf']), 404);
        $filters = $this->filters($r);
        $query = $this->query($filters)->orderBy('cheque_date')->orderBy('id');
        $headers = ['Cheque', 'Type', 'From', 'To', 'Bank', 'Amount', 'Cheque date', 'Status'];
        $map = fn ($c) => [$c->cheque_number, $c->type_label, $c->customer?->name ?? 'Business', $c->supplier?->name ?? 'Business', $c->bank_name, $c->amount, $c->cheque_date->format('Y-m-d'), $c->status];
        if ($format === 'csv') {
            return response()->streamDownload(function () use ($query, $headers, $map) {
                $out = fopen('php://output', 'w');
                fputcsv($out, $headers);
                foreach ($query->lazy(500) as $c) {
                    fputcsv($out, \App\Support\Csv::row($map($c)));
                }fclose($out);
            }, 'cheques.csv', ['Content-Type' => 'text/csv']);
        }

        return response($pdf->render(['title' => 'Cheque report', 'subtitle' => 'Pending instruments are not cleared cashflow. Third-party cheques settle both accounts without business bank movement.', 'headers' => $headers, 'rows' => $query->lazy(500)->map($map), 'rowCount' => (clone $query)->count(), 'filters' => $filters, 'cards' => [], 'notes' => 'Cheque status does not change sales revenue, purchase values or COGS.']))->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'attachment; filename="cheques.pdf"');
    }
}
