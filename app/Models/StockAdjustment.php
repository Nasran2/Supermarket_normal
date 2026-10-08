<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockAdjustment extends Model
{
    protected $fillable = ['reference', 'reason', 'user_id', 'status', 'revision', 'voided_at', 'voided_by', 'void_reason'];

    protected $casts = ['voided_at' => 'datetime', 'revision' => 'integer'];

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
