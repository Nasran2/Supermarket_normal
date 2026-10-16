<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Purchase;
use App\Support\Expiry;
use App\Support\Money;
use App\Support\Units;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

class BarcodePrintService
{
    public function options(Product $p, bool $internal = false): array
    {
        $p->loadMissing('unit', 'conversions.unit', 'stockLayers');
        $layers = $p->stockLayers->filter(fn ($l) => $l->status === 'ACTIVE' && Money::compare($l->remaining_quantity, 0) > 0);
        $result = [];
        foreach ($layers as $layer) {
            $lot = Expiry::tracks($p) ? $layer->id : null;
            foreach (app(ProductUnitService::class)->options($p, $layer->selling_price) as $unit) {
                $price = $unit['price'];
                $cost = SecretCodeService::enabled() ? app(ProductUnitService::class)->rate($layer->cost_price, $unit['base_quantity'], $unit['converted_quantity']) : '0.00';
                $key = $unit['id'].'|'.$price.'|'.$lot.$this->costKey($cost);
                $qty = (string) BigDecimal::of($layer->remaining_quantity)->multipliedBy($unit['converted_quantity'])->dividedBy($unit['base_quantity'], 3, RoundingMode::DOWN);
                if (isset($result[$key])) {
                    $result[$key]['stock'] = Money::quantity($result[$key]['stock'], $qty);

                    continue;
                }
                $result[$key] = ['key' => $key, 'price' => $price, 'unit_id' => $unit['id'], 'unit' => Units::label($unit['short_name']), 'stock' => $qty, 'layer_id' => $lot, 'expires_at' => Expiry::tracks($p) ? $layer->expires_at?->format('Y-m-d') : null, 'batch_number' => Expiry::tracks($p) ? $layer->batch_number : null];
                if ($internal) {
                    $result[$key]['_cost'] = $cost;
                }
            }
        }
        if (! $result) {
            foreach (app(ProductUnitService::class)->options($p) as $u) {
                $key = $u['id'].'|'.$u['price'].'|'.$this->costKey($u['cost']);
                $result[$key] = ['key' => $key, 'price' => $u['price'], 'unit_id' => $u['id'], 'unit' => Units::label($u['short_name']), 'stock' => $p->track_stock ? $p->stock : '0.000', 'layer_id' => null, 'expires_at' => null, 'batch_number' => null];
                if ($internal) {
                    $result[$key]['_cost'] = $u['cost'];
                }
            }
        }

        $options = array_values($result);
        if (SecretCodeService::enabled()) {
            foreach ($options as $index => &$option) {
                $option['stock_group'] = $index + 1;
            }
        }

        return $options;
    }

    private function costKey(string $cost): string
    {
        return SecretCodeService::enabled() ? '|'.substr(hash_hmac('sha256', Money::round($cost), (string) config('app.key')), 0, 16) : '';
    }

    public function product(Product $p): array
    {
        return ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'barcode' => $p->barcode, 'barcode_type' => $p->barcode_type, 'active' => $p->active, 'options' => $this->options($p)];
    }

    public function purchase(Purchase $purchase): array
    {
        $purchase->load('items.product.unit', 'items.product.conversions.unit', 'items.product.stockLayers', 'items.stockLayers');
        $rows = [];
        foreach ($purchase->items as $item) {
            if (! $item->product) {
                continue;
            }
            $p = $item->product;
            $options = $this->options($p);
            $price = Money::round((string) ($item->base_selling_price ?? $item->selling_price ?? $p->price));
            $layer = $item->stockLayers->first();
            $key = $p->unit_id.'|'.$price.'|'.(Expiry::tracks($p) ? $layer?->id : '').$this->costKey((string) ($layer?->cost_price ?? $item->base_cost ?? $item->cost));
            $rows[] = ['product' => $this->product($p), 'option' => collect($options)->contains('key', $key) ? $key : '', 'quantity' => max(1, (int) ceil((float) ($item->base_quantity ?? $item->quantity)))];
        }

        return $rows;
    }

    public function resolve(array $data): array
    {
        $c = app(BarcodePresetService::class)->normalize($data['configuration']);
        $products = Product::with('unit', 'conversions.unit', 'stockLayers')->whereIn('id', array_column($data['items'], 'product_id'))->get()->keyBy('id');
        abort_if($products->count() > 1 && ! auth()->user()->hasPermission('barcode.print_bulk'), 403);
        $labels = [];
        $total = 0;
        $warnings = [];
        $quantities = [];
        $barcodes = app(BarcodeService::class);
        foreach ($data['items'] as $i => $row) {
            $p = $products->get($row['product_id']);
            if (! $p || ! $p->barcode) {
                throw ValidationException::withMessages(['items.'.$i => 'No barcode assigned. Generate or enter a barcode before printing.']);
            }
            $option = collect($this->options($p, true))->firstWhere('key', $row['option']);
            if (! $option) {
                throw ValidationException::withMessages(['items.'.$i.'.option' => Units::enabled() ? 'Select an available selling price, unit and stock lot.' : 'Select an available selling price and stock lot.']);
            }
            $quantity = (int) $row['quantity'];
            $total += $quantity;
            if ($total > 10000) {
                throw ValidationException::withMessages(['items' => 'Print at most 10,000 labels per job. Split larger jobs.']);
            }
            $quantityKey = $p->id.'|'.$option['key'];
            $quantities[$quantityKey] = ($quantities[$quantityKey] ?? 0) + $quantity;
            if ($p->track_stock && $quantities[$quantityKey] > (float) $option['stock']) {
                if (! $barcodes->setting('barcode_allow_above_stock', true)) {
                    throw ValidationException::withMessages(['items.'.$i.'.quantity' => 'Print quantity is greater than current stock.']);
                }
                $warnings[] = $p->name.': print quantity is greater than current stock.';
            }
            $type = $c['barcode_type'] === 'PRODUCT' ? $p->barcode_type : $c['barcode_type'];
            $width = BigDecimal::of($c['sticker_width'])->minus($c['padding_left'])->minus($c['padding_right']);
            // Validate encoding and safe module width even when the graphic is hidden.
            $barcodes->svg($p->barcode, $type, (string) $width, $c['barcode_height']);
            $labels[] = ['product_id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'barcode' => $p->barcode, 'barcode_type' => $type, 'price' => $option['price'], 'unit' => $option['unit'], 'expiry' => $option['expires_at'], 'batch' => $option['batch_number'], 'quantity' => $quantity, 'shop_name' => app(SettingsService::class)->get('business_name', ''), 'currency' => app(SettingsService::class)->get('currency_symbol', 'Rs.')];
            if (SecretCodeService::enabled() && $c['show_secret_code']) {
                $labels[array_key_last($labels)]['secret_code'] = app(SecretCodeService::class)->encode($option['_cost'], $option['price']);
            }
        }
        if ($total > (int) $barcodes->setting('barcode_large_job', 500) && empty($data['confirmed']) && request()->routeIs('barcode.jobs.store')) {
            throw ValidationException::withMessages(['confirmed' => 'Confirm the large print job before continuing.']);
        }

        return ['configuration' => $c, 'labels' => $labels, 'total' => $total, 'rows' => (int) ceil(($total + $c['skip_labels']) / $c['labels_per_row']), 'warnings' => array_unique($warnings)];
    }

    public function sheets(array $labels, array $c, bool $preview = false): array
    {
        $slots = array_fill(0, $preview ? min($c['skip_labels'], 7) : (int) $c['skip_labels'], null);
        foreach ($labels as $label) {
            for ($i = 0; $i < $label['quantity']; $i++) {
                $slots[] = $label;
                if ($preview && count($slots) >= 8) {
                    break 2;
                }
            }
        }
        $perPage = app(BarcodePresetService::class)->rowsPerPage($c);
        $chunks = $perPage ? array_chunk($slots, $perPage * $c['labels_per_row']) : [$slots];

        return array_map(fn ($page) => array_chunk($page, $c['labels_per_row']), $chunks);
    }
}
