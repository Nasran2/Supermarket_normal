@extends('layouts.app')
@section('title', 'Human resources')
@section('content')
@php
    $stats = [
        'active' => ['Active staff', 'users-round', 'hr.staff.index', ['status' => 'ACTIVE'], 'teal'],
        'present' => ['Present today', 'calendar-check', 'hr.attendance', [], 'blue'],
        'pending' => ['Leave awaiting review', 'calendar-days', 'hr.leave', ['status' => 'PENDING'], 'amber'],
        'payroll' => ['Payroll drafts', 'wallet', 'hr.payroll.index', ['status' => 'DRAFT'], 'violet'],
    ];
    $actions = [
        'hr.staff.view' => ['Staff directory', 'Find contact details and employment history.', 'hr.staff.index', 'users-round'],
        'hr.attendance.view' => ['Attendance', 'Open a day to review shifts and attendance.', 'hr.attendance', 'calendar-check'],
        'hr.leave.view' => ['Leave requests', 'Find pending requests and past leave.', 'hr.leave', 'calendar-days'],
        'hr.payroll.view' => ['Payroll', 'Review salary drafts and outstanding balances.', 'hr.payroll.index', 'wallet'],
        'hr.payments.view' => ['Payments & advances', 'Review payment history and staff advances.', 'hr.payments', 'credit-card'],
        'hr.setup.view' => ['HR setup', 'Find departments, job titles, shifts and leave types.', 'hr.setup', 'settings-2'],
    ];
@endphp
<div class="page-heading hr-overview-heading">
    <div>
        <h1>Human resources</h1>
        <p>{{ today()->format('l, d F Y') }}</p>
    </div>
    @if(auth()->user()->hasPermission('hr.staff.view') && auth()->user()->hasPermission('hr.staff.create'))
        <a class="btn primary" href="{{ route('hr.staff.create') }}"><x-icon name="user-plus"/>Add staff</a>
    @endif
</div>

@include('hr.partials.nav')

@if(collect($counts)->contains(fn ($count) => $count !== null))
    <section class="hr-overview-stats" aria-label="HR summary">
        @foreach($stats as $key => [$label, $icon, $route, $params, $color])
            @if($counts[$key] !== null)
                <a class="card hr-overview-stat" href="{{ route($route, $params) }}">
                    <span class="hr-overview-icon" data-tone="{{ $color }}"><x-icon :name="$icon" :size="21"/></span>
                    <strong>{{ $counts[$key] }}</strong>
                    <span>{{ $label }}</span>
                </a>
            @endif
        @endforeach
    </section>
@endif

@php($visibleActions = array_filter($actions, fn ($permission) => auth()->user()->hasPermission($permission), ARRAY_FILTER_USE_KEY))
@if($visibleActions)
    <section class="hr-overview-tasks" aria-labelledby="hr-daily-work">
        <h2 id="hr-daily-work">HR tools</h2>
        <div class="hr-overview-actions">
            @foreach($visibleActions as [$label, $description, $route, $icon])
                <a class="card hr-overview-action" href="{{ route($route) }}">
                    <span class="hr-overview-icon"><x-icon :name="$icon" :size="20"/></span>
                    <span><strong>{{ $label }}</strong><small>{{ $description }}</small></span>
                    <x-icon name="arrow-right" :size="17"/>
                </a>
            @endforeach
        </div>
    </section>
@endif
@include('hr.partials.report-cards')
@endsection
