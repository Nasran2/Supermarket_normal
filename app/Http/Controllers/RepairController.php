<?php

namespace App\Http\Controllers;

use App\Http\Requests\PosCustomerRequest;
use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\RepairBrand;
use App\Models\RepairDevice;
use App\Models\RepairJob;
use App\Models\RepairPayment;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Repair\RepairAccounting;
use App\Services\Repair\RepairInventory;
use App\Services\Repair\RepairReporting;
use App\Services\Repair\RepairWorkflow;
use App\Support\Audit;
use App\Support\Demo;
use App\Support\Money;
use App\Support\RepairReceipt;
use App\Support\Repairs;
use App\Support\SalesVisibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RepairController extends Controller
{
    private const MONEY = 'numeric|min:0|max:999999999|decimal:0,2';

    private const TECHNICIAN = 'nullable|integer|exists:users,id,active,1';

    public function index(Request $request, RepairReporting $reports)
    {
        $filters = $reports->filters($request);
        $jobs = $reports->jobs($filters)->with('customer', 'device', 'technician')->paginate(25)->withQueryString();

        return view('repairs.index', ['jobs' => $jobs, 'technicians' => $this->technicians(), 'methods' => $this->methods()]);
    }

    public function create(Request $request)
    {
        $data = $request->validate(['customer' => 'nullable|integer', 'device' => 'nullable|integer']);
        $customerId = filter_var(old('customer_id', $data['customer'] ?? null), FILTER_VALIDATE_INT);
        $selectedCustomer = $customerId ? Customer::where('active', true)->find($customerId) : null;
        $selectedDevice = $selectedCustomer && isset($data['device']) && RepairJob::visibleTo()->where('customer_id', $selectedCustomer->id)->where('device_id', $data['device'])->exists()
            ? $data['device'] : null;

        $catalog = RepairBrand::where('active', true)->orderBy('name')->with(['models' => fn ($query) => $query->where('active', true)->orderBy('name')->select('id', 'brand_id', 'name', 'inspection')])->get(['id', 'name']);

        return view('repairs.intake', ['technicians' => $this->technicians()] + compact('selectedCustomer', 'selectedDevice', 'catalog'));
    }

    public function store(Request $request, RepairWorkflow $workflow)
    {
        $data = $request->validate([
            'submission' => 'required|uuid', 'customer_id' => 'required|integer', 'device_id' => 'nullable|integer',
            'catalog_model_id' => 'nullable|integer|min:1',
            'brand' => 'required_without_all:device_id,catalog_model_id|nullable|string|max:80',
            'model' => 'required_without_all:device_id,catalog_model_id|nullable|string|max:120',
            'serial' => 'nullable|string|max:100', 'color' => 'nullable|string|max:60', 'accessories' => 'nullable|string|max:500',
            'condition' => 'nullable|string|max:2000', 'faults' => 'required_without:reported_issues|nullable|string|max:2000', 'notes' => 'nullable|string|max:4000',
            'reported_issues' => 'nullable|array|max:10', 'reported_issues.*' => ['required', 'distinct', Rule::in(Repairs::INTAKE_ISSUES)],
            'received_items' => 'nullable|array|max:8', 'received_items.*' => ['required', 'distinct', Rule::in(Repairs::INTAKE_ITEMS)],
            'condition_tags' => 'nullable|array|max:8', 'condition_tags.*' => ['required', 'distinct', Rule::in(Repairs::INTAKE_CONDITIONS)],
            'received_at' => 'required|date_format:Y-m-d|before_or_equal:today', 'due_at' => 'nullable|date_format:Y-m-d|after_or_equal:received_at',
            'technician_id' => self::TECHNICIAN, 'priority' => 'required|in:LOW,NORMAL,HIGH,URGENT',
            'warranty_days' => 'required|integer|min:0|max:3650', 'estimate' => 'nullable|'.self::MONEY,
            'checklist' => 'nullable|array|max:40', 'checklist.*' => 'array:label,result,fault',
            'checklist.*.label' => 'nullable|string|max:80', 'checklist.*.result' => 'nullable|in:Working,Not working,Not tested',
            'checklist.*.fault' => 'nullable|string|max:250', 'sms_opt_in' => (Repairs::sms() ? 'sometimes|boolean' : 'exclude'),
            'unlock_type' => 'required_with:device_unlock|nullable|in:PIN,PASSWORD,PATTERN', 'device_unlock' => 'nullable|string|max:150',
        ]);
        if (Demo::enabled()) {
            unset($data['device_unlock']);
        }
        $credential = $data['device_unlock'] ?? null;
        if ($credential !== null && match ($data['unlock_type']) {
            'PIN' => ! preg_match('/^[0-9]{4,16}$/D', $credential),
            'PATTERN' => ! preg_match('/^[1-9](?:-[1-9]){3,8}$/D', $credential) || count(array_unique(explode('-', $credential))) !== count(explode('-', $credential)),
            default => false,
        }) {
            throw ValidationException::withMessages(['device_unlock' => 'Use a 4–16 digit PIN or a pattern of at least four distinct dots.']);
        }
        foreach (['faults' => 'reported_issues', 'accessories' => 'received_items', 'condition' => 'condition_tags'] as $field => $tags) {
            $text = implode(' · ', array_filter([...($data[$tags] ?? []), $data[$field] ?? null], fn ($value) => filled($value)));
            if (mb_strlen($text) > ($field === 'accessories' ? 500 : 2000)) {
                throw ValidationException::withMessages([$field => 'Shorten the notes to make room for the selected items.']);
            }
            $data[$field] = $text === '' ? null : $text;
        }
        $this->validateTechnician($data);
        $job = $workflow->receive($data, $request->user());

        return redirect()->route('repairs.show', $job)->with('success', 'Device received.');
    }

    public function customers(Request $request)
    {
        $data = $request->validate(['q' => 'nullable|string|max:100']);
        $query = '%'.($data['q'] ?? '').'%';
        $customers = Customer::where('active', true)->where(fn ($q) => $q->where('name', 'like', $query)->orWhere('phone', 'like', $query))
            ->orderBy('name')->limit(15)->get(['id', 'name', 'phone']);

        return response()->json($customers);
    }

    public function storeCustomer(PosCustomerRequest $request)
    {
        $customer = Customer::create($request->safe()->only(['name', 'phone', 'email', 'address', 'tax_number']) + ['active' => true]);
        Audit::record('customers.create', $customer, [], $customer->getAttributes());

        return response()->json($customer->only(['id', 'name', 'phone']), 201);
    }

    public function history(Customer $customer)
    {
        $jobs = RepairJob::visibleTo()->where('customer_id', $customer->id);
        $devices = RepairDevice::where('customer_id', $customer->id)->whereHas('jobs', fn ($q) => $q->visibleTo())
            ->orderByDesc('id')->get(['id', 'brand', 'model', 'serial', 'color']);
        $history = (clone $jobs)->with('device')->latest('received_at')->limit(15)->get()->map(fn ($job) => [
            'device' => $job->device->brand.' '.$job->device->model, 'status' => Repairs::STATUS[$job->status],
            'received' => $job->received_at->toDateString(), 'warranty' => $job->warranty_until?->toDateString(), 'url' => route('repairs.show', $job),
        ]);
        $payments = RepairPayment::whereIn('repair_job_id', (clone $jobs)->select('id'))->latest('id')->limit(12)
            ->get(['created_at', 'kind', 'method_name', 'amount']);
        $open = (clone $jobs)->whereNotIn('status', Repairs::CLOSED)->count();
        $due = (string) (clone $jobs)->whereNotNull('invoiced_at')->selectRaw('COALESCE(SUM(total - credited - paid), 0) as balance')->value('balance');
        $account = $customer->pos_due_balance;
        $previous_payments = $customer->sales()->visibleTo()->whereIn('status', ['ACTIVE', 'RETURN_CANCELLED'])
            ->with('payments', 'collections')->latest('sold_at')->limit(12)->get()
            ->flatMap(fn ($sale) => $sale->payments->map(fn ($payment) => ['date' => $sale->sold_at->toDateString(), 'type' => 'POS payment', 'amount' => Money::displayPayment(Money::sub($payment->amount_paid, $payment->change))])
                ->concat($sale->collections->map(fn ($payment) => ['date' => $payment->collected_at->toDateString(), 'type' => 'Due collection', 'amount' => Money::displayPayment($payment->amount)])))
            ->concat(SalesVisibility::apply($customer->customerPayments()->getQuery(), 'customer_payments.user_id')
                ->with('allocations')->latest('payment_date')->limit(12)->get()->map(fn ($payment) => [
                    'date' => $payment->payment_date->toDateString(), 'type' => 'Opening balance payment',
                    'amount' => Money::displayPayment(Money::sum($payment->allocations->where('type', 'OPENING_BALANCE')->pluck('amount'))),
                ])->filter(fn ($payment) => $payment['amount'] !== Money::displayPayment(0)))
            ->sortByDesc('date')->take(12)->values();
        $due = Money::displayCustomerAmount($due);
        $account = Money::displayCustomerAmount($account);
        $payments = $payments->map(fn ($payment) => ['kind' => $payment->kind, 'method_name' => $payment->method_name, 'amount' => Money::displayPayment($payment->amount)]);

        return response()->json(compact('devices', 'history', 'payments', 'open', 'due', 'account', 'previous_payments'));
    }

    public function show(RepairJob $repair)
    {
        Gate::authorize('view', $repair);
        $repair->load('customer', 'device', 'technician', 'parts', 'payments.reversal', 'attachments', 'costs');
        $events = $repair->events()->with('user')->latest('id')->paginate(20, pageName: 'activity');
        $methods = $this->methods();
        $chequeMethod = PaymentMethod::forContext('receive')->where('type', 'CHEQUE')->where('active', true)->first();
        $heldCheques = config('features.cheque') ? $repair->cheques()->held()->get() : collect();
        $banks = BankAccount::where('active', true)->orderBy('bank_name')->get();
        $suppliers = Supplier::where('active', true)->orderBy('name')->get(['id', 'name']);
        $technicians = $this->technicians();

        return response()->view('repairs.show', ['job' => $repair] + compact('events', 'methods', 'banks', 'suppliers', 'technicians', 'chequeMethod', 'heldCheques'))->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, RepairJob $repair, RepairWorkflow $workflow)
    {
        Gate::authorize('act', [$repair, 'edit']);
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Repairs::STATUS))], 'technician_id' => self::TECHNICIAN,
            'priority' => 'sometimes|required|in:LOW,NORMAL,HIGH,URGENT', 'due_at' => 'nullable|date_format:Y-m-d|after_or_equal:'.$repair->received_at->toDateString(),
            'warranty_days' => 'sometimes|required|integer|min:0|max:3650', 'estimate' => 'nullable|'.self::MONEY,
            'diagnosis' => 'nullable|string|max:4000', 'activity_note' => 'nullable|string|max:4000', 'approved' => 'sometimes|boolean',
            'labor' => 'sometimes|required|'.self::MONEY, 'service_fee' => 'sometimes|required|'.self::MONEY,
            'discount' => 'sometimes|required|'.self::MONEY, 'tax' => 'sometimes|required|'.self::MONEY,
            'sms_opt_in' => (Repairs::sms() ? 'sometimes|boolean' : 'exclude'),
        ]);
        $this->validateTechnician($data);
        $workflow->update($repair, $data, $request->user());

        return back()->with('success', 'Repair updated.');
    }

    public function consent(Request $request, RepairJob $repair)
    {
        Gate::authorize('act', [$repair, 'edit']);
        $data = $request->validate(['sms_opt_in' => 'required|boolean']);
        DB::transaction(function () use ($request, $repair, $data) {
            $job = RepairJob::lockForUpdate()->findOrFail($repair->id);
            $job->sms_opt_in = $data['sms_opt_in'];
            if ($job->isDirty('sms_opt_in')) {
                $job->save();
                $job->events()->create(['kind' => 'CONSENT', 'user_id' => $request->user()->id, 'note' => $job->sms_opt_in ? 'SMS consent granted' : 'SMS consent withdrawn']);
            }
        }, 3);

        return back()->with('success', 'SMS consent updated.');
    }

    public function unlock(RepairJob $repair)
    {
        Gate::authorize('act', [$repair, 'unlock']);
        Audit::record('repair.unlock.view', $repair);

        return response()->json(['type' => $repair->unlock_type, 'value' => $repair->device_unlock])->header('Cache-Control', 'no-store');
    }

    public function products(Request $request)
    {
        $data = $request->validate(['q' => 'nullable|string|max:100']);
        $term = '%'.($data['q'] ?? '').'%';
        $products = Product::where('available_for_repairs', true)->where('active', true)->where('track_stock', true)
            ->whereHas('unit', fn ($q) => $q->where('active', true))
            ->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('sku', 'like', $term))
            ->orderBy('name')->limit(20)->get(['id', 'name', 'sku', 'stock', 'price']);

        return response()->json($products);
    }

    public function addPart(Request $request, RepairJob $repair, RepairInventory $inventory)
    {
        Gate::authorize('act', [$repair, 'parts']);
        $data = $request->validate(['submission' => 'required|uuid', 'product_id' => 'required|integer', 'quantity' => 'required|numeric|min:0.001|max:999999|decimal:0,3', 'price' => 'nullable|'.self::MONEY]);
        $inventory->add($repair, $data, $request->user());

        return back()->with('success', 'Part planned.');
    }

    public function changePart(Request $request, RepairJob $repair, int $part, string $action, RepairInventory $inventory)
    {
        Gate::authorize('act', [$repair, 'parts']);
        $inventory->change($repair, $part, $action, $request->user());

        return back()->with('success', 'Part updated.');
    }

    public function payment(Request $request, RepairJob $repair, string $action, RepairAccounting $accounting)
    {
        $refund = $action === 'refund';
        Gate::authorize('act', [$repair, $refund ? 'correct_payment' : 'pay']);
        $accounting->post($repair, $this->paymentData($request, $refund), $request->user(), $refund ? 'REFUND' : 'COLLECT');

        return back()->with('success', $refund ? 'Refund recorded.' : 'Payment recorded.');
    }

    public function correct(Request $request, RepairJob $repair, int $payment, RepairAccounting $accounting)
    {
        Gate::authorize('act', [$repair, 'correct_payment']);
        $accounting->post($repair, $this->paymentData($request, true), $request->user(), 'CORRECTION', $payment);

        return back()->with('success', 'Correction posted with a reversal.');
    }

    private function paymentData(Request $request, bool $reason): array
    {
        return $request->validate([
            'submission' => 'required|uuid', 'amount' => 'required|gt:0|'.self::MONEY, 'payment_method_id' => 'required|integer',
            'cheque' => 'nullable|array:cheque_number,cheque_date,bank_name,branch',
            'cheque.cheque_number' => 'nullable|string|max:80', 'cheque.cheque_date' => 'nullable|date_format:Y-m-d',
            'cheque.bank_name' => 'nullable|string|max:150', 'cheque.branch' => 'nullable|string|max:150',
            'bank_account_id' => 'nullable|integer', 'reference' => 'nullable|string|max:120', 'reason' => ($reason ? 'required' : 'nullable').'|string|max:500',
        ]);
    }

    public function cost(Request $request, RepairJob $repair, RepairAccounting $accounting)
    {
        Gate::authorize('act', [$repair, 'edit']);
        abort_unless($request->user()->hasPermission('expenses.create'), 403);
        $data = $request->validate(['submission' => 'required|uuid', 'description' => 'required|string|max:250', 'amount' => 'required|gt:0|'.self::MONEY, 'payment_method_id' => 'nullable|integer', 'bank_account_id' => 'nullable|integer', 'supplier_id' => 'nullable|integer']);
        $accounting->cost($repair, $data, $request->user());

        return back()->with('success', 'Direct cost recorded.');
    }

    public function attach(Request $request, RepairJob $repair)
    {
        Gate::authorize('act', [$repair, 'edit']);
        abort_if(Demo::enabled(), 403);
        $file = $request->validate(['file' => 'required|file|mimes:pdf,jpg,jpeg,png,webp|max:10240'])['file'];
        $path = $file->store('repair-attachments', 'local');
        abort_unless($path, 500);
        try {
            DB::transaction(function () use ($request, $repair, $file, $path) {
                $repair->attachments()->create(['user_id' => $request->user()->id, 'name' => mb_substr($file->getClientOriginalName(), 0, 255), 'path' => $path]);
                $repair->events()->create(['kind' => 'ATTACHMENT', 'user_id' => $request->user()->id]);
            }, 3);
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }

        return back()->with('success', 'Attachment uploaded.');
    }

    public function download(RepairJob $repair, int $attachment)
    {
        Gate::authorize('view', $repair);
        $file = $repair->attachments()->findOrFail($attachment);

        return Storage::disk('local')->download($file->path, str_replace(['"', '\\', '/'], '_', $file->name), ['Cache-Control' => 'no-store']);
    }

    public function print(Request $request, RepairJob $repair, string $document)
    {
        Gate::authorize('view', $repair);
        abort_if($document === 'invoice' && ! $repair->invoiced_at, 404);
        $data = $request->validate(RepairReceipt::rules() + ['payment' => ($document === 'payment' ? 'required' : 'nullable').'|integer']);
        $receipt = RepairReceipt::options($data, $document === 'intake');
        $payment = $document === 'payment' ? $repair->payments()->findOrFail($data['payment']) : null;
        $barcode = $receipt['barcode'] ? RepairReceipt::barcode($repair->tracking) : null;

        return response()->view('repairs.print', [
            'job' => $repair->load('device', 'customer', 'parts'), 'document' => $document, 'payment' => $payment,
            'barcode' => $barcode,
        ] + $receipt)->header('Cache-Control', 'no-store');
    }

    public function track(Request $request)
    {
        $result = null;
        $searched = $request->isMethod('post');
        if ($searched) {
            $tracking = strtoupper($request->validate(['tracking' => 'required|string|regex:/^[a-fA-F0-9]{32}$/D'])['tracking']);
            $job = RepairJob::select('id', 'status', 'due_at')->where('tracking', $tracking)->first();
            if ($job) {
                $result = [
                    'status' => Repairs::STATUS[$job->status], 'due' => $job->due_at,
                    'updates' => $job->events()->whereNotNull('public_status')->latest('id')->limit(20)->get(['public_status', 'created_at']),
                ];
            }
        }

        return response()->view('repairs.track', compact('result', 'searched'))->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function technicians()
    {
        return User::where('active', true)->with('role.permissions')->orderBy('name')->get()
            ->filter(fn (User $user) => $user->hasPermission('repairs.view') && $user->hasPermission('repairs.edit'));
    }

    private function validateTechnician(array $data): void
    {
        if (! empty($data['technician_id'])) {
            $technician = User::where('active', true)->find($data['technician_id']);
            if (! $technician?->hasPermission('repairs.view') || ! $technician->hasPermission('repairs.edit')) {
                throw ValidationException::withMessages(['technician_id' => 'Choose an active staff member who can view and manage repair jobs.']);
            }
        }
    }

    private function methods()
    {
        return PaymentMethod::where('active', true)->where('type', '!=', 'CHEQUE')->orderBy('display_order')->get();
    }
}
