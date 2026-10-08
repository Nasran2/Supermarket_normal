@extends('layouts.app')
@section('title','Register summary')
@section('content')
<div class="page-heading"><div><span class="eyebrow">SHIFT SUMMARY</span><h1>REG-{{ $register->id }}</h1><p>{{ $register->user->name }} · {{ $register->opened_at->format('d/m/Y H:i') }} – {{ $register->closed_at?->format('d/m/Y H:i')??'Open' }}</p></div><button class="btn secondary" onclick="window.print()"><x-icon name="printer"/>Print summary</button></div>
<div class="card padded">@include('register.partials.summary')</div>
@endsection
