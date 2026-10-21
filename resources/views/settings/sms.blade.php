@extends('layouts.app')
@section('body-class', 'sms-workspace')
@section('title', 'SMS settings')
@section('content')
<div class="page-heading sms-page-heading"><div><span class="eyebrow">SETTINGS / COMMUNICATION</span><h1>SMS settings</h1><p>Your account, your messages, your choice of notifications.</p></div>@can('settings.view')<a class="btn secondary" href="{{ route('settings.index') }}"><x-icon name="arrow-left"/>All settings</a>@endcan</div>
<form method="POST" action="{{ route('settings.sms.update') }}" class="sms-settings-form" data-sms-settings data-sms-business="{{ $settings['business_name'] ?? config('app.name') }}" data-sms-currency="{{ $settings['currency_symbol'] ?? 'Rs.' }}">@csrf @method('PUT')
<section class="card sms-panel sms-master-panel">
    <div class="sms-panel-title"><span class="sms-icon-tile"><x-icon name="send" :size="22"/></span><div><h2>Allow SMS sending</h2><p>Pause all outgoing SMS here, or choose individual message types below.</p></div></div>
    <label class="sms-toggle"><input type="hidden" name="sending_enabled" value="0"><input type="checkbox" name="sending_enabled" value="1" @checked(old('sending_enabled', $values['sending_enabled'])) aria-label="Allow SMS sending"><span aria-hidden="true"></span></label>
    <p class="sms-master-note">The server switch SMS=true keeps this workspace available. Turning sending off here keeps your settings and history.</p>
</section>
<section class="card sms-panel">
    <div class="sms-section-heading"><div><span class="eyebrow">01 / CONNECTION</span><h2>Textit account</h2><p>Messages use these credentials. Saved passwords stay encrypted.</p></div><span class="sms-status-pill {{ $values['password_configured'] ? 'is-on' : '' }}">{{ $values['password_configured'] ? 'Account configured' : 'Setup needed' }}</span></div>
    <div class="sms-field-grid">
        <label class="field"><span>Username / account phone</span><input id="sms-setting-username" name="username" type="text" inputmode="tel" maxlength="40" value="{{ old('username', $values['username']) }}" placeholder="94771234567" autocomplete="off" required><small>Your Textit account phone in international format.</small>@error('username')<small class="text-danger">{{ $message }}</small>@enderror</label>
        <label class="field"><span>Password</span><input id="sms-setting-password" name="password" type="password" maxlength="255" autocomplete="new-password" @required(!$values['password_configured']) placeholder="{{ $values['password_configured'] ? 'Password configured — leave blank to keep it' : 'Enter Textit password' }}"><small>{{ $values['password_configured'] ? 'Leave blank to keep your current password.' : 'Enter your Textit password to finish setup.' }}</small>@error('password')<small class="text-danger">{{ $message }}</small>@enderror</label>
    </div>
    <details class="sms-connection-test" @if($errors->hasAny(['phone','message','test_sms'])) open @endif>
        <summary><x-icon name="send" :size="17"/><span>Send a test SMS</span><x-icon name="chevron-down" :size="16"/></summary>
        <div class="sms-test-body">
            <p class="sms-muted-note">Uses your saved Textit account. Save account changes before testing. A test sends immediately and uses your SMS balance.</p>
            <div class="sms-field-grid">
                <label class="field"><span>Recipient phone number</span><input form="sms-test-form" id="sms-test-phone" name="phone" type="tel" maxlength="40" placeholder="0771234567" value="{{ old('phone', $values['username']) }}" required><small>Enter the number that should receive the test.</small></label>
                <label class="field"><span>Test message</span><textarea form="sms-test-form" id="sms-test-message" name="message" maxlength="1000" rows="3" required>{{ old('message', 'Test SMS from '.($settings['business_name'] ?? config('app.name')).'. Your SMS connection is working.') }}</textarea></label>
            </div>
            <p id="sms-test-result" class="notice info" role="status" aria-live="polite" hidden></p>
            <div class="sms-test-actions"><small>{{ $values['sending_enabled'] ? 'Individual notification switches do not affect connection tests.' : 'SMS sending is paused. Enable and save Allow SMS sending before testing.' }}</small><button form="sms-test-form" type="submit" class="btn secondary" id="sms-test-send" @disabled(!$values['sending_enabled'])><x-icon name="send" :size="16"/>Send test SMS</button><button type="button" class="btn secondary" id="sms-test-another" hidden>Send another test</button></div>
        </div>
    </details>
</section>
<section class="card sms-panel">
    <div class="sms-section-heading"><div><span class="eyebrow">02 / NOTIFICATIONS</span><h2>Message types & templates</h2><p>Switch off what you don’t need. Edit the wording of every message.</p></div></div>
    <div class="sms-template-grid">
    @foreach(config('sms.types') as $kind => $definition)
        @continue(str_starts_with($kind, 'CHEQUE') && !config('features.cheque'))
        @php($key = strtolower($kind))
        <article class="sms-template-card" data-sms-template-card>
            <div class="sms-template-top"><span class="sms-icon-tile"><x-icon :name="$definition['icon']" :size="20"/></span><div class="sms-template-title"><h3>{{ $definition['label'] }}</h3><p>{{ $definition['description'] }}</p></div><label class="sms-toggle"><input type="hidden" name="{{ $key }}_enabled" value="0"><input type="checkbox" name="{{ $key }}_enabled" value="1" @checked(old($key.'_enabled', $values[$key.'_enabled'])) aria-label="Enable {{ $definition['label'] }}"><span aria-hidden="true"></span></label></div>
            <details class="sms-template-editor" @if($errors->has($key.'_template')) open @endif>
                <summary><span>Edit message template</span><x-icon name="chevron-down" :size="16"/></summary>
                <div class="sms-template-body">
                    <label class="field"><span>Message template</span><textarea id="sms-template-{{ $kind }}" name="{{ $key }}_template" rows="4" maxlength="1000" required data-sms-template-input>{{ old($key.'_template', $values[$key.'_template']) }}</textarea>@error($key.'_template')<small class="text-danger">{{ $message }}</small>@enderror</label>
                    <div class="sms-token-list" aria-label="Available placeholders">@foreach($definition['tokens'] as $token)<button type="button" data-sms-insert="{{ '{'.$token.'}' }}" data-sms-target="sms-template-{{ $kind }}">{{ '{'.$token.'}' }}</button>@endforeach</div>
                    <div class="sms-template-meta"><span data-sms-template-count></span><button type="button" class="text-link" data-sms-reset="sms-template-{{ $kind }}" data-sms-default="{{ $definition['template'] }}">Reset to default</button></div>
                    <div class="sms-message-preview"><span class="eyebrow">EXAMPLE PREVIEW</span><p data-sms-template-preview></p></div>
                </div>
            </details>
        </article>
    @endforeach
    </div>
</section>
@if(config('features.cheque'))
<section class="card sms-panel">
    <div class="sms-section-heading"><div><span class="eyebrow">03 / CHEQUE REMINDERS</span><h2>Recipients & daily schedule</h2><p>Choose admin recipients and when enabled cheque reminders should run.</p></div><span class="sms-status-pill">{{ $settings['timezone'] ?? config('app.timezone') }}</span></div>
    <div class="sms-field-grid">
        <label class="field"><span>Admin phone numbers</span><textarea id="sms-setting-admins" name="admin_numbers" maxlength="1000" rows="3" placeholder="0771234567, 0779876543">{{ old('admin_numbers', $values['admin_numbers']) }}</textarea><small>Separate numbers with commas or new lines. Leave blank to use the Textit account phone. Customer reminders use each customer’s saved number.</small>@error('admin_numbers')<small class="text-danger">{{ $message }}</small>@enderror</label>
        <label class="field"><span>Daily reminder time</span><input id="sms-setting-time" name="cheque_reminder_time" type="time" value="{{ old('cheque_reminder_time', $values['cheque_reminder_time']) }}" required><small>Due-soon and overdue pending cheques follow the alert window in Cheque settings. Each recipient gets at most one reminder per cheque per day.</small>@error('cheque_reminder_time')<small class="text-danger">{{ $message }}</small>@enderror</label>
    </div>
</section>
@else
<input type="hidden" name="admin_numbers" value="{{ $values['admin_numbers'] }}"><input type="hidden" name="cheque_reminder_time" value="{{ $values['cheque_reminder_time'] }}">
@endif
<div class="sms-settings-footer"><p><x-icon name="lock" :size="15"/>Changes apply to new sends. Disabled message types will not be sent from the queue.</p><div>@can('sms.manage')<a class="btn secondary" href="{{ route('sms.index') }}">SMS & reminders</a>@endcan<button type="submit" class="btn primary"><x-icon name="check"/>Save SMS settings</button></div></div>
</form>
<form id="sms-test-form" method="POST" action="{{ route('settings.sms.test') }}" data-paused="{{ $values['sending_enabled'] ? '0' : '1' }}">@csrf<input type="hidden" name="token" value="{{ (string) \Illuminate\Support\Str::uuid() }}"></form>
@endsection
