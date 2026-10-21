<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Sale;
use App\Models\SmsMessage;
use App\Services\SettingsService;
use App\Services\SmsService;
use App\Services\SmsSettingsService;
use App\Services\SmsTemplateService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SmsController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['q' => 'nullable|string|max:100', 'status' => 'nullable|in:PENDING,SENDING,SENT,FAILED,UNKNOWN,CANCELLED,attention']);
        $customers = Customer::withDueBalances()->where('active', true)
            ->when($data['q'] ?? '', fn ($query, $q) => $query->where(fn ($query) => $query->where('name', 'like', '%'.$q.'%')->orWhere('phone', 'like', '%'.$q.'%')))
            ->orderBy('name')->paginate(50)->withQueryString();
        $history = SmsMessage::when(! $request->user()->isAdministrator(), fn ($query) => $query->where('user_id', $request->user()->id));
        $summary = (clone $history)->selectRaw("COUNT(*) AS total, SUM(CASE WHEN status='SENT' THEN 1 ELSE 0 END) AS sent, SUM(CASE WHEN status IN ('PENDING','SENDING') THEN 1 ELSE 0 END) AS pending, SUM(CASE WHEN status IN ('FAILED','UNKNOWN') THEN 1 ELSE 0 END) AS attention")->first();
        $messages = $history->when($data['status'] ?? '', fn ($query, $status) => $status === 'attention' ? $query->whereIn('status', ['FAILED', 'UNKNOWN']) : $query->where('status', $status))
            ->latest('id')->paginate(20, ['*'], 'history_page')->withQueryString();
        $phoneService = app(SmsService::class);
        foreach ($customers as $customer) {
            try {
                $phoneService->phone($customer->phone);
                $customer->setAttribute('sms_phone_valid', true);
            } catch (ValidationException $exception) {
                $customer->setAttribute('sms_phone_valid', false);
            }
        }

        return view('sms.index', ['customers' => $customers, 'messages' => $messages, 'summary' => $summary, 'smsSettings' => app(SmsSettingsService::class)->formValues()]);
    }

    public function customers(Request $request)
    {
        $data = $request->validate(['q' => 'nullable|string|max:100']);

        return Customer::where('active', true)->when($data['q'] ?? '', fn ($query, $q) => $query->where(fn ($query) => $query->where('name', 'like', '%'.$q.'%')->orWhere('phone', 'like', '%'.$q.'%')))
            ->orderBy('name')->limit(100)->get(['id', 'name', 'phone']);
    }

    public function receiptContext(Sale $sale, SmsService $sms)
    {
        abort_unless($sale->status === 'ACTIVE', 422, 'Only active receipts can be sent.');
        $customer = $sale->customer;
        $valid = false;
        if ($customer?->phone) {
            try {
                $sms->phone($customer->phone);
                $valid = true;
            } catch (ValidationException $exception) {
                // Ask for a corrected number in the receipt dialog.
            }
        }

        return response()->json(['customer' => $customer?->only('id', 'name', 'phone'), 'can_send' => $valid]);
    }

    public function receipt(Request $request, Sale $sale, SmsService $sms, SettingsService $settings)
    {
        $sms->ready('RECEIPT');
        $data = $request->validate([
            'token' => 'required|uuid', 'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('active', true)],
            'name' => 'nullable|string|max:255', 'phone' => 'nullable|string|max:40',
        ]);
        $message = DB::transaction(function () use ($sale, $request, $data, $sms) {
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            abort_unless($sale->status === 'ACTIVE', 422, 'Only active receipts can be sent.');
            $key = 'receipt:'.$sale->id.':'.$data['token'];
            if ($existing = SmsMessage::where('dedupe_key', $key)->first()) {
                return $existing;
            }
            // A saved invoice customer cannot be replaced through the SMS action.
            $customer = $sale->customer;
            if (! $customer && ! empty($data['customer_id'])) {
                $customer = Customer::where('active', true)->findOrFail($data['customer_id']);
            }
            if (! $customer) {
                if (blank($data['name'] ?? '') || blank($data['phone'] ?? '')) {
                    throw ValidationException::withMessages(['phone' => 'Select a customer, or enter the customer name and phone number.']);
                }
                $phone = $sms->phone($data['phone']);
                $customer = $sms->matchingCustomer($phone);
                if (! $customer) {
                    abort_unless($request->user()->hasPermission('customers.create'), 403, 'Customer creation permission is required.');
                    $customer = Customer::create(['name' => $data['name'], 'phone' => $phone, 'active' => true]);
                }
                abort_unless($customer->active, 422, 'This phone belongs to an inactive customer. Reactivate the customer first.');
            }
            try {
                $phone = $sms->phone($customer->phone);
            } catch (ValidationException $exception) {
                abort_unless($request->user()->hasPermission('customers.edit'), 403, 'Customer edit permission is required to add or correct this customer’s phone number.');
                $phone = $sms->phone($data['phone'] ?? null);
                $match = $sms->matchingCustomer($phone);
                if ($match && $match->id !== $customer->id) {
                    throw ValidationException::withMessages(['phone' => 'This number belongs to another customer. Correct the customer record first.']);
                }
                $customer->update(['phone' => $phone]);
            }
            $sale->update(['customer_id' => $customer->id]);
            $link = $sms->receiptLink($sale);
            $text = app(SmsTemplateService::class)->render('RECEIPT', [
                'name' => $customer->name, 'invoice' => $sale->invoice, 'total' => Money::display($sale->customer_payable),
                'paid' => Money::display($sale->collected_total), 'due' => Money::display($sale->due_balance), 'receipt_url' => $link,
            ]);

            return $sms->enqueue(['dedupe_key' => $key, 'kind' => 'RECEIPT', 'user_id' => $request->user()->id, 'sale_id' => $sale->id, 'customer_id' => $customer->id, 'phone' => $phone, 'message' => $text]);
        });
        $message = $sms->deliver($message);

        return response()->json(['status' => $message->status, 'message' => match ($message->status) {
            'SENT' => 'Receipt accepted by Textit for '.$message->phone.'.',
            'SENDING' => 'This receipt is already being sent. Check SMS history for the result.',
            default => $message->error ?? 'Receipt is pending. Check SMS history.',
        }]);
    }

    public function preview(Request $request, SmsService $sms, SettingsService $settings)
    {
        $sms->ready();
        $data = $request->validate([
            'kind' => 'required|in:DUE,CUSTOM', 'audience' => 'required|in:selected,all',
            'customers' => 'required_if:audience,selected|array|max:1000', 'customers.*' => 'integer|distinct|exists:customers,id',
            'message' => 'nullable|required_if:kind,CUSTOM|string|max:1000',
        ]);
        $sms->ready($data['kind']);
        $query = Customer::withDueBalances()->where('active', true)->orderBy('id');
        if ($data['audience'] === 'selected') {
            $query->whereIn('id', $data['customers']);
        }
        $template = filled($data['message'] ?? '') ? $data['message'] : null;
        if ($template !== null) {
            app(SmsTemplateService::class)->validate($data['kind'], $template);
        }
        $recipients = [];
        $skipped = 0;
        foreach ($query->get() as $customer) {
            $due = $customer->due_balance;
            if ($data['kind'] === 'DUE' && Money::compare($due, 0) <= 0) {
                $skipped++;

                continue;
            }
            try {
                $phone = $sms->phone($customer->phone);
            } catch (ValidationException $exception) {
                $skipped++;

                continue;
            }
            $recipients[] = ['customer_id' => $customer->id, 'phone' => $phone, 'message' => app(SmsTemplateService::class)->render($data['kind'], [
                'name' => $customer->name, 'due' => Money::display($due),
            ], $template)];
            if (count($recipients) > 1000) {
                throw ValidationException::withMessages(['customers' => 'Send to at most 1,000 customers per campaign. Narrow your selection.']);
            }
        }
        if (! $recipients) {
            throw ValidationException::withMessages(['customers' => 'No eligible customers with valid phone numbers. Due reminders also require a positive balance.']);
        }
        $token = (string) Str::uuid();
        $request->session()->put('sms_preview', ['token' => $token, 'kind' => $data['kind'], 'recipients' => $recipients, 'created_at' => time()]);

        return view('sms.preview', compact('token', 'recipients', 'skipped'));
    }

    public function send(Request $request, SmsService $sms)
    {
        $sms->ready();
        $request->validate(['token' => 'required|uuid']);
        $preview = $request->session()->get('sms_preview');
        abort_unless($preview && hash_equals($preview['token'], $request->input('token')) && $preview['created_at'] > time() - 1800, 422, 'Preview expired. Preview your message again.');
        $sms->ready($preview['kind']);
        DB::transaction(function () use ($preview, $request, $sms) {
            foreach ($preview['recipients'] as $recipient) {
                $sms->enqueue($recipient + ['dedupe_key' => 'campaign:'.$preview['token'].':'.$recipient['customer_id'], 'kind' => $preview['kind'], 'user_id' => $request->user()->id]);
            }
        });
        $request->session()->forget('sms_preview');

        return redirect()->route('sms.index')->with('success', count($preview['recipients']).' messages queued. The scheduled SMS sender processes them every minute.');
    }

    public function publicReceipt(string $token)
    {
        $sale = Sale::where('receipt_token', $token)->firstOrFail();
        $sale->load(['items.allocations', 'user', 'customer', 'payments', 'returns', 'collections']);
        if (config('features.cheque')) {
            $sale->load('payments.cheque');
        }

        return response()->view('sales.receipt', ['sale' => $sale, 'publicReceipt' => true])->withHeaders([
            'X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
