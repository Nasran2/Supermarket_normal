<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\SmsMessage;
use App\Support\Audit;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SmsSettingsService
{
    private function stored(): array
    {
        return Setting::where('group', 'sms')->pluck('value', 'key')->map(fn ($value) => json_decode($value, true))->all();
    }

    public function formValues(): array
    {
        $stored = $this->stored();

        $values = [
            'sending_enabled' => $stored['sms_sending_enabled'] ?? true,
            'username' => $stored['sms_username'] ?? config('sms.username', ''),
            'admin_numbers' => $stored['sms_admin_numbers'] ?? config('sms.admin_numbers', ''),
            'cheque_reminder_time' => $stored['sms_cheque_reminder_time'] ?? config('sms.cheque_reminder_time', '08:00'),
            'password_configured' => filled($stored['sms_password'] ?? config('sms.password')),
        ];
        foreach (config('sms.types') as $kind => $definition) {
            $key = strtolower($kind);
            $values[$key.'_enabled'] = $stored['sms_'.$key.'_enabled'] ?? $definition['enabled'];
            $values[$key.'_template'] = $stored['sms_'.$key.'_template'] ?? $definition['template'];
        }

        return $values;
    }

    public function allows(?string $kind = null): bool
    {
        if (! config('sms.enabled')) {
            return false;
        }
        $values = $this->formValues();
        if (! $values['sending_enabled']) {
            return false;
        }
        if ($kind === null || $kind === 'TEST') {
            return true;
        }
        if (str_starts_with($kind, 'CHEQUE') && ! config('features.cheque')) {
            return false;
        }

        return (bool) ($values[strtolower($kind).'_enabled'] ?? false);
    }

    public function template(string $kind): string
    {
        return $this->formValues()[strtolower($kind).'_template'];
    }

    public function credentials(): array
    {
        $stored = $this->stored();
        try {
            $password = isset($stored['sms_password']) ? Crypt::decryptString($stored['sms_password']) : config('sms.password', '');
        } catch (DecryptException $exception) {
            throw ValidationException::withMessages(['sms' => 'The saved SMS password cannot be read. Enter the password again in Settings → SMS.']);
        }

        return ['username' => $stored['sms_username'] ?? config('sms.username', ''), 'password' => $password];
    }

    public function adminNumbers(): string
    {
        $values = $this->formValues();

        return (string) (filled($values['admin_numbers']) ? $values['admin_numbers'] : $values['username']);
    }

    public function reminderTime(): string
    {
        return $this->formValues()['cheque_reminder_time'];
    }

    public function reminderDue(): bool
    {
        $timezone = app(SettingsService::class)->get('timezone', config('app.timezone', 'Asia/Colombo'));

        return ($this->allows('CHEQUE') || $this->allows('CHEQUE_CUSTOMER')) && now($timezone)->format('H:i') === $this->reminderTime();
    }

    public function save(array $data): void
    {
        DB::transaction(function () use ($data) {
            $before = $this->formValues();
            $password = filled($data['password'] ?? '') ? $data['password'] : $this->credentials()['password'];
            $values = [
                'sms_username' => $data['username'],
                'sms_password' => Crypt::encryptString($password),
                'sms_admin_numbers' => $data['admin_numbers'] ?? '',
                'sms_cheque_reminder_time' => $data['cheque_reminder_time'],
            ];
            $notificationKeys = ['sending_enabled'];
            foreach (array_keys(config('sms.types')) as $kind) {
                $notificationKeys[] = strtolower($kind).'_enabled';
                $notificationKeys[] = strtolower($kind).'_template';
            }
            foreach ($notificationKeys as $key) {
                if (array_key_exists($key, $data)) {
                    $values['sms_'.$key] = str_ends_with($key, '_enabled') ? (bool) $data[$key] : $data[$key];
                }
            }
            foreach ($values as $key => $value) {
                Setting::updateOrCreate(['key' => $key], ['group' => 'sms', 'value' => json_encode($value)]);
            }
            foreach ([...array_keys(config('sms.types')), 'TEST'] as $kind) {
                if (! $this->allows($kind)) {
                    SmsMessage::where('kind', $kind)->where('status', 'PENDING')->update(['status' => 'CANCELLED', 'error' => 'This message type was disabled in SMS settings.']);
                }
            }
            // Audit only non-secret fields; neither plaintext nor encrypted passwords belong in logs.
            Audit::record('settings.sms', Setting::where('key', 'sms_username')->firstOrFail(), $before, $this->formValues() + ['password_changed' => filled($data['password'] ?? '')]);
            Cache::forget('business_settings');
            DB::afterCommit(fn () => Cache::forget('business_settings'));
        });
    }
}
