@extends('superadmin.layout')

@section('title', 'Real Products & Costs (Unrestricted)')

@section('content')
@php
$settings = app(\App\Services\SettingsService::class)->all();
$currency = $settings['currency_symbol'] ?? 'Rs.';

@endphp

<div class="page-heading">
    <div>
        <span class="eyebrow">SUPER ADMIN · UNRESTRICTED</span>
        <h1>All Products (100% Real Costs &amp; Prices)</h1>
        <p>Showing actual purchase cost, selling price and total stock value. Normal users see {{ $percentage }}%.</p>
    </div>
    <div style="display:flex;gap:0.75rem;align-items:center;">
        <a href="{{ route('superadmin.controls') }}" class="btn secondary">
            <x-icon name="sliders-horizontal" />
            Controls ({{ $percentage }}%)
        </a>
    </div>
</div>

<div class="customer-overview product-overview">
    <div class="card">
        <span>Total products</span>
        <strong>{{ $stats->total }}</strong>
        <small>Store catalogue</small>
    </div>
    <div class="card">
        <span>Active products</span>
        <strong>{{ $stats->active_count ?? 0 }}</strong>
        <small>Available</small>
    </div>
    <div class="card">
        <span>Real Stock Value at Cost</span>
        <strong>{{ $currency }} {{ \App\Support\Money::display($stats->stock_value ?? 0) }}</strong>
        <small style="color:#4f46e5;font-weight:600;">Normal users see: {{ $currency }} {{ \App\Support\Money::display((float)($stats->stock_value ?? 0) * ($costPercentage/100)) }}</small>
    </div>
</div>

<section class="card" style="margin-top:20px;">
    <div class="card-heading">
        <div>
            <h2>Products Inventory</h2>
            <p>{{ $products->total() }} products found</p>
        </div>
        <span class="secret-badge">
            <x-icon name="shield-check" :size="14" />
            Super Admin View (Real 100%)
        </span>
    </div>

    <form method="GET" class="sales-filters" style="margin-bottom:16px;">
        <label class="field">
            Search product or SKU
            <div class="search-field">
                <x-icon name="search" />
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Product name, barcode or SKU">
            </div>
        </label>
        <button class="btn primary">Apply</button>
        <a class="btn secondary" href="{{ route('superadmin.products') }}">Reset</a>
    </form>

    <div class="table-wrap">
        <table class="product-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Category</th>
                    <th>Stock</th>
                    <th class="text-right">Real Cost</th>
                    <th class="text-right">Real Price</th>
                    <th class="text-right" style="color:#4f46e5;">Users See Price ({{ $pricePercentage }}%)</th>
                    <th class="text-right" style="color:#4f46e5;">Users See Cost ({{ $costPercentage }}%)</th>
                </tr>
            </thead>
            <tbody>
                @forelse($products as $p)
                <tr>
                    <td>
                        <strong>{{ $p->name }}</strong>
                        <small class="cell-note">{{ $p->sku }}</small>
                    </td>
                    <td>{{ $p->categories->pluck('name')->join(', ') ?: 'General' }}</td>
                    <td>
                        <strong>{{ (float)$p->stock }} {{ $p->unit?->short_name }}</strong>
                    </td>
                    <td class="text-right">
                        <strong>{{ $currency }} {{ \App\Support\Money::display($p->cost) }}</strong>
                    </td>
                    <td class="text-right">
                        <strong>{{ $currency }} {{ \App\Support\Money::display($p->price) }}</strong>
                    </td>
                    <td class="text-right" style="color:#4f46e5;font-weight:600;">
                        {{ $currency }} {{ \App\Support\Money::display((float)$p->price * ($pricePercentage/100)) }}
                    </td>
                    <td class="text-right" style="color:#4f46e5;font-weight:600;">
                        {{ $currency }} {{ \App\Support\Money::display((float)$p->cost * ($costPercentage/100)) }}
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="empty-cell">No products found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="pagination">{{ $products->links() }}</div>
</section>
@endsection