<?php

namespace App\Models\Concerns;

use App\Models\Cheque;

trait ChequePayment
{
    public function cheque()
    {
        return $this->belongsTo(Cheque::class);
    }

    public function scopeEffective($query)
    {
        return $query->whereIn($query->getModel()->qualifyColumn('settlement_status'), ['PENDING', 'PASSED']);
    }

    public function scopeSettled($query)
    {
        return $query->where($query->getModel()->qualifyColumn('settlement_status'), 'PASSED');
    }

    public function scopeFeatureVisible($query)
    {
        return config('features.cheque') ? $query : $query->whereNull($query->getModel()->qualifyColumn('cheque_id'));
    }

    public function getIsEffectiveAttribute(): bool
    {
        return in_array($this->settlement_status ?? 'PASSED', ['PENDING', 'PASSED']);
    }
}
