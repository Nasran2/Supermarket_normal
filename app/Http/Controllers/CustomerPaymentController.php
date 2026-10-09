<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PaymentMethod;
use App\Models\Register;
use App\Models\Sale;
use App\Models\SaleCollection;
use App\Models\SalePayment;
use App\Support\Money;
use App\Support\SalesVisibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
            'allocations' => 'array',
        ]);

        DB::transaction(function () use ($request, $customer) {
            $paymentMethod = PaymentMethod::find($request->payment_method_id);
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
        $openingRemaining = Money::sub($customer->opening_due, $customer->opening_due_paid);
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
                ->oldest('sold_at')
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
            $payment->allocations()->create([
                'type' => 'OPENING_BALANCE',
                'amount' => $allocated,
            ]);
            $customer->increment('opening_due_paid', $allocated);
        }

        if (isset($allocations['sales']) && is_array($allocations['sales'])) {
            foreach ($allocations['sales'] as $saleId => $amount) {
                if (Money::compare((string) $amount, 0) > 0) {
                    $sale = Sale::visibleTo()->where('customer_id', $customer->id)->findOrFail($saleId);
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

        $salesQuery = $customer->sales()->visibleTo()->where('status', 'ACTIVE');
        if ($startDate) {
            $salesQuery->whereDate('sold_at', '>=', $startDate);
        }
        if ($endDate) {
            $salesQuery->whereDate('sold_at', '<=', $endDate);
        }

        $sales = $salesQuery->get()->map(function ($sale) {
            return [
                'date' => $sale->sold_at,
                'type' => 'Invoice',
                'description' => 'Invoice #'.$sale->invoice,
                'debit' => $sale->customer_payable,
                'credit' => 0,
            ];
        });

        $paymentsQuery = SalesVisibility::apply($customer->customerPayments()->getQuery(), 'customer_payments.user_id');
        if ($startDate) {
            $paymentsQuery->whereDate('payment_date', '>=', $startDate);
        }
        if ($endDate) {
            $paymentsQuery->whereDate('payment_date', '<=', $endDate);
        }

        $payments = $paymentsQuery->with(['paymentMethod', 'allocations.sale' => fn ($q) => $q->visibleTo()])->get()->map(function ($payment) {
            $desc = 'Customer Payment - '.($payment->paymentMethod->name ?? 'Unknown');
            if ($payment->allocations->count() > 0) {
                $parts = [];
                foreach ($payment->allocations as $alloc) {
                    if ($alloc->type === 'OPENING_BALANCE') {
                        $parts[] = 'Opening Balance';
                    } elseif ($alloc->type === 'INVOICE' && $alloc->sale) {
                        $parts[] = $alloc->sale->invoice;
                    }
                }
                if (count($parts) > 0) {
                    $desc .= ' [Allocated to: '.implode(', ', $parts).']';
                }
            }

            return [
                'date' => $payment->payment_date,
                'type' => 'Payment',
                'description' => $desc,
                'debit' => 0,
                'credit' => $payment->amount,
            ];
        });

        // Add POS Payments (SalePayments)
        $salePaymentsQuery = SalePayment::whereHas('sale', function ($q) use ($customer) {
            $q->visibleTo()->where('customer_id', $customer->id);
        });
        if ($startDate) {
            $salePaymentsQuery->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $salePaymentsQuery->whereDate('created_at', '<=', $endDate);
        }

        $salePayments = $salePaymentsQuery->with('sale')->get()->map(function ($sp) {
            return [
                'date' => $sp->created_at,
                'type' => 'POS Payment',
                'description' => 'Payment for '.($sp->sale->invoice ?? 'Sale').' ('.$sp->method_name.')',
                'debit' => 0,
                'credit' => Money::sub($sp->amount_paid, $sp->change),
            ];
        });

        $ledger = collect();
        if (! $startDate) {
            $ledger->push([
                'date' => $customer->created_at,
                'type' => 'Opening Balance',
                'description' => 'Opening Balance',
                'debit' => $customer->opening_due,
                'credit' => 0,
            ]);
        }

        $ledger = $ledger->concat($sales)->concat($payments)->concat($salePayments)->sortBy('date')->values();

        $balance = '0.00';
        $ledger = $ledger->map(function ($item) use (&$balance) {
            $balance = Money::add(Money::sub($balance, (string) $item['credit']), (string) $item['debit']);
            $item['balance'] = $balance;

            return $item;
        });

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
