<?php

namespace App\Services;

use App\Models\Product;
use App\Models\PurchaseItem;
use App\Models\Unit;
use App\Support\Units;

class UnitService
{
    public function isEnabled(): bool
    {
        return Units::enabled();
    }

    public function shouldDisplayUnits(): bool
    {
        return $this->isEnabled();
    }

    public function getDefaultUnit(): Unit
    {
        return app(DefaultUnitService::class)->resolve();
    }

    public function resolveProductUnit(Product $product): Unit
    {
        return $product->unit ?? $this->getDefaultUnit();
    }

    /** Populate edits in the primary basis without counting allocated charges twice. */
    public function purchaseInput(PurchaseItem $item): array
    {
        $fields = $item->only('product_id', 'unit_id', 'quantity', 'cost', 'selling_price', 'expires_at', 'batch_number');
        if ($this->isEnabled()) {
            return $fields;
        }
        unset($fields['unit_id']);
        $fields['quantity'] = $item->base_quantity ?? $item->quantity;
        $fields['cost'] = app(ProductUnitService::class)->rate($item->cost, $item->quantity, $fields['quantity']);
        $fields['selling_price'] = $item->base_selling_price ?? $item->selling_price;

        return $fields;
    }

    public function formatQuantity(Product $product, string|int|float $quantity): string
    {
        return Units::quantity($quantity, $this->resolveProductUnit($product));
    }
}
