@extends('layouts.app')
@section('title', $job->invoiceNumber().' · Phone repair')
@section('content')
@php
    $editable = $job->editable();
    $canEdit = auth()->user()->hasPermission('repairs.edit');
    $canParts = auth()->user()->hasPermission('repairs.parts');
    $canPay = auth()->user()->hasPermission('repairs.pay');
    $canCorrect = auth()->user()->hasPermission('repairs.correct_payment');
    $closedWithoutInvoice = !$job->invoiced_at && !$editable;
    $canCost = auth()->user()->hasPermission('products.view_cost');
    $receipt = \App\Support\RepairReceipt::options();
    $statuses = \App\Support\Repairs::STATUS;
@endphp
<div class="repair-page repair-stack">
    <div class="page-heading"><div><span class="eyebrow">PHONE REPAIRS / {{ $job->invoiceNumber() }}</span>
        <h1>{{ $job->device->brand }} {{ $job->device->model }}</h1>
        <p>{{ $job->customer->name }} · Received {{ $job->received_at->format('d M Y') }}</p></div>
        <div class="repair-actions"><span class="repair-badge" data-status="{{ $job->status }}">{{ $statuses[$job->status] }}</span>
        <a class="btn secondary" href="{{ route('repairs.index') }}">All jobs</a>
        <button type="button" class="btn secondary" data-repair-open="repair-print"><x-icon name="printer"/>Print</button></div>
    </div>
    <dialog class="card repair-dialog" id="repair-print"><div class="repair-stack">
        <div class="card-heading"><h2>Print repair document</h2><button type="button" class="btn secondary" data-repair-close>Close</button></div>
        <form action="{{ route('repairs.print',[$job,'intake']) }}" target="_blank" class="repair-stack">
            <div class="repair-fields">
                <label class="field">Paper size<select name="size">@foreach(['80mm'=>'80 mm thermal','A5'=>'A5','A4'=>'A4'] as $size=>$label)<option value="{{ $size }}" @selected($receipt['size']===$size)>{{ $label }}</option>@endforeach</select></label>
                <label class="field">Layout<select name="layout"><option value="classic" @selected($receipt['layout']==='classic')>Standard</option><option value="compact" @selected($receipt['layout']==='compact')>Compact</option></select></label>
            </div>
            @foreach(['inspection'=>'Include inspection results','prices'=>'Include estimate','barcode'=>'Include tracking barcode'] as $key=>$label)
                <label class="repair-setting-check"><input type="hidden" name="{{ $key }}" value="0"><input type="checkbox" name="{{ $key }}" value="1" @checked($receipt[$key])>{{ $label }}</label>
            @endforeach
            <div class="repair-actions"><button class="btn primary">Preview intake receipt</button>
            @if($job->invoiced_at)<a class="btn secondary" href="{{ route('repairs.print',[$job,'invoice']) }}" target="_blank" rel="noopener">Open final invoice</a>@endif</div>
        </form>
    </div></dialog>
    <div class="repair-columns">
        <section class="card repair-panel repair-stack">
            <div class="card-heading"><h2>Intake details</h2></div>
            <dl class="repair-fields repair-data">
                <div><dt>Tracking number</dt><dd class="repair-tracking-code">{{ $job->tracking }}</dd></div>
                <div><dt>IMEI / serial</dt><dd>{{ $job->device->serial ?? '—' }}</dd></div>
                <div><dt>Color</dt><dd>{{ $job->device->color ?? '—' }}</dd></div>
                <div><dt>Accessories</dt><dd>{{ $job->accessories ?? '—' }}</dd></div>
                <div><dt>Physical condition</dt><dd>{{ $job->condition ?? '—' }}</dd></div>
                <div><dt>Reported faults</dt><dd>{{ $job->faults }}</dd></div>
                <div><dt>Internal notes</dt><dd>{{ $job->notes ?? '—' }}</dd></div>
                <div><dt>Estimated completion</dt><dd>{{ $job->due_at?->format('d M Y') ?? '—' }}</dd></div>
                <div><dt>Assigned technician</dt><dd>{{ $job->technician?->name ?? 'Unassigned' }}</dd></div>
                <div><dt>Warranty</dt><dd>{{ $job->warranty_until?->format('d M Y') ?? $job->warranty_days.' days' }}</dd></div>
                <div><dt>Estimate</dt><dd>{{ $job->estimate === null ? '—' : \App\Support\Money::displayPrice($job->estimate) }}</dd></div>
                <div><dt>Customer approval</dt><dd>{{ $job->approved_at?->format('d M Y H:i') ?? 'Pending' }}</dd></div>
            </dl>
            @if($job->checklist)
            <details><summary>Inspection checklist</summary>
                <div class="repair-inspect-grid repair-detail-form">@foreach($job->checklist as $check)
                    <div class="repair-check-card"><div class="repair-check-name"><strong>{{ $check['label'] }}</strong></div>
                    <div><span class="repair-badge" data-status="{{ ['Working'=>'READY','Not working'=>'UNREPAIRABLE'][$check['result'] ?? ''] ?? '' }}">{{ $check['result'] ?? 'Note only' }}</span></div>
                    @if(filled($check['fault'] ?? null))<span class="muted">{{ $check['fault'] }}</span>@endif</div>
                @endforeach</div>
            </details>
            @endif
            @can('repairs.unlock')
                @if($job->unlock_type)<div class="repair-actions"><button type="button" class="btn secondary" data-repair-unlock="{{ route('repairs.unlock',$job) }}">Reveal unlock credential</button><output data-unlock-output hidden></output></div>@endif
            @endcan
            @can('repairs.create')<a class="text-link" href="{{ route('repairs.create',['customer'=>$job->customer_id,'device'=>$job->device_id]) }}">Start a repeat repair</a>@endcan
        </section>
        <section class="card repair-panel repair-stack">
            <div class="card-heading"><h2>Repair workflow</h2></div>
            @if($canEdit && ($editable || $job->status === 'COMPLETED'))
                <form method="post" action="{{ route('repairs.update',$job) }}" class="repair-stack" data-workflow>@csrf @method('PUT')
                    <div class="repair-fields">
                        <label class="field">Status<select name="status">
                            @if($editable)<option value="{{ $job->status }}">{{ $statuses[$job->status] }}</option>@endif
                            @foreach(\App\Support\Repairs::transitions($job->status) as $next)<option value="{{ $next }}" @selected(old('status')===$next)>{{ $statuses[$next] }}</option>@endforeach
                        </select></label>
                        @if($editable)
                            @include('repairs.partials.assignment')
                            <label class="field">Estimated completion<input type="date" name="due_at" value="{{ old('due_at',$job->due_at?->toDateString()) }}"></label>
                            <label class="field">Estimate<input name="estimate" type="number" min="0" step=".01" value="{{ old('estimate',$job->estimate) }}"></label>
                            @foreach(['labor'=>'Labor','service_fee'=>'Service fee','discount'=>'Discount amount','tax'=>'Tax amount'] as $field=>$label)
                                <label class="field">{{ $label }}<input name="{{ $field }}" type="number" min="0" step=".01" value="{{ old($field,$job->$field) }}" required></label>
                            @endforeach
                        @endif
                    </div>
                    @if($editable)
                    <label class="field">Diagnostic findings<textarea name="diagnosis" rows="2" maxlength="4000">{{ old('diagnosis',$job->diagnosis) }}</textarea></label>
                    <label><input type="hidden" name="approved" value="0"><input type="checkbox" name="approved" value="1" @checked(old('approved',(bool)$job->approved_at))> Customer approved this estimate and charges</label>
                    @endif
                    @if(\App\Support\Repairs::sms())<label><input type="hidden" name="sms_opt_in" value="0"><input type="checkbox" name="sms_opt_in" value="1" @checked(old('sms_opt_in',$job->sms_opt_in))> Customer agrees to repair SMS</label>@endif
                    <label class="field">Technician note / status reason<textarea name="activity_note" rows="2" maxlength="4000">{{ old('activity_note') }}</textarea></label>
                    <div><button class="btn primary">{{ $editable ? 'Save job' : 'Mark delivered' }}</button></div>
                </form>
            @else
                <p class="muted">{{ $editable ? 'Job changes require repair editing access.' : 'Job details are locked.' }}</p>
                @if($job->diagnosis)<p>{{ $job->diagnosis }}</p>@endif
                @if($canEdit && \App\Support\Repairs::sms())
                <form method="post" action="{{ route('repairs.consent',$job) }}" class="repair-actions">@csrf @method('PUT')
                    <input type="hidden" name="sms_opt_in" value="0"><label><input type="checkbox" name="sms_opt_in" value="1" @checked($job->sms_opt_in)> Customer agrees to repair SMS</label>
                    <button class="btn secondary">Save consent</button>
                </form>
                @endif
            @endif
        </section>
    </div>
    @include('repairs.partials.parts')
    @include('repairs.partials.payments')
    <div class="repair-columns">
        <section class="card repair-panel repair-stack">
            <div class="card-heading"><h2>Attachments</h2></div>
            <ul class="repair-history">@forelse($job->attachments as $file)<li><a class="text-link" href="{{ route('repairs.download',[$job,$file]) }}">{{ $file->name }}</a></li>@empty<li class="muted">No files attached.</li>@endforelse</ul>
            @if($canEdit)<form class="repair-actions" method="post" enctype="multipart/form-data" action="{{ route('repairs.attach',$job) }}">@csrf
                <label class="field">Add PDF or photo<input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.webp" required></label><button class="btn secondary">Upload</button>
            </form>@endif
        </section>
        <section class="card repair-panel repair-stack">
            <div class="card-heading"><h2>Activity timeline</h2></div>
            <ul class="repair-history">@foreach($events as $event)
            <li><strong>{{ $event->public_status ? $statuses[$event->public_status] : ucfirst(strtolower($event->kind)) }}</strong> · {{ $event->user?->name }} · {{ $event->created_at->format('d M Y H:i') }}
                @if($event->note)<p>{{ $event->note }}</p>@endif
                @if($event->kind==='PAYMENT' && $event->details['before'])<p>{{ $event->details['before']['method_name'] }} {{ \App\Support\Money::displayPayment($event->details['before']['amount']) }} → {{ $event->details['after']['method_name'] }} {{ \App\Support\Money::displayPayment($event->details['after']['amount']) }}</p>
                <p class="muted">Reference {{ $event->details['before']['reference'] ?: '—' }} → {{ $event->details['after']['reference'] ?: '—' }}</p>@endif
            </li>
            @endforeach</ul>
            {{ $events->links() }}
        </section>
    </div>
</div>
@endsection
