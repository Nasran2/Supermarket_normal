@extends('layouts.app')
@section('title', $job->invoiceNumber().' · Phone repair')
@section('content')
@php
    use App\Support\Money;
    use App\Support\Repairs;

    $editable = $job->editable();
    $canEdit = auth()->user()->hasPermission('repairs.edit');
    $canParts = auth()->user()->hasPermission('repairs.parts');
    $canPay = auth()->user()->hasPermission('repairs.pay');
    $canCorrect = auth()->user()->hasPermission('repairs.correct_payment');
    $closedWithoutInvoice = !$job->invoiced_at && !$editable;
    $canCost = auth()->user()->hasPermission('products.view_cost');
    $receipt = \App\Support\RepairReceipt::options();
    $statuses = Repairs::STATUS;
    $settings = app(\App\Services\SettingsService::class)->all();
    $currency = $settings['currency_symbol'] ?? 'Rs.';

    // Progress milestone index
    // 0: Received, 1: Diagnosing, 2: In Progress, 3: Ready, 4: Completed
    $currentStepIndex = match($job->status) {
        'RECEIVED' => 0,
        'DIAGNOSING', 'WAITING_APPROVAL', 'WAITING_PARTS' => 1,
        'IN_PROGRESS' => 2,
        'READY' => 3,
        'COMPLETED', 'DELIVERED' => 4,
        default => 2,
    };
    $isCancelledOrUnrepairable = in_array($job->status, ['CANCELLED', 'UNREPAIRABLE']);

    // Map inspection checklist items for summary cards
    $checklistItems = collect($job->checklist ?? []);
    $inspectionMap = $checklistItems->keyBy('label');
    $coreChecks = ['Display' => 'Screen', 'Touch' => 'Touch', 'Battery' => 'Battery', 'Charging' => 'Charging', 'Front camera' => 'Camera', 'Speaker' => 'Speaker'];

    // Next primary status action
    $nextPrimaryAction = match($job->status) {
        'RECEIVED' => ['status' => 'DIAGNOSING', 'label' => 'Start Diagnosing', 'sub' => 'Confirm device condition and diagnose issues.', 'btn' => 'Diagnose Device'],
        'DIAGNOSING', 'WAITING_APPROVAL', 'WAITING_PARTS' => ['status' => 'IN_PROGRESS', 'label' => 'Start Repair Work', 'sub' => 'Begin active repair and technician work.', 'btn' => 'Start Repair Work'],
        'IN_PROGRESS' => ['status' => 'READY', 'label' => 'Mark as Ready', 'sub' => 'Set this repair as ready for customer collection.', 'btn' => 'Mark as Ready'],
        'READY' => ['status' => 'COMPLETED', 'label' => 'Finalize & Invoice', 'sub' => 'Complete the repair, lock charges, and finalize invoice.', 'btn' => 'Finalize Invoice'],
        'COMPLETED' => ['status' => 'DELIVERED', 'label' => 'Mark Delivered', 'sub' => 'Hand over the device and complete delivery.', 'btn' => 'Mark Delivered'],
        default => null,
    };
@endphp

<div class="repair-job-card-view">
    <!-- Top Bar Navigation -->
    <div class="job-card-top-nav">
        <a class="btn secondary btn-sm" href="{{ route('repairs.index') }}">
            <x-icon name="arrow-left" :size="15"/> Back to Jobs
        </a>
    </div>

    <!-- Job Header Card -->
    <div class="card job-header-card">
        <div class="job-header-left">
            <div class="job-header-icon-box">
                <x-icon name="smartphone" :size="26"/>
            </div>
            <div>
                <div class="job-header-title-row">
                    <h1>Repair Job – {{ $job->invoiceNumber() }}</h1>
                    <span class="repair-badge" data-status="{{ $job->status }}">{{ $statuses[$job->status] }}</span>
                </div>
                <p class="job-header-meta">
                    Created: {{ $job->created_at->format('d M Y, h:i A') }}
                    <span class="meta-sep">|</span>
                    Last Update: {{ $job->updated_at->format('d M Y, h:i A') }}
                </p>
            </div>
        </div>
        <div class="job-header-actions">
            <button type="button" class="btn secondary" data-repair-open="repair-print">
                <x-icon name="printer" :size="15"/> Print
            </button>
            <a class="btn secondary" href="{{ route('repairs.print', [$job, 'intake', 'layout' => 'classic']) }}" target="_blank" rel="noopener">
                <x-icon name="file-text" :size="15"/> Job Sheet
            </a>
            @if($editable && $canEdit)
                <a class="btn secondary" href="#section-work-details">
                    <x-icon name="pencil" :size="15"/> Edit Job
                </a>
                <button type="button" class="btn btn-danger-soft" data-repair-open="repair-cancel-dialog">
                    <x-icon name="circle-x" :size="15"/> Cancel Job
                </button>
            @endif
        </div>
    </div>

    <!-- Hero Row: Tracking Number + Status Stepper -->
    <div class="job-hero-grid">
        <!-- Tracking Banner Card -->
        <div class="card tracking-hero-card">
            <div class="tracking-hero-content">
                <span class="tracking-hero-kicker">Tracking Number</span>
                <div class="tracking-hero-number-wrap">
                    <span class="tracking-hero-number font-mono" id="tracking-code-text">{{ $job->tracking }}</span>
                    <button type="button" class="tracking-copy-btn" data-copy-tracking title="Copy tracking number">
                        <x-icon name="copy" :size="16"/>
                    </button>
                </div>
            </div>
            <div class="tracking-hero-graphic" aria-hidden="true">
                <div class="phone-3d-glow"></div>
                <x-icon name="smartphone" :size="64"/>
            </div>
        </div>

        <!-- Status Stepper Card -->
        <div class="card status-stepper-card">
            <div class="stepper-header">
                <span class="stepper-kicker"><x-icon name="wrench" :size="15"/> Status</span>
                <strong class="stepper-status-title">{{ $statuses[$job->status] }}</strong>
            </div>

            @if($isCancelledOrUnrepairable)
                <div class="stepper-alert-cancelled">
                    <x-icon name="circle-alert" :size="18"/>
                    <span>This repair was closed as <strong>{{ $statuses[$job->status] }}</strong>.</span>
                </div>
            @else
                <div class="repair-milestone-stepper">
                    @php
                        $milestones = [
                            ['label' => 'Received'],
                            ['label' => 'Diagnosing'],
                            ['label' => 'In Progress'],
                            ['label' => 'Ready'],
                            ['label' => 'Completed'],
                        ];
                    @endphp
                    @foreach($milestones as $idx => $m)
                        @php
                            $state = $idx < $currentStepIndex ? 'completed' : ($idx === $currentStepIndex ? 'active' : 'upcoming');
                        @endphp
                        <div class="milestone-node {{ $state }}">
                            <div class="milestone-circle">
                                @if($state === 'completed')
                                    <x-icon name="check" :size="14"/>
                                @elseif($state === 'active')
                                    <span class="milestone-pulse"></span>
                                @else
                                    <span class="milestone-number">{{ $idx + 1 }}</span>
                                @endif
                            </div>
                            <span class="milestone-text">{{ $m['label'] }}</span>
                            @if(!$loop->last)
                                <div class="milestone-line {{ $idx < $currentStepIndex ? 'completed' : '' }}"></div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <!-- 3 Context Information Cards -->
    <div class="job-context-grid">
        <!-- Customer Card -->
        <div class="card context-card">
            <div class="context-card-header">
                <span class="context-card-icon"><x-icon name="user" :size="16"/></span>
                <h3>Customer</h3>
            </div>
            <div class="context-customer-info">
                <div class="context-customer-name-row">
                    <strong>{{ $job->customer->name }}</strong>
                    <span class="cust-badge {{ $customerTotalJobs > 1 ? 'existing' : 'new' }}">
                        {{ $customerTotalJobs > 1 ? 'Existing Customer' : 'New Customer' }}
                    </span>
                </div>
                <div class="context-customer-phone">
                    <x-icon name="phone" :size="13"/>
                    <span>{{ $job->customer->phone ?? 'No phone added' }}</span>
                </div>
                <div class="context-stats-grid">
                    <div class="context-stat-box">
                        <strong>{{ $customerDeviceCount }}</strong>
                        <small>Devices</small>
                    </div>
                    <div class="context-stat-box">
                        <strong>{{ $customerOngoingCount }}</strong>
                        <small>Ongoing</small>
                    </div>
                    <div class="context-stat-box">
                        <strong class="{{ Money::compare($customerDue, 0) > 0 ? 'text-danger' : 'text-success' }}">
                            {{ $currency }} {{ Money::displayCustomerAmount($customerDue) }}
                        </strong>
                        <small>Due Balance</small>
                    </div>
                </div>
                @can('customers.view')
                    <a href="{{ route('manage.show', ['resource' => 'customers', 'id' => $job->customer_id]) }}" class="context-card-action-link" target="_blank">
                        <x-icon name="history" :size="14"/> View Customer History
                    </a>
                @endcan
            </div>
        </div>

        <!-- Device Details Card -->
        <div class="card context-card">
            <div class="context-card-header">
                <span class="context-card-icon"><x-icon name="smartphone" :size="16"/></span>
                <h3>Device Details</h3>
            </div>
            <div class="context-device-body">
                <div class="context-device-thumb">
                    <x-icon name="smartphone" :size="38"/>
                </div>
                <div class="context-device-details">
                    <h4 class="context-device-name">{{ $job->device->brand }} {{ $job->device->model }}</h4>
                    <dl class="context-meta-list">
                        <div><dt>IMEI / Serial</dt><dd class="font-mono">{{ $job->device->serial ?: '—' }}</dd></div>
                        <div><dt>Color</dt><dd>{{ $job->device->color ?: '—' }}</dd></div>
                        <div><dt>Accessories</dt><dd>{{ $job->accessories ?: '—' }}</dd></div>
                    </dl>
                </div>
            </div>
            @can('repairs.unlock')
                @if($job->unlock_type)
                    <div class="context-unlock-bar">
                        <button type="button" class="btn secondary btn-sm" data-repair-unlock="{{ route('repairs.unlock', $job) }}">
                            <x-icon name="key" :size="13"/> Reveal Unlock Credential
                        </button>
                        <output data-unlock-output hidden class="unlock-credential-output"></output>
                    </div>
                @endif
            @endcan
        </div>

        <!-- Job Schedule Card -->
        <div class="card context-card">
            <div class="context-card-header">
                <span class="context-card-icon"><x-icon name="calendar" :size="16"/></span>
                <h3>Job Schedule</h3>
            </div>
            <dl class="context-schedule-list">
                <div>
                    <dt>Estimated Completion</dt>
                    <dd>{{ $job->due_at ? $job->due_at->format('d M Y') : 'Not set' }}</dd>
                </div>
                <div>
                    <dt>Technician</dt>
                    <dd>{{ $job->technician?->name ?? 'Unassigned' }}</dd>
                </div>
                <div>
                    <dt>Warranty</dt>
                    <dd>{{ $job->warranty_until ? $job->warranty_until->format('d M Y') : ($job->warranty_days ? $job->warranty_days.' Days' : '0 Days') }}</dd>
                </div>
                <div>
                    <dt>Priority</dt>
                    <dd>
                        <span class="priority-pill {{ strtolower($job->priority ?? 'NORMAL') }}">
                            {{ ucfirst(strtolower($job->priority ?? 'NORMAL')) }}
                        </span>
                    </dd>
                </div>
            </dl>
        </div>
    </div>

    <!-- Navigation Tabs Bar -->
    <div class="job-tabs-bar">
        <button type="button" class="job-tab-btn active" data-tab-target="all">
            <x-icon name="layout-dashboard" :size="15"/> Overview
        </button>
        <button type="button" class="job-tab-btn" data-tab-target="section-inspection">
            <x-icon name="shield-check" :size="15"/> Inspection
        </button>
        <button type="button" class="job-tab-btn" data-tab-target="section-parts">
            <x-icon name="package" :size="15"/> Parts &amp; Labor
        </button>
        <button type="button" class="job-tab-btn" data-tab-target="section-payments">
            <x-icon name="wallet" :size="15"/> Payments
        </button>
        <button type="button" class="job-tab-btn" data-tab-target="section-work-details">
            <x-icon name="file-text" :size="15"/> Work Details
        </button>
        <button type="button" class="job-tab-btn" data-tab-target="section-attachments">
            <x-icon name="paperclip" :size="15"/> Attachments
        </button>
        <button type="button" class="job-tab-btn" data-tab-target="section-history">
            <x-icon name="history" :size="15"/> Activity History
        </button>
    </div>

    <!-- Main 2-Column Content Layout -->
    <div class="job-main-columns">
        <!-- LEFT COLUMN: Work Details, Spare Parts, Cost Summary, Activity Timeline -->
        <div class="job-left-column">
            <!-- Work Details Card -->
            <section class="card job-card-section" id="section-work-details">
                <div class="job-section-title">
                    <span class="job-section-icon"><x-icon name="wrench" :size="16"/></span>
                    <h2>Work Details</h2>
                </div>

                @if($canEdit && ($editable || $job->status === 'COMPLETED'))
                    <form method="post" action="{{ route('repairs.update', $job) }}" class="job-work-form" data-workflow>
                        @csrf @method('PUT')
                        <div class="form-field-group">
                            <label class="field">
                                <span class="field-title">Customer Complaint / Reported Faults</span>
                                <div class="reported-fault-box">{{ $job->faults ?: 'No specific fault description provided.' }}</div>
                            </label>
                        </div>

                        <div class="form-field-group">
                            <label class="field">
                                <span class="field-title">Diagnostic Findings</span>
                                <textarea name="diagnosis" rows="2" maxlength="4000" placeholder="Describe diagnostic findings, root cause, or required repair actions...">{{ old('diagnosis', $job->diagnosis) }}</textarea>
                            </label>
                        </div>

                        @if($editable)
                            <div class="financial-inputs-grid">
                                <label class="field">
                                    <span>Service (Labor) ({{ $currency }})</span>
                                    <input name="labor" type="number" min="0" step=".01" value="{{ old('labor', $job->labor) }}" required>
                                </label>
                                <label class="field">
                                    <span>Other Charges ({{ $currency }})</span>
                                    <input name="service_fee" type="number" min="0" step=".01" value="{{ old('service_fee', $job->service_fee) }}" required>
                                </label>
                                <label class="field">
                                    <span>Discount ({{ $currency }})</span>
                                    <input name="discount" type="number" min="0" step=".01" value="{{ old('discount', $job->discount) }}" required>
                                </label>
                                <label class="field">
                                    <span>Tax ({{ $currency }})</span>
                                    <input name="tax" type="number" min="0" step=".01" value="{{ old('tax', $job->tax) }}" required>
                                </label>
                            </div>

                            <div class="repair-fields" style="margin-top:12px;">
                                <label class="field">Status
                                    <select name="status">
                                        <option value="{{ $job->status }}">{{ $statuses[$job->status] }}</option>
                                        @foreach(Repairs::transitions($job->status) as $next)
                                            <option value="{{ $next }}" @selected(old('status')===$next)>{{ $statuses[$next] }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                @include('repairs.partials.assignment')
                                <label class="field">Estimated Completion
                                    <input type="date" name="due_at" value="{{ old('due_at', $job->due_at?->toDateString()) }}">
                                </label>
                                <label class="field">Estimate Cost
                                    <input name="estimate" type="number" min="0" step=".01" value="{{ old('estimate', $job->estimate) }}">
                                </label>
                            </div>

                            <div class="checkbox-options-row">
                                <label class="job-check-label">
                                    <input type="hidden" name="approved" value="0">
                                    <input type="checkbox" name="approved" value="1" @checked(old('approved', (bool)$job->approved_at))>
                                    <span>Customer approved this estimate and charges</span>
                                </label>
                                @if(Repairs::sms())
                                    <label class="job-check-label">
                                        <input type="hidden" name="sms_opt_in" value="0">
                                        <input type="checkbox" name="sms_opt_in" value="1" @checked(old('sms_opt_in', $job->sms_opt_in))>
                                        <span>Customer agrees to repair updates by SMS</span>
                                    </label>
                                @endif
                            </div>

                            <label class="field" style="margin-top:10px;">
                                <span>Technician Note / Status Reason <small class="text-muted">(Optional)</small></span>
                                <textarea name="activity_note" rows="2" maxlength="4000" placeholder="Optional notes for activity timeline">{{ old('activity_note') }}</textarea>
                            </label>

                            <div class="form-actions-bar">
                                <button class="btn primary"><x-icon name="check" :size="16"/> Save Changes / Update Job</button>
                            </div>
                        @endif
                    </form>
                @else
                    <div class="read-only-work-details">
                        <div class="form-field-group">
                            <span class="field-title">Customer Complaint / Reported Faults</span>
                            <div class="reported-fault-box">{{ $job->faults ?: 'None' }}</div>
                        </div>
                        <div class="form-field-group">
                            <span class="field-title">Diagnostic Findings</span>
                            <p class="diagnostic-text">{{ $job->diagnosis ?: 'No diagnostic notes recorded.' }}</p>
                        </div>
                    </div>
                @endif
            </section>

            <!-- Spare Parts Card -->
            <section class="card job-card-section" id="section-parts">
                <div class="job-section-title space-between">
                    <div class="flex-title">
                        <span class="job-section-icon"><x-icon name="package" :size="16"/></span>
                        <h2>Spare Parts</h2>
                    </div>
                    @if($editable && $canParts)
                        <button type="button" class="btn primary btn-sm" data-repair-open="modal-add-part">
                            <x-icon name="plus" :size="14"/> Add Part
                        </button>
                    @endif
                </div>

                <div class="job-table-responsive">
                    <table class="job-data-table">
                        <thead>
                            <tr>
                                <th style="width: 36px;">#</th>
                                <th>Part / Description</th>
                                <th class="text-center" style="width: 70px;">Qty</th>
                                <th class="text-right">Unit Price</th>
                                <th class="text-right">Total</th>
                                <th class="text-right" style="width: 120px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($job->parts as $part)
                                <tr>
                                    <td>{{ $loop->iteration }}</td>
                                    <td>
                                        <strong>{{ $part->name }}</strong>
                                        <div class="part-state-tag {{ strtolower($part->state) }}">{{ ucfirst(strtolower($part->state)) }}</div>
                                    </td>
                                    <td class="text-center">{{ (float)$part->quantity }}</td>
                                    <td class="text-right font-mono">{{ $currency }} {{ Money::displayPrice($part->price) }}</td>
                                    <td class="text-right font-mono"><strong>{{ $currency }} {{ Money::displayPrice(Money::mul($part->price, $part->quantity)) }}</strong></td>
                                    <td class="text-right">
                                        @if($editable && $canParts && $part->state !== 'REMOVED')
                                            <div class="table-actions">
                                                @if($part->state === 'PLANNED')
                                                    <form method="post" action="{{ route('repairs.parts.change', [$job, $part, 'consume']) }}" data-repair-confirm="Deduct this part from store inventory?">
                                                        @csrf
                                                        <button class="btn btn-sm secondary" title="Consume part">Consume</button>
                                                    </form>
                                                @endif
                                                <form method="post" action="{{ route('repairs.parts.change', [$job, $part, 'remove']) }}" data-repair-confirm="Remove this part and restore any deducted inventory?">
                                                    @csrf
                                                    <button class="btn btn-sm danger-soft" title="Remove part"><x-icon name="trash-2" :size="14"/></button>
                                                </form>
                                            </div>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="table-empty-row">No spare parts added to this repair job yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($job->costs->isNotEmpty())
                    <div class="outside-costs-list">
                        <h4>Outside Supplier &amp; Repair Costs</h4>
                        <ul>
                            @foreach($job->costs as $cost)
                                <li>{{ $cost->description }} · <strong>{{ $currency }} {{ Money::displayCost($cost->amount) }}</strong></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </section>

            <!-- Cost Summary Card -->
            <section class="card job-card-section" id="section-cost-summary">
                <div class="job-section-title">
                    <span class="job-section-icon"><x-icon name="receipt-text" :size="16"/></span>
                    <h2>Cost Summary</h2>
                </div>
                <div class="cost-summary-list">
                    <div class="cost-summary-row">
                        <span>Parts Total</span>
                        <span class="font-mono">{{ $currency }} {{ Money::displayPrice($partsTotal) }}</span>
                    </div>
                    <div class="cost-summary-row">
                        <span>Service / Labor</span>
                        <span class="font-mono">{{ $currency }} {{ Money::displayPrice($job->labor) }}</span>
                    </div>
                    <div class="cost-summary-row">
                        <span>Other Charges</span>
                        <span class="font-mono">{{ $currency }} {{ Money::displayPrice($job->service_fee) }}</span>
                    </div>
                    <div class="cost-summary-row">
                        <span>Discount</span>
                        <span class="font-mono text-danger">- {{ $currency }} {{ Money::displayPrice($job->discount) }}</span>
                    </div>
                    <div class="cost-summary-row">
                        <span>Tax</span>
                        <span class="font-mono">{{ $currency }} {{ Money::displayPrice($job->tax) }}</span>
                    </div>
                    <div class="cost-summary-total-bar">
                        <span>Total Amount</span>
                        <strong class="font-mono">{{ $currency }} {{ Money::displayPrice($job->total) }}</strong>
                    </div>
                </div>
            </section>

            <!-- Activity Timeline Card -->
            <section class="card job-card-section" id="section-history">
                <div class="job-section-title">
                    <span class="job-section-icon"><x-icon name="history" :size="16"/></span>
                    <h2>Activity Timeline</h2>
                </div>
                <div class="job-timeline">
                    @forelse($events as $event)
                        <div class="timeline-entry">
                            <div class="timeline-indicator">
                                <span class="timeline-node-dot"></span>
                            </div>
                            <div class="timeline-body">
                                <div class="timeline-header-row">
                                    <strong class="timeline-event-name">
                                        {{ $event->public_status ? $statuses[$event->public_status] : ucfirst(strtolower($event->kind)) }}
                                    </strong>
                                    <span class="timeline-timestamp">{{ $event->created_at->format('d M Y, h:i A') }}</span>
                                </div>
                                <p class="timeline-user">By {{ $event->user?->name ?? 'System' }}</p>
                                @if($event->note)
                                    <p class="timeline-note">{{ $event->note }}</p>
                                @endif
                                @if($event->kind === 'PAYMENT' && isset($event->details['before'], $event->details['after']))
                                    <p class="timeline-payment-change">
                                        {{ $event->details['before']['method_name'] ?? 'Payment' }} {{ Money::displayPayment($event->details['before']['amount'] ?? 0) }}
                                        → {{ $event->details['after']['method_name'] ?? 'Payment' }} {{ Money::displayPayment($event->details['after']['amount'] ?? 0) }}
                                    </p>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-muted">No timeline activity recorded yet.</p>
                    @endforelse
                </div>
                @if($events->hasPages())
                    <div class="timeline-pagination">
                        {{ $events->links() }}
                    </div>
                @endif
            </section>

            <!-- Attachments Card -->
            <section class="card job-card-section" id="section-attachments">
                <div class="job-section-title">
                    <span class="job-section-icon"><x-icon name="paperclip" :size="16"/></span>
                    <h2>Attachments</h2>
                </div>
                <ul class="job-attachments-list">
                    @forelse($job->attachments as $file)
                        <li>
                            <x-icon name="file" :size="16"/>
                            <a href="{{ route('repairs.download', [$job, $file]) }}" class="text-link" target="_blank">{{ $file->name }}</a>
                        </li>
                    @empty
                        <li class="text-muted">No documents or photos attached.</li>
                    @endforelse
                </ul>
                @if($canEdit && $editable)
                    <form class="attachment-upload-form" method="post" enctype="multipart/form-data" action="{{ route('repairs.attach', $job) }}">
                        @csrf
                        <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.webp" required>
                        <button class="btn secondary btn-sm"><x-icon name="upload" :size="14"/> Upload Document</button>
                    </form>
                @endif
            </section>
        </div>

        <!-- RIGHT COLUMN: Inspection Summary, Payments, Quick Actions -->
        <div class="job-right-column">
            <!-- Inspection Summary Card -->
            <section class="card job-card-section" id="section-inspection">
                <div class="job-section-title">
                    <span class="job-section-icon"><x-icon name="shield-check" :size="16"/></span>
                    <h2>Inspection Summary</h2>
                </div>

                <div class="inspection-cards-grid">
                    @foreach($coreChecks as $key => $title)
                        @php
                            $check = $inspectionMap->get($key);
                            $result = $check['result'] ?? 'Not tested';
                            $stateClass = match($result) {
                                'Working' => 'working',
                                'Not working' => 'not-working',
                                default => 'skipped',
                            };
                            $iconName = match($key) {
                                'Display' => 'monitor',
                                'Touch' => 'touchpad',
                                'Battery' => 'battery-charging',
                                'Charging' => 'zap',
                                'Front camera' => 'camera',
                                'Speaker' => 'volume-2',
                                default => 'shield-check',
                            };
                        @endphp
                        <div class="inspection-item-box">
                            <div class="inspection-item-header">
                                <x-icon :name="$iconName" :size="16"/>
                                <span class="inspection-item-title">{{ $title }}</span>
                            </div>
                            <div class="inspection-result-badge {{ $stateClass }}">
                                @if($result === 'Working')
                                    <x-icon name="check" :size="12"/> Working
                                @elseif($result === 'Not working')
                                    <x-icon name="x" :size="12"/> Not Working
                                @else
                                    <x-icon name="minus" :size="12"/> Skipped
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                <button type="button" class="btn secondary btn-block" data-repair-open="repair-full-inspection-modal">
                    <x-icon name="list-checks" :size="15"/> View Full Inspection
                </button>
            </section>

            <!-- Payments Card -->
            <section class="card job-card-section" id="section-payments">
                <div class="job-section-title space-between">
                    <div class="flex-title">
                        <span class="job-section-icon"><x-icon name="wallet" :size="16"/></span>
                        <h2>Payments</h2>
                    </div>
                    @if($canPay && !$closedWithoutInvoice && (!$job->invoiced_at || Money::compare($job->due(), 0) > 0))
                        <button type="button" class="btn primary btn-sm" data-repair-open="repair-payment">
                            <x-icon name="plus" :size="14"/> Take Payment
                        </button>
                    @endif
                </div>

                <div class="job-table-responsive">
                    <table class="job-data-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Date &amp; Time</th>
                                <th>Method</th>
                                <th class="text-right">Amount</th>
                                <th class="text-center">Status</th>
                                <th class="text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($job->payments->sortByDesc('id') as $payment)
                                <tr>
                                    <td>{{ $loop->iteration }}</td>
                                    <td>
                                        <div>{{ $payment->created_at->format('d M Y') }}</div>
                                        <small class="text-muted">{{ $payment->created_at->format('h:i A') }}</small>
                                    </td>
                                    <td>{{ $payment->method_name }}</td>
                                    <td class="text-right font-mono">
                                        <strong>{{ $currency }} {{ Money::displayPayment($payment->amount) }}</strong>
                                    </td>
                                    <td class="text-center">
                                        <span class="payment-badge {{ strtolower($payment->kind) }}">
                                            {{ ucfirst(strtolower($payment->kind)) }}
                                        </span>
                                    </td>
                                    <td class="text-right">
                                        <a href="{{ route('repairs.print', [$job, 'payment', 'payment' => $payment->id, 'size' => '80mm']) }}" target="_blank" class="btn btn-sm secondary" title="Print payment receipt">
                                            <x-icon name="printer" :size="13"/>
                                        </a>
                                        @if($canCorrect && !$closedWithoutInvoice && in_array($payment->kind, ['ADVANCE', 'PAYMENT', 'CORRECTION']) && $payment->method_type !== 'CHEQUE' && !$payment->reversal)
                                            <button type="button" class="btn btn-sm secondary" data-repair-open="repair-correction-{{ $payment->id }}" title="Correct payment">
                                                <x-icon name="pencil" :size="13"/>
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="table-empty-row">No payments recorded for this repair.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="payments-totals-block">
                    <div class="payment-total-row">
                        <span>Total Paid</span>
                        <strong class="text-success font-mono">{{ $currency }} {{ Money::displayPayment($job->paid) }}</strong>
                    </div>
                    <div class="payment-total-row">
                        <span>Balance Due</span>
                        <strong class="text-danger font-mono">{{ $currency }} {{ Money::displayCustomerAmount($job->due()) }}</strong>
                    </div>
                </div>

                <div class="payment-notice-callout">
                    <x-icon name="info" :size="16"/>
                    <div>
                        <strong>Cancel or edit a payment?</strong>
                        <p>You can correct a payment (if allowed) and it will be updated in the receipt, stock, and accounts with reason logging.</p>
                    </div>
                </div>
            </section>

            <!-- Quick Actions Card -->
            <section class="card job-card-section" id="section-quick-actions">
                <div class="job-section-title">
                    <span class="job-section-icon"><x-icon name="zap" :size="16"/></span>
                    <h2>Quick Actions</h2>
                </div>

                <div class="quick-actions-grid">
                    <button type="button" class="quick-action-btn" data-repair-open="modal-update-status">
                        <x-icon name="refresh-cw" :size="18"/>
                        <span>Update Status</span>
                    </button>
                    @if($editable && $canParts)
                        <button type="button" class="quick-action-btn" data-repair-open="modal-add-part">
                            <x-icon name="package" :size="18"/>
                            <span>Add Part</span>
                        </button>
                    @endif
                    @if($canPay && !$closedWithoutInvoice && (!$job->invoiced_at || Money::compare($job->due(), 0) > 0))
                        <button type="button" class="quick-action-btn" data-repair-open="repair-payment">
                            <x-icon name="credit-card" :size="18"/>
                            <span>Take Payment</span>
                        </button>
                    @endif
                    <a href="{{ route('repairs.print', [$job, 'intake', 'layout' => 'classic']) }}" target="_blank" class="quick-action-btn">
                        <x-icon name="file-text" :size="18"/>
                        <span>Job Sheet</span>
                    </a>
                </div>

                @if($editable && $nextPrimaryAction)
                    <div class="next-action-banner">
                        <div class="next-action-info">
                            <div class="next-action-icon"><x-icon name="check-circle" :size="20"/></div>
                            <div>
                                <strong>{{ $nextPrimaryAction['label'] }}</strong>
                                <small>{{ $nextPrimaryAction['sub'] }}</small>
                            </div>
                        </div>
                        <form method="post" action="{{ route('repairs.update', $job) }}" data-workflow>
                            @csrf @method('PUT')
                            <input type="hidden" name="status" value="{{ $nextPrimaryAction['status'] }}">
                            <button class="btn btn-action-primary">{{ $nextPrimaryAction['btn'] }}</button>
                        </form>
                    </div>
                @endif
            </section>
        </div>
    </div>
</div>

<!-- MODALS & DIALOGS -->

<!-- 1. Print Dialog -->
<dialog class="card repair-dialog" id="repair-print">
    <div class="repair-stack">
        <div class="card-heading">
            <h2>Print Repair Document</h2>
            <button type="button" class="btn secondary" data-repair-close>Close</button>
        </div>
        <form action="{{ route('repairs.print', [$job, 'intake']) }}" target="_blank" class="repair-stack">
            <div class="repair-fields">
                <label class="field">Paper size
                    <select name="size">
                        @foreach(['80mm' => '80 mm thermal', 'A5' => 'A5', 'A4' => 'A4'] as $size => $label)
                            <option value="{{ $size }}" @selected($receipt['size'] === $size)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="field">Layout
                    <select name="layout">
                        <option value="classic" @selected($receipt['layout'] === 'classic')>Standard</option>
                        <option value="compact" @selected($receipt['layout'] === 'compact')>Compact</option>
                    </select>
                </label>
            </div>
            @foreach(['inspection' => 'Include inspection results', 'prices' => 'Include estimate', 'barcode' => 'Include tracking barcode'] as $key => $label)
                <label class="repair-setting-check">
                    <input type="hidden" name="{{ $key }}" value="0">
                    <input type="checkbox" name="{{ $key }}" value="1" @checked($receipt[$key])>{{ $label }}
                </label>
            @endforeach
            <div class="repair-actions">
                <button class="btn primary">Preview Intake Receipt</button>
                @if($job->invoiced_at)
                    <a class="btn secondary" href="{{ route('repairs.print', [$job, 'invoice']) }}" target="_blank" rel="noopener">Open Final Invoice</a>
                @endif
            </div>
        </form>
    </div>
</dialog>

<!-- 2. Add Part Modal -->
@if($editable && $canParts)
    <dialog class="card repair-dialog" id="modal-add-part">
        <div class="repair-stack">
            <div class="card-heading">
                <h2>Add Spare Part</h2>
                <button type="button" class="btn secondary" data-repair-close>Close</button>
            </div>
            <form method="post" action="{{ route('repairs.parts.add', $job) }}" class="repair-stack" data-repair-part>
                @csrf
                <input type="hidden" name="submission" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                <div class="repair-picker" data-repair-picker="{{ route('repairs.products') }}">
                    <label class="field">Search Repair Product / SKU
                        <input data-picker-query type="search" autocomplete="off" placeholder="Type product name or SKU" required>
                    </label>
                    <input type="hidden" name="product_id" data-picker-value>
                    <div class="repair-picker-results" data-picker-results hidden></div>
                </div>
                <div class="repair-fields">
                    <label class="field">Quantity
                        <input type="number" name="quantity" value="1" step=".001" min=".001" required>
                    </label>
                    <label class="field">Unit Price ({{ $currency }})
                        <input type="number" name="price" step=".01" min="0" placeholder="Product default">
                    </label>
                </div>
                <div class="repair-actions">
                    <button class="btn primary">Add to Job</button>
                    <button type="button" class="btn secondary" data-repair-close>Cancel</button>
                </div>
            </form>
        </div>
    </dialog>
@endif

<!-- 3. Take Payment Modal -->
@if($canPay && !$closedWithoutInvoice && (!$job->invoiced_at || Money::compare($job->due(), 0) > 0))
    <dialog class="card repair-dialog" id="repair-payment">
        @include('repairs.partials.payment-form', [
            'title' => $job->invoiced_at ? 'Collect Payment' : 'Take Advance Payment',
            'action' => route('repairs.payment', [$job, 'collect']),
            'reason' => false,
            'dialog' => true,
            'original' => null
        ])
    </dialog>
@endif

<!-- 4. Payment Corrections -->
@foreach($job->payments as $payment)
    @if($canCorrect && !$closedWithoutInvoice && in_array($payment->kind, ['ADVANCE', 'PAYMENT', 'CORRECTION']) && $payment->method_type !== 'CHEQUE' && !$payment->reversal)
        <dialog class="card repair-dialog" id="repair-correction-{{ $payment->id }}">
            @include('repairs.partials.payment-form', [
                'title' => 'Correct Payment',
                'action' => route('repairs.payment.correct', [$job, $payment]),
                'reason' => true,
                'dialog' => true,
                'original' => $payment
            ])
        </dialog>
    @endif
@endforeach

<!-- 5. Full Inspection Checklist Modal -->
<dialog class="card repair-dialog" id="repair-full-inspection-modal">
    <div class="repair-stack">
        <div class="card-heading">
            <h2>Full Device Inspection Checklist</h2>
            <button type="button" class="btn secondary" data-repair-close>Close</button>
        </div>
        <div class="job-table-responsive" style="max-height: 60vh; overflow-y: auto;">
            <table class="job-data-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Status</th>
                        <th>Note / Fault</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($checklistItems as $check)
                        <tr>
                            <td><strong>{{ $check['label'] }}</strong></td>
                            <td>
                                <span class="inspection-result-badge {{ strtolower(str_replace(' ', '-', $check['result'] ?? 'skipped')) }}">
                                    {{ $check['result'] ?? 'Not tested' }}
                                </span>
                            </td>
                            <td>{{ $check['fault'] ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="table-empty-row">No inspection checks recorded.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div>
            <button type="button" class="btn secondary" data-repair-close>Close</button>
        </div>
    </div>
</dialog>

<!-- 6. Quick Update Status Modal -->
@if($editable && $canEdit)
    <dialog class="card repair-dialog" id="modal-update-status">
        <div class="repair-stack">
            <div class="card-heading">
                <h2>Update Repair Status</h2>
                <button type="button" class="btn secondary" data-repair-close>Close</button>
            </div>
            <form method="post" action="{{ route('repairs.update', $job) }}" class="repair-stack" data-workflow>
                @csrf @method('PUT')
                <label class="field">New Status
                    <select name="status">
                        <option value="{{ $job->status }}">{{ $statuses[$job->status] }} (Current)</option>
                        @foreach(Repairs::transitions($job->status) as $next)
                            <option value="{{ $next }}">{{ $statuses[$next] }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="field">Reason / Note <small class="text-muted">(Optional)</small>
                    <textarea name="activity_note" rows="2" placeholder="Note explaining this status update"></textarea>
                </label>
                <div class="repair-actions">
                    <button class="btn primary">Update Status</button>
                    <button type="button" class="btn secondary" data-repair-close>Cancel</button>
                </div>
            </form>
        </div>
    </dialog>
@endif

<!-- 7. Cancel Repair Job Dialog -->
@if($editable && $canEdit)
    <dialog class="card repair-dialog" id="repair-cancel-dialog">
        <div class="repair-stack">
            <div class="card-heading">
                <h2>Cancel Repair Job</h2>
                <button type="button" class="btn secondary" data-repair-close>Close</button>
            </div>
            <form method="post" action="{{ route('repairs.update', $job) }}" class="repair-stack" data-workflow>
                @csrf @method('PUT')
                <input type="hidden" name="status" value="CANCELLED">
                <p>Are you sure you want to cancel repair job <strong>{{ $job->invoiceNumber() }}</strong>? Stored unlock credentials and plans will be closed.</p>
                <label class="field">Cancellation Reason
                    <textarea name="activity_note" rows="2" placeholder="Explain why this repair is being cancelled..." required></textarea>
                </label>
                <div class="repair-actions">
                    <button class="btn btn-danger-solid">Confirm Cancel Repair</button>
                    <button type="button" class="btn secondary" data-repair-close>Keep Active</button>
                </div>
            </form>
        </div>
    </dialog>
@endif

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Copy tracking number
    const copyBtn = document.querySelector('[data-copy-tracking]');
    if (copyBtn) {
        copyBtn.addEventListener('click', () => {
            const code = document.getElementById('tracking-code-text')?.textContent.trim();
            if (code && navigator.clipboard) {
                navigator.clipboard.writeText(code).then(() => {
                    const original = copyBtn.innerHTML;
                    copyBtn.innerHTML = '<span style="font-size:11px;font-weight:700;color:#22c55e;">Copied!</span>';
                    setTimeout(() => { copyBtn.innerHTML = original; }, 2000);
                });
            }
        });
    }

    // Interactive Tab filtering / smooth scroll
    const tabBtns = document.querySelectorAll('.job-tab-btn');
    tabBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            tabBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            const targetId = btn.dataset.tabTarget;
            if (targetId === 'all') {
                document.querySelectorAll('.job-card-section').forEach(sec => sec.style.display = '');
                window.scrollTo({ top: 0, behavior: 'smooth' });
            } else {
                const targetEl = document.getElementById(targetId);
                if (targetEl) {
                    targetEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    targetEl.style.transition = 'box-shadow 0.3s ease';
                    targetEl.style.boxShadow = '0 0 0 3px #6366f1';
                    setTimeout(() => { targetEl.style.boxShadow = ''; }, 1500);
                }
            }
        });
    });
});
</script>
@endsection
