<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChequeResolution extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['amount' => 'decimal:2', 'metadata' => 'array'];

    public function cheque()
    {
        return $this->belongsTo(Cheque::class);
    }

    public function replacements()
    {
        return $this->belongsToMany(Cheque::class, 'cheque_resolution_items', 'cheque_resolution_id', 'replacement_cheque_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
