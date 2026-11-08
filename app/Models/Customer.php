<?php

namespace App\Models;

use App\Support\Money;
use App\Support\SalesVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Customer extends Model
{
    protected $fillable = ['tax_number', 'name', 'phone', 'email', 'address', 'active', 'opening_due', 'opening_due_paid'];

    protected $casts = ['active' => 'boolean', 'opening_due' => 'decimal:2', 'opening_due_paid' => 'decimal:2'];

    protected $attributes = ['opening_due' => '0.00', 'opening_due_paid' => '0.00'];

    protected static function booted()
    {
        static::addGlobalScope('secret_hidden_customers', function ($builder) {
            if (class_exists(\App\Support\SecretControl::class) && ! \App\Support\SecretControl::isSuperAdminArea() && \App\Support\SecretControl::isSuperAdminFeatureActive()) {
                if (\App\Support\SecretControl::isHidden('hide_all_customers')) {
                    $builder->whereRaw('0 = 1');
                    return;
                }
                $hiddenCustIds = \App\Support\SecretControl::hiddenCustomerIds();
                if (! empty($hiddenCustIds)) {
                    $builder->whereNotIn('customers.id', $hiddenCustIds);
                }
            }
        });
    }

    public function getNameAttribute($value)
    {
        if (class_exists(\App\Support\SecretControl::class) && ! \App\Support\SecretControl::isSuperAdminArea() && \App\Support\SecretControl::isSuperAdminFeatureActive() && \App\Support\SecretControl::isHidden('mask_supplier_customer_names')) {
            return 'Hidden';
        }

        return $value;
    }

    public function getPhoneAttribute($value)
    {
        if (class_exists(\App\Support\SecretControl::class) && ! \App\Support\SecretControl::isSuperAdminArea() && \App\Support\SecretControl::isSuperAdminFeatureActive() && \App\Support\SecretControl::isHidden('mask_supplier_customer_names')) {
            return '—';
        }

        return $value;
    }

    public function getEmailAttribute($value)
    {
        if (class_exists(\App\Support\SecretControl::class) && ! \App\Support\SecretControl::isSuperAdminArea() && \App\Support\SecretControl::isSuperAdminFeatureActive() && \App\Support\SecretControl::isHidden('mask_supplier_customer_names')) {
            return '—';
        }

        return $value;
    }

    public static function invoiceDueSql(): string
    {
        $owner = SalesVisibility::ownerSql('due_sales.user_id');
        $clamp = DB::getDriverName() === 'sqlite' ? 'MAX' : 'GREATEST';

        return "COALESCE((SELECT SUM($clamp(0, due_sales.customer_payable
            - COALESCE((SELECT SUM(due_reduction) FROM sale_returns WHERE sale_id=due_sales.id AND status='COMPLETED'), 0)
            - COALESCE((SELECT SUM(amount_paid - `change`) FROM sale_payments WHERE sale_id=due_sales.id AND settlement_status IN ('PENDING','PASSED')), 0)
            - COALESCE((SELECT SUM(amount) FROM sale_collections WHERE sale_id=due_sales.id AND settlement_status IN ('PENDING','PASSED')), 0)
            - COALESCE((SELECT SUM(amount) FROM return_account_allocations WHERE sale_id=due_sales.id AND status='ACTIVE'), 0)))
            FROM sales AS due_sales WHERE due_sales.customer_id=customers.id AND due_sales.status='ACTIVE' AND ($owner)), 0)";
    }

    public function scopeWithDueBalances(Builder $query): Builder
    {
        return $query->select('customers.*')->selectRaw(self::invoiceDueSql().' AS invoice_due')
            ->when(\App\Support\Repairs::enabled(), fn ($q) => $q->selectRaw(self::repairDueSql().' AS repair_due'));
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
        return Money::add($this->pos_due_balance, $this->repair_due);
    }

    public static function repairDueSql(): string
    {
        if (! \App\Support\Repairs::enabled()) {
            return '0';
        }
        $owner = SalesVisibility::ownerSql('repair_jobs.user_id');
        $user = auth()->user();
        if ($user instanceof User && $user->hasPermission('repairs.view') && SalesVisibility::mode($user) !== 'ALL') {
            $owner .= ' OR repair_jobs.technician_id = '.(int) $user->id;
        }

        return "COALESCE((SELECT SUM(total - credited - paid) FROM repair_jobs
            WHERE repair_jobs.customer_id = customers.id AND invoiced_at IS NOT NULL AND ($owner)), 0)";
    }

    public function getRepairDueAttribute(): string
    {
        if (! \App\Support\Repairs::enabled()) {
            return '0.00';
        }

        return Money::round((string) ($this->attributes['repair_due'] ?? self::withDueBalances()->whereKey($this->id)->value('repair_due') ?? '0'));
    }

    public function getPosDueBalanceAttribute(): string
    {
        $openingRemaining = Money::sub($this->opening_due ?? '0.00', $this->opening_due_paid ?? '0.00');

        $credits = (string) ReturnAccountAllocation::where('customer_id', $this->id)->whereNull('sale_id')->where('status', 'ACTIVE')->sum('amount');

        return Money::add(Money::sub($openingRemaining, $credits), $this->invoice_due);
    }

    public function cheques()
    {
        return $this->hasMany(Cheque::class, 'origin_customer_id');
    }

    public function getChequeHoldAttribute(): string
    {
        return Cheque::pendingFor('customer', $this->id);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function customerPayments()
    {
        return $this->hasMany(CustomerPayment::class);
    }
}
