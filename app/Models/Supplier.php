<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Supplier extends Model
{
    protected $fillable = ['name', 'phone', 'email', 'address', 'active'];

    protected $casts = ['active' => 'boolean'];

    public function purchases()
    {
        return $this->hasMany(Purchase::class);
    }

    public function scopeWithDueBalances($query)
    {
        $clamp = DB::getDriverName() === 'sqlite' ? 'MAX' : 'GREATEST';

        return $query->select('suppliers.*')->selectRaw("COALESCE((SELECT SUM($clamp(0, purchases.total - COALESCE((SELECT SUM(CASE WHEN kind = 'REFUND' THEN -amount ELSE amount END) FROM purchase_payments WHERE purchase_id = purchases.id), 0))) FROM purchases WHERE supplier_id = suppliers.id AND status = 'ACTIVE' AND payment_tracking = 1), 0) AS outstanding_due")
            ->withCount(['purchases as unrecorded_count' => fn ($q) => $q->where('status', 'ACTIVE')->where('payment_tracking', false)]);
    }

    public function getDueBalanceAttribute(): string
    {
        return Money::round((string) ($this->outstanding_due ?? self::withDueBalances()->whereKey($this->id)->value('outstanding_due') ?? 0));
    }
}
