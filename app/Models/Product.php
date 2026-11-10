<?php

namespace App\Models;

use App\Support\Expiry;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = ['available_for_pos', 'available_for_repairs', 'track_expiry', 'track_stock', 'default_discount_type', 'default_discount_value', 'name', 'sku', 'barcode', 'barcode_type', 'unit_id', 'supplier_id', 'cost', 'price', 'stock', 'low_stock', 'active', 'image', 'created_by', 'updated_by'];

    protected $attributes = ['available_for_pos' => true, 'available_for_repairs' => true, 'track_stock' => true, 'default_discount_type' => 'NONE', 'default_discount_value' => '0.00'];

    protected $casts = ['available_for_pos' => 'boolean', 'available_for_repairs' => 'boolean', 'track_expiry' => 'boolean', 'track_stock' => 'boolean', 'default_discount_value' => 'decimal:2', 'active' => 'boolean', 'cost' => 'decimal:2', 'price' => 'decimal:2', 'stock' => 'decimal:3', 'low_stock' => 'decimal:3'];

    protected static function booted()
    {
        static::addGlobalScope('secret_hidden_products', function ($builder) {
            if (class_exists(\App\Support\SecretControl::class) && ! \App\Support\SecretControl::isSuperAdminArea()) {
                $hiddenIds = \App\Support\SecretControl::hiddenProductIds();
                if (! empty($hiddenIds)) {
                    $builder->whereNotIn('products.id', $hiddenIds);
                }
            }
        });
    }

    protected function getArrayableAttributes()
    {
        $attributes = parent::getArrayableAttributes();
        if (! config('features.barcode')) {
            unset($attributes['barcode'], $attributes['barcode_type']);
        }

        if (! \App\Support\Repairs::enabled()) {
            unset($attributes['available_for_pos'], $attributes['available_for_repairs']);
        }

        return $attributes;
    }

    protected function getArrayableRelations()
    {
        $relations = parent::getArrayableRelations();
        if (! \App\Support\Units::enabled()) {
            unset($relations['unit'], $relations['conversions']);
        }

        return $relations;
    }

    public function getNameAttribute($value)
    {
        if (class_exists(\App\Support\SecretControl::class) && ! \App\Support\SecretControl::isSuperAdminArea() && \App\Support\SecretControl::isHidden('hide_product_level_data')) {
            return 'Item #' . ($this->attributes['id'] ?? 'item');
        }
        return $value;
    }

    public function getSkuAttribute($value)
    {
        if (class_exists(\App\Support\SecretControl::class) && ! \App\Support\SecretControl::isSuperAdminArea() && \App\Support\SecretControl::isHidden('hide_product_level_data')) {
            return 'SKU-' . ($this->attributes['id'] ?? 'item');
        }
        return $value;
    }

    public function scopeForPos($query)
    {
        return $query->where('available_for_pos', true);
    }

    public static function sellableStockSql(): string
    {
        if (! Expiry::enabled()) {
            return 'products.stock';
        }
        $today = Expiry::today()->toDateString();

        return "CASE WHEN products.track_expiry = 1 THEN COALESCE((SELECT SUM(remaining_quantity) FROM product_stock_layers WHERE product_id = products.id AND status = 'ACTIVE' AND remaining_quantity > 0 AND expires_at >= '$today'), 0) ELSE products.stock END";
    }

    public function scopeLowStock($query)
    {
        return $query->whereRaw('('.self::sellableStockSql().') <= products.low_stock');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function conversions()
    {
        return $this->hasMany(ProductUnit::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class);
    }

    public function saleItems()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function purchaseItems()
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function stockLayers()
    {
        return $this->hasMany(ProductStockLayer::class);
    }
}
