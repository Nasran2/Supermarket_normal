@extends('layouts.app')
@section('title', 'Sales')
@section('content')
@php($currency = $settings['currency_symbol'] ?? 'Rs.')
<div class="page-heading"><div><span class="eyebrow">TRANSACTIONS</span><h1>Sales</h1><p>Every invoice, payment and return in one place.</p></div>@can('pos.access')<a class="btn primary" href="{{ route('pos.index') }}"><x-icon name="plus"/>New sale</a>@endcan</div>
<div class="sales-overview">
    <div class="card"><span class="stat-icon green"><x-icon name="receipt-text"/></span><div><span>Active invoices</span><strong>{{ $totals->invoices ?? 0 }}</strong><small>Matching your filters</small></div></div>
    <div class="card"><span class="stat-icon green"><x-icon name="shopping-bag"/></span><div><span>Invoiced sales</span><strong>{{ $currency }} {{ \App\Support\Money::display($totals->amount) }}</strong><small>After discounts · before returns</small></div></div>
    <div class="card"><span class="stat-icon green"><x-icon name="wallet"/></span><div><span>Customer payable</span><strong>{{ $currency }} {{ \App\Support\Money::display($totals->payable) }}</strong><small>Original invoices including customer fees</small></div></div>
</div>
<section class="card sales-directory">
    <div class="card-heading"><div><h2>Invoice history</h2><p>{{ $sales->total() }} {{ Str::plural('invoice', $sales->total()) }} found</p></div><span class="badge green">{{ $currency }}</span></div>
    <form method="GET" class="sales-filters">
        <label class="field">Search invoice or customer<div class="search-field"><x-icon name="search"/><input type="search" name="q" value="{{ request('q') }}" placeholder="Invoice, customer or phone" aria-label="Invoice search"></div></label>
        <label class="field">From<input type="date" name="from" value="{{ request('from') }}"></label><label class="field">To<input type="date" name="to" value="{{ request('to') }}"></label>
        <label class="field">Status<select name="status"><option value="">All statuses</option><option value="ACTIVE" @selected(request('status')==='ACTIVE')>Active</option><option value="VOIDED" @selected(request('status')==='VOIDED')>Voided</option></select></label>
        <button class="btn primary">Apply</button><a class="btn secondary" href="{{ route('sales.index') }}">Reset</a>
    </form>
    <div class="table-wrap"><table class="sales-table"><thead><tr><th>Invoice / cashier</th><th>Customer</th><th>Payment</th><th class="text-right">Invoice total</th><th class="text-right">Due</th><th>Status</th><th>Actions</th></tr></thead><tbody>
    @forelse($sales as $sale)<tr>
        <td><a class="sale-invoice" href="{{ route('sales.show', $sale) }}">{{ $sale->invoice }}</a><small class="cell-note">{{ $sale->sold_at->format(($settings['date_format']??'d/m/Y').' '.($settings['time_format']??'h:i A')) }}</small><small class="cell-note">{{ $sale->user->name }}</small></td>
        <td><strong>{{ $sale->customer?->name ?? 'Walk-in customer' }}</strong><small class="cell-note">{{ $sale->customer?->phone ?? 'Counter sale' }}</small></td>
        <td>{{ $sale->payment_names ?: 'Awaiting payment' }}@if($sale->collections->isNotEmpty())<small class="cell-note">{{ $sale->collections->count() }} due {{ Str::plural('payment', $sale->collections->count()) }}</small>@endif</td>
        <td class="text-right"><strong>{{ \App\Support\Money::display($sale->customer_payable) }}</strong>@if($sale->returns->isNotEmpty())<small class="cell-note">Returned {{ \App\Support\Money::display($sale->returned_total) }}</small>@endif</td>
        <td class="text-right {{ \App\Support\Money::compare($sale->due_balance,0)>0?'text-danger':'' }}"><strong>{{ \App\Support\Money::display($sale->due_balance) }}</strong></td>
        <td><span class="badge {{ $sale->status!=='ACTIVE'?'red':($sale->payment_status==='Due'?'amber':'green') }}">{{ $sale->payment_status }}</span>@if($sale->returns->isNotEmpty())<small class="cell-note">Items returned</small>@endif</td>
        <td>@include('sales.partials.actions', ['compact'=>true])</td>
    </tr>@empty<tr><td colspan="7" class="empty-cell"><x-icon name="receipt-text" :size="30"/><h3>No invoices found</h3><p>Try a different search or start a new sale.</p></td></tr>@endforelse
    </tbody></table></div><div class="pagination">{{ $sales->links() }}</div>
</section>
@endsection
