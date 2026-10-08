<?php

namespace App\Services;

use App\Models\PaymentMethod;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

class SplitPaymentService
{
    public function __construct(private PaymentChargeService $charges) {}

    public function quote(array $data, string $saleAmount, bool $lock): array
    {
        $parts = $data['payments'] ?? [['payment_method_id' => $data['payment_method_id'], 'amount' => $saleAmount]];
        $ids = array_column($parts, 'payment_method_id');
        $methods = PaymentMethod::whereIn('id', $ids)->where('active', true)->orderBy('id');
        if ($lock) {
            $methods->lockForUpdate();
        }
        $methods = $methods->get()->keyBy('id');
        $payments = [];
        foreach ($parts as $i => $part) {
            $method = $methods->get($part['payment_method_id']);
            if (! $method) {
                throw ValidationException::withMessages(['payments.'.$i.'.payment_method_id' => 'Select an active payment method.']);
            }
            $amount = Money::round((string) $part['amount']);
            // Each payment entry is independent, even when the method is repeated.
            // Thresholds refer to the whole discounted bill; fees apply to this entry's share.
            $charge = $this->charges->calculateCharge($method, $amount, $lock, $saleAmount);
            if (Money::compare($amount, 0) === 0 && Money::compare($saleAmount, 0) > 0) {
                $charge['processing_charge'] = $charge['customer_payable'] = $charge['business_expense'] = '0.00';
            }
            $payments[] = ['payment_method_id' => $method->id, 'method_name' => $method->name, 'method_type' => $method->type, 'sale_amount' => $amount] + $charge;
        }
        $allocated = Money::sum(array_column($payments, 'sale_amount'));
        if (Money::compare($allocated, $saleAmount) > 0) {
            throw ValidationException::withMessages(['payments' => 'Payment amounts exceed the bill total. Reduce a payment amount.']);
        }
        $customerFees = Money::sum(array_map(fn ($p) => $p['charge_bearer'] === 'CUSTOMER' ? $p['processing_charge'] : '0.00', $payments));
        $businessFees = Money::sum(array_column($payments, 'business_expense'));

        return ['payments' => $payments, 'allocated_amount' => $allocated, 'remaining_amount' => Money::sub($saleAmount, $allocated), 'customer_processing_charge' => $customerFees, 'business_expense' => $businessFees, 'processing_charge' => Money::sum(array_column($payments, 'processing_charge')), 'customer_payable' => Money::add($saleAmount, $customerFees)] + (count($payments) === 1 ? $payments[0] : []);
    }

    public function settle(array $data, array $quote): array
    {
        if (empty($data['allow_due']) && Money::compare($quote['remaining_amount'], 0) !== 0) {
            throw ValidationException::withMessages(['payments' => 'Allocate the full bill amount before completing the sale.']);
        }
        $payments = [];
        foreach ($quote['payments'] as $i => $payment) {
            if (! empty($data['allow_due']) && Money::compare($payment['sale_amount'], 0) === 0 && Money::compare($quote['sale_amount'], 0) > 0) {
                if (Money::compare((string) ($data['payments'][$i]['amount_paid'] ?? $data['amount_paid'] ?? 0), 0) !== 0) {
                    throw ValidationException::withMessages(['payments.'.$i.'.amount_paid' => 'An unused payment entry cannot receive money.']);
                }

                continue;
            }
            if (Money::compare($payment['sale_amount'], 0) <= 0 && (count($quote['payments']) > 1 || Money::compare($quote['sale_amount'], 0) > 0)) {
                throw ValidationException::withMessages(['payments.'.$i.'.amount' => 'Remove unused payment methods or enter a positive amount.']);
            }
            $part = isset($data['payments']) ? $data['payments'][$i] : $data;
            $paidField = isset($data['payments']) ? 'payments.'.$i.'.amount_paid' : 'amount_paid';
            $paid = Money::round((string) $part['amount_paid']);
            if (empty($data['allow_due']) && Money::compare($paid, $payment['customer_payable']) < 0) {
                throw ValidationException::withMessages([$paidField => $payment['method_name'].' received is less than its amount to collect.']);
            }
            if ($payment['method_type'] !== 'CASH' && Money::compare($paid, $payment['customer_payable']) !== 0) {
                throw ValidationException::withMessages([$paidField => $payment['method_name'].' received must match its amount to collect.']);
            }
            $payments[] = $payment + ['amount_paid' => $paid, 'change' => $payment['method_type'] === 'CASH' && Money::compare($paid, $payment['customer_payable']) > 0 ? Money::sub($paid, $payment['customer_payable']) : '0.00', 'reference' => $part['reference'] ?? null];
        }

        return $payments;
    }
}
