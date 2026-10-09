<?php

namespace App\Models\Hr;

use App\Models\User;

class EmploymentEvent extends HrModel
{
    protected array $dateOnly = ['event_date'];

    protected $table = 'hr_employment_events';

    protected $guarded = ['id'];

    protected $casts = ['event_date' => 'date', 'snapshot' => 'array'];

    public function staff()
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
