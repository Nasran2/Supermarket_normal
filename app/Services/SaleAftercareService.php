<?php

namespace App\Services;

use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleCollection;
use App\Models\SaleReturn;
use App\Support\Audit;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        return DB::transaction(function () use ($original, $data, $userId) {
            $register = $this->registers->current($userId, true);
            if (! $register) {
                $this->fail('Open your register before recording a return.');
            }
            $sale = Sale::whereKey($original->id)->lockForUpdate()->firstOrFail();
            $existing = SaleReturn::where('token', $data['token'])->first();
            if ($existing) {
                abort_unless($existing->sale_id === $sale->id && $existing->user_id === $userId, 403);

                return $existing;
            }
            if ($sale->status !== 'ACTIVE') {
                $this->fail('A deleted or voided sale cannot be returned.');
            }
            $available = collect($this->returnableLines($sale))->keyBy(fn ($line) => $line['item']->id);
            $lines = [];
            $amount = '0.00';
            $cost = '0.00';
            foreach ($data['items'] as $input) {
                $line = $available->get($input['sale_item_id']);
                if (! $line) {
                    $this->fail('A return item does not belong to this invoice.');
                }
                $qty = (string) $input['quantity'];
                if (Money::compare($qty, 0) === 0) {
                    continue;
                }
                if (Money::compare($qty, $line['remaining']) > 0) {
                    $this->fail('Return quantity exceeds the remaining quantity for '.$line['item']->name.'.');
                }
                $item = $line['item'];
                $unit = $item->unitRecord ?? $item->product?->unit;
                if ($unit && ! $unit->allow_decimal && Money::compare($qty, Money::round($qty, 0)) !== 0) {
                    $this->fail($item->name.' requires whole quantities.');
                }
                $cumulative = Money::quantity($line['returned'], $qty);
                $base = $this->portion($item->base_quantity ?? $item->quantity, $cumulative, $item->quantity, 3);
                $previousBase = '0.000';
                foreach ($item->returns as $prior) {
                    $previousBase = Money::quantity($previousBase, $prior->base_quantity);
                }
                $base = Money::quantity($base, '-'.$previousBase);
                $refund = Money::sub($this->portion($line['net'], $cumulative, $item->quantity), Money::sum($item->returns->pluck('amount')));
                $originalCost = $item->base_cost !== null ? Money::mul($item->base_cost, $item->base_quantity) : Money::mul($item->cost, $item->quantity);
                $lineCost = Money::sub($this->portion($originalCost, $cumulative, $item->quantity), Money::sum($item->returns->pluck('cost_total')));
                $amount = Money::add($amount, $refund);
                $cost = Money::add($cost, $lineCost);
                $lines[] = ['sale_item_id' => $item->id, 'product_id' => $item->product_id, 'quantity' => $qty, 'base_quantity' => $base, 'amount' => $refund, 'cost_total' => $lineCost];
            }
            if (! $lines) {
                $this->fail('Enter a return quantity for at least one item.');
            }
            $due = $sale->due_balance;
            $reduction = Money::compare($amount, $due) > 0 ? $due : $amount;
            $refund = Money::sub($amount, $reduction);
            $method = null;
            if (Money::compare($refund, 0) > 0) {
                $method = PaymentMethod::whereKey($data['payment_method_id'] ?? null)->where('active', true)->lockForUpdate()->first();
                if (! $method) {
                    $this->fail('Choose an active method for the refund.');
                }
            }
            $return = SaleReturn::create(['sale_id' => $sale->id, 'register_id' => $register->id, 'user_id' => $userId, 'token' => $data['token'], 'reference' => 'RET-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)), 'reason' => $data['reason'], 'amount' => $amount, 'cost_total' => $cost, 'due_reduction' => $reduction, 'refund_amount' => $refund, 'payment_method_id' => $method?->id, 'method_name' => $method?->name, 'method_type' => $method?->type, 'returned_at' => now()]);
            $products = Product::with('unit')->whereIn('id', array_column($lines, 'product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($lines as $line) {
                $product = $products->get($line['product_id']);
                if (! $product) {
                    $this->fail('The original product is unavailable.');
                }
                $this->stock->validateQuantity($product, $line['base_quantity']);
                unset($line['product_id']);
                $return->items()->create($line);
                $this->stock->move($product, $line['base_quantity'], 'SALE RETURN', $return->reference, $userId);
            }
            Audit::record('sale.return', $return, [], $return->load('items')->toArray());

            return $return;
        }, 3);
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
