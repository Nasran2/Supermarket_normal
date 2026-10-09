<?php

namespace App\Models\Hr;

use App\Models\Register;
use App\Models\User;

class StaffPayment extends HrModel
{
    protected array $dateOnly = ['date'];

    protected $table = 'hr_payments';

    protected $guarded = ['id'];

    protected $casts = ['date' => 'date', 'amount' => 'decimal:2'];

    public function staff()
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    public function payroll()
    {
        return $this->belongsTo(Payroll::class, 'payroll_id');
    }

    public function register()
    {
        return $this->belongsTo(Register::class, 'register_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
