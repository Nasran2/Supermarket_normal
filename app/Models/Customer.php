<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Customer extends Model
{
    protected $fillable = ['name', 'phone', 'email', 'address', 'active', 'opening_due'];

    protected $casts = ['active' => 'boolean', 'opening_due' => 'decimal:2'];

    protected $attributes = ['opening_due' => '0.00'];

    public static function invoiceDueSql(): string
    {
        $clamp = DB::getDriverName() === 'sqlite' ? 'MAX' : 'GREATEST';

        return "COALESCE((SELECT SUM($clamp(0, due_sales.customer_payable
            - COALESCE((SELECT SUM(amount) FROM sale_returns WHERE sale_id=due_sales.id), 0)
            - COALESCE((SELECT SUM(amount_paid - `change`) FROM sale_payments WHERE sale_id=due_sales.id), 0)
            - COALESCE((SELECT SUM(amount) FROM sale_collections WHERE sale_id=due_sales.id), 0)
            + COALESCE((SELECT SUM(refund_amount) FROM sale_returns WHERE sale_id=due_sales.id), 0)))
            FROM sales AS due_sales WHERE due_sales.customer_id=customers.id AND due_sales.status='ACTIVE'), 0)";
    }

    public function scopeWithDueBalances(Builder $query): Builder
    {
        return $query->select('customers.*')->selectRaw(self::invoiceDueSql().' AS invoice_due');
    }

    public function getInvoiceDueAttribute(): string
    {
        if (array_key_exists('invoice_due', $this->attributes)) {
            return Money::round((string) $this->attributes['invoice_due']);
        }

        return Money::round((string) (self::withDueBalances()->whereKey($this->id)->value('invoice_due') ?? '0'));
    }

    public function getDueBalanceAttribute(): string
    {
        return Money::add($this->opening_due ?? '0', $this->invoice_due);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }
}
