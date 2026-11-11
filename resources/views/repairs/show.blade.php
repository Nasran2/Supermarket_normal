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
    $plannedPartsTotal = $plannedPartsTotal ?? Money::sum($job->parts->where('state', 'PLANNED')->map(fn ($p) => Money::mul($p->price, $p->quantity)));
    $activePartsTotal = Money::add($partsTotal, $plannedPartsTotal);
    $displayTotal = $job->invoiced_at
        ? $job->total
        : Money::add(Money::sub(Money::sum([$activePartsTotal, $job->labor, $job->service_fee]), $job->discount), $job->tax);
    $consumedChargesRaw = $job->invoiced_at
        ? Money::sub($job->total, $job->credited)
        : Money::add(Money::sub(Money::sum([$partsTotal, $job->labor, $job->service_fee]), $job->discount), $job->tax);
    $consumedChargesTotal = Money::compare($consumedChargesRaw, 0) < 0 ? '0.00' : $consumedChargesRaw;
    $remainingAdvance = (!$job->invoiced_at && Money::compare($job->paid, $consumedChargesTotal) > 0)
        ? Money::sub($job->paid, $consumedChargesTotal)
        : '0.00';
    $effectiveDue = $job->invoiced_at
        ? $job->due()
        : (Money::compare($consumedChargesTotal, $job->paid) > 0 ? Money::sub($consumedChargesTotal, $job->paid) : '0.00');

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
        <div class="tracking-hero-card">
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
                <x-icon name="smartphone" :size="48"/>
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
        <button type="button" class="job-tab-btn" data-tab-target="section-parts">
            <x-icon name="package" :size="15"/> Spare Parts
        </button>
        <button type="button" class="job-tab-btn" data-tab-target="section-work-details">
            <x-icon name="file-text" :size="15"/> Service &amp; Work Details
        </button>
        <button type="button" class="job-tab-btn" data-tab-target="section-inspection">
            <x-icon name="shield-check" :size="15"/> Inspection
        </button>
        <button type="button" class="job-tab-btn" data-tab-target="section-payments">
            <x-icon name="wallet" :size="15"/> Payments
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
        <!-- LEFT COLUMN: Spare Parts (first), Work Details & Service/Labor, Cost Summary, Activity Timeline -->
        <div class="job-left-column">
            <!-- Spare Parts Card (First Step Before Service & Labor) -->
            <section class="card job-card-section" id="section-parts">
                <div class="job-section-title space-between">
                    <div class="flex-title">
                        <span class="job-section-icon"><x-icon name="package" :size="16"/></span>
                        <div>
                            <h2>Spare Parts</h2>
                            <p class="job-section-subtitle">Add or consume spare parts first, then enter service &amp; other charges below.</p>
                        </div>
                    </div>
                    @if($editable && $canParts)
                        <button type="button" id="shortcut-add-part" class="btn primary btn-sm" data-repair-open="modal-add-part" aria-keyshortcuts="F2" title="Add Spare Part (F2)">
                            <x-icon name="plus" :size="14"/> Add Part <kbd class="repair-kbd on-primary">F2</kbd>
                        </button>
                    @endif
                </div>

                <div class="job-table-responsive">
                    <table class="job-data-table">
                        <thead>
                            <tr>
                                <th style="width: 36px;">#</th>
                                <th>Part / Description</th>
                                <th class="text-center" style="width: 64px;">Qty</th>
                                <th class="text-right no-wrap">Unit Price</th>
                                <th class="text-right no-wrap">Total</th>
                                <th class="text-right no-wrap">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($job->parts as $part)
                                <tr>
                                    <td>{{ $loop->iteration }}</td>
                                    <td>
                                        <strong>{{ $part->name }}</strong>
                                        <span class="part-state-tag {{ strtolower($part->state) }}">{{ ucfirst(strtolower($part->state)) }}</span>
                                    </td>
                                    <td class="text-center no-wrap">{{ (float)$part->quantity }}</td>
                                    <td class="text-right font-mono no-wrap">{{ $currency }} {{ Money::displayPrice($part->price) }}</td>
                                    <td class="text-right font-mono no-wrap"><strong>{{ $currency }} {{ Money::displayPrice(Money::mul($part->price, $part->quantity)) }}</strong></td>
                                    <td class="text-right no-wrap">
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
                                    <td colspan="6" class="table-empty-row">
                                        No spare parts added to this repair job yet.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($canCost && $job->costs->isNotEmpty())
                    <div class="outside-costs-list">
                        <h4>Outside Supplier &amp; Repair Costs</h4>
                        <ul>
                            @foreach($job->costs as $cost)
                                <li>{{ $cost->description }} · <strong>{{ $currency }} {{ Money::displayCost($cost->amount) }}</strong></li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if($editable && $canEdit && auth()->user()->hasPermission('expenses.create'))
                    <details class="outside-cost-details">
                        <summary>Record an outside repair or supplier cost</summary>
                        <form method="post" action="{{ route('repairs.cost', $job) }}" class="repair-fields repair-detail-form">
                            @csrf
                            <input type="hidden" name="submission" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                            <label class="field">Description<input name="description" maxlength="250" required></label>
                            <label class="field">Supplier<select name="supplier_id"><option value="">No linked supplier</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach</select></label>
                            <label class="field">Amount<input name="amount" type="number" min=".01" step=".01" required></label>
                            <label class="field">Paid by<select name="payment_method_id"><option value="">Accrued cost / no payment recorded</option>@foreach($methods as $method)<option value="{{ $method->id }}">{{ $method->name }}</option>@endforeach</select></label>
                            @if($banks->isNotEmpty())
                                <label class="field">Bank account (optional)<select name="bank_account_id"><option value="">No linked account</option>@foreach($banks as $bank)<option value="{{ $bank->id }}">{{ $bank->bank_name }} · {{ $bank->account_name }} · {{ substr($bank->account_number, -4) }}</option>@endforeach</select></label>
                            @endif
                            <div><button class="btn secondary">Record cost</button></div>
                        </form>
                    </details>
                @endif
            </section>

            <!-- Work Details Card (Service, Labor & Other Charges) -->
            <section class="card job-card-section" id="section-work-details">
                <div class="job-section-title space-between">
                    <div class="flex-title">
                        <span class="job-section-icon"><x-icon name="wrench" :size="16"/></span>
                        <div>
                            <h2>Work Details &amp; Service Charges</h2>
                            <p class="job-section-subtitle">Enter diagnostic notes, service (labor), and other charges after adding parts.</p>
                        </div>
                    </div>
                    <span class="work-parts-summary-pill">
                        <x-icon name="package" :size="13"/>
                        Parts: {{ $currency }} {{ Money::displayPrice($activePartsTotal) }}
                    </span>
                </div>

                @if($canEdit && ($editable || $job->status === 'COMPLETED'))
                    <form method="post" action="{{ route('repairs.update', $job) }}" class="job-work-form" data-workflow>
                        @csrf @method('PUT')
                        <div class="reported-fault-box">
                            <span class="reported-fault-kicker">Customer Complaint / Reported Faults</span>
                            <div>{{ $job->faults ?: 'No specific fault description provided.' }}</div>
                        </div>

                        @if($editable)
                            <label class="field">
                                <span class="field-title">Diagnostic Findings</span>
                                <textarea name="diagnosis" rows="2" maxlength="4000" placeholder="Describe diagnostic findings, root cause, or required repair actions...">{{ old('diagnosis', $job->diagnosis) }}</textarea>
                            </label>

                            <div class="work-subpanel">
                                <h3 class="work-subpanel-title">Service Charges &amp; Pricing</h3>
                                <div class="financial-inputs-grid">
                                    <label class="field">
                                        <span>Service (Labor) ({{ $currency }})</span>
                                        <input name="labor" type="number" min="0" step=".01" value="{{ old('labor', $job->labor) }}" data-cost-input="labor" required>
                                    </label>
                                    <label class="field">
                                        <span>Other Charges ({{ $currency }})</span>
                                        <input name="service_fee" type="number" min="0" step=".01" value="{{ old('service_fee', $job->service_fee) }}" data-cost-input="service_fee" required>
                                    </label>
                                    <label class="field">
                                        <span>Discount ({{ $currency }})</span>
                                        <input name="discount" type="number" min="0" step=".01" value="{{ old('discount', $job->discount) }}" data-cost-input="discount" required>
                                    </label>
                                    <label class="field">
                                        <span>Tax ({{ $currency }})</span>
                                        <input name="tax" type="number" min="0" step=".01" value="{{ old('tax', $job->tax) }}" data-cost-input="tax" required>
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
                            </div>

                            <div class="work-subpanel">
                                <h3 class="work-subpanel-title">Status, Assignment &amp; Schedule</h3>
                                <div class="work-schedule-grid">
                                    <label class="field">
                                        <span>Status</span>
                                        <select name="status">
                                            <option value="{{ $job->status }}">{{ $statuses[$job->status] }}</option>
                                            @foreach(Repairs::transitions($job->status) as $next)
                                                <option value="{{ $next }}" @selected(old('status')===$next)>{{ $statuses[$next] }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    @include('repairs.partials.assignment')
                                    <label class="field">
                                        <span>Estimated Completion</span>
                                        <input type="date" name="due_at" value="{{ old('due_at', $job->due_at?->toDateString()) }}">
                                    </label>
                                    <label class="field">
                                        <span>Estimate Cost ({{ $currency }})</span>
                                        <input name="estimate" type="number" min="0" step=".01" placeholder="Optional" value="{{ old('estimate', $job->estimate) }}">
                                    </label>
                                </div>

                                <label class="field">
                                    <span>Technician Note / Status Reason <small class="text-muted">(Optional)</small></span>
                                    <textarea name="activity_note" rows="2" maxlength="4000" placeholder="Optional notes for activity timeline">{{ old('activity_note') }}</textarea>
                                </label>
                            </div>

                            <div class="form-actions-bar">
                                <button class="btn primary"><x-icon name="check" :size="16"/> Save Changes / Update Job</button>
                            </div>
                        @else
                            @if($job->diagnosis)
                                <div class="form-field-group">
                                    <span class="field-title">Diagnostic Findings</span>
                                    <p class="diagnostic-text">{{ $job->diagnosis }}</p>
                                </div>
                            @endif
                            <div class="work-subpanel">
                                <h3 class="work-subpanel-title">Completion &amp; Delivery</h3>
                                <div class="work-schedule-grid">
                                    <label class="field">
                                        <span>Status</span>
                                        <select name="status">
                                            @foreach(Repairs::transitions($job->status) as $next)
                                                <option value="{{ $next }}" @selected(old('status', $next)===$next)>{{ $statuses[$next] }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                </div>
                                <label class="field">
                                    <span>Delivery / Completion Note <small class="text-muted">(Optional)</small></span>
                                    <textarea name="activity_note" rows="2" maxlength="4000" placeholder="Optional delivery note">{{ old('activity_note') }}</textarea>
                                </label>
                            </div>
                            <div class="form-actions-bar">
                                <button class="btn primary"><x-icon name="check" :size="16"/> Mark Delivered</button>
                            </div>
                        @endif
                    </form>
                @else
                    <div class="read-only-work-details">
                        <div class="reported-fault-box">
                            <span class="reported-fault-kicker">Customer Complaint / Reported Faults</span>
                            <div>{{ $job->faults ?: 'None' }}</div>
                        </div>
                        <div class="form-field-group" style="margin-top: .75rem;">
                            <span class="field-title">Diagnostic Findings</span>
                            <p class="diagnostic-text">{{ $job->diagnosis ?: 'No diagnostic notes recorded.' }}</p>
                        </div>
                    </div>
                @endif
            </section>

            <!-- Cost Summary Card -->
            <section class="card job-card-section" id="section-cost-summary">
                <div class="job-section-title space-between">
                    <div class="flex-title">
                        <span class="job-section-icon"><x-icon name="receipt-text" :size="16"/></span>
                        <h2>Cost Summary</h2>
                    </div>
                    @if(!$job->invoiced_at)
                        <span class="job-section-subtitle">Estimated before invoicing</span>
                    @endif
                </div>
                <div class="cost-summary-list">
                    <div class="cost-summary-row">
                        <span>Parts Total (Consumed)</span>
                        <span class="font-mono">{{ $currency }} {{ Money::displayPrice($partsTotal) }}</span>
                    </div>
                    @if(!$job->invoiced_at && Money::compare($plannedPartsTotal, 0) > 0)
                        <div class="cost-summary-row">
                            <span>Planned Parts (Pending Consume)</span>
                            <span class="font-mono">{{ $currency }} {{ Money::displayPrice($plannedPartsTotal) }}</span>
                        </div>
                    @endif
                    <div class="cost-summary-row">
                        <span>Service / Labor</span>
                        <span class="font-mono" data-summary-field="labor">{{ $currency }} {{ Money::displayPrice($job->labor) }}</span>
                    </div>
                    <div class="cost-summary-row">
                        <span>Other Charges</span>
                        <span class="font-mono" data-summary-field="service_fee">{{ $currency }} {{ Money::displayPrice($job->service_fee) }}</span>
                    </div>
                    <div class="cost-summary-row">
                        <span>Discount</span>
                        <span class="font-mono text-danger" data-summary-field="discount">- {{ $currency }} {{ Money::displayPrice($job->discount) }}</span>
                    </div>
                    <div class="cost-summary-row">
                        <span>Tax</span>
                        <span class="font-mono" data-summary-field="tax">{{ $currency }} {{ Money::displayPrice($job->tax) }}</span>
                    </div>
                    <div class="cost-summary-total-bar">
                        <span>{{ $job->invoiced_at ? 'Total Amount' : 'Estimated Total' }}</span>
                        <strong class="font-mono" data-summary-total data-invoiced="{{ $job->invoiced_at ? '1' : '0' }}" data-parts-base="{{ (float)$activePartsTotal }}" data-currency="{{ $currency }}">{{ $currency }} {{ Money::displayPrice($displayTotal) }}</strong>
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

        <!-- RIGHT COLUMN: Quick Actions, Inspection Summary, Payments -->
        <div class="job-right-column">
            <!-- Quick Actions Card -->
            <section class="card job-card-section" id="section-quick-actions">
                <div class="job-section-title space-between">
                    <div class="flex-title">
                        <span class="job-section-icon"><x-icon name="zap" :size="16"/></span>
                        <h2>Quick Actions</h2>
                    </div>
                    <span class="repair-shortcut-hint">Shortcuts active</span>
                </div>

                <div class="quick-actions-grid">
                    @if($editable && $canEdit)
                        <button type="button" id="shortcut-update-status" class="quick-action-btn" data-repair-open="modal-update-status" aria-keyshortcuts="F8" title="Update Status (F8)">
                            <x-icon name="refresh-cw" :size="18"/>
                            <span>Update Status</span>
                            <kbd class="repair-kbd">F8</kbd>
                        </button>
                    @endif
                    @if($editable && $canParts)
                        <button type="button" class="quick-action-btn" data-repair-open="modal-add-part" aria-keyshortcuts="F2" title="Add Part (F2)">
                            <x-icon name="package" :size="18"/>
                            <span>Add Part</span>
                            <kbd class="repair-kbd">F2</kbd>
                        </button>
                    @endif
                    @if($canPay && !$closedWithoutInvoice && (!$job->invoiced_at || Money::compare($job->due(), 0) > 0))
                        <button type="button" class="quick-action-btn" data-repair-open="repair-payment" aria-keyshortcuts="F4" title="Take Payment (F4)">
                            <x-icon name="credit-card" :size="18"/>
                            <span>Take Payment</span>
                            <kbd class="repair-kbd">F4</kbd>
                        </button>
                    @endif
                    <a href="{{ route('repairs.print', [$job, 'intake', 'layout' => 'classic']) }}" target="_blank" class="quick-action-btn">
                        <x-icon name="file-text" :size="18"/>
                        <span>Job Sheet</span>
                    </a>
                </div>

                @if($canEdit && ($editable || $job->status === 'COMPLETED') && $nextPrimaryAction)
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
                            <button id="btn-next-status-action" class="btn btn-action-primary" aria-keyshortcuts="F9" title="{{ $nextPrimaryAction['btn'] }} (F9)">
                                {{ $nextPrimaryAction['btn'] }}
                                <kbd class="repair-kbd on-success">F9</kbd>
                            </button>
                        </form>
                    </div>
                @endif
            </section>

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
                    <div class="repair-actions">
                        @if($canCorrect && !$closedWithoutInvoice && Money::compare($job->paid, 0) > 0)
                            <button type="button" class="btn secondary btn-sm" data-repair-open="repair-refund" title="Record refund">
                                Refund
                            </button>
                        @endif
                        @if($canPay && !$closedWithoutInvoice && (!$job->invoiced_at || Money::compare($job->due(), 0) > 0))
                            <button type="button" id="shortcut-take-payment" class="btn primary btn-sm" data-repair-open="repair-payment" aria-keyshortcuts="F4" title="Take Payment (F4)">
                                <x-icon name="plus" :size="14"/> Take Payment <kbd class="repair-kbd on-primary">F4</kbd>
                            </button>
                        @endif
                    </div>
                </div>

                @if($canViewPayments)
                <div class="job-table-responsive">
                    <table class="job-data-table job-table-compact">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Method</th>
                                <th class="text-right">Amount</th>
                                <th class="text-center">Status</th>
                                <th class="text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($job->payments->sortByDesc('id') as $payment)
                                <tr>
                                    <td class="no-wrap">
                                        <div>{{ $payment->created_at->format('d M Y') }}</div>
                                        <small class="text-muted">{{ $payment->created_at->format('h:i A') }}</small>
                                    </td>
                                    <td>{{ $payment->method_name }}</td>
                                    <td class="text-right font-mono no-wrap">
                                        <strong>{{ $currency }} {{ Money::displayPayment($payment->amount) }}</strong>
                                    </td>
                                    <td class="text-center no-wrap">
                                        <span class="payment-badge {{ strtolower($payment->kind) }}">
                                            {{ ucfirst(strtolower($payment->kind)) }}
                                        </span>
                                    </td>
                                    <td class="text-right no-wrap">
                                        <div class="table-actions">
                                            <a href="{{ route('repairs.print', [$job, 'payment', 'payment' => $payment->id, 'size' => '80mm']) }}" target="_blank" class="btn btn-sm secondary" title="Print payment receipt">
                                                <x-icon name="printer" :size="13"/>
                                            </a>
                                            @if($canCorrect && !$closedWithoutInvoice && in_array($payment->kind, ['ADVANCE', 'PAYMENT', 'CORRECTION']) && $payment->method_type !== 'CHEQUE' && !$payment->reversal)
                                                <button type="button" class="btn btn-sm secondary" data-repair-open="repair-correction-{{ $payment->id }}" title="Correct payment">
                                                    <x-icon name="pencil" :size="13"/>
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="table-empty-row">No payments recorded for this repair.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @endif

                <div class="payments-totals-block">
                    <div class="payment-total-row">
                        <span>{{ !$job->invoiced_at && Money::compare($job->paid, 0) > 0 ? 'Advance Paid' : 'Total Paid' }}</span>
                        <strong class="text-success font-mono">{{ $currency }} {{ Money::displayPayment($job->paid) }}</strong>
                    </div>
                    <div class="payment-total-row">
                        <span>{{ $job->invoiced_at ? 'Invoice Total (Net)' : 'Consumed Charges (Deducted)' }}</span>
                        <strong class="font-mono text-danger" data-payment-used>- {{ $currency }} {{ Money::displayPrice($consumedChargesTotal) }}</strong>
                    </div>
                    @if(!$job->invoiced_at && Money::compare($job->paid, 0) > 0)
                        <div class="payment-total-row" data-payment-advance-row @if(Money::compare($remainingAdvance, 0) <= 0) hidden @endif>
                            <span>Remaining Advance Balance</span>
                            <strong class="font-mono text-primary" data-payment-advance>{{ $currency }} {{ Money::displayPayment($remainingAdvance) }}</strong>
                        </div>
                    @endif
                    <div class="payment-total-row">
                        <span>{{ $job->invoiced_at ? 'Balance Due' : 'Estimated Balance Due' }}</span>
                        <strong class="{{ Money::compare($effectiveDue, 0) > 0 ? 'text-danger' : 'text-success' }} font-mono" data-payment-due data-paid-amount="{{ (float)$job->paid }}" data-consumed-parts="{{ (float)$partsTotal }}" data-invoiced="{{ $job->invoiced_at ? '1' : '0' }}" data-currency="{{ $currency }}">
                            {{ $currency }} {{ $job->invoiced_at ? Money::displayCustomerAmount($effectiveDue) : Money::displayPrice($effectiveDue) }}
                        </strong>
                    </div>
                </div>

                @if($heldCheques->isNotEmpty())
                    <div class="pending-cheques-callout">
                        <x-icon name="clock" :size="16"/>
                        <ul>
                            @foreach($heldCheques as $cheque)
                                <li>
                                    Pending cheque · {{ $currency }} {{ Money::displayCheque($cheque->amount) }} · {{ $cheque->cheque_date->format('d M Y') }}
                                    @can('cheques.view')
                                        <a class="text-link" href="{{ route('cheques.show', $cheque) }}">Review cheque</a>
                                    @endcan
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if($canCorrect)
                    <div class="payment-notice-callout">
                        <x-icon name="info" :size="16"/>
                        <p>Use <strong>Correct payment</strong> to replace a mistaken entry. The original and the reason stay in the payment history.</p>
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
                
                <div class="field-title-with-action">
                    <span class="field-label-text">Search Repair Product / SKU</span>
                    @can('products.create')
                        <button type="button" class="btn secondary btn-sm" data-open-quick-product>
                            <x-icon name="plus" :size="13"/> New Product
                        </button>
                    @endcan
                </div>

                <div class="repair-picker" data-repair-picker="{{ route('repairs.products') }}" data-picker-type="product">
                    <label class="field">
                        <span class="sr-only">Search Repair Product / SKU</span>
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

    @can('products.create')
        <!-- Quick Add Product Popup Modal -->
        <dialog class="card repair-dialog" id="modal-quick-add-product" style="max-width: 540px;">
            <div class="repair-stack">
                <div class="card-heading">
                    <div>
                        <h2>New Repair Product / Part</h2>
                        <p class="muted">Add a new spare part or product to inventory and auto-select it.</p>
                    </div>
                    <button type="button" class="btn secondary" data-repair-close>Close</button>
                </div>
                <form data-new-repair-product action="{{ route('repairs.products.store') }}" class="repair-stack">
                    @csrf
                    <div class="repair-fields">
                        <label class="field full">Product Name <b class="text-danger">*</b>
                            <input name="name" required maxlength="255" placeholder="e.g. iPhone 11 Pro Display Replacement" data-product-name-input autofocus>
                        </label>
                    </div>
                    <div class="repair-fields">
                        <label class="field">SKU <small class="muted">(auto if blank)</small>
                            <input name="sku" maxlength="255" placeholder="e.g. DISP-IP11P">
                        </label>
                        <label class="field">Unit <b class="text-danger">*</b>
                            <select name="unit_id" required>
                                @foreach($units ?? [] as $u)
                                    <option value="{{ $u->id }}" @selected($loop->first || strtolower($u->name) === 'piece')>{{ $u->name }} ({{ $u->short_name }})</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                    <div class="repair-fields">
                        <label class="field">Selling Price ({{ $currency }}) <b class="text-danger">*</b>
                            <input type="number" name="price" step=".01" min="0" placeholder="0.00" required>
                        </label>
                        @can('products.view_cost')
                            <label class="field">Cost Price ({{ $currency }})
                                <input type="number" name="cost" step=".01" min="0" placeholder="0.00" value="0.00">
                            </label>
                        @endcan
                    </div>
                    <div class="repair-fields">
                        <label class="field">Opening Stock Quantity
                            <input type="number" name="stock" step=".001" min="0" value="1" placeholder="1">
                        </label>
                        <label class="field">Low Stock Alert Level
                            <input type="number" name="low_stock" step=".001" min="0" value="0" placeholder="0">
                        </label>
                    </div>
                    <p class="text-danger" data-product-error role="alert" hidden></p>
                    <div class="repair-actions">
                        <button class="btn primary" type="submit">Create & Select Part</button>
                        <button type="button" class="btn secondary" data-repair-close>Cancel</button>
                    </div>
                </form>
            </div>
        </dialog>
    @endcan
@endif

<!-- 3. Take Payment & Refund Modals -->
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

@if($canCorrect && !$closedWithoutInvoice && Money::compare($job->paid, 0) > 0)
    <dialog class="card repair-dialog" id="repair-refund">
        @include('repairs.partials.payment-form', [
            'title' => 'Record Refund',
            'action' => route('repairs.payment', [$job, 'refund']),
            'reason' => true,
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

    // Live preview for Cost Summary and Payments when editing Service/Labor, Other Charges, Discount, Tax
    const summaryTotalEl = document.querySelector('[data-summary-total]');
    const paymentDueEl = document.querySelector('[data-payment-due]');
    const costInputs = document.querySelectorAll('[data-cost-input]');
    if (summaryTotalEl && summaryTotalEl.dataset.invoiced !== '1' && costInputs.length) {
        const currency = summaryTotalEl.dataset.currency || 'Rs.';
        const partsBase = parseFloat(summaryTotalEl.dataset.partsBase || '0') || 0;
        const consumedPartsBase = parseFloat(paymentDueEl?.dataset.consumedParts || '0') || 0;
        const paidAmount = parseFloat(paymentDueEl?.dataset.paidAmount || '0') || 0;
        const fmt = (val) => (Number.isFinite(val) ? val : 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const updateSummary = () => {
            const values = {};
            costInputs.forEach((input) => {
                const key = input.dataset.costInput;
                const num = parseFloat(input.value || '0') || 0;
                values[key] = num;
                const target = document.querySelector('[data-summary-field="' + key + '"]');
                if (target) {
                    target.textContent = (key === 'discount' ? '- ' : '') + currency + ' ' + fmt(num);
                }
            });
            const total = Math.max(0, partsBase + (values.labor || 0) + (values.service_fee || 0) - (values.discount || 0) + (values.tax || 0));
            summaryTotalEl.textContent = currency + ' ' + fmt(total);

            if (paymentDueEl && paymentDueEl.dataset.invoiced !== '1') {
                const consumedCharges = Math.max(0, consumedPartsBase + (values.labor || 0) + (values.service_fee || 0) - (values.discount || 0) + (values.tax || 0));
                const usedEl = document.querySelector('[data-payment-used]');
                if (usedEl) usedEl.textContent = '- ' + currency + ' ' + fmt(consumedCharges);

                const remainingAdv = Math.max(0, paidAmount - consumedCharges);
                const advRow = document.querySelector('[data-payment-advance-row]');
                const advEl = document.querySelector('[data-payment-advance]');
                if (advRow && advEl) {
                    advRow.hidden = remainingAdv <= 0;
                    advEl.textContent = currency + ' ' + fmt(remainingAdv);
                }

                const due = Math.max(0, consumedCharges - paidAmount);
                paymentDueEl.textContent = currency + ' ' + fmt(due);
                paymentDueEl.classList.toggle('text-danger', due > 0);
                paymentDueEl.classList.toggle('text-success', due <= 0);
            }
        };
        costInputs.forEach((input) => input.addEventListener('input', updateSummary));
    }

    // Focus primary field when repair modal opens (click or shortcut)
    const focusDialogPrimaryField = (dialogId) => {
        const selectors = {
            'modal-add-part': '[data-picker-query]',
            'repair-payment': 'input[name="amount"]',
            'repair-refund': 'input[name="amount"]',
            'modal-update-status': 'select[name="status"]',
            'repair-cancel-dialog': 'textarea[name="activity_note"]',
        };
        const dialog = document.getElementById(dialogId);
        const field = dialog?.querySelector(selectors[dialogId] || 'input, select, textarea');
        if (field) {
            requestAnimationFrame(() => {
                field.focus();
                if (typeof field.select === 'function' && field.tagName === 'INPUT') {
                    field.select();
                }
            });
        }
    };

    document.querySelectorAll('[data-repair-open]').forEach((btn) => {
        btn.addEventListener('click', () => focusDialogPrimaryField(btn.dataset.repairOpen));
    });

    // Keyboard shortcuts: F2 Add Part, F4 Take Payment, F8 Change Status, F9 Next Status Action
    document.addEventListener('keydown', (e) => {
        const openDialog = document.querySelector('dialog[open]');
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter' && openDialog) {
            const form = openDialog.querySelector('form');
            if (form) {
                e.preventDefault();
                form.requestSubmit();
            }
            return;
        }
        if (e.ctrlKey || e.metaKey || e.altKey || e.shiftKey || openDialog) {
            return;
        }
        const actions = {
            F2: 'shortcut-add-part',
            F4: 'shortcut-take-payment',
            F8: 'shortcut-update-status',
            F9: 'btn-next-status-action',
        };
        const targetId = actions[e.key];
        if (!targetId) return;
        const target = document.getElementById(targetId);
        if (target) {
            e.preventDefault();
            target.click();
        }
    });
});
</script>
@endsection
