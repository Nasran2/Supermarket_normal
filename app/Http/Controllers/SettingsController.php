<?php

namespace App\Http\Controllers;

use App\Http\Requests\SettingsRequest;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\Audit;
use App\Support\Expiry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    public function index()
    {
        return view('settings.index');
    }

    public function edit(string $group, SettingsService $service)
    {
        $fields = config('pos.'.$group);
        abort_if($group === 'branding' && ! \App\Support\Branding::enabled(), 404);
        abort_if($group === 'expiry' && ! Expiry::enabled(), 404);
        abort_unless($fields, 404);
        abort_unless(auth()->user()->hasPermission(match ($group) {
            'branding' => 'settings.business','expiry' => 'expiry.manage_settings','stock' => 'settings.stock','system' => 'settings.system',default => 'settings.'.$group
        }), 403);
        if (! config('features.barcode')) {
            unset($fields['barcode_enabled']);
            if ($group === 'receipt') {
                $fields['show_sku'][0] = 'Show SKU';
            }
        }
        if (! \App\Support\Units::enabled()) {
            unset($fields['show_unit']);
        }
        $values = $service->all();
        if (! config('features.barcode') && ($values['search_mode'] ?? '') === 'barcode') {
            $values['search_mode'] = 'all';
        }
        $paymentMethods = PaymentMethod::where('active', true)->orderBy('display_order')->get();
        $customers = Customer::where('active', true)->orderBy('name')->get();

        return view($group === 'branding' ? 'settings.branding' : 'settings.form', compact('group', 'fields', 'values', 'paymentMethods', 'customers'));
    }

    public function update(SettingsRequest $request, string $group, SettingsService $service)
    {
        $data = $request->validated();
        foreach (config('bill_fields.'.$group, []) as $option) {
            if (array_key_exists($option['setting'], $data)) {
                $data[$option['setting']] = $request->boolean($option['setting']);
            }
        }
        $fileKeys = array_keys(array_filter(config('pos.'.$group), fn ($field) => $field[1] === 'file'));
        $uploads = [];
        $oldPaths = [];
        try {
            foreach ($fileKeys as $key) {
                if ($request->hasFile($key)) {
                    $oldPaths[$key] = $service->get($key);
                    $path = $request->file($key)->store('branding', 'public');
                    if (! $path) {
                        throw ValidationException::withMessages([$key => 'The logo could not be saved. Please try again.']);
                    }
                    $uploads[] = $data[$key] = $path;
                } elseif ($request->boolean('remove_'.$key)) {
                    $oldPaths[$key] = $service->get($key);
                    $data[$key] = null;
                } else {
                    unset($data[$key]);
                }
                unset($data['remove_'.$key]);
            }
            DB::transaction(function () use ($data, $group, $service) {
                $before = Setting::where('group', $group)->lockForUpdate()->pluck('value', 'key')->all();
                $service->put($group, $data);
                $subject = Setting::where('group', $group)->firstOrFail();
                Audit::record('settings.'.$group, $subject, $before, $data);
            });
        } catch (\Throwable $e) {
            if ($uploads) {
                Storage::disk('public')->delete($uploads);
            }
            throw $e;
        }
        $currentPaths = array_map(fn ($key) => $service->get($key), $fileKeys);
        foreach ($oldPaths as $oldPath) {
            if ($oldPath && ! in_array($oldPath, $currentPaths, true)) {
                Storage::disk('public')->delete($oldPath);
            }
        }

        return back()->with('success', 'Settings saved.');
    }
}
