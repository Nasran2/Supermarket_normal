@extends('layouts.app')
@section('title','Customers')
@section('content')
@php($currency = $settings['currency_symbol']??'Rs.')
<div class="page-heading"><div><span class="eyebrow">CUSTOMER ACCOUNTS</span><h1>Customers</h1><p>Find your customers, manage their details and keep outstanding dues visible.</p></div>@can('sales.create')<a class="btn primary" href="{{ route('manage.create','customers') }}"><x-icon name="plus"/>Add customer</a>@endcan</div>
<div class="customer-overview">
    <div class="card customer-overview-card"><span class="customer-section-icon"><x-icon name="contact"/></span><div><span>Total customers</span><strong>{{ $stats->total??0 }}</strong></div></div>
    <div class="card customer-overview-card"><span class="customer-section-icon"><x-icon name="circle-check"/></span><div><span>Active customers</span><strong>{{ $stats->active_count??0 }}</strong></div></div>
    <div class="card customer-overview-card"><span class="customer-due-icon"><x-icon name="wallet"/></span><div><span>Customers with dues</span><strong>{{ $stats->due_count??0 }}</strong></div></div>
    <div class="card customer-overview-card due"><div><span>Total outstanding due</span><strong>{{ $currency }} {{ \App\Support\Money::display($stats->total_due??0) }}</strong><small>Across all customer accounts</small></div></div>
</div>
<div class="card customer-directory">
    <form method="GET" class="filter-bar"><label class="search-field"><x-icon name="search"/><input name="q" type="search" value="{{ request('q') }}" placeholder="Search name, phone or email…" aria-label="Search customers" maxlength="150"></label><select name="balance" aria-label="Balance"><option value="">All balances</option><option value="due" @selected(request('balance')==='due')>With outstanding due</option><option value="clear" @selected(request('balance')==='clear')>No outstanding due</option></select><select name="active" aria-label="Status"><option value="">All statuses</option><option value="1" @selected(request('active')==='1')>Active</option><option value="0" @selected(request('active')==='0')>Inactive</option></select><button class="btn secondary">Apply</button><a class="text-link" href="{{ route('manage.index','customers') }}">Reset</a></form>
    <div class="customer-directory-meta"><span>{{ $rows->total() }} {{ \Illuminate\Support\Str::plural('customer',$rows->total()) }}</span><span>Includes old balances and unpaid invoices.</span></div>
    <div class="table-wrap"><table><thead><tr><th>Customer</th><th>Contact</th><th>Outstanding due</th><th>Status</th><th class="text-right">Actions</th></tr></thead><tbody>
        @forelse($rows as $customer)
        <tr><td><a class="customer-identity" href="{{ route('manage.show',['customers',$customer->id]) }}"><span class="customer-avatar">{{ mb_strtoupper(mb_substr($customer->name,0,1)) }}</span><span><strong>{{ $customer->name }}</strong><small>CUS-{{ str_pad($customer->id,5,'0',STR_PAD_LEFT) }}</small></span></a></td><td><div class="customer-contact-list"><span>{{ $customer->phone??'No phone added' }}</span><small>{{ $customer->email??'No email added' }}</small></div></td><td><div class="customer-balance-cell {{ \App\Support\Money::compare($customer->due_balance,0)>0?'owing':'clear' }}"><strong>{{ $currency }} {{ \App\Support\Money::display($customer->due_balance) }}</strong><small>{{ \App\Support\Money::compare($customer->due_balance,0)>0?'Opening balance + unpaid invoices':'No outstanding due' }}</small>@if(\App\Support\Money::compare($customer->due_balance, 0) > 0)<a class="btn primary" style="padding: 3px 8px; font-size: 11px; margin-top: 6px; display: inline-block; width: max-content;" href="{{ route('manage.show',['customers',$customer->id]) }}#payment-modal">Collect Payment</a>@endif</div></td><td><span class="badge {{ $customer->active?'green':'slate' }}">{{ $customer->active?'Active':'Inactive' }}</span></td><td><div class="row-actions"><a class="icon-button" href="{{ route('manage.show',['customers',$customer->id]) }}" title="View customer" aria-label="View {{ $customer->name }}"><x-icon name="eye" :size="17"/></a>@can('sales.edit')<a class="icon-button" href="{{ route('manage.edit',['customers',$customer->id]) }}" title="Edit customer" aria-label="Edit {{ $customer->name }}"><x-icon name="pencil" :size="17"/></a>@endcan
            @can('sales.delete')
                @if(\App\Support\Money::compare($customer->due_balance,0)===0)
                <form method="POST" action="{{ route('manage.destroy',['customers',$customer->id]) }}" data-confirm="Delete this customer? Customers linked to sales cannot be deleted.">@csrf @method('DELETE')<button class="icon-button text-danger" title="Delete customer" aria-label="Delete {{ $customer->name }}"><x-icon name="trash-2" :size="17"/></button></form>
                @endif
            @endcan
        </div></td></tr>
        @empty
        <tr><td colspan="5"><div class="empty-state"><x-icon name="contact" :size="38"/><h3>No customers found</h3><p>Add your first customer or change the search filters.</p>@can('sales.create')<a class="btn primary" href="{{ route('manage.create','customers') }}">Add customer</a>@endcan</div></td></tr>
        @endforelse
    </tbody></table></div><div class="pagination">{{ $rows->links() }}</div>
</div>
@endsection
