<?php

namespace App\Models\Hr;

use App\Models\User;

class Attendance extends HrModel
{
    protected array $dateOnly = ['date'];

    protected $table = 'hr_attendances';

    protected $guarded = ['id'];

    protected $casts = ['date' => 'date', 'check_in' => 'datetime', 'check_out' => 'datetime', 'overtime_hours' => 'decimal:2'];

    public function staff()
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
