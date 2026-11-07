@extends('superadmin.layout')

@section('title', 'Real Suppliers (Unrestricted)')

@section('content')
@php
    $settings = app(\App\Services\SettingsService::class)->all();
    $currency = $settings['currency_symbol'] ?? 'Rs.';
@endphp

<div class="page-heading">
    <div>
        <span class="eyebrow">SUPER ADMIN · UNRESTRICTED</span>
        <h1>All Suppliers (100% Real Accounts &amp; Balances)</h1>
        <p>Showing actual supplier accounts, unmasked company details, and confirmed purchase balances.</p>
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
        <span class="stat-icon green"><x-icon name="truck"/></span>
        <div>
            <span>Total Suppliers</span>
            <strong>{{ $stats->total ?? 0 }}</strong>
            <small>Registered vendors</small>
        </div>
    </div>
    <div class="card">
        <span class="stat-icon green"><x-icon name="circle-check"/></span>
        <div>
            <span>Active Suppliers</span>
            <strong>{{ $stats->active_count ?? 0 }}</strong>
            <small>Available</small>
        </div>
    </div>
    <div class="card">
        <span class="stat-icon green"><x-icon name="wallet"/></span>
        <div>
            <span>Confirmed Outstanding Due</span>
            <strong>{{ $currency }} {{ \App\Support\Money::display($stats->total_due ?? 0) }}</strong>
            <small>Across suppliers</small>
        </div>
    </div>
    <div class="card">
        <span class="stat-icon green"><x-icon name="file-text"/></span>
        <div>
            <span>Awaiting Payment Balances</span>
            <strong>{{ $stats->unrecorded_count ?? 0 }}</strong>
            <small>Untracked purchases</small>
        </div>
    </div>
</div>

<section class="card sales-directory" style="margin-top:20px;">
    <div class="card-heading">
        <div>
            <h2>Supplier Accounts (Real Data)</h2>
            <p>{{ $suppliers->total() }} {{ Str::plural('supplier', $suppliers->total()) }} found</p>
        </div>
        <span class="secret-badge">
            <x-icon name="shield-check" :size="14"/>
            Super Admin View (Real 100%)
        </span>
    </div>

    <form method="GET" class="sales-filters" style="margin-bottom:16px;">
        <label class="field">
            Search supplier
            <div class="search-field">
                <x-icon name="search"/>
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Supplier name, phone or email">
            </div>
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
        <a class="btn secondary" href="{{ route('superadmin.suppliers') }}">Reset</a>
    </form>

    <div class="table-wrap">
        <table class="sales-table">
            <thead>
                <tr>
                    <th>Supplier</th>
                    <th>Contact</th>
                    <th class="text-right">Confirmed Outstanding Due</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($suppliers as $supplier)
                    <tr>
                        <td>
                            <strong>{{ $supplier->name }}</strong>
                            <small class="cell-note">SUP-{{ str_pad($supplier->id, 5, '0', STR_PAD_LEFT) }}</small>
                        </td>
                        <td>
                            <div>{{ $supplier->phone ?: 'No phone' }}</div>
                            <small class="cell-note">{{ $supplier->email ?: 'No email' }}</small>
                        </td>
                        <td class="text-right">
                            <strong>{{ $currency }} {{ \App\Support\Money::display($supplier->due_balance) }}</strong>
                            @if($supplier->unrecorded_count)
                                <small class="cell-note" style="color:#d97706;">{{ $supplier->unrecorded_count }} purchase balances not recorded</small>
                            @endif
                        </td>
                        <td>
                            <span class="badge {{ $supplier->active ? 'green' : 'slate' }}">
                                {{ $supplier->active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="empty-cell">No suppliers found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="pagination">{{ $suppliers->links() }}</div>
</section>
@endsection

