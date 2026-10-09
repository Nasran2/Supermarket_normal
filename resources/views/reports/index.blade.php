@extends('layouts.app')
@section('title','Reports')
@section('main-class','report-workspace')
@section('content')
<div class="page-heading"><div><span class="eyebrow">STORE INSIGHTS</span><h1>Reports</h1><p>Explore your performance, track balances and download company-branded PDF reports.</p></div><span class="report-export-badge"><x-icon name="file-text"/>PDF & CSV exports</span></div>
@foreach(['Transactions'=>['sales','purchases','returns','collections','expenses','profit'],'Inventory'=>['stock','product-sales'],'Payments & cash'=>['payments','register','cash','payment-charges','card-charges','qr-charges','bank-charges'],'Activity'=>['audit']] as $group=>$kinds)
@php($available=collect($kinds)->filter(fn($kind)=>auth()->user()->hasPermission(\App\Services\ReportService::permission($kind))))
@if($available->isNotEmpty())<section class="report-library-section"><div class="report-group-heading"><h2>{{ $group }}</h2><span>{{ $available->count() }} {{ \Illuminate\Support\Str::plural('report',$available->count()) }}</span></div><div class="report-library-grid">@foreach($available as $kind)@php($title=$titles[$kind])<a class="card report-library-card" href="{{ route('reports.show',$kind) }}"><span class="tile-icon"><x-icon :name="match($kind){'profit'=>'trending-up','stock'=>'boxes','expenses'=>'wallet','payments'=>'credit-card','audit'=>'history',default=>'chart-no-axes-combined'}" :size="23"/></span><div><h3>{{ $title }}</h3><p>{{ match($kind){'stock'=>'Current stock and prices by delivery.','profit'=>'Revenue, costs and operating profit.','cash'=>'Opening balances, money in and money out.','audit'=>'Changes and activity across your store.',default=>'Filter records and review the details.'} }}</p></div><x-icon name="arrow-up-right" class="report-card-arrow"/></a>@endforeach</div></section>@endif
@endforeach
@endsection
