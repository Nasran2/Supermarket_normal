<?php

namespace App\Services;

use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleCollection;
use App\Models\SaleReturn;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleAftercareService
{
    public function __construct(private RegisterService $registers, private StockService $stock) {}

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['sale' => $message]);
    }

    private function portion(string $total, string $quantity, string $whole, int $scale = 2): string
    {
        return (string) BigDecimal::of($total)->multipliedBy($quantity)->dividedBy($whole, $scale, RoundingMode::HALF_UP);
    }

    // Allocate the invoice discount once, with any rounding cent assigned deterministically.
    public function returnableLines(Sale $sale): array
    {
        $sale->loadMissing(['items.product.unit', 'items.unitRecord', 'items.returns']);
        $total = Money::sum($sale->items->pluck('total'));
        $cumulative = '0.00';
        $allocated = '0.00';
        $lines = [];
        foreach ($sale->items->sortBy('id') as $item) {
            $cumulative = Money::add($cumulative, $item->total);
            $target = Money::compare($total, 0) > 0 ? $this->portion($sale->sale_amount, $cumulative, $total) : '0.00';
            $net = Money::sub($target, $allocated);
            $allocated = $target;
            $returned = '0.000';
            foreach ($item->returns as $prior) {
                $returned = Money::quantity($returned, $prior->quantity);
            }
            $lines[] = ['item' => $item, 'net' => $net, 'returned' => $returned, 'remaining' => Money::quantity($item->quantity, '-'.$returned)];
        }

        return $lines;
    }

    public function returnItems(Sale $original, array $data, int $userId): SaleReturn
    {
        if (! $this->registers->current($userId)) {
            $this->fail('Open your register before recording a return.');
        }

        return app(SalesReturnService::class)->complete($original, $data, User::findOrFail($userId));
    }

    public function collect(Sale $original, array $data, int $userId): SaleCollection
    {
        return DB::transaction(function () use ($original, $data, $userId) {
            $register = $this->registers->current($userId, true);
            if (! $register) {
                $this->fail('Open your register before collecting a due payment.');
            }
            $sale = Sale::whereKey($original->id)->lockForUpdate()->firstOrFail();
            $existing = SaleCollection::where('token', $data['token'])->first();
            if ($existing) {
                abort_unless($existing->sale_id === $sale->id && $existing->user_id === $userId, 403);

                return $existing;
            }
            if ($sale->status !== 'ACTIVE') {
                $this->fail('A deleted or voided sale cannot receive payments.');
            }
            $due = $sale->due_balance;
            $amount = Money::round($data['amount']);
            if (Money::compare($due, 0) <= 0 || Money::compare($amount, $due) > 0) {
                $this->fail('Payment exceeds the outstanding balance. Refresh the invoice and check its due.');
            }
            $method = PaymentMethod::whereKey($data['payment_method_id'])->where('active', true)->lockForUpdate()->first();
            if (! $method) {
                $this->fail('Choose an active payment method.');
            }
            $paid = Money::round($data['amount_paid']);
            if (Money::compare($paid, $amount) < 0 || ($method->type !== 'CASH' && Money::compare($paid, $amount) !== 0)) {
                $this->fail('Cash must cover the payment. Other methods must match it exactly.');
            }
            $collection = SaleCollection::create(['sale_id' => $sale->id, 'register_id' => $register->id, 'user_id' => $userId, 'payment_method_id' => $method->id, 'token' => $data['token'], 'method_name' => $method->name, 'method_type' => $method->type, 'amount' => $amount, 'amount_paid' => $paid, 'change' => Money::sub($paid, $amount), 'reference' => $data['reference'] ?? null, 'collected_at' => now()]);
            Audit::record('sale.collect', $collection, [], $collection->toArray());

            return $collection;
        }, 3);
    }
}
