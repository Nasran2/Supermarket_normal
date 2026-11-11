@extends('layouts.app')
@section('title','Repair reports')
@section('content')
<div class="repair-page repair-stack">
    <div class="page-heading"><div><span class="eyebrow">SERVICE DESK / REPORTS</span><h1>Repair reports</h1><p>Review activity, outstanding balances, and repair performance.</p></div>
        <div class="repair-actions">
            @can('repairs.reports.export')
                <a class="btn secondary" data-repair-export href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}">Export CSV</a>
            @endcan
            @can('repairs.view')
                <a class="btn secondary" href="{{ route('repairs.index') }}">Repair jobs</a>
            @endcan
        </div>
    </div>
    <nav class="repair-actions repair-report-nav" aria-label="Repair reports">
        @foreach(\App\Services\Repair\RepairReporting::VIEWS as $key=>$label)
            @continue($key==='profit' && !$canProfit)
            <a class="btn {{ $kind===$key?'primary':'secondary' }}" @if($kind===$key) aria-current="page" @endif href="{{ route('repairs.reports',array_merge(request()->except('page','export'),['view'=>$key])) }}">{{ $label }}</a>
        @endforeach
    </nav>
    @include('repairs.partials.filters')
    <section class="card repair-panel repair-stack" data-repair-results aria-live="polite">
        <div class="card-heading"><h2>{{ \App\Services\Repair\RepairReporting::VIEWS[$kind] }}</h2></div>
        @if($summary)<dl class="repair-fields repair-data">@foreach($summary as $label=>$value)<div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>@endforeach</dl>@endif
        <div class="repair-table"><table><thead><tr>@foreach($columns as $label)<th>{{ $label }}</th>@endforeach<th>Details</th></tr></thead><tbody>
            @forelse($rows as $row)<tr>@foreach($map($row) as $value)<td>{{ $value ?? '—' }}</td>@endforeach<td>@if($link=$url($row))<a class="text-link" href="{{ $link }}">Open job</a>@endif</td></tr>
            @empty<tr><td colspan="{{ count($columns)+1 }}" class="muted">No entries match these filters.</td></tr>@endforelse
        </tbody></table></div>
        {{ $rows->links() }}
    </section>
</div>
@endsection
