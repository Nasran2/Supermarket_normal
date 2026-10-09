<?php

namespace App\Models\Hr;

use App\Models\User;

class LeaveRequest extends HrModel
{
    protected array $dateOnly = ['from', 'to'];

    protected $table = 'hr_leaves';

    protected $guarded = ['id'];

    protected $casts = ['from' => 'date', 'to' => 'date', 'paid' => 'boolean'];

    public function staff()
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    public function leaveType()
    {
        return $this->belongsTo(Option::class, 'leave_type_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
