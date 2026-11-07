@extends('superadmin.layout')

@section('title', 'Real Purchases (Unrestricted)')

@section('content')
@php
    $settings = app(\App\Services\SettingsService::class)->all();
    $currency = $settings['currency_symbol'] ?? 'Rs.';
@endphp

<div class="page-heading">
    <div>
        <span class="eyebrow">SUPER ADMIN · UNRESTRICTED</span>
        <h1>All Purchases (100% Real Purchases)</h1>
        <p>Showing actual supplier deliveries, raw purchase costs, and payment dues. Normal users see {{ $costPercentage }}%.</p>
    </div>
    <div style="display:flex;gap:0.75rem;align-items:center;">
        <a href="{{ route('superadmin.stock') }}" class="btn secondary">
            <x-icon name="sliders-horizontal"/>
            Cost &amp; Stock Controls
        </a>
    </div>
</div>

<div class="sales-overview">
    <div class="card">
        <span class="stat-icon green"><x-icon name="shopping-bag"/></span>
        <div>
            <span>Active Deliveries</span>
            <strong>{{ $totals->total_count ?? 0 }}</strong>
            <small>Matching filters</small>
        </div>
    </div>
    <div class="card">
        <span class="stat-icon green"><x-icon name="truck"/></span>
        <div>
            <span>Real Gross Purchases</span>
            <strong>{{ $currency }} {{ \App\Support\Money::display($totals->gross_total ?? 0) }}</strong>
            <small style="color:#4f46e5;font-weight:600;">Normal users see: {{ $currency }} {{ \App\Support\Money::display((float)($totals->gross_total ?? 0) * ($costPercentage/100)) }}</small>
        </div>
    </div>
    <div class="card">
        <span class="stat-icon green"><x-icon name="wallet"/></span>
        <div>
            <span>Real Balance Due</span>
            <strong>{{ $currency }} {{ \App\Support\Money::display($totals->total_due ?? 0) }}</strong>
            <small>Across suppliers</small>
        </div>
    </div>
</div>

<section class="card sales-directory" style="margin-top:20px;">
    <div class="card-heading">
        <div>
            <h2>Purchase Deliveries (Real Values)</h2>
            <p>{{ $purchases->total() }} {{ Str::plural('delivery', $purchases->total()) }} found</p>
        </div>
        <span class="secret-badge">
            <x-icon name="shield-check" :size="14"/>
            Super Admin View (Real 100%)
        </span>
    </div>

    <form method="GET" class="sales-filters" style="margin-bottom:16px;">
        <label class="field">
            Search reference or supplier
            <div class="search-field">
                <x-icon name="search"/>
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Reference or supplier name">
            </div>
        </label>
        <label class="field">
            From
            <input type="date" name="from" value="{{ request('from') }}">
        </label>
        <label class="field">
            To
            <input type="date" name="to" value="{{ request('to') }}">
        </label>
        <button class="btn primary">Apply</button>
        <a class="btn secondary" href="{{ route('superadmin.purchases') }}">Reset</a>
    </form>

    <div class="table-wrap">
        <table class="sales-table">
            <thead>
                <tr>
                    <th>Reference / Recorded</th>
                    <th>Supplier</th>
                    <th class="text-right">Real Total</th>
                    <th class="text-right" style="color:#4f46e5;">Users See ({{ $costPercentage }}%)</th>
                    <th class="text-right">Paid</th>
                    <th class="text-right">Due</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($purchases as $purchase)
                    <tr>
                        <td>
                            <strong>{{ $purchase->reference }}</strong>
                            <small class="cell-note">{{ $purchase->purchase_date->format('d/m/Y') }} · {{ $purchase->user->name ?? 'N/A' }}</small>
                        </td>
                        <td>
                            <strong>{{ $purchase->supplier->name ?? 'Direct supplier' }}</strong>
                            <small class="cell-note">{{ $purchase->supplier->phone ?? 'No phone' }}</small>
                        </td>
                        <td class="text-right">
                            <strong>{{ $currency }} {{ \App\Support\Money::display($purchase->total) }}</strong>
                        </td>
                        <td class="text-right" style="color:#4f46e5;font-weight:600;">
                            {{ $currency }} {{ \App\Support\Money::display((float)$purchase->total * ($costPercentage/100)) }}
                        </td>
                        <td class="text-right">
                            {{ $currency }} {{ \App\Support\Money::display($purchase->paid_amount) }}
                        </td>
                        <td class="text-right font-semibold">
                            {{ $currency }} {{ \App\Support\Money::display($purchase->due_amount ?? 0) }}
                        </td>
                        <td>
                            <span class="badge {{ $purchase->status !== 'ACTIVE' ? 'red' : ($purchase->payment_status === 'Paid' ? 'green' : 'amber') }}">
                                {{ $purchase->payment_status }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="empty-cell">No purchases found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="pagination">{{ $purchases->links() }}</div>
</section>
@endsection

