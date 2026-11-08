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
    $settings = app(\App\Services\SettingsService::class)->all();
    $currency = $settings['currency_symbol'] ?? 'Rs.';
    $draftTracking = 'TRK-'.strtoupper(substr(md5((string) (old('submission') ?? uniqid())), 0, 8));
@endphp
<div class="repair-page repair-desk" data-repair-intake data-intake-start="{{ $startStep }}" data-currency="{{ $currency }}" data-selected-customer-phone="{{ $selectedCustomer?->phone ?? '' }}">
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
        <aside class="repair-intake-aside-container">
            <div class="repair-bill-slip">
                <!-- Top Bill Header Banner -->
                <div class="bill-slip-top">
                    <div class="bill-badge-row">
                        <span class="bill-live-pill"><span class="bill-pulse-dot"></span> LIVE BILL PREVIEW</span>
                        <span class="bill-ref-pill">Draft #{{ date('ymd') }}</span>
                    </div>

                    <!-- Business Header -->
                    <div class="bill-store-header">
                        @if(!empty($settings['logo']))
                            <img src="{{ asset('storage/'.$settings['logo']) }}" alt="Logo" class="bill-store-logo">
                        @endif
                        <h3 class="bill-store-name">{{ $settings['business_name'] ?? config('app.name') }}</h3>
                        @if(!empty($settings['address']))
                            <p class="bill-store-sub">{{ $settings['address'] }}</p>
                        @endif
                        @if(!empty($settings['phone']) || !empty($settings['mobile']))
                            <p class="bill-store-sub">Tel: {{ $settings['phone'] ?? $settings['mobile'] }}</p>
                        @endif
                    </div>

                    <div class="bill-title-strip">
                        <span class="bill-title-text">DEVICE INTAKE RECEIPT</span>
                        <span class="bill-date-text" data-bill="received-date">{{ today()->format('d M Y') }}</span>
                    </div>
                </div>

                <!-- Bill Body Content -->
                <div class="bill-slip-body">
                    <!-- Customer Information -->
                    <div class="bill-section">
                        <div class="bill-section-label">CUSTOMER INFORMATION</div>
                        <div class="bill-highlight-box">
                            <div class="bill-customer-name" data-bill="customer-name">{{ $selectedCustomer?->name ?? 'Select customer' }}</div>
                            <div class="bill-customer-sub" data-bill="customer-phone" @if(!$selectedCustomer?->phone) hidden @endif>{{ $selectedCustomer?->phone ? 'Tel: '.$selectedCustomer->phone : '' }}</div>
                        </div>
                    </div>

                    <!-- Device Details -->
                    <div class="bill-section">
                        <div class="bill-section-label">DEVICE DETAILS</div>
                        <div class="bill-device-title" data-bill="device-title">Select device</div>
                        <div class="bill-meta-grid" data-bill-device-meta hidden>
                            <div class="bill-meta-item" data-bill-wrap="serial" hidden>
                                <span class="meta-label">IMEI/SN:</span>
                                <span class="meta-val font-mono" data-bill="device-serial"></span>
                            </div>
                            <div class="bill-meta-item" data-bill-wrap="color" hidden>
                                <span class="meta-label">Color:</span>
                                <span class="meta-val" data-bill="device-color"></span>
                            </div>
                        </div>
                    </div>

                    <!-- Reported Issues -->
                    <div class="bill-section">
                        <div class="bill-section-label">REPORTED ISSUE / FAULT</div>
                        <div class="bill-issues-container" data-bill="issues-list">
                            <span class="bill-empty-muted">Add a reported issue</span>
                        </div>
                    </div>

                    <!-- Condition (Visible when entered) -->
                    <div class="bill-section" data-bill-wrap="condition" hidden>
                        <div class="bill-section-label">DEVICE CONDITION</div>
                        <div class="bill-condition-text" data-bill="condition-text"></div>
                    </div>

                    <!-- Items received (Visible when entered) -->
                    <div class="bill-section" data-bill-wrap="items" hidden>
                        <div class="bill-section-label">ITEMS RECEIVED WITH DEVICE</div>
                        <div class="bill-items-text" data-bill="items-text"></div>
                    </div>

                    <!-- Inspection Checks (Visible when checks recorded) -->
                    <div class="bill-section" data-bill-wrap="inspection" hidden>
                        <div class="bill-section-label">INSPECTION RECORD</div>
                        <div class="bill-inspect-pills" data-bill="inspection-list"></div>
                    </div>

                    <!-- Financials / Estimation & Timeline -->
                    <div class="bill-divider-dashed"></div>
                    <div class="bill-financial-block">
                        <div class="bill-line-item">
                            <span>Estimated Cost:</span>
                            <strong class="bill-price font-mono" data-bill="estimate">{{ $currency }} 0.00</strong>
                        </div>
                        <div class="bill-line-item" data-bill-wrap="due-date" hidden>
                            <span>Expected By:</span>
                            <strong data-bill="due-date"></strong>
                        </div>
                        <div class="bill-line-item" data-bill-wrap="technician" hidden>
                            <span>Technician:</span>
                            <strong data-bill="technician"></strong>
                        </div>
                        <div class="bill-line-item" data-bill-wrap="warranty" hidden>
                            <span>Warranty:</span>
                            <strong data-bill="warranty"></strong>
                        </div>
                    </div>

                    <!-- Tracking Barcode Simulation -->
                    <div class="bill-divider-dashed"></div>
                    <div class="bill-tracking-slip">
                        <span class="tracking-kicker">CUSTOMER TRACKING CODE</span>
                        <div class="tracking-number font-mono" data-bill="tracking-code">{{ $draftTracking }}</div>
                        <div class="barcode-graphic" aria-hidden="true">
                            <span class="barcode-line w-2"></span>
                            <span class="barcode-line w-1"></span>
                            <span class="barcode-line w-3"></span>
                            <span class="barcode-line w-1"></span>
                            <span class="barcode-line w-2"></span>
                            <span class="barcode-line w-4"></span>
                            <span class="barcode-line w-1"></span>
                            <span class="barcode-line w-3"></span>
                            <span class="barcode-line w-2"></span>
                            <span class="barcode-line w-1"></span>
                            <span class="barcode-line w-3"></span>
                            <span class="barcode-line w-2"></span>
                            <span class="barcode-line w-4"></span>
                            <span class="barcode-line w-1"></span>
                            <span class="barcode-line w-2"></span>
                            <span class="barcode-line w-3"></span>
                            <span class="barcode-line w-1"></span>
                            <span class="barcode-line w-2"></span>
                        </div>
                        <small class="tracking-hint">Tracking barcode generated on saving</small>
                    </div>

                    <!-- Signatures placeholder -->
                    <div class="bill-signatures-row">
                        <div class="bill-signature-cell">
                            <div class="sig-line"></div>
                            <span>Customer Signature</span>
                        </div>
                        <div class="bill-signature-cell">
                            <div class="sig-line"></div>
                            <span>Staff Signature</span>
                        </div>
                    </div>

                    <!-- Hidden review elements for compatibility -->
                    <dl style="display:none" aria-hidden="true">
                        <div><dt>Customer</dt><dd data-review="customer">Select a customer</dd></div>
                        <div><dt>Device</dt><dd data-review="device">Choose a device</dd></div>
                        <div><dt>Reported issue</dt><dd data-review="issue">Add a reported issue</dd></div>
                    </dl>

                    <!-- Footer notice -->
                    <div class="bill-slip-footer">
                        <p>Keep this drop-off receipt to collect your device.</p>
                        <small class="powered-by">Powered by {{ config('app.developer.name', 'Twinsofte') }}</small>
                    </div>
                </div>
            </div>
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
