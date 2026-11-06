@extends('layouts.app')
@section('title','Profit & loss')
@section('main-class','report-workspace')
@section('content')
@if(class_exists(\App\Support\SecretControl::class) && \App\Support\SecretControl::isHidden('hide_profit'))
<div class="card empty-state" style="margin-top:2rem; padding:3rem; text-align:center;">
    <x-icon name="shield-alert" :size="48"/>
    <h2 style="margin-top:1rem;">Profit &amp; Loss Hidden</h2>
    <p class="muted">Access to profit and loss information is restricted by system settings.</p>
    <a href="{{ route('reports.index') }}" class="btn secondary" style="margin-top:1.5rem;">Back to Reports</a>
</div>
@else
<div class="page-heading"><div><span class="eyebrow">BUSINESS PERFORMANCE</span><h1>Profit & loss</h1><p>{{ $filters['from'] }} to {{ $filters['to'] }} · {{ $settings['currency']??'LKR' }}</p></div><div class="heading-actions"><a class="btn secondary" href="{{ route('reports.index') }}">All reports</a>@can('reports.profit.pdf')<a class="btn primary" href="{{ route('reports.pdf',['report'=>'profit']+$filters) }}"><x-icon name="download"/>Download PDF</a>@endcan<button class="btn secondary" onclick="window.print()"><x-icon name="printer"/>Print</button></div></div>
<x-sales-visibility-note/>

@php
    $sellMult = class_exists(\App\Support\SecretControl::class) ? \App\Support\SecretControl::sellingPriceMultiplier() : 1.0;
    $costMult = class_exists(\App\Support\SecretControl::class) ? \App\Support\SecretControl::costMultiplier() : 1.0;
    $genMult  = class_exists(\App\Support\SecretControl::class) ? \App\Support\SecretControl::multiplier() : 1.0;

    $scaledSummary = [];
    foreach ($summary as $k => $v) {
        $m = match($k) {
            'grossSales', 'salesReturns', 'revenue' => $sellMult,
            'cogs', 'estimatedRecovery' => $costMult,
            default => $genMult,
        };
        $scaledSummary[$k] = (float) $v * $m;
    }
    if (isset($scaledSummary['revenue'], $scaledSummary['cogs'])) {
        $scaledSummary['gross'] = $scaledSummary['revenue'] - $scaledSummary['cogs'];
        $scaledSummary['net']   = $scaledSummary['gross'] - ($scaledSummary['expenses'] ?? 0);
    }
@endphp

@if(!\App\Support\SecretControl::isHidden('hide_top_summary'))
<div class="stat-grid mb-6">@foreach(['grossSales'=>'Gross sales','salesReturns'=>'Less sales returns','revenue'=>'Net sales revenue','gross'=>'Gross profit','net'=>'Net profit'] as $key=>$label)<div class="card stat-card"><div class="stat-top">{{ $label }}</div><strong>{{ $settings['currency_symbol']??'Rs.' }} {{ \App\Support\Money::formatRaw($scaledSummary[$key] ?? $summary[$key]) }}</strong></div>@endforeach</div>
@endif
<div class="card report-data-card profit-statement"><div class="card-heading"><div><h2>Income statement</h2><p class="muted">Revenue, inventory costs and operating expenses for this period.</p></div><span class="tile-icon"><x-icon name="trending-up"/></span></div><form class="filter-bar report-filter-bar" method="GET"><label class="field compact">From<input type="date" name="from" value="{{ $filters['from'] }}" required></label><label class="field compact">To<input type="date" name="to" value="{{ $filters['to'] }}" required></label><button class="btn secondary">Apply filters</button><a class="text-link" href="{{ route('reports.show','profit') }}">Reset</a></form><div class="profit-lines">@foreach(['grossSales'=>'Gross sales','salesReturns'=>'Less sales returns','revenue'=>'Net sales revenue','cogs'=>'Cost of goods sold','gross'=>'Gross profit','manual'=>'Other operating expenses','processing'=>'Business-paid processing expenses','purchaseCharges'=>'Purchase shipping & other charges','returnWriteoffs'=>'Return write-off expenses','noReceiptCredit'=>'No-receipt approved credit (unverified)','estimatedRecovery'=>'Estimated returned inventory / supplier claim cost','noReceiptAdjustments'=>'No-receipt return adjustment (credit less estimated recovery)','expenses'=>'Total operating expenses','net'=>'Net profit'] as $key=>$label)<div class="{{ in_array($key,['gross','net'])?'profit-total':'' }} {{ $key==='net'?'net':'' }}"><span>{{ $label }}</span><strong>{{ $settings['currency_symbol']??'Rs.' }} {{ \App\Support\Money::formatRaw($scaledSummary[$key] ?? $summary[$key]) }}</strong></div>@endforeach</div><p class="muted text-sm p-6">Verified returns reverse original revenue and inventory cost. Unverified credit less estimated recovery is shown separately as a no-receipt adjustment; write-offs are expensed once. Customer-paid processing fees are tracked separately from product revenue. Business-paid fees reduce profit once through their linked expenses. Voided sales and reversed expenses are excluded.</p></div>
@endif
@endsection
