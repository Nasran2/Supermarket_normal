<?php

namespace Database\Seeders;

use App\Models\Unit;
use App\Models\UnitPreset;
use App\Support\Audit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UnitPresetSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([['Pieces & dozen', 'pcs', 'doz', '12', '1'], ['Kilograms & grams', 'kg', 'g', '1', '1000'], ['Liters & milliliters', 'L', 'ml', '1', '1000']] as [$name, $base, $converted, $baseQuantity, $convertedQuantity]) {
            DB::transaction(function () use ($name, $base, $converted, $baseQuantity, $convertedQuantity) {
                $primary = Unit::where('short_name', $base)->where('active', true)->first();
                $additional = Unit::where('short_name', $converted)->where('active', true)->first();
                if (! $primary || ! $additional || UnitPreset::where('name', $name)->exists()) {
                    return;
                }
                $preset = UnitPreset::create(['name' => $name, 'unit_id' => $primary->id]);
                $preset->conversions()->create(['unit_id' => $additional->id, 'base_quantity' => $baseQuantity, 'converted_quantity' => $convertedQuantity]);
                Audit::record('unit-presets.seed', $preset, [], $preset->load('conversions')->toArray());
            });
        }
    }
}
