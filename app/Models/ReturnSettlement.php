<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReturnSettlement extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['returned_at' => 'datetime', 'cancelled_at' => 'datetime', 'sent_at' => 'datetime', 'settled_at' => 'datetime', 'reversed_at' => 'datetime', 'amount' => 'decimal:2', 'cost_total' => 'decimal:2', 'quantity' => 'decimal:3', 'base_quantity' => 'decimal:3'];

    public function saleReturn()
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function purchaseReturn()
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function supplierReturn()
    {
        return $this->belongsTo(SupplierReturn::class);
    }
}
