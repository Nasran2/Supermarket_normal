<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RepairModel extends Model
{
    public const INSPECTIONS = ['Standard', 'Face ID', 'Touch ID', 'Fingerprint', 'Foldable'];

    protected $fillable = ['name', 'name_key', 'inspection', 'active'];

    protected $casts = ['active' => 'boolean'];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(RepairBrand::class, 'brand_id');
    }
}
