<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Support\Money;

class ProfitLossService
{
    public function calculate(string $from, string $to): array
    {
        $sales = Sale::where('status', 'ACTIVE')->whereBetween('sold_at', [$from.' 00:00:00', $to.' 23:59:59']);
        $expenses = Expense::where('status', 'ACTIVE')->whereBetween('expense_date', [$from, $to]);
        $revenue = Money::round((string) (clone $sales)->sum('sale_amount'));
        $cogs = Money::round((string) $sales->sum('cost_total'));
        $returns = SaleReturn::whereBetween('returned_at', [$from.' 00:00:00', $to.' 23:59:59']);
        $revenue = Money::sub($revenue, (string) (clone $returns)->sum('amount'));
        $cogs = Money::sub($cogs, (string) $returns->sum('cost_total'));
        $gross = Money::sub($revenue, $cogs);
        $manual = Money::round((string) (clone $expenses)->where('type', 'MANUAL')->sum('amount'));
        $processing = Money::round((string) (clone $expenses)->where('type', 'AUTOMATIC')->sum('amount'));
        $purchaseCharges = Money::round((string) (clone $expenses)->where('type', 'PURCHASE_CHARGE')->sum('amount'));
        $totalExpenses = Money::add(Money::add($manual, $processing), $purchaseCharges);
        $net = Money::sub($gross, $totalExpenses);

        return compact('revenue', 'cogs', 'gross', 'manual', 'processing', 'purchaseCharges', 'net') + ['expenses' => $totalExpenses];
    }
}
