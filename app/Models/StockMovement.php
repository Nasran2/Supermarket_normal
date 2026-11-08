<?php

namespace App\Models;

use App\Support\SalesVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $fillable = ['product_id', 'user_id', 'quantity', 'balance', 'reason', 'reference', 'repair_job_id'];

    protected $casts = ['quantity' => 'decimal:3', 'balance' => 'decimal:3'];

    protected static function booted()
    {
        static::addGlobalScope('secret_hidden_stock_movements', function ($builder) {
            if (class_exists(\App\Support\SecretControl::class) && ! \App\Support\SecretControl::isSuperAdminArea()) {
                $hiddenIds = \App\Support\SecretControl::hiddenProductIds();
                if (! empty($hiddenIds)) {
                    $builder->whereNotIn('stock_movements.product_id', $hiddenIds);
                }
            }
        });
    }

    public function scopeVisibleSales(Builder $query): Builder
    {
        if (! \App\Support\Repairs::enabled() || ! auth()->user()?->hasPermission('repairs.view')) {
            $query->whereNull('stock_movements.repair_job_id');
        } else {
            $query->where(fn ($q) => $q->whereNull('stock_movements.repair_job_id')
                ->orWhereIn('stock_movements.repair_job_id', RepairJob::visibleTo()->select('id')));
        }
        if (SalesVisibility::mode() === 'ALL') {
            return $query;
        }

        return $query->where(fn ($q) => $q->whereDoesntHave('sale')->orWhereHas('sale', fn ($s) => $s->visibleTo()))
            ->where(fn ($q) => $q->whereDoesntHave('saleReturn')->orWhereHas('saleReturn', fn ($s) => $s->visibleTo()));
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
