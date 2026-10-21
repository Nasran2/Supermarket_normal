<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PaymentMethod;
use App\Models\Register;
use App\Models\ReturnAccountAllocation;
use App\Models\Sale;
use App\Models\SaleCollection;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerPaymentService
{
    public function record(Customer $customer, array $data, int $userId): void
    {
        $request = new Request($data);
        DB::transaction(function () use ($request, $customer, $userId) {
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

            $chequeService = app(ChequeService::class);
            if ($paymentMethod->type === 'CHEQUE') {
                $chequeService->authorize($userId, 'receive');
                $entries = $chequeService->entries($request->input('cheques', []), (string) $amount);
            } else {
                if ($request->filled('cheques')) {
                    $chequeService->fail('Cheque entries require the Cheque payment method.');
                }
                $entries = [['amount' => $amount]];
            }
            $targets = $request->input('allocations', []);
            $firstPayment = null;
            foreach ($entries as $entry) {
                $cheque = $paymentMethod->type === 'CHEQUE' ? $chequeService->create($entry, 'CUSTOMER_RECEIVED', $customer->id, $userId) : null;
                $customerPayment = $customer->customerPayments()->create([
                    'user_id' => $userId, 'register_id' => null, 'payment_method_id' => $paymentMethod->id,
                    'amount' => $entry['amount'], 'payment_date' => $request->input('payment_date'), 'notes' => $request->input('notes'),
                    'cheque_id' => $cheque?->id, 'settlement_status' => $cheque ? 'PENDING' : 'PASSED',
                ]);
                $firstPayment ??= $customerPayment;
                if ($request->mode === 'auto') {
                    $this->autoAllocate($customerPayment, $customer, $entry['amount'], $paymentMethod);
                } else {
                    $remaining = (string) $entry['amount'];
                    $part = ['opening_balance' => '0.00', 'sales' => []];
                    $opening = (string) ($targets['opening_balance'] ?? 0);
                    $take = Money::compare($opening, $remaining) > 0 ? $remaining : $opening;
                    $part['opening_balance'] = $take;
                    $targets['opening_balance'] = Money::sub($opening, $take);
                    $remaining = Money::sub($remaining, $take);
                    foreach ($targets['sales'] ?? [] as $id => $target) {
                        $take = Money::compare((string) $target, $remaining) > 0 ? $remaining : (string) $target;
                        $part['sales'][$id] = $take;
                        $targets['sales'][$id] = Money::sub((string) $target, $take);
                        $remaining = Money::sub($remaining, $take);
                    }
                    $this->manualAllocate($customerPayment, $customer, $part, $paymentMethod);
                }
                Audit::record('customer.payment', $customerPayment, [], $customerPayment->toArray());
            }
            if ($firstPayment) {
                app(PaymentSmsService::class)->queue($customer->id, $userId, (string) $amount, $paymentMethod->name, 'account:'.$firstPayment->id, null, $paymentMethod->type === 'CHEQUE');
            }
        });

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
                    'cheque_id' => $payment->cheque_id, 'settlement_status' => $payment->settlement_status,
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
                        'cheque_id' => $payment->cheque_id, 'settlement_status' => $payment->settlement_status,
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
}
