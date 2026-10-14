<?php

namespace App\Services;

use App\Models\ProductStockLayer;
use App\Support\Expiry;
use App\Support\Money;

class ExpiryReportService
{
    public function query(array $filters = [])
    {
        $query = ProductStockLayer::with(['product.unit', 'product.categories', 'purchaseItem.purchase.supplier'])->where('status', 'ACTIVE')->whereHas('product', fn ($q) => $q->where('track_stock', true)->where('track_expiry', true));
        if (($filters['positive_only'] ?? '1') !== '0') {
            $query->where('remaining_quantity', '>', 0);
        }
        $today = Expiry::today()->toDateString();
        $limit = Expiry::today()->addDays((int) Expiry::setting('expiry_alert_days'))->toDateString();
        $urgent = Expiry::today()->addDays((int) Expiry::setting('expiry_urgent_days'))->toDateString();
        match ($filters['tab'] ?? 'soon') {
            'expired' => $query->where('expires_at', '<', $today),
            'all' => null,
            default => $query->whereBetween('expires_at', [$today, $limit]),
        };
        match ($filters['status'] ?? '') {
            'Expired' => $query->where('expires_at', '<', $today),
            'Urgent' => $query->whereBetween('expires_at', [$today, $urgent]),
            'Expiring soon' => $query->where('expires_at', '>', $urgent)->where('expires_at', '<=', $limit),
            'Safe' => $query->where('expires_at', '>', $limit),
            'Unknown expiry' => $query->whereNull('expires_at'),
            default => null,
        };
        foreach (['from' => '>=', 'to' => '<='] as $field => $operator) {
            if (! empty($filters[$field])) {
                $query->where('expires_at', $operator, $filters[$field]);
            }
        }
        if (! empty($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }
        if (! empty($filters['category_id'])) {
            $query->whereHas('product.categories', fn ($q) => $q->where('categories.id', $filters['category_id']));
        }
        if (! empty($filters['supplier_id'])) {
            $query->whereHas('purchaseItem.purchase', fn ($q) => $q->where('supplier_id', $filters['supplier_id']));
        }
        if (! empty($filters['q'])) {
            $query->whereHas('product', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$filters['q'].'%')->orWhere('sku', 'like', '%'.$filters['q'].'%')->when(config('features.barcode'), fn ($barcodeQuery) => $barcodeQuery->orWhere('barcode', $filters['q']))));
        }
        foreach (['batch' => 'batch_number', 'purchase' => 'source_reference'] as $field => $column) {
            if (! empty($filters[$field])) {
                $query->where($column, 'like', '%'.$filters[$field].'%');
            }
        }

        return $query->orderBy('expires_at')->orderBy('product_id')->orderBy('id');
    }

    public function exportRow(ProductStockLayer $layer, bool $cost): array
    {
        $days = Expiry::days($layer->expires_at);
        $row = [$layer->product->name, $layer->product->sku, $layer->purchaseItem?->purchase?->supplier?->name ?? '—', $layer->source_reference, $layer->expires_at?->format('d M Y') ?? 'Unknown', $days === null ? '—' : ($days < 0 ? abs($days).' days ago' : $days.' days'), $layer->remaining_quantity, $layer->product->unit->short_name];
        if (! \App\Support\Units::enabled()) {
            array_pop($row);
        }
        if (Expiry::setting('expiry_batch_enabled')) {
            array_splice($row, 2, 0, [$layer->batch_number ?? '—']);
        }
        if ($cost) {
            $row[] = Money::display($layer->stock_value);
        }
        $row[] = Money::display(Money::mul($layer->remaining_quantity, $layer->selling_price));
        $row[] = Expiry::status($layer->expires_at);

        return $row;
    }
}
