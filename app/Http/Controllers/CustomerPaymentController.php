<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PaymentMethod;
use App\Models\Register;
use App\Models\ReturnAccountAllocation;
use App\Models\Sale;
use App\Models\SaleCollection;
use App\Services\CustomerLedgerService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerPaymentController extends Controller
{
    public function store(Request $request, Customer $customer)
    {
        abort_unless($request->user()->hasPermission('customers.collect_payment'), 403);
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method_id' => 'required|exists:payment_methods,id',
            'payment_date' => 'required|date',
            'mode' => 'required|in:auto,manual',
            'allocations' => 'array', 'allocations.opening_balance' => 'nullable|numeric|min:0|decimal:0,2', 'allocations.sales' => 'nullable|array|max:100', 'allocations.sales.*' => 'numeric|min:0|decimal:0,2',
        ]);

        DB::transaction(function () use ($request, $customer) {
            $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $paymentMethod = PaymentMethod::whereKey($request->payment_method_id)->where('active', true)->lockForUpdate()->firstOrFail();
            if ($request->mode === 'manual') {
                foreach (array_keys($request->input('allocations.sales', [])) as $id) {
                    Sale::visibleTo()->where('customer_id', $customer->id)->lockForUpdate()->findOrFail($id);
                }
                $allocated = Money::add((string) $request->input('allocations.opening_balance', '0'), Money::sum($request->input('allocations.sales', [])));
                if (Money::compare($allocated, (string) $request->amount) !== 0) {
                    throw ValidationException::withMessages(['allocations' => 'Allocate exactly the received payment amount.']);
                }
            }
            if (Money::compare((string) $request->amount, $customer->due_balance) > 0) {
                throw ValidationException::withMessages(['amount' => 'Payment exceeds the outstanding customer due.']);
            }
            $amount = $request->input('amount');

            $customerPayment = $customer->customerPayments()->create([
                'user_id' => auth()->id(),
                'register_id' => null, // Customer payments don't require an active POS register session
                'payment_method_id' => $paymentMethod->id,
                'amount' => $amount,
                'payment_date' => $request->input('payment_date'),
                'notes' => $request->input('notes'),
            ]);

            if ($request->mode === 'auto') {
                $this->autoAllocate($customerPayment, $customer, $amount, $paymentMethod);
            } else {
                $this->manualAllocate($customerPayment, $customer, $request->input('allocations', []), $paymentMethod);
            }
        });

        return back()->with('success', 'Payment recorded successfully.');
    }

    private function autoAllocate(CustomerPayment $payment, Customer $customer, $amount, $paymentMethod)
    {
        $remainingAmount = (string) $amount;

        // Pay opening balance first
        $openingRemaining = Money::sub(Money::sub($customer->opening_due, $customer->opening_due_paid), (string) ReturnAccountAllocation::where('customer_id', $customer->id)->whereNull('sale_id')->where('status', 'ACTIVE')->sum('amount'));
        if (Money::compare($openingRemaining, 0) > 0 && Money::compare($remainingAmount, 0) > 0) {
            $allocated = Money::compare($remainingAmount, $openingRemaining) >= 0 ? $openingRemaining : $remainingAmount;
            $payment->allocations()->create([
                'type' => 'OPENING_BALANCE',
                'amount' => $allocated,
            ]);
            $customer->increment('opening_due_paid', $allocated);
            $remainingAmount = Money::sub($remainingAmount, $allocated);
        }

        // Pay oldest invoices next
        if (Money::compare($remainingAmount, 0) > 0) {
            $unpaidSales = $customer->sales()->visibleTo()
                ->where('status', 'ACTIVE')
                ->oldest('sold_at')->lockForUpdate()
                ->get()
                ->filter(fn ($sale) => Money::compare($sale->due_balance, 0) > 0);

            foreach ($unpaidSales as $sale) {
                if (Money::compare($remainingAmount, 0) <= 0) {
                    break;
                }

                $due = $sale->due_balance;
                $allocated = Money::compare($remainingAmount, $due) >= 0 ? $due : $remainingAmount;

                $payment->allocations()->create([
                    'type' => 'INVOICE',
                    'sale_id' => $sale->id,
                    'amount' => $allocated,
                ]);

                SaleCollection::create([
                    'sale_id' => $sale->id,
                    'register_id' => $sale->register_id ?? Register::first()->id, // fallback
                    'user_id' => $payment->user_id,
                    'payment_method_id' => $paymentMethod->id,
                    'token' => Str::uuid(),
                    'method_name' => $paymentMethod->name,
                    'method_type' => $paymentMethod->type,
                    'amount' => $allocated,
                    'amount_paid' => $allocated,
                    'change' => 0,
                    'collected_at' => $payment->payment_date,
                    'reference' => 'Customer Payment #'.$payment->id,
                ]);

                $remainingAmount = Money::sub($remainingAmount, $allocated);
            }
        }
    }

    private function manualAllocate(CustomerPayment $payment, Customer $customer, $allocations, $paymentMethod)
    {
        if (isset($allocations['opening_balance']) && Money::compare((string) $allocations['opening_balance'], 0) > 0) {
            $allocated = (string) $allocations['opening_balance'];
            $opening = Money::sub(Money::sub($customer->opening_due, $customer->opening_due_paid), (string) ReturnAccountAllocation::where('customer_id', $customer->id)->whereNull('sale_id')->where('status', 'ACTIVE')->sum('amount'));
            if (Money::compare($allocated, $opening) > 0) {
                throw ValidationException::withMessages(['allocations' => 'Opening allocation exceeds remaining due.']);
            }
            $payment->allocations()->create([
                'type' => 'OPENING_BALANCE',
                'amount' => $allocated,
            ]);
            $customer->increment('opening_due_paid', $allocated);
        }

        if (isset($allocations['sales']) && is_array($allocations['sales'])) {
            foreach ($allocations['sales'] as $saleId => $amount) {
                if (Money::compare((string) $amount, 0) > 0) {
                    $sale = Sale::visibleTo()->where('customer_id', $customer->id)->lockForUpdate()->findOrFail($saleId);
                    if (Money::compare((string) $amount, $sale->due_balance) > 0) {
                        throw ValidationException::withMessages(['allocations' => 'Invoice allocation exceeds remaining due.']);
                    }
                    $payment->allocations()->create([
                        'type' => 'INVOICE',
                        'sale_id' => $sale->id,
                        'amount' => $amount,
                    ]);

                    SaleCollection::create([
                        'sale_id' => $sale->id,
                        'register_id' => $sale->register_id ?? Register::first()->id,
                        'user_id' => $payment->user_id,
                        'payment_method_id' => $paymentMethod->id,
                        'token' => Str::uuid(),
                        'method_name' => $paymentMethod->name,
                        'method_type' => $paymentMethod->type,
                        'amount' => $amount,
                        'amount_paid' => $amount,
                        'change' => 0,
                        'collected_at' => $payment->payment_date,
                        'reference' => 'Customer Payment #'.$payment->id,
                    ]);
                }
            }
        }
    }

    public function ledger(Request $request, Customer $customer)
    {
        abort_unless($request->user()->hasPermission('customers.ledger'), 403);
        abort_if($request->filled('export') && ! $request->user()->hasPermission('customers.export'), 403);
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $ledger = app(CustomerLedgerService::class)->build($customer, $startDate, $endDate);

        if ($request->input('export') === 'pdf') {
            return view('customers.ledger_print', compact('customer', 'ledger', 'startDate', 'endDate'));
        }

        if ($request->input('export') === 'csv') {
            $filename = "ledger_{$customer->name}.csv";
            $headers = [
                'Content-type' => 'text/csv',
                'Content-Disposition' => "attachment; filename=$filename",
                'Pragma' => 'no-cache',
                'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
                'Expires' => '0',
            ];
            $callback = function () use ($ledger) {
                $file = fopen('php://output', 'w');
                fputcsv($file, ['Date', 'Type', 'Description', 'Debit', 'Credit', 'Balance']);
                foreach ($ledger as $row) {
                    fputcsv($file, [
                        $row['date']->format('Y-m-d H:i'),
                        $row['type'],
                        $row['description'],
                        $row['debit'],
                        $row['credit'],
                        $row['balance'],
                    ]);
                }
                fclose($file);
            };

            return response()->stream($callback, 200, $headers);
        }

        return view('customers.ledger', compact('customer', 'ledger', 'startDate', 'endDate'));
    }
}
