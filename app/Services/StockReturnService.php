<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductStockLayer;
use App\Models\ReturnStockAllocation;
use App\Models\SaleItem;
use App\Models\SaleStockAllocation;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

class StockReturnService
{
    public function portion(string $total, string $part, string $whole, int $scale = 2): string
    {
        return (string) BigDecimal::of($total)->multipliedBy($part)->dividedBy($whole, $scale, RoundingMode::HALF_UP);
    }

    public function salePlan(SaleItem $item, string $quantity, bool $lock = false): array
    {
        $q = $item->allocations()->orderByDesc('id');
        $allocations = ($lock ? $q->lockForUpdate() : $q)->get();
        $left = $quantity;
        $rows = [];
        foreach ($allocations as $a) {
            $take = ReturnSettlementService::minimum($left, Money::quantity($a->quantity, '-'.$a->returned_quantity));
            if (Money::compare($take, 0) <= 0) {
                continue;
            }
            $layer = ProductStockLayer::with('purchaseItem.purchase')->findOrFail($a->stock_layer_id);
            $cost = Money::sub($this->portion($a->cost_total, Money::quantity($a->returned_quantity, $take), $a->quantity), $this->portion($a->cost_total, $a->returned_quantity, $a->quantity));
            $rows[] = ['allocation_id' => $a->id, 'stock_layer_id' => $a->stock_layer_id, 'quantity' => $take, 'cost_total' => $cost, 'supplier_id' => $layer->purchaseItem?->purchase?->supplier_id];
            $left = Money::quantity($left, '-'.$take);
        }
        if (Money::compare($left, 0) > 0) {
            if ($allocations->isEmpty() && ! $lock) {
                return [['allocation_id' => null, 'stock_layer_id' => null, 'quantity' => $quantity, 'cost_total' => Money::mul($quantity, $item->base_cost ?? $item->cost), 'supplier_id' => null]];
            }
            throw ValidationException::withMessages(['items' => 'The original stock allocation has already been returned.']);
        }

        return $rows;
    }

    public function applySale($returnItem, array $rows, string $action, string $reference, int $user): void
    {
        $product = Product::with('unit')->whereKey($returnItem->item->product_id)->lockForUpdate()->firstOrFail();
        $moves = [];
        foreach ($rows as $row) {
            $layer = ProductStockLayer::whereKey($row['stock_layer_id'])->lockForUpdate()->firstOrFail();
            $a = SaleStockAllocation::whereKey($row['allocation_id'])->lockForUpdate()->firstOrFail();
            $a->update(['returned_quantity' => Money::quantity($a->returned_quantity, $row['quantity'])]);
            ReturnStockAllocation::create(['sale_return_item_id' => $returnItem->id, 'sale_stock_allocation_id' => $a->id, 'stock_layer_id' => $layer->id, 'quantity' => $row['quantity'], 'cost_total' => $row['cost_total'], 'stock_action' => $action]);
            if ($action === 'RESTOCK') {
                $values = ['remaining_quantity' => Money::quantity($layer->remaining_quantity, $row['quantity']), 'status' => 'ACTIVE'];
                if ($layer->remaining_cost_total !== null) {
                    $values['remaining_cost_total'] = Money::add($layer->remaining_cost_total, $row['cost_total']);
                }
                $layer->update($values);
                $moves[] = [$layer, $row['quantity'], $row['cost_total']];
            }
        }
        if ($moves) {
            app(StockLayerService::class)->movement($product, $moves, 'SALES_RETURN_RESTOCK', $reference, $user);
        } else {
            app(StockService::class)->move($product, '0.000', 'SALES_RETURN_'.$action, $reference, $user, false);
        }
    }

    public function purchasePlan($item, string $quantity, bool $lock = false): array
    {
        $q = $item->stockLayers()->where('status', 'ACTIVE')->orderByDesc('id');
        $layers = ($lock ? $q->lockForUpdate() : $q)->get();
        $left = $quantity;
        $rows = [];
        foreach ($layers as $layer) {
            $take = ReturnSettlementService::minimum($left, $layer->remaining_quantity);
            if (Money::compare($take, 0) <= 0) {
                continue;
            }
            $rows[] = ['stock_layer_id' => $layer->id, 'quantity' => $take, 'cost_total' => $this->portion($layer->stock_value, $take, $layer->remaining_quantity)];
            $left = Money::quantity($left, '-'.$take);
        }
        if (Money::compare($left, 0) > 0) {
            throw ValidationException::withMessages(['items' => 'Not enough stock remains in the original purchase layer for '.$item->name.'.']);
        }

        return $rows;
    }

    public function applyPurchase($returnItem, array $rows, string $reference, int $user): void
    {
        $product = Product::with('unit')->whereKey($returnItem->item->product_id)->lockForUpdate()->firstOrFail();
        $moves = [];
        foreach ($rows as $row) {
            $layer = ProductStockLayer::whereKey($row['stock_layer_id'])->lockForUpdate()->firstOrFail();
            $values = ['remaining_quantity' => Money::quantity($layer->remaining_quantity, '-'.$row['quantity'])];
            if ($layer->remaining_cost_total !== null) {
                $values['remaining_cost_total'] = Money::sub($layer->remaining_cost_total, $row['cost_total']);
            }
            $layer->update($values);
            ReturnStockAllocation::create(['purchase_return_item_id' => $returnItem->id, 'stock_layer_id' => $layer->id, 'quantity' => $row['quantity'], 'cost_total' => $row['cost_total'], 'stock_action' => 'PURCHASE_RETURN']);
            $moves[] = [$layer, '-'.$row['quantity'], '-'.$row['cost_total']];
        }
        app(StockLayerService::class)->movement($product, $moves, 'PURCHASE_RETURN', $reference, $user);
    }

    public function reverse($document, bool $sale, int $user): void
    {
        foreach ($document->items as $item) {
            $product = Product::with('unit')->whereKey($item->item->product_id)->lockForUpdate()->firstOrFail();
            $moves = [];
            foreach ($item->allocations as $row) {
                $layer = ProductStockLayer::whereKey($row->stock_layer_id)->lockForUpdate()->firstOrFail();
                if ($sale) {
                    $a = SaleStockAllocation::whereKey($row->sale_stock_allocation_id)->lockForUpdate()->firstOrFail();
                    $a->update(['returned_quantity' => Money::quantity($a->returned_quantity, '-'.$row->quantity)]);
                }
                if (! $sale || $row->stock_action === 'RESTOCK') {
                    $qty = $sale ? '-'.$row->quantity : $row->quantity;
                    $cost = $sale ? '-'.$row->cost_total : $row->cost_total;
                    if (Money::compare(Money::quantity($layer->remaining_quantity, $qty), 0) < 0 || ($layer->remaining_cost_total !== null && Money::compare(Money::add($layer->remaining_cost_total, $cost), 0) < 0)) {
                        throw ValidationException::withMessages(['reason' => 'Restocked goods have already been used. This return cannot be cancelled.']);
                    }
                    $values = ['remaining_quantity' => Money::quantity($layer->remaining_quantity, $qty)];
                    if ($layer->remaining_cost_total !== null) {
                        $values['remaining_cost_total'] = Money::add($layer->remaining_cost_total, $cost);
                    }
                    $layer->update($values);
                    $moves[] = [$layer, $qty, $cost];
                }
            }
            if ($moves) {
                app(StockLayerService::class)->movement($product, $moves, 'REVERSE_RETURN', $document->reference, $user);
            }
        }
    }
}
