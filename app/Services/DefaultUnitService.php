<?php

namespace App\Services;

use App\Models\Unit;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DefaultUnitService
{
    public function current(): ?Unit
    {
        return Unit::where('default_slot', 1)->where('active', true)->first();
    }

    public function set(int $id): void
    {
        DB::transaction(function () use ($id) {
            $units = Unit::orderBy('id')->lockForUpdate()->get();
            $unit = $units->firstWhere('id', $id);
            if (! $unit?->active) {
                throw ValidationException::withMessages(['unit' => 'Choose an active default unit.']);
            }
            $before = $units->firstWhere('default_slot', 1)?->id;
            Unit::where('default_slot', 1)->update(['default_slot' => null]);
            $unit->update(['default_slot' => 1]);
            Audit::record('unit.default', $unit, ['unit_id' => $before], ['unit_id' => $id]);
        }, 3);
    }
}
