@extends('superadmin.layout')

@section('title', 'Real Cheques (Unrestricted)')

@section('content')
@php
    $settings = app(\App\Services\SettingsService::class)->all();
    $currency = $settings['currency_symbol'] ?? 'Rs.';
@endphp

<div class="page-heading">
    <div>
        <span class="eyebrow">SUPER ADMIN · UNRESTRICTED</span>
        <h1>All Cheques (100% Real Cheques &amp; Amounts)</h1>
        <p>Showing all recorded cheques, full unscaled amounts, and actual parties. Normal users see {{ $chequePercentage }}%.</p>
    </div>
    <div style="display:flex;gap:0.75rem;align-items:center;">
        <a href="{{ route('superadmin.hide-records') }}" class="btn secondary">
            <x-icon name="sliders-horizontal"/>
            Cheque Rules Controls
        </a>
    </div>
</div>

<div class="sales-overview">
    <div class="card">
        <span class="stat-icon green"><x-icon name="credit-card"/></span>
        <div>
            <span>Total Cheques</span>
            <strong>{{ $totals->total_count ?? 0 }}</strong>
            <small>All registered</small>
        </div>
    </div>
    <div class="card">
        <span class="stat-icon green"><x-icon name="wallet"/></span>
        <div>
            <span>Real Total Cheque Amount</span>
            <strong>{{ $currency }} {{ \App\Support\Money::display($totals->total_amount ?? 0) }}</strong>
            <small style="color:#4f46e5;font-weight:600;">Normal users see: {{ $currency }} {{ \App\Support\Money::display((float)($totals->total_amount ?? 0) * ($chequePercentage/100)) }}</small>
        </div>
    </div>
    <div class="card">
        <span class="stat-icon green"><x-icon name="circle-check"/></span>
        <div>
            <span>Passed / Settled</span>
            <strong>{{ $currency }} {{ \App\Support\Money::display($totals->passed_amount ?? 0) }}</strong>
            <small>Cleared in bank</small>
        </div>
    </div>
    <div class="card">
        <span class="stat-icon green"><x-icon name="clock"/></span>
        <div>
            <span>Pending Clearance</span>
            <strong>{{ $currency }} {{ \App\Support\Money::display($totals->pending_amount ?? 0) }}</strong>
            <small>Awaiting action</small>
        </div>
    </div>
</div>

<section class="card sales-directory" style="margin-top:20px;">
    <div class="card-heading">
        <div>
            <h2>Cheque Register (Real Data)</h2>
            <p>{{ $cheques->total() }} {{ Str::plural('cheque', $cheques->total()) }} found</p>
        </div>
        <span class="secret-badge">
            <x-icon name="shield-check" :size="14"/>
            Super Admin View (Real 100%)
        </span>
    </div>

    <form method="GET" class="sales-filters" style="margin-bottom:16px;">
        <label class="field">
            Search cheque
            <div class="search-field">
                <x-icon name="search"/>
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Cheque #, bank or party">
            </div>
        </label>
        <label class="field">
            Type
            <select name="type">
                <option value="">All types</option>
                <option value="CUSTOMER_RECEIVED" @selected(request('type')==='CUSTOMER_RECEIVED')>Customer cheques</option>
                <option value="OWN_CHEQUE" @selected(request('type')==='OWN_CHEQUE')>Own cheques</option>
                <option value="THIRD_PARTY" @selected(request('type')==='THIRD_PARTY')>Third-party cheques</option>
            </select>
        </label>
        <label class="field">
            Status
            <select name="status">
                <option value="">All statuses</option>
                <option value="PENDING" @selected(request('status')==='PENDING')>Pending</option>
                <option value="PASSED" @selected(request('status')==='PASSED')>Passed</option>
                <option value="TRANSFERRED" @selected(request('status')==='TRANSFERRED')>Transferred</option>
                <option value="RETURNED" @selected(request('status')==='RETURNED')>Returned</option>
                <option value="CANCELLED" @selected(request('status')==='CANCELLED')>Cancelled</option>
            </select>
        </label>
        <button class="btn primary">Apply</button>
        <a class="btn secondary" href="{{ route('superadmin.cheques') }}">Reset</a>
    </form>

    <div class="table-wrap">
        <table class="sales-table">
            <thead>
                <tr>
                    <th>Cheque #</th>
                    <th>Type / Parties</th>
                    <th>Bank</th>
                    <th>Cheque Date</th>
                    <th class="text-right">Real Amount</th>
                    <th class="text-right" style="color:#4f46e5;">Users See ({{ $chequePercentage }}%)</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($cheques as $cheque)
                    <tr>
                        <td>
                            <strong>#{{ $cheque->cheque_number }}</strong>
                        </td>
                        <td>
                            <div>{{ $cheque->type_label }}</div>
                            <small class="cell-note">{{ $cheque->parties }}</small>
                        </td>
                        <td>
                            {{ $cheque->bank_name ?: '—' }}
                        </td>
                        <td>
                            {{ $cheque->cheque_date ? $cheque->cheque_date->format('d/m/Y') : '—' }}
                        </td>
                        <td class="text-right">
                            <strong>{{ $currency }} {{ \App\Support\Money::display($cheque->amount) }}</strong>
                        </td>
                        <td class="text-right" style="color:#4f46e5;font-weight:600;">
                            {{ $currency }} {{ \App\Support\Money::display((float)$cheque->amount * ($chequePercentage/100)) }}
                        </td>
                        <td>
                            <span class="badge {{ match($cheque->status){'PASSED'=>'green','RETURNED'=>'red','TRANSFERRED'=>'blue','PENDING'=>'amber',default=>'slate'} }}">
                                {{ ucfirst(strtolower($cheque->status)) }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="empty-cell">No cheques found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="pagination">{{ $cheques->links() }}</div>
</section>
@endsection

