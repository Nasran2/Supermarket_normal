@extends('layouts.app')
@section('title', 'Customer Ledger - ' . $customer->name)
@section('content')
@php($currency = $settings['currency_symbol'] ?? 'Rs.')
<div class="page-heading">
    <div>
        <span class="eyebrow">CUSTOMER LEDGER</span>
        <h1>{{ $customer->name }}</h1>
    </div>
    <div class="heading-actions">
        <a class="btn secondary" href="{{ route('manage.show', ['customers', $customer->id]) }}">
            <x-icon name="arrow-left"/>Back to Profile
        </a>
    </div>
</div>

<div class="card" style="margin-bottom:20px;">
    <form method="GET" style="display:flex; gap:15px; align-items:flex-end;">
        <label class="field">Start Date
            <input type="date" name="start_date" value="{{ $startDate }}">
        </label>
        <label class="field">End Date
            <input type="date" name="end_date" value="{{ $endDate }}">
        </label>
        <button type="submit" class="btn primary">Filter</button>
        <button type="submit" name="export" value="pdf" class="btn secondary" formtarget="_blank"><x-icon name="printer"/> Print / PDF</button>
        <button type="submit" name="export" value="csv" class="btn secondary"><x-icon name="download"/> CSV</button>
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
                    <td>{{ $row['date']->format('d/m/Y H:i') }}</td>
                    <td>{{ $row['type'] }}</td>
                    <td>{{ $row['description'] }}</td>
                    <td style="text-align:right;">{{ \App\Support\Money::compare($row['debit'], 0) > 0 ? \App\Support\Money::display($row['debit']) : '-' }}</td>
                    <td style="text-align:right;">{{ \App\Support\Money::compare($row['credit'], 0) > 0 ? \App\Support\Money::display($row['credit']) : '-' }}</td>
                    <td style="text-align:right;"><strong>{{ \App\Support\Money::display($row['balance']) }}</strong></td>
                </tr>
                @empty
                <tr>
                    <td colspan="6"><div class="empty-state">No records found.</div></td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
