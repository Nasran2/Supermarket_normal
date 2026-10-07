<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Unit;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseService
{
    public function __construct(private StockService $stock) {}

    public function save(array $data, int $userId, ?Purchase $purchase = null): Purchase
    {
        return DB::transaction(function () use ($data, $userId, $purchase) {
            $before = [];
            if ($purchase) {
                $purchase = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
                $before = $purchase->load('items')->toArray();
                $ids = array_unique(array_merge(array_column($data['items'], 'product_id'), $purchase->items->pluck('product_id')->all()));
                Product::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
                if ($purchase->status !== 'ACTIVE') {
                    throw ValidationException::withMessages(['items' => 'Voided purchases cannot be edited.']);
                }
                $this->reverseItems($purchase, $userId);
                $purchase->items()->delete();
            }
            $products = Product::with('unit')->whereIn('id', array_column($data['items'], 'product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $units = Unit::whereIn('id', $products->pluck('unit_id')->unique())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($products as $product) {
                $product->setRelation('unit', $units[$product->unit_id]);
            }
            $total = '0.00';
            $lines = [];
            foreach ($data['items'] as $item) {
                $p = $products->get($item['product_id']);
                if (! $p || ! $p->active || ! $p->unit->active) {
                    throw ValidationException::withMessages(['items' => 'Choose active products and units.']);
                }
                $this->stock->validateQuantity($p, (string) $item['quantity']);
                $lineTotal = Money::mul((string) $item['cost'], (string) $item['quantity']);
                $total = Money::add($total, $lineTotal);
                $lines[] = ['product_id' => $p->id, 'name' => $p->name, 'unit' => $p->unit->short_name, 'quantity' => $item['quantity'], 'cost' => $item['cost'], 'total' => $lineTotal];
            }
            if (Money::compare($total, '9999999999999.99') > 0) {
                throw ValidationException::withMessages(['items' => 'The purchase total exceeds the supported range.']);
            }
            $fields = ['reference' => $data['reference'], 'supplier_id' => $data['supplier_id'], 'purchase_date' => $data['purchase_date'], 'notes' => $data['notes'] ?? null, 'total' => $total];
            if ($purchase) {
                $purchase->update($fields);
            } else {
                $purchase = Purchase::create($fields + ['user_id' => $userId]);
            }
            foreach ($lines as $line) {
                $line['previous_cost'] = $products[$line['product_id']]->cost;
                $purchase->items()->create($line);
                $p = $products[$line['product_id']];
                $this->stock->move($p, (string) $line['quantity'], 'PURCHASE', $purchase->reference, $userId);
                $p->update(['cost' => $line['cost']]);
            }
            Audit::record('purchase.save', $purchase, $before, $purchase->load('items')->toArray());

            return $purchase;
        }, 3);
    }

    private function reverseItems(Purchase $purchase, int $userId): void
    {
        foreach ($purchase->items()->orderBy('product_id')->get() as $item) {
            $p = Product::whereKey($item->product_id)->lockForUpdate()->firstOrFail();
            if (Money::compare($p->stock, $item->quantity) < 0) {
                throw ValidationException::withMessages(['items' => 'Cannot reverse '.$p->name.': purchased stock has already been sold.']);
            }
            $this->stock->move($p, '-'.$item->quantity, 'PURCHASE REVERSAL', $purchase->reference, $userId);
            if (Money::compare($p->cost, $item->cost) === 0) {
                $prior = PurchaseItem::where('product_id', $p->id)->where('purchase_id', '!=', $purchase->id)->whereHas('purchase', fn ($q) => $q->where('status', 'ACTIVE'))->latest('id')->first();
                $restoredCost = $prior?->cost ?? $item->previous_cost;
                if ($restoredCost !== null) {
                    $p->update(['cost' => $restoredCost]);
                }
            }
        }
    }

    public function void(Purchase $purchase, int $userId, string $reason): void
    {
        DB::transaction(function () use ($purchase, $userId, $reason) {
            $p = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($p->status !== 'ACTIVE') {
                throw ValidationException::withMessages(['reason' => 'Purchase is already voided.']);
            }
            $this->reverseItems($p, $userId);
            $p->update(['status' => 'VOIDED']);
            Audit::record('purchase.void', $p, [], ['reason' => $reason]);
        }, 3);
    }
}
