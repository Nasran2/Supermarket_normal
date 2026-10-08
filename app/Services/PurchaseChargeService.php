<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Purchase;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

class PurchaseChargeService
{
    public function allocate(array $lines, string $charges): array
    {
        $subtotal = Money::sum(array_column($lines, 'total'));
        $weight = BigDecimal::zero();
        $allocated = '0.00';
        foreach ($lines as &$line) {
            $weight = $weight->plus(Money::compare($subtotal, 0) > 0 ? $line['total'] : 1);
            $cumulative = (string) BigDecimal::of($charges)->multipliedBy($weight)->dividedBy(Money::compare($subtotal, 0) > 0 ? $subtotal : count($lines), 2, RoundingMode::HALF_UP);
            $line['allocated_charge'] = Money::sub($cumulative, $allocated);
            $allocated = $cumulative;
            $line['base_cost'] = (string) BigDecimal::of(Money::add($line['total'], $line['allocated_charge']))->dividedBy($line['base_quantity'], 2, RoundingMode::HALF_UP);
            if (Money::compare($line['base_cost'], '9999999999999.99') > 0) {
                throw ValidationException::withMessages(['charges' => 'Allocated cost exceeds the supported unit cost.']);
            }
        }

        return $lines;
    }

    public function replace(Purchase $purchase, array $charges, int $userId): void
    {
        foreach ($purchase->charges()->get() as $charge) {
            $charge->expense?->update(['status' => 'REVERSED']);
            $charge->update(['status' => 'REVERSED']);
        }
        foreach ($charges as $row) {
            $expense = null;
            if ($purchase->charge_treatment === 'EXPENSE') {
                $category = ExpenseCategory::firstOrCreate(['name' => 'Purchase charges'], ['system' => true]);
                $expense = Expense::create(['expense_category_id' => $category->id, 'user_id' => $userId, 'type' => 'PURCHASE_CHARGE', 'status' => 'ACTIVE', 'expense_date' => $purchase->purchase_date, 'reference' => $purchase->reference, 'description' => $row['label'].' · '.$purchase->supplier->name, 'amount' => $row['amount']]);
            }
            $purchase->charges()->create(['label' => $row['label'], 'amount' => $row['amount'], 'expense_id' => $expense?->id]);
        }
    }

    public function reverse(Purchase $purchase): void
    {
        foreach ($purchase->charges()->get() as $charge) {
            $charge->expense?->update(['status' => 'REVERSED']);
        }
    }
}
