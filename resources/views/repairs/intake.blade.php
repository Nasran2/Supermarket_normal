@extends('layouts.app')
@section('title','Receive device')
@section('content')
@php
    use App\Support\Repairs;
    $groups = [
        'Screen & camera'=>['Display','Touch','Front camera','Rear camera'],
        'Power & sound'=>['Battery','Charging','Speaker','Microphone','Buttons'],
        'Connections'=>['Wi-Fi','Mobile network','Bluetooth'],
    ];
    $previousChecks = collect(old('checklist', []))->filter(fn ($row) => is_array($row) && is_string($row['label'] ?? null))->keyBy('label');
    $index = 0;
    $startStep = $errors->hasAny(['customer_id','device_id','brand','model','catalog_model_id','serial','color','faults','reported_issues','reported_issues.*']) ? 0
        : ($errors->hasAny(['checklist','checklist.*','accessories','condition','received_items','condition_tags','device_unlock','unlock_type']) ? 1 : (int) old('intake_step',0));
@endphp
<div class="repair-page repair-desk" data-repair-intake data-intake-start="{{ $startStep }}">
    <header class="repair-heading">
        <div class="repair-heading-copy"><span class="repair-kicker">PHONE REPAIRS</span><h1>Receive a device</h1><p>Register the drop-off and give the customer a tracking receipt.</p></div>
        <a class="btn secondary" href="{{ route('repairs.index') }}"><x-icon name="arrow-left" :size="16"/> Repair jobs</a>
    </header>
    <ol class="repair-stepper" aria-label="Intake progress">
        @foreach(['Customer & device','Device condition','Review & save'] as $label)
            <li><button type="button" data-intake-step="{{ $loop->index }}" @if($loop->first) aria-current="step" @endif><span class="repair-step-number">{{ $loop->iteration }}</span><span>{{ $label }}</span></button></li>
        @endforeach
    </ol>
    <form method="post" action="{{ route('repairs.store') }}" class="repair-intake-form">@csrf
        <input type="hidden" name="submission" value="{{ old('submission', (string) \Illuminate\Support\Str::uuid()) }}">
        <input type="hidden" name="intake_step" value="{{ $startStep }}">
        <script type="application/json" data-device-catalog>{!! $catalog->toJson(JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
        <div class="repair-intake-main">
            <section data-intake-panel="0" class="repair-stack" aria-labelledby="intake-customer-heading">
                <div class="card repair-panel repair-stack">
                    <div class="repair-section-title"><span class="repair-section-icon"><x-icon name="user"/></span><div><h2 id="intake-customer-heading" tabindex="-1">Who’s dropping it off?</h2><p>Search by customer name or phone number.</p></div></div>
                    <div class="repair-customer-search">
                        <div class="repair-picker" data-repair-picker="{{ route('repairs.customers') }}" data-history-url="{{ route('repairs.customers.history','__id__') }}">
                            <label class="field"><span class="sr-only">Customer search</span><input type="search" data-picker-query maxlength="100" autocomplete="off" value="{{ $selectedCustomer?->name }}" placeholder="Search by name or phone" required></label>
                            <input type="hidden" name="customer_id" value="{{ $selectedCustomer?->id }}" data-picker-value>
                            <div data-picker-results class="repair-picker-results" hidden></div>
                        </div>
                        @can('customers.create')<button type="button" class="btn secondary" data-repair-open="repair-customer-dialog"><x-icon name="plus" :size="16"/> New customer</button>@endcan
                    </div>
                    <div data-repair-history class="repair-intake-history" aria-live="polite" hidden></div>
                </div>
                <div class="card repair-panel repair-stack">
                    <div class="repair-section-title"><span class="repair-section-icon"><x-icon name="package"/></span><div><h2>Which device?</h2><p>Pick a saved device or choose a brand and model.</p></div></div>
                    <label class="field" data-saved-devices hidden>Saved device<select name="device_id" data-repair-device data-selected="{{ old('device_id', $selectedDevice) }}"><option value="">Add a new device</option></select></label>
                    <div class="repair-stack" data-new-device>
                        <div class="repair-fields repair-intake-fields" data-catalog-fields>
                            <label class="field">Brand<select data-catalog-brand data-selected="{{ old('catalog_brand', '') }}" name="catalog_brand">
                                <option value="">Choose a brand</option>
                                @foreach($catalog as $brand)<option value="{{ $brand->id }}">{{ $brand->name }}</option>@endforeach
                            </select></label>
                            <label class="field">Model<select data-catalog-model name="catalog_model_id" data-selected="{{ old('catalog_model_id', '') }}"><option value="">Choose a brand first</option></select></label>
                        </div>
                        <div class="repair-fields repair-intake-fields" data-manual-device>
                            <label class="field">Brand name<input name="brand" value="{{ old('brand') }}" maxlength="80" placeholder="e.g. Sony"></label>
                            <label class="field">Model name<input name="model" value="{{ old('model') }}" maxlength="120" placeholder="e.g. Xperia 10"></label>
                        </div>
                        <button type="button" class="text-link repair-manual-link" data-manual-toggle>Can’t find the device? Enter it manually</button>
                        <details class="repair-fold" @if(old('serial') || old('color') || $errors->hasAny(['serial','color'])) open @endif>
                            <summary>IMEI, serial number or color <small>Optional</small></summary>
                            <div class="repair-fields repair-intake-fields">
                                <label class="field">IMEI or serial number<input name="serial" value="{{ old('serial') }}" maxlength="100" autocomplete="off"></label>
                                <label class="field">Color<input name="color" value="{{ old('color') }}" maxlength="60" placeholder="e.g. Black"></label>
                            </div>
                        </details>
                    </div>
                </div>
                <div class="card repair-panel repair-stack">
                    <div class="repair-section-title"><span class="repair-section-icon"><x-icon name="circle-alert"/></span><div><h2>What needs fixing?</h2><p>Pick the issues the customer reported. Add a note if needed.</p></div></div>
                    @include('repairs.partials.intake-tags',['choices'=>Repairs::INTAKE_ISSUES,'name'=>'reported_issues'])
                    <details class="repair-fold" @if(old('faults') || $errors->has('faults')) open @endif>
                        <summary>Other issue or extra details <small>Optional if you picked an issue</small></summary>
                        <label class="field"><span class="sr-only">Describe the issue</span><textarea name="faults" rows="2" maxlength="2000" placeholder="e.g. The screen flickers after a drop">{{ old('faults') }}</textarea></label>
                    </details>
                    <p class="text-danger" role="alert" data-intake-issue-error hidden>Choose an issue or describe what needs fixing.</p>
                </div>
            </section>
            <section data-intake-panel="1" class="repair-stack" aria-labelledby="intake-inspect-heading">
                <div class="card repair-panel repair-stack">
                    <div class="repair-section-title"><span class="repair-section-icon"><x-icon name="shield-check"/></span><div><h2 id="intake-inspect-heading" tabindex="-1">Inspect the device</h2><p>Tap a result for the checks you can do now. The inspection is optional.</p></div></div>
                    <div class="repair-inspect-summary"><span data-inspection-count aria-live="polite">No checks recorded</span><span><i></i> Works <i></i> Issue <i></i> Skip</span></div>
                    <div data-repair-checks class="repair-stack">
                        @foreach($groups as $group=>$labels)
                            <div class="repair-check-group"><h3>{{ $group }}</h3><div class="repair-inspect-grid">
                                @foreach($labels as $label)
                                    @include('repairs.partials.check',['i'=>$index++,'row'=>$previousChecks->pull($label,['label'=>$label]),'custom'=>false])
                                @endforeach
                            </div></div>
                        @endforeach
                        <div class="repair-check-group" data-extra-checks hidden><h3>Model-specific checks</h3><div class="repair-inspect-grid" data-extra-check-list></div></div>
                        <div class="repair-check-group" data-custom-checks @if($previousChecks->isEmpty()) hidden @endif><h3>Additional checks</h3><div class="repair-inspect-grid" data-custom-check-list>
                            @foreach($previousChecks->take(28) as $row)@include('repairs.partials.check',['i'=>$index++,'row'=>$row,'custom'=>true])@endforeach
                        </div></div>
                    </div>
                    <template data-check-template>@include('repairs.partials.check', ['i'=>'__INDEX__','row'=>[],'custom'=>true])</template>
                    <template data-extra-check-template>@include('repairs.partials.check', ['i'=>'__INDEX__','row'=>[],'custom'=>false])</template>
                    <div><button class="btn secondary" type="button" data-add-check><x-icon name="plus" :size="15"/> Add another check</button></div>
                </div>
                <div class="card repair-panel repair-stack">
                    <div class="repair-section-title"><span class="repair-section-icon"><x-icon name="package-open"/></span><div><h2>What came with it?</h2><p>Record visible damage or items the customer left with the device.</p></div></div>
                    <fieldset class="repair-tag-field"><legend>Visible condition</legend>@include('repairs.partials.intake-tags',['choices'=>Repairs::INTAKE_CONDITIONS,'name'=>'condition_tags'])</fieldset>
                    <fieldset class="repair-tag-field"><legend>Items received</legend>@include('repairs.partials.intake-tags',['choices'=>Repairs::INTAKE_ITEMS,'name'=>'received_items'])</fieldset>
                    <details class="repair-fold" @if(old('condition') || old('accessories')) open @endif><summary>More condition or accessory details <small>Optional</small></summary>
                        <div class="repair-fields repair-intake-fields">
                            <label class="field">Condition details<input name="condition" value="{{ old('condition') }}" maxlength="2000"></label>
                            <label class="field">Other items<input name="accessories" value="{{ old('accessories') }}" maxlength="500"></label>
                        </div>
                    </details>
                    @include('repairs.partials.unlock')
                </div>
            </section>
            <section data-intake-panel="2" class="repair-stack" aria-labelledby="intake-finish-heading">
                <div class="card repair-panel repair-stack">
                    <div class="repair-section-title"><span class="repair-section-icon"><x-icon name="calendar"/></span><div><h2 id="intake-finish-heading" tabindex="-1">Review the drop-off</h2><p>Save now, or enter a promised date and an estimate if you have them.</p></div></div>
                    <dl class="repair-review-data">
                        <div><dt>Customer</dt><dd data-review="customer">Select a customer</dd></div>
                        <div><dt>Device</dt><dd data-review="device">Choose a device</dd></div>
                        <div><dt>Reported issue</dt><dd data-review="issue">Add a reported issue</dd></div>
                    </dl>
                    <div class="repair-fields repair-intake-fields">
                        <label class="field">Expected by <span class="muted">Optional</span><input type="date" name="due_at" value="{{ old('due_at') }}"></label>
                        <label class="field">Estimated cost <span class="muted">Optional</span><input type="number" step=".01" min="0" name="estimate" value="{{ old('estimate') }}"></label>
                    </div>
                    <details class="repair-fold" @if($errors->hasAny(['received_at','technician_id','priority','warranty_days','notes']) || old('notes')) open @endif><summary>Assignment, warranty and staff notes <small>Optional</small></summary>
                        <div class="repair-stack">
                            <div class="repair-fields repair-intake-fields">
                                <label class="field">Received date<input type="date" name="received_at" value="{{ old('received_at',today()->toDateString()) }}" required></label>
                                @include('repairs.partials.assignment', ['job'=>null])
                            </div>
                            <label class="field">Staff-only notes<textarea name="notes" rows="2" maxlength="4000">{{ old('notes') }}</textarea></label>
                        </div>
                    </details>
                    @if(Repairs::smsEnabled())<label class="repair-setting-check"><input name="sms_opt_in" type="checkbox" value="1" @checked(old('sms_opt_in'))> Customer agrees to get repair updates by SMS</label>@endif
                </div>
            </section>
            <div class="repair-intake-footer">
                <button type="button" class="btn secondary" data-intake-back hidden><x-icon name="arrow-left" :size="16"/> Back</button>
                <button type="button" class="btn primary" data-intake-next hidden>Continue to condition <x-icon name="arrow-right" :size="16"/></button>
                <button class="btn primary" data-intake-save><x-icon name="check" :size="16"/> Save repair</button>
            </div>
        </div>
        <aside class="card repair-intake-aside">
            <span class="repair-section-icon"><x-icon name="receipt-text"/></span>
            <h2>Drop-off summary</h2>
            <dl><div><dt>Customer</dt><dd data-review="customer">Select a customer</dd></div><div><dt>Device</dt><dd data-review="device">Choose a device</dd></div><div><dt>Reported issue</dt><dd data-review="issue">Add a reported issue</dd></div></dl>
            <p>The tracking number and receipt are ready after you save.</p>
        </aside>
    </form>
    @can('customers.create')
        <dialog class="card repair-dialog" id="repair-customer-dialog"><form data-new-repair-customer action="{{ route('repairs.customers.store') }}" class="repair-stack">@csrf
            <div class="card-heading"><h2>New customer</h2><button type="button" class="btn secondary" data-repair-close>Close</button></div>
            <label class="field">Name<input name="name" required maxlength="255"></label>
            <label class="field">Phone<input name="phone" type="tel" maxlength="255"></label>
            <label class="field">Email <span class="muted">Optional</span><input name="email" type="email" maxlength="255"></label>
            <p class="text-danger" data-customer-error role="alert" hidden></p>
            <div><button class="btn primary">Save and select customer</button></div>
        </form></dialog>
    @endcan
</div>
@endsection
