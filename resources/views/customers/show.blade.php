@extends('layouts.app')
@section('title','Customer profile')
@section('content')
@php($currency = $settings['currency_symbol']??'Rs.')
<div class="page-heading"><div class="customer-profile-identity"><span class="customer-avatar large">{{ mb_strtoupper(mb_substr($record->name,0,1)) }}</span><div><span class="eyebrow">CUS-{{ str_pad($record->id,5,'0',STR_PAD_LEFT) }}</span><h1>{{ $record->name }}</h1><span class="badge {{ $record->active?'green':'slate' }}">{{ $record->active?'Active customer':'Inactive customer' }}</span></div></div><div class="heading-actions"><a class="btn secondary" href="{{ route('manage.index','customers') }}"><x-icon name="arrow-left"/>Customers</a>@can('sales.edit')<a class="btn primary" href="{{ route('manage.edit',['customers',$record->id]) }}"><x-icon name="pencil"/>Edit customer</a>@endcan</div></div>
<div class="customer-profile-stats">
    <div class="card customer-profile-due"><span class="eyebrow">OUTSTANDING DUE</span><strong>{{ $currency }} {{ \App\Support\Money::display($record->due_balance) }}</strong><span>{{ \App\Support\Money::compare($record->due_balance,0)>0?'Opening balance + unpaid invoices':'No outstanding balance' }}</span></div>
    <div class="card customer-profile-stat"><span>Invoiced purchases</span><strong>{{ $currency }} {{ \App\Support\Money::display($record->paid_purchases??0) }}</strong><small>Completed POS sales, including customer fees</small></div>
    <div class="card customer-profile-stat"><span>Completed purchases</span><strong>{{ $record->completed_sales_count }}</strong><small>Voided sales are excluded</small></div>
</div>
<div class="customer-profile-body">
    <section class="card customer-contact-card"><div class="customer-section-heading"><span class="customer-section-icon"><x-icon name="contact"/></span><div><h2>Contact details</h2><p>Customer account information</p></div></div><dl><div><dt>Phone</dt><dd>{{ $record->phone??'Not added' }}</dd></div><div><dt>Email</dt><dd>{{ $record->email??'Not added' }}</dd></div><div><dt>Address</dt><dd class="customer-address">{{ $record->address??'Not added' }}</dd></div><div><dt>Customer since</dt><dd>{{ $record->created_at->format('d M Y') }}</dd></div></dl><div class="customer-profile-opening"><span>Old balance / opening due</span><strong>{{ $currency }} {{ \App\Support\Money::display($record->opening_due) }}</strong><p>This is the unpaid balance brought into the customer account. New POS sales and their payments are recorded separately.</p></div></section>
    <section class="card customer-purchase-history"><div class="card-heading"><div><h2>Purchase history</h2><p class="muted text-sm">{{ $sales->total() }} recorded {{ \Illuminate\Support\Str::plural('sale',$sales->total()) }}</p></div></div><div class="table-wrap"><table><thead><tr><th>Invoice</th><th>Date</th><th>Payment</th><th>Amount collected</th><th>Due</th><th>Status</th></tr></thead><tbody>
        @forelse($sales as $sale)
        <tr><td><a class="text-link" href="{{ route('sales.show',$sale) }}">{{ $sale->invoice }}</a></td><td>{{ $sale->sold_at->format('d/m/Y H:i') }}</td><td>{{ $sale->payment_names ?: 'On account (unpaid)' }}</td><td>{{ $currency }} {{ \App\Support\Money::display(\App\Support\Money::sub($sale->collected_total,$sale->refunded_total)) }}</td><td>{{ $currency }} {{ \App\Support\Money::display($sale->due_balance) }}</td><td><span class="badge {{ $sale->status==='ACTIVE'?(\App\Support\Money::compare($sale->due_balance,0)>0?'amber':'green'):'slate' }}">{{ $sale->payment_status }}</span></td></tr>
        @empty
        <tr><td colspan="6"><div class="empty-state"><x-icon name="receipt-text" :size="34"/><h3>No purchases yet</h3><p>Sales linked to this customer will appear here.</p></div></td></tr>
        @endforelse
    </tbody></table></div><div class="pagination">{{ $sales->links() }}</div></section>
</div>
@endsection
