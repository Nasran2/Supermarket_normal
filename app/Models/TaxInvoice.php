<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxInvoice extends Model
{
    protected $fillable = ['sale_id', 'snapshot'];

    protected $casts = ['snapshot' => 'array'];
}
