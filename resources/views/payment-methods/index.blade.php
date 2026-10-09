@extends('layouts.app')
@section('title','Payment methods')
@section('content')
@php($currency=$settings['currency_symbol']??'Rs.')
<div class="page-heading"><div><span class="eyebrow">PAYMENTS</span><h1>Payment methods</h1><p>See collections and charges across every payment method.</p></div>@can('payment-methods.create')<a class="btn primary" href="{{ route('manage.create','payment-methods') }}"><x-icon name="plus"/>Add payment method</a>@endcan</div>
<x-sales-visibility-note/>
@include('payment-methods.partials.filters',['overview'=>true])
@include('payment-methods.partials.totals')
<section class="card payment-activity-card"><div class="card-heading"><div><h2>Collections by method</h2><p class="muted">{{ $rows->total() }} payment methods · totals include all matching methods and dates</p></div><span class="badge slate">{{ $totals['bills'] }} bills involved</span></div>
<div class="table-wrap"><table class="payment-activity-table"><thead><tr><th>Payment method</th><th>Collected</th><th>Charges</th><th>Refunds</th><th>Net collections</th><th>Status</th><th class="text-right">Actions</th></tr></thead><tbody>
@forelse($rows as $method)
@php($summary=$byMethod->get($method->id))
<tr><td><a class="payment-method-name" href="{{ route('manage.show',['resource'=>'payment-methods','id'=>$method->id]+array_intersect_key($filters,array_flip(['range','from','to']))) }}">{{ $method->name }}</a><small class="table-secondary">{{ $method->code }} · {{ str_replace('_',' ',ucfirst(strtolower($method->type))) }} · {{ $summary?->entries??0 }} {{ \Illuminate\Support\Str::plural('entry',$summary?->entries??0) }}</small></td><td class="payment-number">{{ $currency }} {{ \App\Support\Money::display($summary?->collected??0) }}</td><td class="payment-number">{{ $currency }} {{ \App\Support\Money::display($summary?->charges??0) }}</td><td class="payment-number">{{ $currency }} {{ \App\Support\Money::display($summary?->refunded??0) }}</td><td class="payment-number"><strong>{{ $currency }} {{ \App\Support\Money::display(\App\Support\Money::sub((string)($summary?->collected??0),(string)($summary?->refunded??0))) }}</strong></td><td><span class="badge {{ $method->active?'green':'slate' }}">{{ $method->active?'Active':'Inactive' }}</span></td><td><div class="row-actions"><a class="btn secondary small" href="{{ route('manage.show',['resource'=>'payment-methods','id'=>$method->id]+array_intersect_key($filters,array_flip(['range','from','to']))) }}"><x-icon name="eye" :size="16"/>View bills</a>@can('payment-methods.edit')<a class="icon-button" href="{{ route('manage.edit',['payment-methods',$method->id]) }}" aria-label="Edit {{ $method->name }}"><x-icon name="pencil" :size="17"/></a>@endcan @can('payment-methods.delete')<form method="POST" action="{{ route('manage.destroy',['payment-methods',$method->id]) }}" data-confirm="Delete this payment method?">@csrf @method('DELETE')<button class="icon-button text-danger" aria-label="Delete {{ $method->name }}"><x-icon name="trash-2" :size="17"/></button></form>@endcan</div></td></tr>
@empty
<tr><td colspan="7" class="empty-cell">No payment methods match your search.</td></tr>
@endforelse
</tbody></table></div><div class="pagination">{{ $rows->links() }}</div></section>
@endsection
