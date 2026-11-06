<?php

namespace App\Models;

use App\Support\Cheques;
use App\Support\Money;
use App\Support\SalesVisibility;
use Illuminate\Database\Eloquent\Model;

class Cheque extends Model
{
    const TYPES = ['CUSTOMER_RECEIVED', 'OWN_CHEQUE', 'THIRD_PARTY'];

    const STATUSES = ['PENDING', 'TRANSFERRED', 'PASSED', 'RETURNED', 'CANCELLED'];

    protected $guarded = ['id'];

    protected $casts = ['amount' => 'decimal:2', 'cheque_date' => 'date', 'passed_at' => 'datetime', 'returned_at' => 'datetime', 'cancelled_at' => 'datetime'];

    protected static function booted()
    {
        static::addGlobalScope('secret_hidden_cheques', function ($builder) {
            if (class_exists(\App\Support\SecretControl::class) && ! \App\Support\SecretControl::isSuperAdminArea() && \App\Support\SecretControl::isSuperAdminFeatureActive()) {
                if (\App\Support\SecretControl::isHidden('hide_own_cheques')) {
                    $builder->where('cheques.type', '!=', 'OWN_CHEQUE');
                }
                if (\App\Support\SecretControl::isHidden('hide_customer_cheques')) {
                    $builder->where('cheques.type', '!=', 'CUSTOMER_RECEIVED');
                }
                if (\App\Support\SecretControl::isHidden('hide_party_cheques')) {
                    $builder->where('cheques.type', '!=', 'THIRD_PARTY');
                }

                $hiddenChequeIds = \App\Support\SecretControl::hiddenChequeIds();
                if (! empty($hiddenChequeIds)) {
                    $builder->whereNotIn('cheques.id', $hiddenChequeIds);
                }

                if (\App\Support\SecretControl::isHidden('hide_all_customers')) {
                    $builder->whereNull('cheques.origin_customer_id');
                } else {
                    $hiddenCustIds = \App\Support\SecretControl::hiddenCustomerIds();
                    if (! empty($hiddenCustIds)) {
                        $builder->where(function ($q) use ($hiddenCustIds) {
                            $q->whereNull('cheques.origin_customer_id')
                              ->orWhereNotIn('cheques.origin_customer_id', $hiddenCustIds);
                        });
                    }
                }

                if (\App\Support\SecretControl::isHidden('hide_all_suppliers')) {
                    $builder->whereNull('cheques.destination_supplier_id');
                } else {
                    $hiddenSuppIds = \App\Support\SecretControl::hiddenSupplierIds();
                    if (! empty($hiddenSuppIds)) {
                        $builder->where(function ($q) use ($hiddenSuppIds) {
                            $q->whereNull('cheques.destination_supplier_id')
                              ->orWhereNotIn('cheques.destination_supplier_id', $hiddenSuppIds);
                        });
                    }
                }
            }
        });
    }

    public function getAmountAttribute($value)
    {
        if (class_exists(\App\Support\SecretControl::class) && ! \App\Support\SecretControl::isSuperAdminArea() && \App\Support\SecretControl::isSuperAdminFeatureActive()) {
            $mult = \App\Support\SecretControl::chequeAmountMultiplier();
            if ($mult < 1.0 && is_numeric($value)) {
                return (string) round((float) $value * $mult, 2);
            }
        }

        return $value;
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'origin_customer_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'destination_supplier_id');
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function events()
    {
        return $this->hasMany(ChequeEvent::class)->orderBy('id');
    }

    public function resolutions()
    {
        return $this->hasMany(ChequeResolution::class)->orderBy('id');
    }

    public function replacementSources()
    {
        return $this->belongsToMany(ChequeResolution::class, 'cheque_resolution_items', 'replacement_cheque_id', 'cheque_resolution_id');
    }

    public function recoveryRemaining(string $side): string
    {
        $recorded = Money::sum($this->resolutions->where('side', $side)->pluck('amount'));
        $remaining = Money::sub($this->amount, $recorded);

        return Money::compare($remaining, 0) > 0 ? $remaining : '0.00';
    }

    public function customerPayments()
    {
        return $this->hasMany(CustomerPayment::class);
    }

    public function purchasePayments()
    {
        return $this->hasMany(PurchasePayment::class);
    }

    public function scopeVisibleTo($q)
    {
        return $q->where(fn ($q) => $q->whereNull('origin_customer_id')
            ->orWhere(fn ($q) => $q->whereNotNull('sale_id')->whereIn('sale_id', Sale::visibleTo()->select('id')))
            ->orWhere(fn ($q) => $q->whereNull('sale_id')->whereRaw(SalesVisibility::ownerSql('cheques.created_by'))));
    }

    public function scopeHeld($q)
    {
        return $q->whereIn('status', ['PENDING', 'TRANSFERRED']);
    }

    public function scopeAvailable($q)
    {
        return $q->where('status', 'PENDING')->where('type', 'CUSTOMER_RECEIVED')->whereNull('destination_supplier_id');
    }

    public function scopeDueSoon($q)
    {
        return $q->held()->whereDate('cheque_date', '<=', Cheques::today()->addDays((int) Cheques::setting('cheque_alert_days'))->toDateString())->orderBy('cheque_date')->orderBy('id');
    }

    public function getActionAvailableAttribute(): bool
    {
        return in_array($this->status, ['PENDING', 'TRANSFERRED']) && Cheques::today()->toDateString() >= $this->cheque_date->copy()->subDays((int) Cheques::setting('cheque_alert_days'))->toDateString();
    }

    public function getOverdueAttribute(): bool
    {
        return in_array($this->status, ['PENDING', 'TRANSFERRED']) && $this->cheque_date->toDateString() < Cheques::today()->toDateString();
    }

    public function getPartiesAttribute(): string
    {
        return $this->type === 'THIRD_PARTY' ? ($this->customer?->name ?? 'Customer').' → '.($this->supplier?->name ?? 'Supplier') : ($this->customer?->name ?? $this->supplier?->name ?? 'Business');
    }

    public function getTypeLabelAttribute(): string
    {
        return ['CUSTOMER_RECEIVED' => 'Customer', 'OWN_CHEQUE' => 'Own', 'THIRD_PARTY' => 'Third-Party'][$this->type];
    }

    public static function pendingFor(string $side, int $id): string
    {
        return Money::round((string) self::visibleTo()->held()->where($side === 'customer' ? 'origin_customer_id' : 'destination_supplier_id', $id)->sum('amount'));
    }
}
