@extends('superadmin.layout')

@section('title', 'Real Customers (Unrestricted)')

@section('content')
@php
    $settings = app(\App\Services\SettingsService::class)->all();
    $currency = $settings['currency_symbol'] ?? 'Rs.';
@endphp

<div class="page-heading">
    <div>
        <span class="eyebrow">SUPER ADMIN · UNRESTRICTED</span>
        <h1>All Customers (100% Real Accounts &amp; Dues)</h1>
        <p>Showing actual customer accounts, unmasked identities, and real outstanding dues. Normal users see {{ $customerPercentage }}%.</p>
    </div>
    <div style="display:flex;gap:0.75rem;align-items:center;">
        <a href="{{ route('superadmin.hide-records') }}" class="btn secondary">
            <x-icon name="sliders-horizontal"/>
            Hide Records Controls
        </a>
    </div>
</div>

<div class="sales-overview">
    <div class="card">
        <span class="stat-icon green"><x-icon name="users"/></span>
        <div>
            <span>Total Customers</span>
            <strong>{{ $stats->total ?? 0 }}</strong>
            <small>Registered accounts</small>
        </div>
    </div>
    <div class="card">
        <span class="stat-icon green"><x-icon name="circle-check"/></span>
        <div>
            <span>Active Customers</span>
            <strong>{{ $stats->active_count ?? 0 }}</strong>
            <small>Can place sales</small>
        </div>
    </div>
    <div class="card">
        <span class="stat-icon green"><x-icon name="receipt-text"/></span>
        <div>
            <span>Customers With Dues</span>
            <strong>{{ $stats->due_count ?? 0 }}</strong>
            <small>Carrying balances</small>
        </div>
    </div>
    <div class="card">
        <span class="stat-icon green"><x-icon name="wallet"/></span>
        <div>
            <span>Real Outstanding Due</span>
            <strong>{{ $currency }} {{ \App\Support\Money::display($stats->total_due ?? 0) }}</strong>
            <small style="color:#4f46e5;font-weight:600;">Normal users see: {{ $currency }} {{ \App\Support\Money::display((float)($stats->total_due ?? 0) * ($customerPercentage/100)) }}</small>
        </div>
    </div>
</div>

<section class="card sales-directory" style="margin-top:20px;">
    <div class="card-heading">
        <div>
            <h2>Customer Accounts (Real Data)</h2>
            <p>{{ $customers->total() }} {{ Str::plural('customer', $customers->total()) }} found</p>
        </div>
        <span class="secret-badge">
            <x-icon name="shield-check" :size="14"/>
            Super Admin View (Real 100%)
        </span>
    </div>

    <form method="GET" class="sales-filters" style="margin-bottom:16px;">
        <label class="field">
            Search customer
            <div class="search-field">
                <x-icon name="search"/>
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Name, phone or email">
            </div>
        </label>
        <label class="field">
            Balance
            <select name="balance">
                <option value="">All balances</option>
                <option value="due" @selected(request('balance')==='due')>With outstanding due</option>
                <option value="clear" @selected(request('balance')==='clear')>No outstanding due</option>
            </select>
        </label>
        <label class="field">
            Status
            <select name="active">
                <option value="">All statuses</option>
                <option value="1" @selected(request('active')==='1')>Active</option>
                <option value="0" @selected(request('active')==='0')>Inactive</option>
            </select>
        </label>
        <button class="btn primary">Apply</button>
        <a class="btn secondary" href="{{ route('superadmin.customers') }}">Reset</a>
    </form>

    <div class="table-wrap">
        <table class="sales-table">
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Contact</th>
                    <th class="text-right">Real Due Balance</th>
                    <th class="text-right" style="color:#4f46e5;">Users See ({{ $customerPercentage }}%)</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($customers as $customer)
                    <tr>
                        <td>
                            <strong>{{ $customer->name }}</strong>
                            <small class="cell-note">CUS-{{ str_pad($customer->id, 5, '0', STR_PAD_LEFT) }}</small>
                        </td>
                        <td>
                            <div>{{ $customer->phone ?: 'No phone' }}</div>
                            <small class="cell-note">{{ $customer->email ?: 'No email' }}</small>
                        </td>
                        <td class="text-right">
                            <strong>{{ $currency }} {{ \App\Support\Money::display($customer->due_balance) }}</strong>
                        </td>
                        <td class="text-right" style="color:#4f46e5;font-weight:600;">
                            {{ $currency }} {{ \App\Support\Money::display((float)$customer->due_balance * ($customerPercentage/100)) }}
                        </td>
                        <td>
                            <span class="badge {{ $customer->active ? 'green' : 'slate' }}">
                                {{ $customer->active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="empty-cell">No customers found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="pagination">{{ $customers->links() }}</div>
</section>
@endsection

