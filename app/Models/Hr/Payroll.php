<?php

namespace App\Models\Hr;

use App\Support\Money;

class Payroll extends HrModel
{
    protected array $dateOnly = ['period'];

    protected $table = 'hr_payrolls';

    protected $guarded = ['id'];

    protected $casts = ['period' => 'date', 'basic' => 'decimal:2', 'earnings' => 'decimal:2', 'deductions' => 'decimal:2', 'advance_recovery' => 'decimal:2', 'net' => 'decimal:2', 'lines' => 'array', 'attendance_snapshot' => 'array', 'approved_at' => 'datetime'];

    public function staff()
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    public function payments()
    {
        return $this->hasMany(StaffPayment::class, 'payroll_id');
    }

    public function getPaidAttribute(): string
    {
        return Money::round((string) $this->payments()->where('status', 'ACTIVE')->sum('amount'));
    }

    public function getDueAttribute(): string
    {
        return Money::sub($this->net, $this->paid);
    }
}
