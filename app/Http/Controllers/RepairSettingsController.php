<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\RepairDevice;
use App\Models\RepairJob;
use App\Services\SettingsService;
use App\Services\SmsSettingsService;
use App\Support\Demo;
use App\Support\RepairReceipt;
use App\Support\Repairs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RepairSettingsController extends Controller
{
    public function edit(Request $request, SmsSettingsService $sms)
    {
        $super = $request->routeIs('superadmin.*');
        $canEditSms = Repairs::sms() && ($super || $request->user()->hasPermission('sms.settings'));

        return view('repairs.settings', [
            'super' => $super, 'receipt' => RepairReceipt::options(),
            'terms' => app(SettingsService::class)->get('repair_terms', ''),
            'smsTypes' => $canEditSms ? $sms->repairTypes() : [],
            'smsValues' => $canEditSms ? $sms->formValues() : [],
            'repairSmsEnabled' => $canEditSms && Repairs::smsEnabled(),
        ]);
    }

    public function update(Request $request)
    {
        abort_if(Demo::enabled(), 403);
        $data = $request->validate(RepairReceipt::settingsRules());
        $values = array_key_exists('terms', $data) ? ['repair_terms' => $data['terms'] ?? ''] : [];
        if (isset($data['receipt'])) {
            $values['repair_receipt'] = RepairReceipt::options($data['receipt']);
        }
        DB::transaction(fn () => app(SettingsService::class)->put('repairs', $values));

        return back()->with('success', 'Receipt settings saved.');
    }

    public function sms(Request $request, SmsSettingsService $sms)
    {
        abort_if(Demo::enabled(), 403);
        $kinds = array_keys($sms->repairTypes());
        $data = $request->validate(['enabled' => 'required|boolean', 'events' => 'required|array:'.implode(',', $kinds)]
            + array_fill_keys(array_map(fn ($kind) => 'events.'.$kind, $kinds), 'required|boolean'));
        $sms->saveRepairPreferences($request->boolean('enabled'), $data['events']);

        return back()->with('success', 'Repair SMS preferences saved.');
    }

    public function preview(Request $request)
    {
        $settings = app(SettingsService::class)->all();
        if ($request->isMethod('put')) {
            $draft = $request->validate(RepairReceipt::settingsRules());
            if (array_key_exists('terms', $draft)) {
                $settings['repair_terms'] = $draft['terms'] ?? '';
            }
            $receipt = RepairReceipt::options($draft['receipt'] ?? []);
        } else {
            $receipt = RepairReceipt::options($request->validate(RepairReceipt::rules()));
        }
        $job = new RepairJob([
            'tracking' => 'A4D53C7E5F902BF1E23D7A9356F10012', 'received_at' => today(),
            'due_at' => today()->addDays(3), 'warranty_days' => 30, 'estimate' => '3500.00',
            'accessories' => 'Case · Cable', 'condition' => 'Scratches', 'faults' => 'Cracked screen',
            'checklist' => [['label' => 'Display', 'result' => 'Not working', 'fault' => 'Visible lines'], ['label' => 'Charging', 'result' => 'Working']],
        ]);
        $job->id = 123;
        $job->setRelation('customer', new Customer(['name' => 'Sample customer', 'phone' => '077 000 0000']));
        $job->setRelation('device', new RepairDevice(['brand' => 'Apple', 'model' => 'iPhone 14', 'serial' => 'SAMPLE-014', 'color' => 'Black']));

        return view('repairs.print', [
            'job' => $job, 'document' => 'intake', 'payment' => null, 'preview' => true,
            'settings' => $settings,
            'barcode' => $receipt['barcode'] ? RepairReceipt::barcode($job->tracking) : null,
        ] + $receipt);
    }
}
