<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RegisterMovement extends Model
{
    protected $fillable = ['register_id', 'user_id', 'type', 'amount', 'description'];

    protected $casts = ['amount' => 'decimal:2'];

    public function scopeVisibleDetails(Builder $query): Builder
    {
        return $query->when(! auth()->user()?->hasPermission('hr.payments.view'), fn ($q) => $q
            ->where(fn ($q) => $q->whereNull('description')->orWhere(fn ($q) => $q
                ->where('description', 'not like', 'HR payment #%')
                ->where('description', 'not like', 'Reversal of HR payment #%'))));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
