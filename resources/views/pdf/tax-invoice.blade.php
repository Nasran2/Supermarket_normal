<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Tax Invoice {{ $invoice['invoice'] }}</title>
<style>
@page { size: A4; margin: 32px 36px 42px; }
body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #151515; line-height: 1.5; }
h1 { text-align: center; font-size: 18px; margin: 0 0 20px; }
h1 span { border: 2px solid #222; padding: 7px 22px; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; }
.details { margin-bottom: 9px; }
.details td { width: 49%; border: 1px solid #333; padding: 9px; vertical-align: top; overflow-wrap: break-word; }
.details .gap { width: 2%; border: 0; padding: 0; }
.party { min-height: 105px; }
.label { font-size: 9px; color: #555; }
.value { font-size: 11px; }
.address { margin: 7px 0; white-space: pre-line; }
.extra { border: 1px solid #333; padding: 9px; margin: 10px 0 16px; white-space: pre-line; }
.lines th, .lines td { border: 1px solid #333; padding: 8px 6px; vertical-align: top; overflow-wrap: break-word; }
.lines th { background: #f5f5f5; text-align: center; font-size: 9px; }
thead { display: table-header-group; }
tr { page-break-inside: avoid; }
.num { text-align: right; }
.totals td:first-child { width: 77%; }
.totals td:last-child { width: 23%; }
.totals td { border: 1px solid #333; padding: 8px 6px; }
.grand { font-weight: bold; }
.ending { margin-top: 15px; page-break-inside: avoid; }
.ending td { border: 1px solid #333; padding: 9px; }
.note { margin-top: 12px; font-size: 8px; color: #555; }
@if($browserPreview ?? false)
body { margin:0; }
.print-controls { display:flex; justify-content:center; gap:10px; padding:16px; font:13px Arial,sans-serif; }
.print-controls button, .print-controls a { background:#08785b; color:white; border:0; border-radius:7px; padding:11px 18px; text-decoration:none; cursor:pointer; font:inherit; }
@media screen {
    body { background:#edf1ef; }
    .tax-invoice-sheet { box-sizing:border-box; width:190mm; max-width:none; margin:16px auto; padding:24px; background:white; box-shadow:0 2px 12px #14291f14; }
}
@media print {
    body { margin:0; background:white; }
    .print-controls { display:none; }
    .tax-invoice-sheet { width:auto; max-width:none; margin:0; padding:10px 0 0; box-shadow:none; zoom:1 !important; }
}
@endif
</style></head><body>
@if(($browserPreview ?? false) && !request()->boolean('embed'))<div class="print-controls"><button type="button" onclick="window.print()">Print invoice</button><a href="{{ $downloadUrl }}">Download PDF</a></div>@endif
<main class="tax-invoice-sheet" data-tax-invoice>
@if(($browserPreview ?? false) && \App\Support\Demo::enabled())<p class="note">DEMO - Sample invoice</p>@endif
@php($f = fn($v) => number_format((float)$v, 2, '.', ','))
<h1><span>Tax Invoice</span></h1>
<table class="details"><tr><td><span class="label">Date of Invoice</span><br><strong class="value">{{ $invoice['date'] }}</strong></td><td class="gap"></td><td><span class="label">Tax Invoice No.</span><br><strong class="value">{{ $invoice['invoice'] }}</strong></td></tr></table>
<table class="details"><tr>
@foreach(['supplier'=>'Supplier', 'purchaser'=>'Purchaser'] as $party=>$title)
@if($party==='purchaser')<td class="gap"></td>@endif
<td><div class="party"><div>{{ $title }}'s TIN: <strong>{{ $invoice[$party]['tax_number'] }}</strong></div><div>{{ $title }}'s Name: <strong>{{ $invoice[$party]['name'] }}</strong></div><div class="address">Address: {{ $invoice[$party]['address'] }}</div><div>Telephone No: {{ $invoice[$party]['phone'] }}</div></div></td>
@endforeach
</tr></table>
<table class="details"><tr><td>Date of Delivery: <strong>{{ \Carbon\Carbon::parse($invoice['delivery']['delivery_date'])->format('d/m/Y') }}</strong></td><td class="gap"></td><td>Place of Supply: <strong>{{ $invoice['delivery']['place_of_supply'] }}</strong></td></tr></table>
<div class="extra"><span class="label">Additional Information if any:</span><br>{{ $invoice['delivery']['additional_information'] ?? '-' }}</div>
<table class="lines"><thead><tr><th style="width:13%">Reference</th><th style="width:36%">Description of Goods or Services</th><th style="width:12%">Quantity</th><th style="width:16%">Unit Price<br>(Excluding VAT)</th><th style="width:23%">Amount<br>Excluding VAT<br>({{ $invoice['currency'] }})</th></tr></thead><tbody>
@foreach($invoice['lines'] as $line)<tr><td>{{ $line['reference'] }}</td><td>{{ $line['description'] }}</td><td class="num">{{ $line['quantity'] }}<br><span class="label">{{ $line['unit'] }}</span></td><td class="num">{{ number_format((float)$line['price'], 4, '.', ',') }}</td><td class="num">{{ $f($line['amount']) }}</td></tr>@endforeach
</tbody></table>
<table class="totals"><colgroup><col style="width:77%"><col style="width:23%"></colgroup><tbody>
<tr><td>Total Value of Supply:</td><td class="num">{{ $f($invoice['tax']['base']) }}</td></tr>
<tr><td>VAT Amount (Total Value of Supply @ {{ (float)$invoice['tax']['rate'] }}%)</td><td class="num">{{ $f($invoice['tax']['vat']) }}</td></tr>
<tr class="grand"><td>Total Amount including VAT:</td><td class="num">{{ $f($invoice['tax']['total']) }}</td></tr>
@if(\App\Support\Money::compare($invoice['fees'],0)>0)
<tr><td>Payment processing charge (separate from goods supply):</td><td class="num">{{ $f($invoice['fees']) }}</td></tr>
<tr class="grand"><td>Total Payable:</td><td class="num">{{ $f($invoice['payable']) }}</td></tr>
@endif
</tbody></table>
<table class="ending"><tr><td>Total Amount in words: {{ $invoice['words'] }}</td></tr><tr><td>Mode of Payment: {{ $invoice['payment'] }}</td></tr></table>
@if(filled($invoice['note'] ?? null))<div class="note" style="white-space:pre-line">{{ $invoice['note'] }}</div>@endif
<div class="note">Item amounts reflect line and bill discounts. All amounts are in {{ $invoice['currency'] }}.</div>
</main>
@if($browserPreview ?? false)
<script>
const invoiceSheet = document.querySelector('.tax-invoice-sheet');
function fitInvoicePreview() {
    invoiceSheet.style.zoom = String(Math.min(1, Math.max(1, window.innerWidth - 32) / invoiceSheet.offsetWidth));
}
window.addEventListener('resize', fitInvoicePreview);
window.addEventListener('beforeprint', () => { invoiceSheet.style.zoom = '1'; });
window.addEventListener('afterprint', fitInvoicePreview);
fitInvoicePreview();
</script>
@endif
</body></html>
