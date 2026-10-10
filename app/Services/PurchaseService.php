<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\Unit;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseService
{
    public function __construct(private StockService $stock) {}

    public function save(array $data, int $userId, ?Purchase $purchase = null): Purchase
    {
        return DB::transaction(function () use ($data, $userId, $purchase) {
            $user = User::findOrFail($userId);
            if (! $user->hasPermission('purchases.manage_charges')) {
                if ($purchase) {
                    $data['charges'] = $purchase->charges->map(fn ($charge) => $charge->only('label', 'amount'))->all();
                    $data['charge_treatment'] = $purchase->charge_treatment;
                } else {
                    abort_if(! empty($data['charges']), 403);
                }
            }
            $before = [];
            $isNew = ! $purchase;
            $mode = $data['payment_mode'] ?? 'AUTO';
            if ($isNew && (in_array($mode, ['FULL', 'PARTIAL']) || ($mode === 'AUTO' && Money::compare((string) ($data['amount_paid'] ?? 0), 0) > 0))) {
                $take = ! isset($data['take_from_register']) || $data['take_from_register'];
                app(PurchasePaymentService::class)->registerFor($data['payment_method_id'] ?? null, $userId, $take);
            }
            if ($purchase) {
                $purchase = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
                if ($purchase->returns()->exists() || PurchaseReturn::where('replacement_purchase_id', $purchase->id)->orWhere('purchase_id', $purchase->id)->exists()) {
                    throw ValidationException::withMessages(['items' => 'Purchases linked to returns cannot be edited.']);
                }
                $before = $purchase->load('items', 'charges')->toArray();
                $ids = array_unique(array_merge(array_column($data['items'], 'product_id'), $purchase->items->pluck('product_id')->all()));
                Product::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
                if ($purchase->status !== 'ACTIVE') {
                    throw ValidationException::withMessages(['items' => 'Voided purchases cannot be edited.']);
                }
                if ($purchase->supplier_id != $data['supplier_id'] && Money::compare($purchase->paid_amount, 0) > 0) {
                    throw ValidationException::withMessages(['supplier_id' => 'Refund the recorded payments before changing this purchase supplier.']);
                }
                $this->reverseItems($purchase, $userId);
                $purchase->items()->delete();
            }
            $products = Product::with(['unit', 'conversions.unit'])->whereIn('id', array_column($data['items'], 'product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $units = Unit::whereIn('id', $products->pluck('unit_id')->merge($products->flatMap(fn ($p) => $p->conversions->pluck('unit_id')))->unique())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($products as $product) {
                $product->setRelation('unit', $units[$product->unit_id]);
                foreach ($product->conversions as $conversion) {
                    $conversion->setRelation('unit', $units[$conversion->unit_id]);
                }
            }
            $total = '0.00';
            $lines = [];
            foreach ($data['items'] as $item) {
                $p = $products->get($item['product_id']);
                if (! $p || ! $p->active || ! $p->unit->active) {
                    throw ValidationException::withMessages(['items' => 'Choose active products and units.']);
                }
                $selected = app(ProductUnitService::class)->resolve($p, $item['unit_id'] ?? null, (string) $item['quantity']);
                $baseCost = app(ProductUnitService::class)->rate((string) $item['cost'], $selected['converted_quantity'], $selected['base_quantity']);
                if (Money::compare($baseCost, '9999999999999.99') > 0) {
                    throw ValidationException::withMessages(['items' => 'Converted cost exceeds the supported range.']);
                }
                $selling = isset($item['selling_price']) ? (string) $item['selling_price'] : $selected['price'];
                if (isset($item['selling_price']) && Money::compare($selling, $selected['price']) !== 0 && ! User::findOrFail($userId)->hasPermission('purchases.manage_prices')) {
                    throw ValidationException::withMessages(['items' => 'You do not have permission to change incoming selling prices.']);
                }
                $baseSelling = app(ProductUnitService::class)->rate($selling, $selected['converted_quantity'], $selected['base_quantity']);
                if (Money::compare($baseSelling, '9999999999999.99') > 0) {
                    throw ValidationException::withMessages(['items' => 'Converted selling price exceeds the supported range.']);
                }
                $lineTotal = Money::mul((string) $item['cost'], (string) $item['quantity']);
                $total = Money::add($total, $lineTotal);
                $lines[] = ['product_id' => $p->id, 'name' => $p->name, 'unit' => $selected['short_name'], 'unit_id' => $selected['id'], 'base_quantity' => $selected['base_stock_quantity'], 'base_cost' => $baseCost, 'selling_price' => $selling, 'base_selling_price' => $baseSelling, 'quantity' => $item['quantity'], 'cost' => $item['cost'], 'total' => $lineTotal];
            }
            $subtotal = $total;
            $charges = $data['charges'] ?? [];
            $chargesTotal = Money::sum(array_column($charges, 'amount'));
            $treatment = $data['charge_treatment'] ?? 'EXPENSE';
            if ($treatment === 'COST' && Money::compare($chargesTotal, 0) > 0) {
                $lines = app(PurchaseChargeService::class)->allocate($lines, $chargesTotal);
            }
            $total = Money::add($subtotal, $chargesTotal);
            if (Money::compare($total, '9999999999999.99') > 0) {
                throw ValidationException::withMessages(['items' => 'The purchase total exceeds the supported range.']);
            }
            $fields = ['reference' => $isNew && (! empty($data['auto_reference']) || empty($data['reference'])) ? app(DocumentNumberService::class)->next('PURCHASE', $data['purchase_date']) : $data['reference'], 'subtotal' => $subtotal, 'charges_total' => $chargesTotal, 'charge_treatment' => $treatment, 'supplier_id' => $data['supplier_id'], 'purchase_date' => $data['purchase_date'], 'notes' => $data['notes'] ?? null, 'total' => $total];
            if ($purchase) {
                if (Money::compare($total, $purchase->paid_amount) < 0) {
                    throw ValidationException::withMessages(['items' => 'The new total is less than the amount already paid. Record the supplier refund before reducing this purchase.']);
                }
                $purchase->update($fields);
            } else {
                $purchase = Purchase::create($fields + ['user_id' => $userId, 'payment_tracking' => true]);
            }
            foreach ($lines as $line) {
                $line['previous_cost'] = $products[$line['product_id']]->cost;
                $purchaseItem = $purchase->items()->create($line);
                $p = $products[$line['product_id']];
                app(StockLayerService::class)->receive($p, (string) $line['base_quantity'], $line['base_cost'], $line['base_selling_price'], 'PURCHASE', $purchase->reference, $userId, $purchaseItem->id, $purchase->purchase_date, $purchaseItem->id, $treatment === 'COST' && Money::compare($chargesTotal, 0) > 0 ? Money::add($line['total'], $line['allocated_charge']) : null);
                $p->update(['cost' => $line['base_cost']]);
            }
            app(PurchaseChargeService::class)->replace($purchase, $charges, $userId);
            if ($isNew) {
                $amount = $mode === 'FULL' ? $total : (in_array($mode, ['PARTIAL', 'AUTO']) ? Money::round((string) ($data['amount_paid'] ?? 0)) : '0.00');
                if ($mode === 'PARTIAL' && (Money::compare($amount, 0) <= 0 || Money::compare($amount, $total) >= 0)) {
                    throw ValidationException::withMessages(['amount_paid' => 'For a partial payment, enter an amount greater than zero and less than the purchase total.']);
                }
                if (Money::compare($amount, 0) > 0) {
                    app(PurchasePaymentService::class)->record($purchase, ['token' => (string) Str::uuid(), 'allow_change' => $mode === 'AUTO', 'amount' => $amount, 'payment_method_id' => $data['payment_method_id'], 'reference' => $data['payment_reference'] ?? null, 'take_from_register' => $data['take_from_register'] ?? true], $userId);
                }
            }
            Audit::record('purchase.save', $purchase, $before, $purchase->load('items')->toArray());

            return $purchase;
        }, 3);
    }

    private function reverseItems(Purchase $purchase, int $userId): void
    {
        foreach ($purchase->items()->orderBy('product_id')->get() as $item) {
            $p = Product::whereKey($item->product_id)->lockForUpdate()->firstOrFail();
            $layers = $item->stockLayers()->lockForUpdate()->get();
            if ($layers->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'This purchase predates stock price tracking. Use a stock adjustment to correct it.']);
            }
            foreach ($layers as $layer) {
                if ($layer->status !== 'ACTIVE' || Money::compare($layer->remaining_quantity, $layer->original_quantity) !== 0 || $layer->movements()->where('quantity', '<', 0)->exists()) {
                    throw ValidationException::withMessages(['items' => 'Some stock from this purchase has already been used. Use a stock adjustment instead.']);
                }
                $quantity = '-'.$layer->remaining_quantity;
                $reversedCost = '-'.$layer->stock_value;
                $layer->update(['remaining_quantity' => '0.000', 'remaining_cost_total' => $layer->remaining_cost_total !== null ? '0.00' : null, 'status' => 'VOID']);
                app(StockLayerService::class)->movement($p, [[$layer, $quantity, $reversedCost]], 'PURCHASE REVERSAL', $purchase->reference, $userId);
            }
            if (Money::compare($p->cost, $item->base_cost ?? $item->cost) === 0) {
                $prior = PurchaseItem::where('product_id', $p->id)->where('purchase_id', '!=', $purchase->id)->whereHas('purchase', fn ($q) => $q->where('status', 'ACTIVE'))->latest('id')->first();
                $restoredCost = $prior?->base_cost ?? $prior?->cost ?? $item->previous_cost;
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
            if ($p->returns()->exists() || PurchaseReturn::where('replacement_purchase_id', $p->id)->orWhere('purchase_id', $p->id)->exists()) {
                throw ValidationException::withMessages(['reason' => 'Cancel the linked return instead of voiding this purchase.']);
            }
            if ($p->status !== 'ACTIVE') {
                throw ValidationException::withMessages(['reason' => 'Purchase is already voided.']);
            }
            if (Money::compare($p->paid_amount, 0) > 0) {
                throw ValidationException::withMessages(['reason' => 'Record the supplier refund before voiding a paid purchase. Its payment history will be retained.']);
            }
            $this->reverseItems($p, $userId);
            app(PurchaseChargeService::class)->reverse($p);
            $p->update(['status' => 'VOIDED']);
            Audit::record('purchase.void', $p, [], ['reason' => $reason]);
        }, 3);
    }
}
