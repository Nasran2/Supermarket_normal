<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = ['user_id', 'action', 'subject_type', 'subject_id', 'before', 'after'];

    protected $casts = ['before' => 'array', 'after' => 'array'];

    protected static function booted(): void
    {
        static::addGlobalScope('cheque-feature', function ($q) {
            if (! config('features.cheque')) {
                $q->where('action', 'not like', 'cheque.%');
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
