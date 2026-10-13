@extends('layouts.app')
@section('title','Register summary')
@section('content')
<div class="page-heading"><div><span class="eyebrow">SHIFT SUMMARY</span><h1>REG-{{ $register->id }}</h1><p>{{ $register->user->name }} · {{ $register->opened_at->format('d/m/Y H:i') }} – {{ $register->closed_at?->format('d/m/Y H:i')??'Open' }}</p></div><button class="btn secondary" onclick="window.print()"><x-icon name="printer"/>Print summary</button></div>
<div class="card padded">@include('register.partials.summary')</div>
@if(config('features.cheque') && isset($summary['cheque_pending']))<p class="card cheque-card">Cheque Pending: <strong>{{ \App\Support\Money::display($summary['cheque_pending']) }}</strong> · Informational; excluded from drawer cash.</p>@endif
@endsection
