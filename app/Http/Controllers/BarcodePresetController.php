<?php

namespace App\Http\Controllers;

use App\Http\Requests\BarcodePresetRequest;
use App\Models\BarcodeLabelPreset;
use App\Services\BarcodePresetService;

class BarcodePresetController extends Controller
{
    public function index(BarcodePresetService $service)
    {
        return view('barcode.presets', ['presets' => BarcodeLabelPreset::orderByDesc('is_default')->orderBy('name')->get(), 'defaults' => $service->defaults()]);
    }

    public function edit(BarcodePresetService $service, ?BarcodeLabelPreset $preset = null)
    {
        $configuration = $service->forDisplay($preset?->configuration ?? []);

        return view('barcode.preset-edit', compact('preset', 'configuration'));
    }

    public function store(BarcodePresetRequest $request, BarcodePresetService $service)
    {
        $preset = $service->save($request->validated());

        return response()->json($preset, 201);
    }

    public function update(BarcodePresetRequest $request, BarcodeLabelPreset $preset, BarcodePresetService $service)
    {
        return response()->json($service->save($request->validated(), $preset));
    }

    public function duplicate(BarcodeLabelPreset $preset, BarcodePresetService $service)
    {
        $name = $preset->name.' (copy)';
        $n = 2;
        while (BarcodeLabelPreset::where('name', $name)->exists()) {
            $name = mb_substr($preset->name, 0, 120).' (copy '.$n++.')';
        }
        $service->save(['name' => $name, 'configuration' => $preset->configuration, 'is_default' => false]);

        return back()->with('success', 'Preset duplicated.');
    }

    public function makeDefault(BarcodeLabelPreset $preset, BarcodePresetService $service)
    {
        $service->save(['name' => $preset->name, 'configuration' => $preset->configuration, 'is_default' => true], $preset);

        return back()->with('success', 'Default preset updated.');
    }

    public function destroy(BarcodeLabelPreset $preset, BarcodePresetService $service)
    {
        $service->delete($preset);

        return back()->with('success', 'Preset deleted.');
    }
}
