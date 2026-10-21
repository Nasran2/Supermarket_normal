<?php

use App\Models\Role;
use App\Models\SmsMessage;
use App\Models\User;
use App\Services\ChequeSmsReminderService;
use App\Services\DemoDataService;
use App\Services\SmsService;
use App\Services\SmsSettingsService;
use App\Support\Demo;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

Artisan::command('pos:admin {email} {--name=Administrator} {--username= : Login username}', function () {
    $email = $this->argument('email');
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Enter a valid email.');

        return 1;
    }
    if (User::where('email', $email)->exists()) {
        $this->error('Account already exists. Use Users to edit it.');

        return 1;
    }
    $username = $this->option('username');
    if ($username === null) {
        $base = trim(preg_replace('/[^a-z0-9._-]/', '', strtolower(explode('@', $email, 2)[0])), '._-');
        $base = substr($base !== '' ? $base : 'admin', 0, 48);
        $username = $base;
        $suffix = 1;
        while (User::where('username', $username)->exists()) {
            $username = $base.'-'.$suffix++;
        }
    }
    $username = strtolower(trim($username));
    $validator = Validator::make(['username' => $username], ['username' => 'required|string|max:64|regex:/^[a-z0-9][a-z0-9._-]*$/|unique:users,username']);
    if ($validator->fails()) {
        $this->error($validator->errors()->first());

        return 1;
    }
    $password = $this->secret('New admin password (at least 10 characters)');
    if (strlen($password) < 10) {
        $this->error('Password is too short.');

        return 1;
    }
    $role = Role::where('name', 'Administrator')->firstOrFail();
    User::create(['name' => $this->option('name'), 'username' => $username, 'email' => $email, 'password' => $password, 'role_id' => $role->id, 'active' => true]);
    $this->info('Administrator created. Username: '.$username);
})->purpose('Create an administrator without a default password');

Artisan::command('demo:reset', function (DemoDataService $service) {
    if (! Demo::enabled()) {
        $this->info('Demo mode is disabled. No data was changed.');

        return 0;
    }
    $state = $service->synchronized(fn () => $service->refresh());
    $this->info(($state['reset'] ? 'Demo data restored.' : 'Demo cycle ready; no reset due.').' Next reset: '.$state['next_reset_at']->toDateTimeString());

    return 0;
})->purpose('Initialize the demo baseline or restore it when the ten-day cycle expires');

Schedule::command('demo:reset')->everyMinute()->withoutOverlapping();

Artisan::command('sms:send-pending {--limit=100}', function (SmsService $sms) {
    if (! config('sms.enabled')) {
        $this->info('SMS is disabled.');

        return 0;
    }
    try {
        $count = 0;
        foreach (SmsMessage::where('status', 'PENDING')->orderBy('id')->limit(max(1, min(1000, (int) $this->option('limit'))))->get() as $message) {
            $sms->deliver($message);
            $count++;
        }
        $this->info($count.' pending messages processed. Check SMS history for results.');

        return 0;
    } catch (ValidationException $exception) {
        $this->error($exception->validator->errors()->first());

        return 1;
    }
})->purpose('Send queued SMS messages once, without retrying uncertain results');

Artisan::command('sms:cheque-reminders', function (ChequeSmsReminderService $reminders, SmsService $sms) {
    try {
        $this->info($reminders->queue($sms).' cheque reminders queued.');

        return 0;
    } catch (ValidationException $exception) {
        $this->error($exception->validator->errors()->first());

        return 1;
    }
})->purpose('Queue daily admin reminders for due-soon and overdue cheques');

Schedule::command('sms:cheque-reminders')->everyMinute()->withoutOverlapping()->when(fn () => config('sms.enabled') && config('features.cheque') && app(SmsSettingsService::class)->reminderDue());
Schedule::command('sms:send-pending')->everyMinute()->withoutOverlapping()->when(fn () => config('sms.enabled'));
