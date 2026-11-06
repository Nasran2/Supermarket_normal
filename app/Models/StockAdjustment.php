<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockAdjustment extends Model
{
    protected $fillable = ['reference', 'reason', 'user_id', 'status', 'revision', 'voided_at', 'voided_by', 'void_reason'];

    protected $casts = ['voided_at' => 'datetime', 'revision' => 'integer'];

    protected static function booted()
    {
        static::addGlobalScope('secret_hidden_adjustments', function ($builder) {
            if (class_exists(\App\Support\SecretControl::class) && ! \App\Support\SecretControl::isSuperAdminArea()) {
                $hiddenIds = \App\Support\SecretControl::hiddenProductIds();
                if (! empty($hiddenIds)) {
                    $builder->whereDoesntHave('items', function ($q) use ($hiddenIds) {
                        $q->withoutGlobalScopes()->whereIn('stock_adjustment_items.product_id', $hiddenIds);
                    });
                }
            }
        });
    }

    public function items()
    {
        return $this->hasMany(StockAdjustmentItem::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function voider()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
