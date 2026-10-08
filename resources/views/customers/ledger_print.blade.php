<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer Ledger - {{ $customer->name }}</title>
    <style>
        body { font-family: sans-serif; font-size: 14px; margin: 0; padding: 20px; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #000; padding-bottom: 10px; }
        .header h1 { margin: 0; font-size: 24px; }
        .header p { margin: 5px 0 0; color: #555; }
        .details { margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { padding: 8px; border: 1px solid #ccc; text-align: left; }
        th { background: #f4f4f5; }
        .text-right { text-align: right; }
        .fw-bold { font-weight: bold; }
        @media print {
            body { padding: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body onload="window.print()">
    <div class="no-print" style="margin-bottom:20px; text-align:right;">
        <button onclick="window.print()">Print</button>
    </div>

    <div class="header">
        <h1>Customer Ledger</h1>
        <p>{{ $settings['store_name'] ?? 'Supermarket' }}</p>
    </div>

    <div class="details">
        <strong>Customer:</strong> {{ $customer->name }}<br>
        <strong>Phone:</strong> {{ $customer->phone ?? 'N/A' }}<br>
        <strong>Period:</strong> {{ $startDate ? \Carbon\Carbon::parse($startDate)->format('d/m/Y') : 'Beginning' }} to {{ $endDate ? \Carbon\Carbon::parse($endDate)->format('d/m/Y') : 'Now' }}
    </div>

    @php($currency = $settings['currency_symbol'] ?? 'Rs.')
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Description</th>
                <th class="text-right">Debit ({{ $currency }})</th>
                <th class="text-right">Credit ({{ $currency }})</th>
                <th class="text-right">Balance ({{ $currency }})</th>
            </tr>
        </thead>
        <tbody>
            @foreach($ledger as $row)
            <tr>
                <td>{{ $row['date']->format('d/m/Y H:i') }}</td>
                <td>{{ $row['type'] }}</td>
                <td>{{ $row['description'] }}</td>
                <td class="text-right">{{ \App\Support\Money::compare($row['debit'], 0) > 0 ? \App\Support\Money::display($row['debit']) : '-' }}</td>
                <td class="text-right">{{ \App\Support\Money::compare($row['credit'], 0) > 0 ? \App\Support\Money::display($row['credit']) : '-' }}</td>
                <td class="text-right fw-bold">{{ \App\Support\Money::display($row['balance']) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
