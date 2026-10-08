<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchasePayment extends Model
{
    protected $fillable = ['purchase_id', 'user_id', 'payment_method_id', 'register_id', 'register_movement_id', 'token', 'kind', 'method_name', 'method_type', 'amount_paid', 'change', 'amount', 'reference', 'notes', 'paid_at'];

    protected $casts = ['amount' => 'decimal:2', 'amount_paid' => 'decimal:2', 'change' => 'decimal:2', 'paid_at' => 'datetime'];

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function register()
    {
        return $this->belongsTo(Register::class);
    }

    public function movement()
    {
        return $this->belongsTo(RegisterMovement::class, 'register_movement_id');
    }
}
