@extends('superadmin.layout')

@section('title', 'Overview')

@section('content')
@php
    $settings = app(\App\Services\SettingsService::class)->all();
    $currency = $settings['currency_symbol'] ?? 'Rs.';
@endphp

<div class="page-heading">
    <div>
        <span class="eyebrow">SUPER ADMIN OVERVIEW</span>
        <h1>Control Center &amp; Actuals</h1>
        <p>{{ \Carbon\Carbon::parse($from)->format('d M Y') }} - {{ \Carbon\Carbon::parse($to)->format('d M Y') }} · Showing 100% real numbers</p>
    </div>
    <div style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;">
        <a href="{{ route('superadmin.controls') }}" class="btn primary" style="background:#4f46e5;border-color:#4f46e5;">
            <x-icon name="sliders-horizontal"/>
            Dashboard Controls ({{ $percentage }}%)
        </a>
        <form method="POST" action="{{ route('superadmin.toggle') }}">
            @csrf
            <button type="submit" class="btn {{ $systemActive ? 'secondary' : 'primary' }}"
                    style="{{ $systemActive ? 'border-color:#ef4444;color:#ef4444;' : 'background:#16a34a;' }}"
                    onclick="return confirm('Are you sure you want to {{ $systemActive ? 'DISABLE' : 'ENABLE' }} the system?')">
                <x-icon name="power"/>
                {{ $systemActive ? 'Kill Switch (Disable System)' : 'Enable System' }}
            </button>
        </form>
    </div>
</div>

{{-- Banner highlighting current modifier --}}
<div class="card" style="background:linear-gradient(135deg, #eef2ff 0%, #ede9fe 100%);border:1px solid #c7d2fe;margin-bottom:24px;padding:16px 20px;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <div style="display:flex;align-items:center;gap:12px;">
            <div style="background:#4f46e5;color:#fff;width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;">
                🎛️
            </div>
            <div>
                <strong style="color:#312e81;font-size:14px;display:block;">
                    Active Visibility Modifier: {{ $percentage }}%
                </strong>
                <span style="color:#4338ca;font-size:12px;">
                    Normal users &amp; cashiers see <strong>{{ $percentage }}%</strong> of actual profits and prices. You see 100% real figures below.
                </span>
            </div>
        </div>
        <a href="{{ route('superadmin.controls') }}" class="btn secondary" style="font-size:12px;padding:6px 14px;background:#fff;">
            Change Setting &rarr;
        </a>
    </div>
</div>

{{-- Main Summary Cards --}}
<div class="stat-grid">
    {{-- Sales --}}
    <div class="card stat-card">
        <div class="stat-top">
            <span>Sales Revenue</span>
            <span class="stat-icon green"><x-icon name="shopping-bag"/></span>
        </div>
        <strong>{{ $currency }} {{ \App\Support\Money::display($realSummary['revenue']) }}</strong>
        <small style="color:#4f46e5;font-weight:600;margin-top:4px;">
            Users see: {{ $currency }} {{ \App\Support\Money::display($normalSummary['revenue']) }} ({{ $percentage }}%)
        </small>
    </div>

    {{-- Profit --}}
    <div class="card stat-card">
        <div class="stat-top">
            <span>Net Profit</span>
            <span class="stat-icon blue"><x-icon name="trending-up"/></span>
        </div>
        <strong>{{ $currency }} {{ \App\Support\Money::display($realSummary['net']) }}</strong>
        <small style="color:#4f46e5;font-weight:600;margin-top:4px;">
            Users see: {{ $currency }} {{ \App\Support\Money::display($normalSummary['net']) }} ({{ $percentage }}%)
        </small>
    </div>

    {{-- Expenses --}}
    <div class="card stat-card">
        <div class="stat-top">
            <span>Expenses</span>
            <span class="stat-icon amber"><x-icon name="wallet"/></span>
        </div>
        <strong>{{ $currency }} {{ \App\Support\Money::display($realSummary['expenses']) }}</strong>
        <small style="color:#4f46e5;font-weight:600;margin-top:4px;">
            Users see: {{ $currency }} {{ \App\Support\Money::display($normalSummary['expenses']) }}
        </small>
    </div>

    {{-- Transactions --}}
    <div class="card stat-card">
        <div class="stat-top">
            <span>Period Transactions</span>
            <span class="stat-icon purple"><x-icon name="receipt-text"/></span>
        </div>
        <strong>{{ $stats['period_sales'] }}</strong>
        <small>{{ $stats['sales_count'] }} all-time sales</small>
    </div>
</div>

<div class="dashboard-grid">
    {{-- 7-Day Chart --}}
    <section class="card chart-card">
        <div class="card-heading">
            <div>
                <h2>7-Day Sales Overview</h2>
                <p>Comparison: Real daily revenue vs User visible revenue ({{ $percentage }}%)</p>
            </div>
        </div>
        @php($max = max(1, ...array_map(fn($d) => abs((float)$d['real_amount']), $overview)))
        <div class="bar-chart" role="img" aria-label="Daily sales">
            @foreach($overview as $day)
                <div class="bar-column" title="{{ $day['label'] }}: Real {{ \App\Support\Money::display($day['real_amount']) }} | Users see {{ \App\Support\Money::display($day['user_amount']) }}">
                    <div class="bar-track">
                        <div class="bar" style="height:{{ abs((float)$day['real_amount']) / $max * 100 }}%;background:#4f46e5;"></div>
                    </div>
                    <span>{{ $day['label'] }}</span>
                </div>
            @endforeach
        </div>
    </section>

    {{-- System Status & Quick Counts --}}
    <section class="card" style="display:flex;flex-direction:column;justify-content:space-between;">
        <div>
            <div class="card-heading">
                <div>
                    <h2>System Information</h2>
                    <p>Core environment and status</p>
                </div>
                <x-icon name="shield-check"/>
            </div>

            <div style="padding: 0 24px 8px;">
                <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid var(--border);font-size:13px;">
                    <span style="color:var(--muted);font-weight:500;">System Access</span>
                    <strong style="color:{{ $systemActive ? '#15803d' : '#b91c1c' }};display:inline-flex;align-items:center;gap:6px;">
                        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:{{ $systemActive ? '#16a34a' : '#dc2626' }};"></span>
                        {{ $systemActive ? 'Online (Normal access enabled)' : 'Offline (Normal users locked out)' }}
                    </strong>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid var(--border);font-size:13px;">
                    <span style="color:var(--muted);font-weight:500;">Visible Profit Multiplier</span>
                    <strong style="color:#4f46e5;font-weight:700;">{{ $percentage }}%</strong>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid var(--border);font-size:13px;">
                    <span style="color:var(--muted);font-weight:500;">Total Products</span>
                    <strong style="font-weight:700;">{{ $stats['products'] }}</strong>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid var(--border);font-size:13px;">
                    <span style="color:var(--muted);font-weight:500;">Total System Users</span>
                    <strong style="font-weight:700;">{{ $stats['users'] }}</strong>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;font-size:13px;">
                    <span style="color:var(--muted);font-weight:500;">SUPER_ADMIN (.env)</span>
                    <strong style="color:#15803d;font-weight:700;">Active</strong>
                </div>
            </div>
        </div>

        <div style="padding: 12px 24px 24px;">
            <a href="{{ route('superadmin.controls') }}" class="btn secondary w-full" style="display:flex;align-items:center;justify-content:center;gap:8px;padding:10px 16px;border-radius:8px;font-size:13px;font-weight:600;">
                <x-icon name="sliders-horizontal" :size="16"/>
                Open Secret Dashboard Controls
            </a>
        </div>
    </section>

    {{-- Recent Sales Table --}}
    <section class="card" style="grid-column:1 / -1;">
        <div class="card-heading">
            <div>
                <h2>Recent Sales (Real Amounts vs User Seen)</h2>
                <p>Last 8 transactions</p>
            </div>
            <a class="text-link" href="{{ route('superadmin.sales') }}">
                Full sales list <x-icon name="arrow-right" :size="14"/>
            </a>
        </div>
        <div class="table-wrap">
            <table class="superadmin-table">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Cashier</th>
                        <th>Date &amp; Status</th>
                        <th>Payment</th>
                        <th class="text-right">Real Amount</th>
                        <th class="text-right" style="color:#4f46e5;">User Sees ({{ $percentage }}%)</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentSales as $sale)
                        <tr>
                            <td>
                                <strong>{{ $sale->invoice }}</strong>
                            </td>
                            <td>{{ $sale->user->name ?? 'N/A' }}</td>
                            <td>
                                <small class="cell-note">{{ $sale->sold_at->format('d M, h:i A') }} · {{ $sale->status }}</small>
                            </td>
                            <td>
                                <span class="badge slate">{{ $sale->payment_names }}</span>
                            </td>
                            <td class="text-right font-semibold">
                                {{ $currency }} {{ \App\Support\Money::display($sale->customer_payable) }}
                            </td>
                            <td class="text-right font-semibold" style="color:#4f46e5;">
                                {{ $currency }} {{ \App\Support\Money::display((float)$sale->customer_payable * $multiplier) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="empty-cell">No sales recorded yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
