<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RepairPayment extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['amount' => 'decimal:2'];

    public function job()
    {
        return $this->belongsTo(RepairJob::class, 'repair_job_id');
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function reversal()
    {
        return $this->hasOne(self::class, 'reverses_id');
    }
}
