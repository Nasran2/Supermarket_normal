<?php

namespace App\Http\Controllers;

use App\Http\Requests\SettingsRequest;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SettingsController extends Controller
{
    public function index()
    {
        return view('settings.index');
    }

    public function edit(string $group, SettingsService $service)
    {
        $fields = config('pos.'.$group);
        abort_unless($fields, 404);
        abort_unless(auth()->user()->hasPermission(match ($group) {
            'stock' => 'settings.pos','system' => 'settings.business',default => 'settings.'.$group
        }), 403);
        $values = $service->all();
        $paymentMethods = PaymentMethod::where('active', true)->orderBy('display_order')->get();
        $customers = Customer::where('active', true)->orderBy('name')->get();

        return view('settings.form', compact('group', 'fields', 'values', 'paymentMethods', 'customers'));
    }

    public function update(SettingsRequest $request, string $group, SettingsService $service)
    {
        $data = $request->validated();
        $oldLogo = $service->get('logo');
        $path = null;
        if ($request->hasFile('logo')) {
            $path = $data['logo'] = $request->file('logo')->store('branding', 'public');
        } elseif ($request->boolean('remove_logo')) {
            $data['logo'] = null;
        } else {
            unset($data['logo']);
        }unset($data['remove_logo']);
        try {
            DB::transaction(function () use ($data, $group, $service) {
                $before = Setting::where('group', $group)->lockForUpdate()->pluck('value', 'key')->all();
                $service->put($group, $data);
                $subject = Setting::where('group', $group)->firstOrFail();
                Audit::record('settings.'.$group, $subject, $before, $data);
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('public')->delete($path);
            }
            throw $e;
        }
        if ($oldLogo && ($path || $request->boolean('remove_logo'))) {
            Storage::disk('public')->delete($oldLogo);
        }

        return back()->with('success', 'Settings saved.');
    }
}
