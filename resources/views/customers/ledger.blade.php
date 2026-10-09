@extends('layouts.app')
@section('title', 'Customer Ledger - ' . $customer->name)
@section('content')
@php($currency = $settings['currency_symbol'] ?? 'Rs.')
<div class="page-heading">
    <div>
        <span class="eyebrow">CUSTOMER LEDGER</span>
        <h1>{{ $customer->name }}</h1>
        <p>Complete history of purchases and payments.</p>
    </div>
    <div class="heading-actions">
        <a class="btn secondary" href="{{ route('manage.show', ['customers', $customer->id]) }}">
            <x-icon name="arrow-left"/>Back to Profile
        </a>
    </div>
</div>

<div class="card padded" style="margin-bottom: 24px;">
    <form method="GET" style="display:flex; flex-wrap:wrap; gap:16px; align-items:flex-end;">
        <label class="field" style="margin:0; width: 180px;">Start Date
            <input type="date" name="start_date" value="{{ $startDate }}">
        </label>
        <label class="field" style="margin:0; width: 180px;">End Date
            <input type="date" name="end_date" value="{{ $endDate }}">
        </label>
        <button type="submit" class="btn primary">Filter</button>
        @can('customers.export')<button type="submit" name="export" value="pdf" class="btn secondary" formtarget="_blank"><x-icon name="printer"/> Print / PDF</button>@endcan
        @can('customers.export')<button type="submit" name="export" value="csv" class="btn secondary"><x-icon name="download"/> CSV</button>@endcan
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Description</th>
                    <th style="text-align:right;">Debit ({{ $currency }})</th>
                    <th style="text-align:right;">Credit ({{ $currency }})</th>
                    <th style="text-align:right;">Balance ({{ $currency }})</th>
                </tr>
            </thead>
            <tbody>
                @forelse($ledger as $row)
                <tr>
                    <td>{{ $row['date']->format('d M Y, H:i') }}</td>
                    <td>
                        @if($row['type'] === 'Opening Balance')
                            <span class="badge slate">{{ $row['type'] }}</span>
                        @elseif($row['type'] === 'Invoice')
                            <span class="badge amber">{{ $row['type'] }}</span>
                        @else
                            <span class="badge green">{{ $row['type'] }}</span>
                        @endif
                    </td>
                    <td>{{ $row['description'] }}</td>
                    <td style="text-align:right; font-weight:500;">
                        {!! \App\Support\Money::compare($row['debit'], 0) > 0 ? \App\Support\Money::display($row['debit']) : '<span class="muted">-</span>' !!}
                    </td>
                    <td style="text-align:right; font-weight:500; color:var(--green-700);">
                        {!! \App\Support\Money::compare($row['credit'], 0) > 0 ? \App\Support\Money::display($row['credit']) : '<span class="muted">-</span>' !!}
                    </td>
                    <td style="text-align:right;"><strong>{{ \App\Support\Money::display($row['balance']) }}</strong></td>
                </tr>
                @empty
                <tr>
                    <td colspan="6">
                        <div class="empty-state">
                            <x-icon name="file-text" :size="34"/>
                            <h3>No ledger records</h3>
                            <p>Try adjusting the date filters.</p>
                        </div>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
