<?php

namespace App\Http\Controllers;

use App\Services\SmsService;
use App\Services\SmsSettingsService;
use App\Services\SmsTemplateService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SmsSettingsController extends Controller
{
    public function edit(SmsSettingsService $settings)
    {
        return view('settings.sms', ['values' => $settings->formValues()]);
    }

    public function test(Request $request, SmsService $sms)
    {
        $data = $request->validate(['token' => 'required|uuid', 'phone' => 'required|string|max:40', 'message' => 'required|string|max:1000']);
        $message = $sms->enqueue([
            'dedupe_key' => 'test:'.$request->user()->id.':'.$data['token'], 'kind' => 'TEST',
            'user_id' => $request->user()->id, 'phone' => $data['phone'], 'message' => $data['message'],
        ]);
        $message = $sms->deliver($message);
        $result = match ($message->status) {
            'SENT' => 'Test SMS accepted by Textit for '.$message->phone.'. Check the recipient’s phone for delivery.',
            'SENDING' => 'This test SMS is already being sent. Check SMS history for its result.',
            default => $message->error ?? 'Test SMS is pending. Check SMS history for its result.',
        };
        if ($request->expectsJson()) {
            return response()->json(['status' => $message->status, 'message' => $result]);
        }

        return $message->status === 'SENT' ? back()->with('success', $result) : back()->withErrors(['test_sms' => $result]);
    }

    public function update(Request $request, SmsSettingsService $settings, SmsService $sms)
    {
        $rules = [
            'username' => 'required|string|max:40',
            'password' => 'nullable|string|max:255',
            'admin_numbers' => 'nullable|string|max:1000',
            'cheque_reminder_time' => 'required|date_format:H:i',
            'sending_enabled' => 'sometimes|boolean',
        ];
        foreach (config('sms.types') as $kind => $definition) {
            $key = strtolower($kind);
            $rules[$key.'_enabled'] = 'sometimes|boolean';
            $rules[$key.'_template'] = 'sometimes|required|string|max:1000';
        }
        $data = $request->validate($rules);
        foreach (config('sms.types') as $kind => $definition) {
            $key = strtolower($kind).'_template';
            if (isset($data[$key])) {
                app(SmsTemplateService::class)->validate($kind, $data[$key], $key);
            }
        }
        try {
            $data['username'] = $sms->phone($data['username']);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['username' => 'Enter the Textit account phone number, e.g. 94771234567.']);
        }
        try {
            $phones = preg_split('/[,\r\n]+/', $data['admin_numbers'] ?? '', -1, PREG_SPLIT_NO_EMPTY);
            $data['admin_numbers'] = implode(',', array_unique(array_map(fn ($phone) => $sms->phone(trim($phone)), $phones)));
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['admin_numbers' => 'Enter valid admin phone numbers, separated by commas or new lines.']);
        }
        if (blank($data['password'] ?? '') && ! $settings->formValues()['password_configured']) {
            throw ValidationException::withMessages(['password' => 'Enter the Textit account password.']);
        }
        $settings->save($data);

        return redirect()->route('settings.sms')->with('success', 'SMS settings saved.');
    }
}
