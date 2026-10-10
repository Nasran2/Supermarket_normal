<?php

namespace App\Models;

use App\Support\SalesVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $fillable = ['product_id', 'user_id', 'quantity', 'balance', 'reason', 'reference'];

    protected $casts = ['quantity' => 'decimal:3', 'balance' => 'decimal:3'];

    public function scopeVisibleSales(Builder $query): Builder
    {
        if (SalesVisibility::mode() === 'ALL') {
            return $query;
        }

        return $query->where(fn ($q) => $q->whereDoesntHave('sale')->orWhereHas('sale', fn ($s) => $s->visibleTo()))
            ->where(fn ($q) => $q->whereDoesntHave('saleReturn')->orWhereHas('saleReturn.sale', fn ($s) => $s->visibleTo()));
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class, 'reference', 'invoice');
    }

    public function saleReturn()
    {
        return $this->belongsTo(SaleReturn::class, 'reference', 'reference');
    }

    public function purchaseReturn()
    {
        return $this->belongsTo(PurchaseReturn::class, 'reference', 'reference');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function layers()
    {
        return $this->hasMany(StockLayerMovement::class);
    }
}
