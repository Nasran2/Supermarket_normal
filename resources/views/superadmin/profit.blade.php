@extends('superadmin.layout')

@section('title', 'Real Profit & Loss (Unrestricted)')

@section('content')
@php
    $settings = app(\App\Services\SettingsService::class)->all();
    $currency = $settings['currency_symbol'] ?? 'Rs.';
@endphp

<div class="page-heading">
    <div>
        <span class="eyebrow">SUPER ADMIN · UNRESTRICTED</span>
        <h1>Profit &amp; Loss (100% Real Numbers)</h1>
        <p>Real income statement and actual profits from database. Normal users and admin see {{ $percentage }}% or hidden.</p>
    </div>
    <div style="display:flex;gap:0.75rem;align-items:center;">
        <a href="{{ route('superadmin.controls') }}" class="btn secondary">
            <x-icon name="sliders-horizontal"/>
            Controls ({{ $percentage }}%)
        </a>
    </div>
</div>

<div class="stat-grid mb-6">
    <div class="card stat-card">
        <div class="stat-top">Real Net Sales Revenue</div>
        <strong>{{ $currency }} {{ \App\Support\Money::display($summary['revenue']) }}</strong>
        <small style="color:#4f46e5;font-weight:600;">Normal users: {{ $currency }} {{ \App\Support\Money::display((float)$summary['revenue'] * ($percentage/100)) }}</small>
    </div>
    <div class="card stat-card">
        <div class="stat-top">Real Cost of Goods Sold</div>
        <strong>{{ $currency }} {{ \App\Support\Money::display($summary['cogs']) }}</strong>
        <small style="color:#4f46e5;font-weight:600;">Normal users: {{ $currency }} {{ \App\Support\Money::display((float)$summary['cogs'] * ($percentage/100)) }}</small>
    </div>
    <div class="card stat-card">
        <div class="stat-top">Real Gross Profit</div>
        <strong>{{ $currency }} {{ \App\Support\Money::display($summary['gross']) }}</strong>
        <small style="color:#4f46e5;font-weight:600;">Normal users: {{ $currency }} {{ \App\Support\Money::display((float)$summary['gross'] * ($percentage/100)) }}</small>
    </div>
    <div class="card stat-card">
        <div class="stat-top">Real Net Profit</div>
        <strong>{{ $currency }} {{ \App\Support\Money::display($summary['net']) }}</strong>
        <small style="color:#4f46e5;font-weight:600;">Normal users: {{ $currency }} {{ \App\Support\Money::display((float)$summary['net'] * ($percentage/100)) }}</small>
    </div>
</div>

<div class="card report-data-card profit-statement">
    <div class="card-heading">
        <div>
            <h2>Income statement (Real Data)</h2>
            <p class="muted">100% unrestricted revenue, costs and operating expenses for this period.</p>
        </div>
        <span class="tile-icon"><x-icon name="trending-up"/></span>
    </div>

    <form class="filter-bar report-filter-bar" method="GET">
        <label class="field compact">From
            <input type="date" name="from" value="{{ $from }}" required>
        </label>
        <label class="field compact">To
            <input type="date" name="to" value="{{ $to }}" required>
        </label>
        <button class="btn secondary">Apply filters</button>
        <a class="text-link" href="{{ route('superadmin.profit') }}">Reset</a>
    </form>

    <div class="profit-lines">
        @foreach([
            'grossSales'            => 'Gross sales',
            'salesReturns'          => 'Less sales returns',
            'revenue'               => 'Net sales revenue',
            'cogs'                  => 'Cost of goods sold',
            'gross'                 => 'Gross profit',
            'manual'                => 'Other operating expenses',
            'processing'            => 'Business-paid processing expenses',
            'purchaseCharges'       => 'Purchase shipping & other charges',
            'returnWriteoffs'       => 'Return write-off expenses',
            'noReceiptCredit'       => 'No-receipt approved credit (unverified)',
            'estimatedRecovery'     => 'Estimated returned inventory / supplier claim cost',
            'noReceiptAdjustments'  => 'No-receipt return adjustment (credit less estimated recovery)',
            'expenses'              => 'Total operating expenses',
            'net'                   => 'Net profit'
        ] as $key => $label)
            <div class="{{ in_array($key, ['gross', 'net']) ? 'profit-total' : '' }} {{ $key === 'net' ? 'net' : '' }}" style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid var(--border);">
                <span>{{ $label }}</span>
                <div style="text-align:right;">
                    <strong>{{ $currency }} {{ \App\Support\Money::display($summary[$key]) }}</strong>
                    <div style="font-size:11px;color:#4f46e5;">Users: {{ $currency }} {{ \App\Support\Money::display((float)$summary[$key] * ($percentage/100)) }}</div>
                </div>
            </div>
        @endforeach
    </div>
</div>
@endsection

