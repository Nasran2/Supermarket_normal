@extends('superadmin.layout')

@section('title', 'Real Sales (Unrestricted)')

@section('content')
@php
    $settings = app(\App\Services\SettingsService::class)->all();
    $currency = $settings['currency_symbol'] ?? 'Rs.';
@endphp

<div class="page-heading">
    <div>
        <span class="eyebrow">SUPER ADMIN · UNRESTRICTED</span>
        <h1>All Sales (100% Real Numbers)</h1>
        <p>Showing actual raw financial values as recorded in database. Normal users see {{ $percentage }}%.</p>
    </div>
    <div style="display:flex;gap:0.75rem;align-items:center;">
        <a href="{{ route('superadmin.controls') }}" class="btn secondary">
            <x-icon name="sliders-horizontal"/>
            Controls ({{ $percentage }}%)
        </a>
    </div>
</div>

<div class="sales-overview">
    <div class="card">
        <span class="stat-icon green"><x-icon name="receipt-text"/></span>
        <div>
            <span>Active invoices</span>
            <strong>{{ $totals->invoices ?? 0 }}</strong>
            <small>Matching filters</small>
        </div>
    </div>
    <div class="card">
        <span class="stat-icon green"><x-icon name="shopping-bag"/></span>
        <div>
            <span>Real Invoiced sales</span>
            <strong>{{ $currency }} {{ \App\Support\Money::display($totals->amount) }}</strong>
            <small style="color:#4f46e5;font-weight:600;">Normal users see: {{ $currency }} {{ \App\Support\Money::display((float)($totals->amount ?? 0) * ($percentage/100)) }}</small>
        </div>
    </div>
    <div class="card">
        <span class="stat-icon green"><x-icon name="wallet"/></span>
        <div>
            <span>Real Customer payable</span>
            <strong>{{ $currency }} {{ \App\Support\Money::display($totals->payable) }}</strong>
            <small style="color:#4f46e5;font-weight:600;">Normal users see: {{ $currency }} {{ \App\Support\Money::display((float)($totals->payable ?? 0) * ($percentage/100)) }}</small>
        </div>
    </div>
</div>

<section class="card sales-directory" style="margin-top:20px;">
    <div class="card-heading">
        <div>
            <h2>Invoice history (Real Values)</h2>
            <p>{{ $sales->total() }} {{ Str::plural('invoice', $sales->total()) }} found</p>
        </div>
        <span class="secret-badge">
            <x-icon name="shield-check" :size="14"/>
            Super Admin View (Real 100%)
        </span>
    </div>

    <form method="GET" class="sales-filters" style="margin-bottom:16px;">
        <label class="field">
            Search invoice or customer
            <div class="search-field">
                <x-icon name="search"/>
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Invoice, customer or phone">
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
        <a class="btn secondary" href="{{ route('superadmin.sales') }}">Reset</a>
    </form>

    <div class="table-wrap">
        <table class="sales-table">
            <thead>
                <tr>
                    <th>Invoice / cashier</th>
                    <th>Customer</th>
                    <th>Payment</th>
                    <th class="text-right">Real Total</th>
                    <th class="text-right" style="color:#4f46e5;">Users See ({{ $percentage }}%)</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($sales as $sale)
                    <tr>
                        <td>
                            <strong>{{ $sale->invoice }}</strong>
                            <small class="cell-note">{{ $sale->sold_at->format('d/m/Y h:i A') }}</small>
                            <small class="cell-note">{{ $sale->user->name ?? 'N/A' }}</small>
                        </td>
                        <td>
                            <strong>{{ $sale->customer?->name ?? 'Walk-in customer' }}</strong>
                            <small class="cell-note">{{ $sale->customer?->phone ?? 'Counter sale' }}</small>
                        </td>
                        <td>
                            <span class="badge slate">{{ $sale->payment_names ?: 'Awaiting payment' }}</span>
                        </td>
                        <td class="text-right">
                            <strong>{{ $currency }} {{ \App\Support\Money::display($sale->customer_payable) }}</strong>
                        </td>
                        <td class="text-right" style="color:#4f46e5;font-weight:600;">
                            {{ $currency }} {{ \App\Support\Money::display((float)$sale->customer_payable * ($percentage/100)) }}
                        </td>
                        <td>
                            <span class="badge {{ $sale->status !== 'ACTIVE' ? 'red' : ($sale->payment_status === 'Due' ? 'amber' : 'green') }}">
                                {{ $sale->payment_status }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="empty-cell">No invoices found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="pagination">{{ $sales->links() }}</div>
</section>
@endsection

