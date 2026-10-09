<?php

namespace App\Models\Hr;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

abstract class HrModel extends Model
{
    protected array $dateOnly = [];

    public function setAttribute($key, $value)
    {
        // DATE columns must remain dates on SQLite as well as MySQL.
        if (in_array($key, $this->dateOnly, true)) {
            $this->attributes[$key] = $value === null ? null : Carbon::parse($value)->toDateString();

            return $this;
        }

        return parent::setAttribute($key, $value);
    }
}
