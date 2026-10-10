<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Support\Money;
use App\Support\SalesVisibility;

class ProfitLossService
{
    public function calculate(string $from, string $to): array
    {
        $period = [$from.' 00:00:00', $to.' 23:59:59'];
        $sales = Sale::visibleTo()->whereIn('status', ['ACTIVE', 'RETURN_CANCELLED'])->whereBetween('sold_at', $period);
        $returns = SaleReturn::whereHas('sale', fn ($q) => $q->visibleTo())->whereBetween('returned_at', $period);
        $cancellations = SaleReturn::whereHas('sale', fn ($q) => $q->visibleTo())->where('status', 'CANCELLED')->whereBetween('cancelled_at', $period);
        $grossSales = Money::round((string) (clone $sales)->sum('sale_amount'));
        $salesReturns = Money::sub((string) (clone $returns)->sum('amount'), (string) (clone $cancellations)->sum('amount'));
        $exchangeReversals = Sale::visibleTo()->where('status', 'RETURN_CANCELLED')->whereBetween('voided_at', $period);
        $grossSales = Money::sub($grossSales, (string) (clone $exchangeReversals)->sum('sale_amount'));
        $revenue = Money::sub($grossSales, $salesReturns);
        $cogs = Money::sub(Money::sub((string) $sales->sum('cost_total'), (string) $returns->sum('cost_total')), (string) $exchangeReversals->sum('cost_total'));
        $cogs = Money::add($cogs, (string) $cancellations->sum('cost_total'));
        $gross = Money::sub($revenue, $cogs);
        $expenses = SalesVisibility::apply(Expense::visibleSales(), 'expenses.user_id')->whereBetween('expense_date', [$from, $to]);
        $expenses->where(fn ($q) => $q->where('status', 'ACTIVE')->orWhere(fn ($q) => $q->where('status', 'REVERSED')->where(fn ($q) => $q->where('type', 'RETURN_WRITEOFF')->orWhere(fn ($q) => $q->where('type', 'AUTOMATIC')->whereHas('sale', fn ($q) => $q->where('status', 'RETURN_CANCELLED'))))));
        $reversed = SalesVisibility::apply(Expense::visibleSales(), 'expenses.user_id')->where('status', 'REVERSED')->whereBetween('updated_at', $period)->where(fn ($q) => $q->where('type', 'RETURN_WRITEOFF')->orWhere(fn ($q) => $q->where('type', 'AUTOMATIC')->whereHas('sale', fn ($q) => $q->where('status', 'RETURN_CANCELLED'))));
        $manual = Money::round((string) (clone $expenses)->whereIn('type', ['MANUAL', 'HR_PAYROLL'])->sum('amount'));
        $processing = Money::sub((string) (clone $expenses)->where('type', 'AUTOMATIC')->sum('amount'), (string) (clone $reversed)->where('type', 'AUTOMATIC')->sum('amount'));
        $purchaseCharges = Money::round((string) (clone $expenses)->where('type', 'PURCHASE_CHARGE')->sum('amount'));
        $returnWriteoffs = Money::sub((string) (clone $expenses)->where('type', 'RETURN_WRITEOFF')->sum('amount'), (string) $reversed->where('type', 'RETURN_WRITEOFF')->sum('amount'));
        $totalExpenses = Money::sum([$manual, $processing, $purchaseCharges, $returnWriteoffs]);
        $net = Money::sub($gross, $totalExpenses);

        return compact('grossSales', 'salesReturns', 'revenue', 'cogs', 'gross', 'manual', 'processing', 'purchaseCharges', 'returnWriteoffs', 'net') + ['expenses' => $totalExpenses];
    }
}
