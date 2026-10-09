<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductStockLayer;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockLayerMovement;
use App\Support\Audit;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockLayerService
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['items' => $message]);
    }

    /** Compatibility import for integrations that created a balance before adopting layers. */
    public function ensureLegacy(Product $product): void
    {
        if (Money::compare($product->stock, 0) <= 0 || ($product->relationLoaded('stockLayers') && $product->stockLayers->isNotEmpty())) {
            return;
        }
        if (! $product->stockLayers()->exists() && Money::compare($product->stock, 0) > 0) {
            DB::transaction(function () use ($product) {
                $p = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
                if (! $p->stockLayers()->exists() && Money::compare($p->stock, 0) > 0) {
                    $this->create($p, $p->stock, $p->cost, $p->price, 'MIGRATED_STOCK', $p->sku, $p->created_by, null, $p->created_at);
                }
            });
            $product->unsetRelation('stockLayers');
        }
    }

    private function create(Product $p, string $quantity, string $cost, string $price, string $source, string $reference, ?int $user, ?int $sourceId = null, $received = null, ?int $purchaseItem = null): ProductStockLayer
    {
        if (Money::compare($cost, 0) < 0 || Money::compare($price, 0) < 0) {
            $this->fail('Cost and selling price must be zero or greater.');
        }
        $layer = $p->stockLayers()->create(['primary_unit_id' => $p->unit_id, 'cost_price' => Money::round($cost), 'selling_price' => Money::round($price), 'original_quantity' => $quantity, 'remaining_quantity' => $quantity, 'source_type' => $source, 'source_id' => $sourceId, 'source_reference' => $reference, 'purchase_item_id' => $purchaseItem, 'received_at' => $received ?? now(), 'created_by' => $user]);
        Audit::record('stock.layer.create', $layer, [], $layer->getAttributes());

        return $layer;
    }

    public function receive(Product $p, string $quantity, string $cost, string $price, string $source, string $reference, int $user, ?int $sourceId = null, $received = null, ?int $purchaseItem = null, ?string $inventoryCost = null): ProductStockLayer
    {
        return DB::transaction(function () use ($p, $quantity, $cost, $price, $source, $reference, $user, $sourceId, $received, $purchaseItem, $inventoryCost) {
            $locked = Product::whereKey($p->id)->lockForUpdate()->firstOrFail();
            $p->setRawAttributes($locked->getAttributes(), true);
            app(StockService::class)->validateQuantity($p, $quantity);
            $this->ensureLegacy($p);
            $layer = $this->create($p, $quantity, $cost, $price, $source, $reference, $user, $sourceId, $received, $purchaseItem);
            if ($inventoryCost !== null) {
                $layer->update(['inventory_cost_total' => $inventoryCost, 'remaining_cost_total' => $inventoryCost]);
            }
            $this->movement($p, [[$layer, $quantity, $inventoryCost]], str_replace('_', ' ', $source), $reference, $user);

            return $layer;
        }, 3);
    }

    public function groups(Product $p, ?Sale $editing = null): array
    {
        $this->ensureLegacy($p);
        $credits = $this->credits($editing, $p);
        $layers = ! $editing && $p->relationLoaded('stockLayers') ? $p->stockLayers : $p->stockLayers()->where('status', 'ACTIVE')->where(fn ($q) => $q->where('remaining_quantity', '>', 0)->when($credits, fn ($q) => $q->orWhereIn('id', array_keys($credits))))->orderBy('selling_price')->get();
        $groups = [];
        foreach ($layers as $layer) {
            $quantity = Money::quantity($layer->remaining_quantity, $credits[$layer->id] ?? '0');
            if (Money::compare($quantity, 0) <= 0) {
                continue;
            }
            $key = $layer->selling_price;
            $groups[$key] ??= ['stock_price' => $key, 'quantity' => '0.000', 'units' => array_map(fn ($option) => array_diff_key($option, ['cost' => true]), app(ProductUnitService::class)->options($p, $key))];
            $groups[$key]['quantity'] = Money::quantity($groups[$key]['quantity'], $quantity);
        }
        ksort($groups, SORT_NUMERIC);

        return array_values($groups);
    }

    private function credits(?Sale $editing, Product $p): array
    {
        $credits = [];
        if ($editing) {
            foreach ($editing->items->where('product_id', $p->id) as $item) {
                $this->legacyAllocation($item, $p);
                foreach ($item->allocations()->get() as $a) {
                    $credits[$a->stock_layer_id] = Money::quantity($credits[$a->stock_layer_id] ?? '0', Money::quantity($a->quantity, '-'.$a->returned_quantity));
                }
            }
        }

        return $credits;
    }

    /** Separate selectable stock rows, including rows with the same selling price. */
    public function choices(Product $p, ?Sale $editing = null): array
    {
        $this->ensureLegacy($p);
        $credits = $this->credits($editing, $p);
        $layers = ! $editing && $p->relationLoaded('stockLayers') ? $p->stockLayers : $p->stockLayers()->where('status', 'ACTIVE')->where(fn ($q) => $q->where('remaining_quantity', '>', 0)->when($credits, fn ($q) => $q->orWhereIn('id', array_keys($credits))))->get();

        return $layers->filter(fn ($layer) => $layer->status === 'ACTIVE' && Money::compare(Money::quantity($layer->remaining_quantity, $credits[$layer->id] ?? '0'), 0) > 0)
            ->sort(fn ($a, $b) => Money::compare($a->selling_price, $b->selling_price) ?: $a->received_at <=> $b->received_at ?: $a->id <=> $b->id)
            ->map(fn ($layer) => ['stock_layer_id' => $layer->id, 'stock_price' => $layer->selling_price, 'quantity' => Money::quantity($layer->remaining_quantity, $credits[$layer->id] ?? '0'), 'reference' => $layer->source_reference, 'received' => $layer->received_at?->format('d M Y'), 'units' => array_map(fn ($option) => array_diff_key($option, ['cost' => true]), app(ProductUnitService::class)->options($p, $layer->selling_price))])->values()->all();
    }

    public function plan(Product $p, string $quantity, ?string $price, array &$reserved, bool $lock = false, ?Sale $editing = null, ?int $layerId = null): array
    {
        $this->ensureLegacy($p);
        $credits = $this->credits($editing, $p);
        $query = $p->stockLayers()->where('status', 'ACTIVE')->where(fn ($q) => $q->where('remaining_quantity', '>', 0)->when($credits, fn ($q) => $q->orWhereIn('id', array_keys($credits))))->orderBy('received_at')->orderBy('id');
        if ($layerId !== null) {
            $query->whereKey($layerId);
        }
        if ($lock) {
            $query->lockForUpdate();
        }
        $layers = $query->get();
        $eligible = $layers->filter(fn ($l) => Money::compare(Money::quantity($l->remaining_quantity, $credits[$l->id] ?? '0'), 0) > 0);
        $prices = $eligible->pluck('selling_price')->unique();
        if ($price === null) {
            if ($prices->count() !== 1) {
                $this->fail($prices->isEmpty() ? $p->name.' is out of stock.' : 'Choose a selling price for '.$p->name.'.');
            }
            $price = $prices->first();
        }
        $price = Money::round($price);
        $available = '0.000';
        $allocations = [];
        $left = $quantity;
        $cost = BigDecimal::zero();
        $hasLandedCost = false;
        foreach ($layers as $layer) {
            if (Money::compare($layer->selling_price, $price) !== 0) {
                continue;
            }
            $qty = Money::quantity(Money::quantity($layer->remaining_quantity, $credits[$layer->id] ?? '0'), '-'.($reserved[$layer->id] ?? '0'));
            $available = Money::quantity($available, $qty);
            if (Money::compare($qty, 0) <= 0 || Money::compare($left, 0) <= 0) {
                continue;
            }
            $take = Money::compare($qty, $left) < 0 ? $qty : $left;
            $lineCost = BigDecimal::of($take)->multipliedBy($layer->cost_price);
            if ($layer->remaining_cost_total !== null) {
                $hasLandedCost = true;
                $creditCost = '0.00';
                if ($editing) {
                    foreach ($editing->items->where('product_id', $p->id) as $editItem) {
                        foreach ($editItem->allocations as $a) {
                            if ($a->stock_layer_id === $layer->id) {
                                $creditCost = Money::add($creditCost, Money::sub($a->cost_total, (string) BigDecimal::of($a->cost_total)->multipliedBy($a->returned_quantity)->dividedBy($a->quantity, 2, RoundingMode::HALF_UP)));
                            }
                        }
                    }
                }
                $pool = Money::sub(Money::add($layer->remaining_cost_total, $creditCost), $reserved['cost_'.$layer->id] ?? '0');
                $lineCost = BigDecimal::of($pool)->multipliedBy($take)->dividedBy($qty, 2, RoundingMode::HALF_UP);
                $reserved['cost_'.$layer->id] = Money::add($reserved['cost_'.$layer->id] ?? '0', (string) $lineCost);
            }
            $allocations[] = ['stock_layer_id' => $layer->id, 'quantity' => $take, 'cost_price' => $layer->cost_price, 'cost_total' => Money::round((string) $lineCost)];
            $cost = $cost->plus($lineCost);
            $reserved[$layer->id] = Money::quantity($reserved[$layer->id] ?? '0', $take);
            $left = Money::quantity($left, '-'.$take);
        }
        if (Money::compare($left, 0) > 0) {
            $this->fail('Only '.$available.' '.$p->unit->short_name.' of '.$p->name.' are available at '.Money::display($price).'. Choose another price for the remaining quantity.');
        }
        $total = $hasLandedCost ? Money::sum(array_column($allocations, 'cost_total')) : (string) $cost->toScale(2, RoundingMode::HALF_UP);
        $last = count($allocations) - 1;
        $allocations[$last]['cost_total'] = Money::add($allocations[$last]['cost_total'], Money::sub($total, Money::sum(array_column($allocations, 'cost_total'))));

        return ['stock_price' => $price, 'cogs_total' => $total, 'allocations' => $allocations];
    }

    public function consume(Product $p, SaleItem $item, array $allocations, string $reason, string $ref, int $user): void
    {
        $moves = [];
        foreach ($allocations as $row) {
            $layer = ProductStockLayer::whereKey($row['stock_layer_id'])->lockForUpdate()->firstOrFail();
            if ($layer->product_id !== $p->id || $layer->status !== 'ACTIVE' || Money::compare($layer->selling_price, $item->stock_price) !== 0 || Money::compare($layer->remaining_quantity, $row['quantity']) < 0) {
                $this->fail('Stock changed. Review this sale again.');
            }
            $values = ['remaining_quantity' => Money::quantity($layer->remaining_quantity, '-'.$row['quantity'])];
            if ($layer->remaining_cost_total !== null) {
                $values['remaining_cost_total'] = Money::sub($layer->remaining_cost_total, $row['cost_total']);
            }
            $layer->update($values);
            $item->allocations()->create($row);
            $moves[] = [$layer, '-'.$row['quantity'], '-'.$row['cost_total']];
        }
        $this->movement($p, $moves, $reason, $ref, $user);
        Audit::record('stock.sale.allocate', $item, [], ['allocations' => $allocations]);
    }

    /** Preserve legacy sale snapshots; this bridge is only used when an old sale is edited/returned. */
    private function legacyAllocation(SaleItem $item, Product $p): void
    {
        if ($item->allocations()->exists()) {
            return;
        }
        $qty = $item->base_quantity ?? $item->quantity;
        $price = $item->stock_price ?? app(ProductUnitService::class)->rate($item->catalog_price ?? $item->price, $item->quantity, $qty);
        $layer = $p->stockLayers()->where('source_type', 'RETURN')->where('source_id', $item->id)->first();
        $layer ??= $this->create($p, '0.000', $item->base_cost ?? $item->cost, $price, 'RETURN', $item->sale->invoice, $item->sale->user_id, $item->id, $item->sale->sold_at);
        $returned = $item->returns()->sum('base_quantity');
        $item->allocations()->create(['stock_layer_id' => $layer->id, 'quantity' => $qty, 'returned_quantity' => $returned, 'cost_price' => $item->base_cost ?? $item->cost, 'cost_total' => Money::mul($qty, $item->base_cost ?? $item->cost)]);
    }

    public function restore(Product $p, SaleItem $item, string $quantity, string $reason, string $reference, int $user): string
    {
        $this->legacyAllocation($item, $p);
        $left = $quantity;
        $moves = [];
        $cost = BigDecimal::zero();
        foreach ($item->allocations()->orderBy('id')->lockForUpdate()->get() as $allocation) {
            $remaining = Money::quantity($allocation->quantity, '-'.$allocation->returned_quantity);
            $take = Money::compare($remaining, $left) < 0 ? $remaining : $left;
            if (Money::compare($take, 0) <= 0) {
                continue;
            }
            $layer = ProductStockLayer::whereKey($allocation->stock_layer_id)->lockForUpdate()->firstOrFail();
            $returnedQuantity = Money::quantity($allocation->returned_quantity, $take);
            $returnedCost = Money::sub((string) BigDecimal::of($allocation->cost_total)->multipliedBy($returnedQuantity)->dividedBy($allocation->quantity, 2, RoundingMode::HALF_UP), (string) BigDecimal::of($allocation->cost_total)->multipliedBy($allocation->returned_quantity)->dividedBy($allocation->quantity, 2, RoundingMode::HALF_UP));
            $values = ['remaining_quantity' => Money::quantity($layer->remaining_quantity, $take), 'status' => 'ACTIVE'];
            if ($layer->remaining_cost_total !== null) {
                $values['remaining_cost_total'] = Money::add($layer->remaining_cost_total, $returnedCost);
            }
            $layer->update($values);
            $allocation->update(['returned_quantity' => $returnedQuantity]);
            $moves[] = [$layer, $take, $returnedCost];
            $cost = $cost->plus($returnedCost);
            $left = Money::quantity($left, '-'.$take);
        }
        if (Money::compare($left, 0) > 0) {
            $this->fail('This quantity has already been restored.');
        }
        $this->movement($p, $moves, $reason, $reference, $user);
        Audit::record('stock.sale.restore', $item, [], ['quantity' => $quantity, 'reference' => $reference]);

        return (string) $cost->toScale(2, RoundingMode::HALF_UP);
    }

    public function movement(Product $p, array $moves, string $reason, string $reference, int $user): void
    {
        $qty = '0.000';
        foreach ($moves as [$layer, $change]) {
            $qty = Money::quantity($qty, $change);
        }
        $movement = app(StockService::class)->move($p, $qty, $reason, $reference, $user, false);
        foreach ($moves as $entry) {
            [$layer,$change] = $entry;
            StockLayerMovement::create(['stock_movement_id' => $movement->id, 'stock_layer_id' => $layer->id, 'quantity' => $change, 'cost_total' => $entry[2] ?? Money::mul($change, $layer->cost_price), 'cost_price' => $layer->cost_price, 'selling_price' => $layer->selling_price]);
        }
    }

    public function adjust(Product $p, string $delta, ?int $layerId, string $cost, string $price, string $reference, string $reason, int $user): void
    {
        DB::transaction(function () use ($p, $delta, $layerId, $cost, $price, $reference, $reason, $user) {
            $locked = Product::whereKey($p->id)->lockForUpdate()->firstOrFail();
            $p->setRawAttributes($locked->getAttributes(), true);
            $this->ensureLegacy($p);
            if ($layerId) {
                $selected = $p->stockLayers()->whereKey($layerId)->where('status', 'ACTIVE')->lockForUpdate()->first();
                if (! $selected) {
                    $this->fail('Choose a stock row belonging to '.$p->name.'.');
                }
            }
            if (Money::compare($delta, 0) > 0) {
                app(StockService::class)->validateQuantity($p, $delta);
                if ($layerId) {
                    $cost = $selected->cost_price;
                    $price = $selected->selling_price;
                }
                $layer = $this->create($p, $delta, $cost, $price, 'STOCK_ADJUSTMENT', $reference, $user);
                $this->movement($p, [[$layer, $delta]], $reason, $reference, $user);

                return;
            }
            $layers = $p->stockLayers()->available()->orderBy('id')->lockForUpdate()->get();
            if (! $layerId && $layers->count() === 1) {
                $layerId = $layers->first()->id;
            }
            $layer = $layers->firstWhere('id', $layerId);
            $quantity = ltrim($delta, '-');
            app(StockService::class)->validateQuantity($p, $quantity);
            if (! $layer || Money::compare($layer->remaining_quantity, $quantity) < 0) {
                $this->fail('Select a stock price row with enough available quantity for '.$p->name.'.');
            }
            $removedCost = (string) BigDecimal::of($layer->stock_value)->multipliedBy($quantity)->dividedBy($layer->remaining_quantity, 2, RoundingMode::HALF_UP);
            $values = ['remaining_quantity' => Money::quantity($layer->remaining_quantity, $delta)];
            if ($layer->remaining_cost_total !== null) {
                $values['remaining_cost_total'] = Money::sub($layer->remaining_cost_total, $removedCost);
            }
            $layer->update($values);
            $this->movement($p, [[$layer, $delta, '-'.$removedCost]], $reason, $reference, $user);
        }, 3);
    }

    public function reverseSource(Product $p, string $reference, string $reason, int $user): void
    {
        $moves = StockLayerMovement::whereHas('layer', fn ($q) => $q->where('product_id', $p->id))->whereHas('movement', fn ($q) => $q->where('reference', $reference)->where('reason', 'not like', 'REVERSE%'))->orderBy('id')->get();
        $net = [];
        $netCost = [];
        foreach ($moves as $move) {
            $netCost[$move->stock_layer_id] = Money::add($netCost[$move->stock_layer_id] ?? '0', $move->cost_total ?? Money::mul($move->quantity, $move->cost_price));
            $net[$move->stock_layer_id] = Money::quantity($net[$move->stock_layer_id] ?? '0', $move->quantity);
        }
        $restorations = [];
        foreach ($net as $id => $qty) {
            $layer = ProductStockLayer::whereKey($id)->lockForUpdate()->firstOrFail();
            $delta = (string) BigDecimal::of($qty)->negated();
            if (Money::compare(Money::quantity($layer->remaining_quantity, $delta), 0) < 0) {
                $this->fail('Stock from this adjustment has been used. It cannot be reversed.');
            }
            $costDelta = (string) BigDecimal::of($netCost[$id])->negated();
            $values = ['remaining_quantity' => Money::quantity($layer->remaining_quantity, $delta)];
            if ($layer->remaining_cost_total !== null) {
                $values['remaining_cost_total'] = Money::add($layer->remaining_cost_total, $costDelta);
            }
            $layer->update($values);
            $restorations[] = [$layer, $delta, $costDelta];
        }
        $this->movement($p, $restorations, $reason, $reference, $user);
    }

    public function reprice(ProductStockLayer $layer, string $price): void
    {
        DB::transaction(function () use ($layer, $price) {
            Product::whereKey($layer->product_id)->lockForUpdate()->firstOrFail();
            $layer = ProductStockLayer::whereKey($layer->id)->lockForUpdate()->firstOrFail();
            if ($layer->status !== 'ACTIVE' || Money::compare($layer->remaining_quantity, 0) <= 0) {
                $this->fail('Only stock that is currently available can be repriced.');
            }
            $before = $layer->getAttributes();
            $layer->update(['selling_price' => Money::round($price)]);
            Audit::record('stock.layer.reprice', $layer, $before, $layer->getAttributes());
        }, 3);
    }
}
