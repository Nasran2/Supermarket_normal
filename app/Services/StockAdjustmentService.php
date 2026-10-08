<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StockAdjustmentService
{
    public function __construct(private StockService $stock) {}

    public function save(array $data, User $user, ?int $id = null): StockAdjustment
    {
        return DB::transaction(function () use ($data, $user, $id) {
            $batch = $id ? StockAdjustment::with('items')->lockForUpdate()->findOrFail($id) : new StockAdjustment;
            if ($id && ($batch->status !== 'ACTIVE' || (int) ($data['revision'] ?? 0) !== $batch->revision)) {
                $this->fail('This adjustment changed or was reversed. Reload before editing.');
            }
            $before = $id ? $batch->toArray() : [];
            $oldItems = $id ? $batch->items->keyBy('product_id') : collect();
            $ids = array_column($data['items'], 'product_id');
            if ($oldItems->keys()->diff($ids)->isNotEmpty()) {
                $this->fail('Keep the original products when editing. Use zero change to leave a product unchanged, or reverse the whole batch.');
            }
            $products = Product::with(['unit', 'conversions.unit'])->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $rows = [];
            $changed = false;
            foreach ($data['items'] as $i => $input) {
                $p = $products->get($input['product_id']);
                if (! $p || ! $p->active || ! $p->unit->active) {
                    $this->fail('Choose active products with active primary units.');
                }
                if ((int) $input['unit_id'] !== $p->unit_id || Money::compare($input['expected_stock'], $p->stock) !== 0 || Money::compare($input['expected_price'], $p->price) !== 0 || Money::compare($input['expected_cost'], $p->cost) !== 0) {
                    $this->fail($p->name.' changed since it was selected. Reload the product before saving.');
                }
                $old = $oldItems->get($p->id);
                if ($old && $old->unit_id !== $p->unit_id) {
                    $this->fail('The primary unit changed. This adjustment cannot be edited.');
                }
                $qty = (string) $input['quantity'];
                if (Money::compare($qty, 0) > 0) {
                    $this->stock->validateQuantity($p, $qty);
                }
                $delta = match ($input['mode']) {
                    'SET' => (string) BigDecimal::of($qty)->minus($p->stock)->toScale(3), 'REMOVE' => Money::quantity('0', '-'.$qty), default => Money::quantity('0', $qty)
                };
                $net = Money::quantity($old?->quantity_change ?? '0', $delta);
                if (Money::compare($net, '999999999999.999') > 0 || Money::compare($net, '-999999999999.999') < 0) {
                    $this->fail('The net stock correction exceeds the supported range.');
                }
                $after = Money::quantity($p->stock, $delta);
                if (Money::compare($after, 0) < 0) {
                    $this->fail($p->name.' would have negative stock.');
                }
                $price = isset($input['price']) ? Money::round((string) $input['price']) : $p->price;
                $cost = isset($input['cost']) ? Money::round((string) $input['cost']) : $p->cost;
                $priceChanged = Money::compare($price, $p->price) !== 0;
                $costChanged = Money::compare($cost, $p->cost) !== 0;
                if ($old && (($priceChanged && $old->price_changed && Money::compare($p->price, $old->price_after) !== 0) || ($costChanged && $old->cost_changed && Money::compare($p->cost, $old->cost_after) !== 0))) {
                    $this->fail($p->name.' has a newer price or cost. Use a new adjustment for that change.');
                }
                if ($priceChanged || $costChanged) {
                    foreach ($p->conversions as $conversion) {
                        app(ProductUnitService::class)->rate($cost, $conversion->base_quantity, $conversion->converted_quantity);
                        if ($conversion->price === null) {
                            app(ProductUnitService::class)->rate($price, $conversion->base_quantity, $conversion->converted_quantity);
                        }
                    }
                }
                $changed = $changed || Money::compare($delta, 0) !== 0 || $priceChanged || $costChanged;
                $rows[] = compact('p', 'old', 'delta', 'after', 'price', 'cost', 'priceChanged', 'costChanged');
            }
            if (! $id && ! $changed) {
                $this->fail('Change at least one quantity, selling price or cost.');
            }
            $batch->fill(['reason' => $data['reason']]);
            if (! $id) {
                $batch->fill(['reference' => 'ADJ-'.now()->format('Ymd').'-'.strtoupper(Str::random(8)), 'user_id' => $user->id, 'status' => 'ACTIVE', 'revision' => 1]);
            } else {
                $batch->revision++;
            }
            $batch->save();
            foreach ($rows as ['p' => $p, 'old' => $old, 'delta' => $delta, 'after' => $after, 'price' => $price, 'cost' => $cost, 'priceChanged' => $priceChanged, 'costChanged' => $costChanged]) {
                $priceBefore = $old?->price_before ?? $p->price;
                $costBefore = $old?->cost_before ?? $p->cost;
                // If this batch first changes a price in a later revision, snapshot the current price.
                if ($priceChanged && ! $old?->price_changed) {
                    $priceBefore = $p->price;
                }
                if ($costChanged && ! $old?->cost_changed) {
                    $costBefore = $p->cost;
                }
                $snapshot = ['name' => $p->name, 'sku' => $p->sku, 'unit_id' => $p->unit_id, 'unit' => $p->unit->short_name, 'stock_before' => $old?->stock_before ?? $p->stock, 'stock_after' => $after, 'quantity_change' => Money::quantity($old?->quantity_change ?? '0', $delta), 'price_before' => $priceBefore, 'price_after' => $priceChanged ? $price : ($old?->price_after ?? $p->price), 'cost_before' => $costBefore, 'cost_after' => $costChanged ? $cost : ($old?->cost_after ?? $p->cost), 'price_changed' => $priceChanged || ($old?->price_changed ?? false), 'cost_changed' => $costChanged || ($old?->cost_changed ?? false)];
                if (Money::compare($delta, 0) !== 0) {
                    $this->stock->move($p, $delta, 'ADJUSTMENT: '.Str::limit($batch->reason, 240, ''), $batch->reference, $user->id);
                }
                if ($priceChanged || $costChanged) {
                    $p->update(['price' => $price, 'cost' => $cost]);
                }
                $batch->items()->updateOrCreate(['product_id' => $p->id], $snapshot);
            }
            $batch->unsetRelation('items')->load('items');
            Audit::record($id ? 'stock.batch.update' : 'stock.batch.create', $batch, $before, $batch->toArray());

            return $batch;
        });
    }

    public function reverse(int $id, User $user, int $revision, string $reason): StockAdjustment
    {
        return DB::transaction(function () use ($id, $user, $revision, $reason) {
            $batch = StockAdjustment::with('items')->lockForUpdate()->findOrFail($id);
            if ($batch->status !== 'ACTIVE' || $batch->revision !== $revision) {
                $this->fail('This adjustment changed or was already reversed. Reload before continuing.');
            }
            $before = $batch->toArray();
            $products = Product::with('conversions.unit')->whereIn('id', $batch->items->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($batch->items as $item) {
                $p = $products->get($item->product_id);
                if (! $p || $p->unit_id !== $item->unit_id) {
                    $this->fail('A product or primary unit changed; this batch cannot be reversed.');
                }
                $delta = (string) BigDecimal::of($item->quantity_change)->negated()->toScale(3);
                if (Money::compare(Money::quantity($p->stock, $delta), 0) < 0) {
                    $this->fail($p->name.' has insufficient stock to reverse this adjustment.');
                }
                if (($item->price_changed && Money::compare($p->price, $item->price_after) !== 0) || ($item->cost_changed && Money::compare($p->cost, $item->cost_after) !== 0)) {
                    $this->fail($p->name.' has a newer price or cost. Restore that value before reversing this batch.');
                }
                if (Money::compare($delta, 0) !== 0) {
                    $this->stock->move($p, $delta, 'REVERSE ADJUSTMENT: '.Str::limit($reason, 235, ''), $batch->reference, $user->id);
                }
                $changes = [];
                if ($item->price_changed) {
                    $changes['price'] = $item->price_before;
                }
                if ($item->cost_changed) {
                    $changes['cost'] = $item->cost_before;
                }
                if ($changes) {
                    foreach ($p->conversions as $conversion) {
                        app(ProductUnitService::class)->rate($changes['cost'] ?? $p->cost, $conversion->base_quantity, $conversion->converted_quantity);
                        if ($conversion->price === null) {
                            app(ProductUnitService::class)->rate($changes['price'] ?? $p->price, $conversion->base_quantity, $conversion->converted_quantity);
                        }
                    }
                    $p->update($changes);
                }
            }
            $batch->update(['status' => 'VOID', 'voided_at' => now(), 'voided_by' => $user->id, 'void_reason' => $reason, 'revision' => $batch->revision + 1]);
            Audit::record('stock.batch.reverse', $batch, $before, $batch->fresh()->toArray());

            return $batch;
        });
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['items' => $message]);
    }
}
