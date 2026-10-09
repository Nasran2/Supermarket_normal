<?php

namespace App\Models\Hr;

use App\Models\User;

class Staff extends HrModel
{
    protected array $dateOnly = ['joined_on', 'left_on'];

    protected $table = 'hr_staff';

    protected $guarded = ['id'];

    protected $casts = ['joined_on' => 'date', 'left_on' => 'date', 'salary_rate' => 'decimal:2', 'overtime_rate' => 'decimal:2'];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function department()
    {
        return $this->belongsTo(Option::class, 'department_id');
    }

    public function position()
    {
        return $this->belongsTo(Option::class, 'position_id');
    }

    public function shift()
    {
        return $this->belongsTo(Option::class, 'shift_id');
    }

    public function events()
    {
        return $this->hasMany(EmploymentEvent::class, 'staff_id');
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class, 'staff_id');
    }

    public function leaves()
    {
        return $this->hasMany(LeaveRequest::class, 'staff_id');
    }

    public function payrolls()
    {
        return $this->hasMany(Payroll::class, 'staff_id');
    }

    public function payments()
    {
        return $this->hasMany(StaffPayment::class, 'staff_id');
    }
}
