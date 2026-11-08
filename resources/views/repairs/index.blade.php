@extends('layouts.app')
@section('title','Phone repairs')
@section('content')
<div class="repair-page">
    <div class="page-heading">
        <div><span class="eyebrow">SERVICE DESK</span><h1>Phone repairs</h1><p>Find a device, check its progress, or receive a new repair.</p></div>
        <div class="repair-actions">
            <a class="btn secondary" href="{{ route('repairs.track') }}">Customer tracking</a>
            @can('repairs.reports') @unless(\App\Support\SecretControl::isHidden('disable_reports'))<a class="btn secondary" href="{{ route('repairs.reports') }}">Reports</a>@endunless @endcan
            @can('repairs.create')<a class="btn primary" href="{{ route('repairs.create') }}"><x-icon name="plus"/>Receive device</a>@endcan
        </div>
    </div>
    @include('repairs.partials.filters')
    <section class="card repair-panel" data-repair-results aria-live="polite">
        <div class="card-heading"><h2>Repair jobs <small class="muted">· {{ $jobs->total() }}</small></h2></div>
        <div class="repair-table"><table><thead><tr><th>Job</th><th>Customer & device</th><th>Status</th><th>Technician</th><th>Due</th><th>Balance</th></tr></thead>
        <tbody>@forelse($jobs as $job)
        <tr><td><a class="text-link" href="{{ route('repairs.show',$job) }}">{{ $job->invoiceNumber() }}</a><br><small>{{ $job->received_at->format('d M Y') }}</small></td>
            <td>{{ $job->customer->name }}<br><small>{{ $job->device->brand }} {{ $job->device->model }}</small></td>
            <td><span class="repair-badge" data-status="{{ $job->status }}">{{ \App\Support\Repairs::STATUS[$job->status] }}</span><br><small>{{ ucfirst(strtolower($job->priority)) }} priority</small></td>
            <td>{{ $job->technician?->name ?? 'Unassigned' }}</td><td>{{ $job->due_at?->format('d M Y') ?? '—' }}</td><td>{{ $job->invoiced_at ? \App\Support\Money::displayCustomerAmount($job->due()) : '—' }}</td></tr>
        @empty<tr><td colspan="6" class="muted">No repairs match these filters.</td></tr>@endforelse</tbody></table></div>
        {{ $jobs->links() }}
    </section>
</div>
@endsection
