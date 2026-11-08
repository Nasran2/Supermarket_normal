@extends($super ? 'superadmin.layout' : 'layouts.app')
@section('title','Repair settings')
@section('content')
<div class="repair-page repair-stack repair-desk">
    <header class="repair-heading">
        <div class="repair-heading-copy"><span class="repair-kicker">PHONE REPAIRS</span><h1>Repair settings</h1><p>Choose what customers receive at drop-off and during a repair.</p></div>
        @unless($super)<a class="btn secondary" href="{{ route('repairs.catalog') }}">Device catalog</a>@endunless
    </header>
    @if($super && session('success'))<p class="notice success" role="status">{{ session('success') }}</p>@endif
    @if($super && $errors->any())<p class="notice error" role="alert">{{ $errors->first() }}</p>@endif
    <div class="repair-settings-grid">
        <form class="card repair-panel repair-stack" method="post" action="{{ route($super ? 'superadmin.repairs.settings.update' : 'repairs.settings.update') }}">
            @csrf @method('PUT')
            <div class="repair-section-title"><span class="repair-section-icon"><x-icon name="printer"/></span><div><h2>Intake receipt</h2><p>Set the defaults staff see when they open a drop-off receipt.</p></div></div>
            <div class="repair-fields">
                <label class="field">Paper size<select name="receipt[size]">@foreach(['80mm'=>'80 mm thermal','A5'=>'A5','A4'=>'A4'] as $size=>$label)<option value="{{ $size }}" @selected(old('receipt.size',$receipt['size'])===$size)>{{ $label }}</option>@endforeach</select></label>
                <label class="field">Layout<select name="receipt[layout]"><option value="classic" @selected(old('receipt.layout',$receipt['layout'])==='classic')>Standard · more room</option><option value="compact" @selected(old('receipt.layout',$receipt['layout'])==='compact')>Compact · less paper</option></select></label>
            </div>
            <div class="repair-setting-list">
                @foreach(['inspection'=>'Include inspection results','barcode'=>'Print a tracking barcode','prices'=>'Show the estimate when available'] as $key=>$label)
                <label class="repair-setting-check"><input type="hidden" name="receipt[{{ $key }}]" value="0"><input type="checkbox" name="receipt[{{ $key }}]" value="1" @checked(old('receipt.'.$key,$receipt[$key]))>{{ $label }}</label>
                @endforeach
            </div>
            <label class="field">Printed terms<textarea name="terms" rows="3" maxlength="3000" placeholder="Collection, warranty or intake terms">{{ old('terms',$terms) }}</textarea></label>
            <div class="repair-actions"><button class="btn primary">Save receipt settings</button><button class="btn secondary" formaction="{{ route($super ? 'superadmin.repairs.settings.preview' : 'repairs.settings.preview') }}" formtarget="_blank">Preview sample receipt <x-icon name="arrow-up-right" :size="15"/></button></div>
            <small class="muted">Preview your current choices with sample customer and device details.</small>
        </form>
        @if($smsTypes)
        <form class="card repair-panel repair-stack" method="post" action="{{ route($super ? 'superadmin.repairs.settings.sms' : 'repairs.settings.sms') }}">
            @csrf @method('PUT')
            <div class="repair-section-title"><span class="repair-section-icon"><x-icon name="send"/></span><div><h2>Repair text messages</h2><p>Send updates to customers who agree to receive them.</p></div></div>
            <label class="repair-setting-check repair-master-check"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked(old('enabled',$repairSmsEnabled))> Enable repair SMS</label>
            <div class="repair-setting-list">
            @foreach($smsTypes as $kind=>$type)
                <label class="repair-setting-check"><input type="hidden" name="events[{{ $kind }}]" value="0"><input type="checkbox" name="events[{{ $kind }}]" value="1" @checked(old('events.'.$kind,$smsValues[strtolower($kind).'_enabled']))>{{ $type['label'] }}</label>
            @endforeach
            </div>
            @if(!$smsValues['sending_enabled'] || !$smsValues['password_configured'] || !$smsValues['username'])
                <p class="muted">Sending also requires an active SMS account in the store’s SMS settings.</p>
            @endif
            <div class="repair-actions"><button class="btn primary">Save SMS preferences</button>@unless($super)<a class="text-link" href="{{ route('settings.sms') }}">SMS account and templates</a>@endunless</div>
        </form>
        @endif
    </div>
</div>
@endsection
