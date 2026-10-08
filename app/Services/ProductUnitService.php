<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Unit;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

class ProductUnitService
{
    public function rows(Unit $base, array $rows): array
    {
        if (! $base->active) {
            throw ValidationException::withMessages(['unit_id' => 'Choose an active primary unit.']);
        }
        $ids = array_column($rows, 'unit_id');
        if (in_array($base->id, $ids) || count(array_unique($ids)) !== count($ids)) {
            throw ValidationException::withMessages(['conversions' => 'Choose each additional unit once, different from the primary unit.']);
        }
        $units = Unit::whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
        foreach ($rows as $index => $row) {
            $unit = $units->get($row['unit_id']);
            if (! $unit?->active) {
                throw ValidationException::withMessages(["conversions.$index.unit_id" => 'Choose an active unit.']);
            }
            foreach ([[$base, $row['base_quantity']], [$unit, $row['converted_quantity']]] as [$measure, $quantity]) {
                if (! $measure->allow_decimal && Money::compare($quantity, Money::round($quantity, 0)) !== 0) {
                    throw ValidationException::withMessages(['conversions' => $measure->name.' requires a whole conversion quantity.']);
                }
            }
        }

        return $rows;
    }

    public function rate(string $amount, string $numerator, string $denominator): string
    {
        $value = (string) BigDecimal::of($amount)->multipliedBy($numerator)->dividedBy($denominator, 2, RoundingMode::HALF_UP);
        if (Money::compare($value, '9999999999999.99') > 0) {
            throw ValidationException::withMessages(['conversions' => 'Converted unit price or cost exceeds the supported range.']);
        }

        return $value;
    }

    public function options(Product $product): array
    {
        $product->loadMissing(['unit', 'conversions.unit']);
        $options = [['id' => $product->unit_id, 'name' => $product->unit->name, 'short_name' => $product->unit->short_name, 'decimal' => $product->unit->allow_decimal, 'price' => $product->price, 'cost' => $product->cost, 'base_quantity' => '1', 'converted_quantity' => '1']];
        foreach ($product->conversions as $row) {
            if (! $row->unit->active) {
                continue;
            }
            $options[] = ['id' => $row->unit_id, 'name' => $row->unit->name, 'short_name' => $row->unit->short_name, 'decimal' => $row->unit->allow_decimal, 'price' => $row->price ?? $this->rate($product->price, $row->base_quantity, $row->converted_quantity), 'cost' => $this->rate($product->cost, $row->base_quantity, $row->converted_quantity), 'base_quantity' => $row->base_quantity, 'converted_quantity' => $row->converted_quantity];
        }

        return $options;
    }

    public function resolve(Product $product, ?int $unitId, string $quantity): array
    {
        $unitId ??= $product->unit_id;
        $option = collect($this->options($product))->firstWhere('id', $unitId);
        if (! $option) {
            throw ValidationException::withMessages(['items' => 'The selected unit is unavailable for '.$product->name.'.']);
        }
        $selected = clone $product;
        $selected->setRelation('unit', Unit::findOrFail($unitId));
        app(StockService::class)->validateQuantity($selected, $quantity);
        $exact = BigDecimal::of($quantity)->multipliedBy($option['base_quantity']);
        $precision = (int) app(SettingsService::class)->get('quantity_decimals', 3);
        $baseQuantity = (string) $exact->dividedBy($option['converted_quantity'], $precision, RoundingMode::HALF_UP)->toScale(3);
        // Whole-piece stock must never silently round a fractional piece.
        if (! $product->unit->allow_decimal && $exact->compareTo(BigDecimal::of($baseQuantity)->multipliedBy($option['converted_quantity'])) !== 0) {
            throw ValidationException::withMessages(['items' => 'This conversion would use a fractional '.$product->unit->short_name.'. Enter a compatible quantity.']);
        }
        app(StockService::class)->validateQuantity($product, $baseQuantity);

        return $option + ['base_stock_quantity' => $baseQuantity];
    }
}
