<?php

namespace App\Services;

use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\SupplierReturn;
use App\Support\SecretControl;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DocumentNumberService
{
    private function parts(string $type, $date): array
    {
        if ($type === 'SALES_RETURN') {
            return ['SR-', 6, SaleReturn::class, 'reference'];
        }
        if ($type === 'PURCHASE_RETURN') {
            return ['PR-', 6, PurchaseReturn::class, 'reference'];
        }
        if ($type === 'SUPPLIER_RETURN') {
            return ['SR-SUP-', 6, SupplierReturn::class, 'reference'];
        }
        $date = Carbon::parse($date);

        if ($type === 'SALE' && SecretControl::isSuperAdminFeatureActive()) {
            $code = config('features.scode')
                ? app(SecretCodeService::class)->encodeDigits($date->format('Ymd'))
                : strtoupper(Str::password(8, numbers: false, symbols: false));

            return ['INV-'.$code, 2, Sale::class, 'invoice', 'sale-coded-invoices'];
        }

        return $type === 'PURCHASE' ? ['pur-'.$date->format('Ym').'-', 4, Purchase::class, 'reference'] : ['INV-'.$date->format('Ymd').'-', 5, Sale::class, 'invoice'];
    }

    public function preview(string $type, $date): string
    {
        $parts = $this->parts($type, $date);
        [$prefix, $width, $model, $column] = $parts;
        $key = $parts[4] ?? $prefix;
        $number = (int) (DB::table('document_sequences')->where('key', $key)->value('next_number') ?? 1);
        while ($model::withoutGlobalScopes()->where($column, $prefix.str_pad((string) $number, $width, '0', STR_PAD_LEFT))->exists()) {
            $number++;
        }

        return $prefix.str_pad((string) $number, $width, '0', STR_PAD_LEFT);
    }

    public function next(string $type, $date): string
    {
        return DB::transaction(function () use ($type, $date) {
            $parts = $this->parts($type, $date);
            [$prefix, $width, $model, $column] = $parts;
            $key = $parts[4] ?? $prefix;
            DB::table('document_sequences')->insertOrIgnore(['key' => $key, 'next_number' => 1]);
            $number = (int) DB::table('document_sequences')->where('key', $key)->lockForUpdate()->value('next_number');
            do {
                $reference = $prefix.str_pad((string) $number++, $width, '0', STR_PAD_LEFT);
            } while ($model::withoutGlobalScopes()->where($column, $reference)->exists());
            DB::table('document_sequences')->where('key', $key)->update(['next_number' => $number]);

            return $reference;
        }, 3);
    }
}
