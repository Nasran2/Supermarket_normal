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

    /** Resolve by the unique default slot; repair legacy installations without changing products. */
    public function resolve(): Unit
    {
        if ($unit = $this->current()) {
            return $unit;
        }

        try {
            return DB::transaction(function () {
                $units = Unit::orderBy('id')->lockForUpdate()->get();
                if ($default = $units->firstWhere('default_slot', 1)) {
                    $default->update(['active' => true]);

                    return $default;
                }
                $piece = $units->firstWhere('short_name', 'pcs') ?? $units->firstWhere('name', 'Piece');
                $piece ??= Unit::create(['name' => 'Piece', 'short_name' => 'pcs', 'allow_decimal' => false, 'active' => true]);
                $piece->update(['active' => true, 'default_slot' => 1]);

                return $piece;
            }, 3);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Concurrent first-use requests can race on an empty units table.
            return $this->current() ?? throw $e;
        }
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
