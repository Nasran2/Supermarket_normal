<?php

namespace App\Models;

use App\Support\Money;
use App\Support\Repairs;
use App\Support\SalesVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RepairJob extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['device_unlock', 'unlock_type'];

    protected $casts = [
        'device_unlock' => 'encrypted', 'checklist' => 'array', 'sms_opt_in' => 'boolean',
        'invoice_customer' => 'array', 'credited' => 'decimal:2', 'tax_credited' => 'decimal:2',
        'received_at' => 'datetime', 'due_at' => 'date', 'approved_at' => 'datetime',
        'warranty_until' => 'date', 'invoiced_at' => 'datetime', 'closed_at' => 'datetime',
        'estimate' => 'decimal:2', 'labor' => 'decimal:2', 'service_fee' => 'decimal:2', 'discount' => 'decimal:2',
        'tax' => 'decimal:2', 'net_revenue' => 'decimal:2', 'total' => 'decimal:2', 'paid' => 'decimal:2', 'part_cost' => 'decimal:2',
    ];

    public function scopeVisibleTo(Builder $query, ?User $user = null): Builder
    {
        $user ??= auth()->user();
        $query->whereHas('customer');
        if ($user instanceof User && $user->hasPermission('repairs.view') && SalesVisibility::mode($user) !== 'ALL') {
            return $query->where(fn ($q) => $q->where(fn ($owner) => SalesVisibility::apply($owner, 'repair_jobs.user_id', $user))
                ->orWhere('repair_jobs.technician_id', $user->id));
        }

        return SalesVisibility::apply($query, 'repair_jobs.user_id', $user);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function device()
    {
        return $this->belongsTo(RepairDevice::class);
    }

    public function technician()
    {
        return $this->belongsTo(User::class);
    }

    public function events()
    {
        return $this->hasMany(RepairEvent::class);
    }

    public function parts()
    {
        return $this->hasMany(RepairPart::class);
    }

    public function payments()
    {
        return $this->hasMany(RepairPayment::class);
    }

    public function cheques()
    {
        return $this->hasMany(Cheque::class);
    }

    public function chequeHold(?int $except = null): string
    {
        return Money::round((string) $this->cheques()->withoutGlobalScopes()->held()
            ->when($except, fn ($q) => $q->where('id', '!=', $except))->sum('amount'));
    }

    public function costs()
    {
        return $this->hasMany(RepairCost::class);
    }

    public function attachments()
    {
        return $this->hasMany(RepairAttachment::class);
    }

    public function editable(): bool
    {
        return ! in_array($this->status, Repairs::CLOSED, true);
    }

    public function invoiceNumber(): string
    {
        return 'REP-'.str_pad($this->id, 7, '0', STR_PAD_LEFT);
    }

    public function due(): string
    {
        return $this->invoiced_at ? Money::sub(Money::sub($this->total, $this->credited), $this->paid) : '0.00';
    }
}
