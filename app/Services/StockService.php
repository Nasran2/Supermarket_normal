<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockMovement;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockService
{
    public function validateQuantity(Product $product, string $quantity): void
    {
        if (Money::compare($quantity, 0) <= 0) {
            throw ValidationException::withMessages(['items' => 'Quantity must be greater than zero.']);
        }
        $precision = (int) app(SettingsService::class)->get('quantity_decimals', 3);
        if (Money::compare($quantity, Money::round($quantity, $precision)) !== 0) {
            throw ValidationException::withMessages(['items' => 'Quantities support up to '.$precision.' decimal places.']);
        }
        if (! $product->unit->allow_decimal && Money::compare($quantity, BigDecimal::of($quantity)->toScale(0, RoundingMode::DOWN)->__toString()) !== 0) {
            throw ValidationException::withMessages(['items' => $product->name.' uses '.$product->unit->short_name.' and requires whole quantities.']);
        }
    }

    public function move(Product $product, string $quantity, string $reason, string $reference, int $userId, bool $trackLayers = true): StockMovement
    {
        if ($trackLayers) {
            return DB::transaction(function () use ($product, $quantity, $reason, $reference, $userId) {
                $locked = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
                $product->setRawAttributes($locked->getAttributes(), true);
                app(StockLayerService::class)->adjust($product, $quantity, null, $product->cost, $product->price, $reference, $reason, $userId);

                return StockMovement::where('product_id', $product->id)->latest('id')->firstOrFail();
            }, 3);
        }
        $balance = Money::quantity($product->stock, $quantity);
        if (Money::compare($balance, '999999999999.999') > 0 || Money::compare($balance, '-999999999999.999') < 0) {
            throw ValidationException::withMessages(['quantity' => 'Stock balance exceeds the supported range.']);
        }
        $product->update(['stock' => $balance]);

        return StockMovement::create(['product_id' => $product->id, 'user_id' => $userId, 'quantity' => $quantity, 'balance' => $balance, 'reason' => $reason, 'reference' => $reference]);
    }
}
