@php
    $links = [
        'hr.view' => ['Overview', 'hr.index', 'hr.index', 'layout-dashboard'],
        'hr.staff.view' => ['Staff', 'hr.staff.index', 'hr.staff.*', 'users-round'],
        'hr.attendance.view' => ['Attendance', 'hr.attendance', 'hr.attendance', 'calendar-check'],
        'hr.leave.view' => ['Leave', 'hr.leave', 'hr.leave', 'calendar-days'],
        'hr.payroll.view' => ['Payroll', 'hr.payroll.index', 'hr.payroll.*', 'wallet'],
        'hr.payments.view' => ['Payments', 'hr.payments', 'hr.payments', 'credit-card'],
        'hr.setup.view' => ['Setup', 'hr.setup', 'hr.setup', 'settings-2'],
    ];
@endphp
<nav class="hr-nav" aria-label="Human resources">
    @foreach($links as $permission => [$label, $route, $pattern, $icon])
        @can($permission)
            <a href="{{ route($route) }}" @if(request()->routeIs($pattern)) class="active" aria-current="page" @endif>
                <x-icon :name="$icon" :size="17"/>{{ $label }}
            </a>
        @endcan
    @endforeach
    @if(collect(array_keys(\App\Support\Hr::REPORTS))->contains(fn ($kind) => auth()->user()->hasPermission('hr.reports.'.$kind.'.view')))
        <a href="{{ route('hr.reports.index') }}" @if(request()->routeIs('hr.reports.*')) class="active" aria-current="page" @endif>
            <x-icon name="chart-no-axes-combined" :size="17"/>Reports
        </a>
    @endif
</nav>
