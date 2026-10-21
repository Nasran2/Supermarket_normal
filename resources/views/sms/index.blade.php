@extends('layouts.app')
@section('body-class', 'sms-workspace')
@section('title', 'SMS & reminders')
@section('content')
@php
    $smsPolicy = app(\App\Services\SmsSettingsService::class);
    $canDue = $smsPolicy->allows('DUE');
    $canCustom = $smsPolicy->allows('CUSTOM');
    $defaultKind = old('kind', $canDue ? 'DUE' : 'CUSTOM');
    $defaultMessage = old('message', $smsSettings[strtolower($defaultKind).'_template']);
    $composerData = ['DUE' => ['template' => $smsSettings['due_template'], 'tokens' => config('sms.types.DUE.tokens')], 'CUSTOM' => ['template' => $smsSettings['custom_template'], 'tokens' => config('sms.types.CUSTOM.tokens')]];
@endphp
<div class="page-heading sms-page-heading"><div><span class="eyebrow">CUSTOMER COMMUNICATION</span><h1>SMS & reminders</h1><p>A thoughtful message, to the right customer, at the right time.</p></div>@can('sms.settings')<a class="btn secondary" href="{{ route('settings.sms') }}"><x-icon name="settings-2"/>SMS settings</a>@endcan</div>
@if(blank($smsSettings['username']) || !$smsSettings['password_configured'])<div class="notice error"><x-icon name="circle-alert"/><span>Configure your Textit username and password in Settings → SMS before sending.</span></div>@endif
@if(!$smsSettings['sending_enabled'])<div class="notice info"><x-icon name="ban"/><span>SMS sending is paused. Your message history is available. Enable sending in SMS settings when you’re ready.</span></div>@endif
<div class="sms-stats">
    @foreach([['sent','Accepted messages','circle-check','green'],['pending','Queued & sending','history','amber'],['attention','Need attention','circle-alert','red'],['total','All messages','send','neutral']] as [$key,$label,$icon,$tone])
    <div class="card sms-stat"><span class="sms-stat-icon {{ $tone }}"><x-icon :name="$icon" :size="20"/></span><div><span>{{ $label }}</span><strong>{{ number_format($summary->$key ?? 0) }}</strong></div></div>
    @endforeach
</div>
<div class="sms-content-grid">
<section class="card sms-compose-panel">
    <div class="sms-section-heading"><div><span class="eyebrow">COMPOSE</span><h2>Make it personal</h2><p>Choose your message and recipients. Preview every SMS before sending.</p></div><span class="sms-icon-tile"><x-icon name="send" :size="22"/></span></div>
    <form method="GET" class="sms-search-form"><label class="field"><span>Find customers</span><input name="q" value="{{ request('q') }}" placeholder="Search name or phone" aria-label="Find customers"></label><button class="btn secondary"><x-icon name="search" :size="16"/>Search</button>@if(request('q'))<a href="{{ route('sms.index') }}" class="btn secondary">Clear</a>@endif</form>
    <form method="POST" action="{{ route('sms.preview') }}" data-sms-composer>@csrf
    <fieldset class="sms-composer-fields" @disabled(!$canDue && !$canCustom)>
        @if(!$canDue && !$canCustom)<p class="sms-muted-note">Due reminders and custom messages are disabled. Enable a message type in SMS settings to compose messages.</p>@endif
        <div class="sms-field-grid">
            <label class="field"><span>Message type</span><select name="kind" id="sms-compose-kind"><option value="DUE" @selected($defaultKind==='DUE') @disabled(!$canDue)>Due reminder{{ !$canDue ? ' · Disabled' : '' }}</option><option value="CUSTOM" @selected($defaultKind==='CUSTOM') @disabled(!$canCustom)>Promotion / custom message{{ !$canCustom ? ' · Disabled' : '' }}</option></select></label>
            <label class="field"><span>Send to</span><select name="audience" id="sms-compose-audience"><option value="selected">Selected customers</option><option value="all" @selected(old('audience')==='all')>All active customers</option></select></label>
        </div>
        <div class="sms-message-label"><label for="sms-compose-message">Your message</label><button type="button" class="text-link" id="sms-use-template">Use saved template</button></div>
        <textarea id="sms-compose-message" name="message" rows="5" maxlength="1000" placeholder="Write your message…">{{ $defaultMessage }}</textarea>
        <div class="sms-message-tools"><div class="sms-token-list" id="sms-compose-tokens"></div><small id="sms-compose-count"></small></div>
        <p class="sms-muted-note">Click a placeholder to insert it. Due reminders only include customers with a positive balance.</p>
        <div class="sms-recipient-heading"><h3>Recipients <span class="sms-status-pill" id="sms-selected-count">0 selected</span></h3><small>Customers without a valid number are skipped.</small></div>
        <div class="table-wrap sms-recipient-table"><table><thead><tr><th class="sms-checkbox-cell"><input type="checkbox" id="sms-select-page" aria-label="Select all customers on this page"></th><th>Customer</th><th class="text-right">Balance due</th></tr></thead><tbody>
        @forelse($customers as $customer)
            <tr><td class="sms-checkbox-cell"><input type="checkbox" name="customers[]" value="{{ $customer->id }}" aria-label="Select {{ $customer->name }}" @disabled(!$customer->sms_phone_valid) @checked($customer->sms_phone_valid && in_array($customer->id, old('customers', [])))></td><td><div class="sms-contact"><span class="sms-contact-avatar">{{ mb_substr($customer->name,0,1) }}</span><div><strong>{{ $customer->name }}</strong>@if($customer->sms_phone_valid)<small>{{ $customer->phone }}</small>@else<small class="text-danger">{{ $customer->phone ? 'Invalid phone number' : 'No phone number' }}</small>@endif</div></div></td><td class="text-right"><strong>{{ $settings['currency_symbol']??'Rs.' }} {{ \App\Support\Money::display($customer->due_balance) }}</strong></td></tr>
        @empty<tr><td colspan="3"><div class="sms-empty"><x-icon name="contact" :size="28"/><strong>No customers found</strong><p>Try a different name or phone number.</p></div></td></tr>@endforelse
        </tbody></table></div>
        <div class="sms-compose-footer"><p>Selections apply to this page.<br>All active customers includes up to 1,000 eligible contacts.</p><button class="btn primary"><x-icon name="eye" :size="17"/>Preview messages</button></div>
    </fieldset>
    </form><div class="sms-pagination">{{ $customers->links() }}</div>
</section>
<aside class="sms-side-column">
    <section class="card sms-panel"><div class="sms-section-heading"><div><span class="eyebrow">YOUR AUTOMATIONS</span><h2>Messages on your terms</h2><p>Control each notification in SMS settings.</p></div></div>
    <div class="sms-automation-list">@foreach(config('sms.types') as $kind => $definition)@continue(str_starts_with($kind,'CHEQUE') && !config('features.cheque'))<div class="sms-automation"><span class="sms-icon-tile"><x-icon :name="$definition['icon']" :size="18"/></span><div><strong>{{ $definition['label'] }}</strong><small>{{ $kind==='PAYMENT' ? 'After a due payment is recorded' : (str_starts_with($kind,'CHEQUE') ? 'Daily at '.$smsSettings['cheque_reminder_time'] : ($kind==='RECEIPT' ? 'From the receipt popup' : 'Individual or bulk')) }}</small></div><span class="sms-status-pill {{ $smsPolicy->allows($kind) ? 'is-on' : '' }}">{{ $smsPolicy->allows($kind) ? 'On' : 'Off' }}</span></div>@endforeach</div>
    @can('sms.settings')<a class="text-link sms-manage-link" href="{{ route('settings.sms') }}">Manage templates & notifications<x-icon name="arrow-right" :size="16"/></a>@endcan
    </section>
    <section class="sms-help-panel"><span class="sms-icon-tile"><x-icon name="info" :size="20"/></span><h3>A quick check before you send</h3><p>Preview the recipient’s name, balance, and message. Longer messages may use multiple SMS segments.</p><p><strong>Sent</strong> means Textit accepted the message. Delivery confirmation is available in your Textit account.</p></section>
</aside>
</div>
<section class="card sms-history-panel" id="sms-history">
    <div class="sms-section-heading"><div><span class="eyebrow">ACTIVITY</span><h2>Message history</h2><p>Every queued message and its gateway result, in one place.</p></div><form method="GET" class="sms-history-filter">@if(request('q'))<input type="hidden" name="q" value="{{ request('q') }}">@endif<label class="field"><span class="sr-only">Filter history</span><select name="status" aria-label="Filter message history" onchange="this.form.submit()">@foreach([''=>'All statuses','PENDING'=>'Queued','SENDING'=>'Sending','SENT'=>'Sent','attention'=>'Needs attention','CANCELLED'=>'Cancelled'] as $value=>$label)<option value="{{ $value }}" @selected(request('status','')===$value)>{{ $label }}</option>@endforeach</select></label></form></div>
    <div class="table-wrap"><table><thead><tr><th>Recipient / type</th><th>Message</th><th>Status</th><th>Created</th></tr></thead><tbody>
    @forelse($messages as $message)<tr><td><strong class="sms-phone">{{ $message->phone }}</strong><small class="cell-note">{{ $message->kind==='TEST' ? 'Test SMS' : config('sms.types.'.$message->kind.'.label', $message->kind) }}</small></td><td class="sms-history-message"><p class="sms-message-excerpt">{{ $message->message }}</p><details><summary>Read message</summary><p>{{ $message->message }}</p></details></td><td><span class="sms-status-pill {{ $message->status==='SENT' ? 'is-on' : (in_array($message->status,['FAILED','UNKNOWN']) ? 'is-error' : 'is-pending') }}">{{ $message->status }}</span>@if($message->provider_id)<small class="cell-note">ID: {{ $message->provider_id }}</small>@endif @if($message->error)<small class="cell-note sms-error-note">{{ $message->error }}</small>@endif</td><td class="sms-history-date">{{ $message->created_at->format('d M Y') }}<small class="cell-note">{{ $message->created_at->format('h:i A') }}</small></td></tr>
    @empty<tr><td colspan="4"><div class="sms-empty"><x-icon name="send" :size="30"/><strong>Your messages will appear here</strong><p>Send your first message or enable an automatic notification.</p></div></td></tr>@endforelse
    </tbody></table></div><div class="sms-pagination">{{ $messages->links() }}</div>
    <p class="sms-history-note"><x-icon name="info" :size="15"/>Unknown results or messages stuck at SENDING should be checked in Textit before sending again.</p>
</section>
<script id="sms-composer-data" type="application/json">@json($composerData)</script>
@endsection
