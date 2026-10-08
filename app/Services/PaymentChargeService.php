<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentChargeRule;
use App\Models\PaymentMethod;
use App\Models\SalePayment;
use App\Support\Money;

class PaymentChargeService
{
    public function getApplicableRule(PaymentMethod $method, string $amount, bool $lock = false): ?PaymentChargeRule
    {
        $rules = $method->rules()->where('active', true)->orderByDesc('priority')->orderBy('id');
        if ($lock) {
            $rules->lockForUpdate();
        }

        return $rules->get()->first(function ($r) use ($amount) {
            $min = Money::compare($amount, $r->minimum_amount);

            return ($r->comparison_operator === 'GT' ? $min > 0 : $min >= 0) && ($r->maximum_amount === null || Money::compare($amount, $r->maximum_amount) <= 0);
        });
    }

    public function calculateCharge(PaymentMethod $method, string $amount, bool $lock = false, ?string $ruleAmount = null): array
    {
        $rule = $this->getApplicableRule($method, $ruleAmount ?? $amount, $lock);
        $charge = $rule ? ($rule->charge_type === 'PERCENTAGE' ? Money::percent($amount, $rule->charge_value) : Money::round($rule->charge_value)) : '0.00';
        $bearer = $rule?->charge_bearer ?? $method->charge_bearer;

        return ['rule_id' => $rule?->id, 'rule_name' => $rule?->name, 'charge_type' => $rule?->charge_type, 'charge_value' => $rule?->charge_value ?? '0.0000', 'charge_bearer' => $bearer, 'processing_charge' => $charge, 'customer_payable' => $bearer === 'CUSTOMER' ? Money::add($amount, $charge) : $amount, 'business_expense' => $bearer === 'BUSINESS' ? $charge : '0.00'];
    }

    public function createProcessingExpense(SalePayment $payment): ?Expense
    {
        if ($payment->charge_bearer !== 'BUSINESS' || Money::compare($payment->processing_charge, 0) <= 0) {
            return null;
        }
        $category = ExpenseCategory::firstOrCreate(['name' => 'Payment Processing Charges'], ['system' => true]);
        if (! $category->system) {
            $category->update(['system' => true]);
        }

        return Expense::create(['expense_category_id' => $category->id, 'user_id' => $payment->sale->user_id, 'sale_id' => $payment->sale_id, 'sale_payment_id' => $payment->id, 'payment_method_id' => $payment->payment_method_id, 'type' => 'AUTOMATIC', 'expense_date' => $payment->sale->sold_at->toDateString(), 'reference' => $payment->sale->invoice, 'description' => $payment->method_name.' processing charge — '.$payment->sale->invoice.' · '.$payment->rule_name.' ('.$payment->charge_value.' '.($payment->charge_type === 'PERCENTAGE' ? '%' : 'fixed').')', 'amount' => $payment->processing_charge]);
    }
}
