@extends('layouts.app')
@section('title','Barcode Print History')
@section('content')
<div class="page-heading"><div><span class="eyebrow">BARCODE LABELS</span><h1>Print History</h1><p>Generated jobs store their original label content and configuration. Browser printing does not confirm physical printer delivery.</p></div></div>
<div class="card table-wrap"><table><thead><tr><th>Job</th><th>Generated</th><th>User</th><th>Preset</th><th>Labels</th><th>Reprint</th></tr></thead><tbody>@forelse($jobs as $job)<tr><td>BP-{{ str_pad($job->id,6,'0',STR_PAD_LEFT) }}</td><td>{{ $job->created_at->format('d M Y h:i A') }}</td><td>{{ \App\Models\User::find($job->created_by)?->name }}</td><td>{{ $job->preset_name }}</td><td>{{ $job->total_labels }}</td><td>@can('barcode.print')@if(collect($job->labels)->pluck('product_id')->unique()->count()===1 || auth()->user()->hasPermission('barcode.print_bulk'))<a class="btn secondary small" href="{{ route('barcode.jobs.show',$job) }}" target="_blank" rel="noopener">View & Reprint</a>@endif @endcan</td></tr>@empty<tr><td colspan="6">No generated jobs yet.</td></tr>@endforelse</tbody></table>{{ $jobs->links() }}</div>
@endsection
