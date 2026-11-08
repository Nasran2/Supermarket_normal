@php
    use App\Support\Money;
    $title = ['intake'=>'Device intake receipt','invoice'=>'Repair invoice','payment'=>'Repair payment receipt'][$document];
    $width = ['80mm'=>80,'A5'=>148,'A4'=>210][$size];
    $compact = $layout==='compact' || $size==='80mm';
    $person = $document==='invoice' && !\App\Support\SecretControl::isHidden('mask_supplier_customer_names') ? $job->invoice_customer : $job->customer->only(['name','phone','address']);
@endphp
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title }} · {{ $job->invoiceNumber() }}</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f1f5f9;font:13px/1.4 system-ui,sans-serif;color:#122537}
main{background:white;width:calc(100% - 1.5rem);max-width:{{ $width }}mm;margin:1rem auto;padding:{{ $compact ? '4mm' : '8mm' }};overflow-wrap:anywhere;box-shadow:0 5px 25px #253b4c15;border:1px solid #dae4ed}
.receipt-id{margin:.6rem 0 0;padding:{{ $compact ? '.4rem .5rem' : '.62rem .78rem' }};border:1px solid #cbdce9;border-radius:.35rem;background:#edf5fa;color:#1c435c}
.receipt-id h2{margin:0;font-size:{{ $compact ? '1.05rem' : '1.23rem' }};line-height:1.25}
.receipt-id p{margin:.14rem 0 0;color:#3c5c73;font-size:.83rem}
.receipt-info{display:grid;grid-template-columns:{{ $compact ? 'minmax(0,1fr)' : 'repeat(2,minmax(0,1fr))' }};gap:.26rem .8rem;margin:.65rem 0}
.receipt-info p{margin:0}.receipt-detail{border-top:1px solid #dce6ee;padding-top:.42rem;margin-top:.5rem}
.receipt-sample{display:block;margin:.25rem auto 0;color:#3a5468;text-align:center;font-size:.77rem}
.receipt-track{border:1px dashed #98b1c3;border-radius:.35rem;padding:.45rem .5rem;margin-top:.65rem;break-inside:avoid}
.receipt-signatures{display:grid;grid-template-columns:{{ $compact ? 'minmax(0,1fr)' : 'repeat(2,minmax(0,1fr))' }};gap:.65rem}
.receipt-signatures p{border-top:1px solid #8da2b1;padding-top:.28rem}
p{margin:.3rem 0}.center{text-align:center}.logo{max-width:32mm;max-height:16mm;object-fit:contain}
h1,h2,h3{margin:.6rem 0 .25rem}hr{border:0;border-top:1px solid #aab3bc;margin:.8rem 0}
table{border-collapse:collapse;width:100%;table-layout:fixed}th,td{text-align:left;border-bottom:1px solid #dce1e5;padding:.35rem .15rem;vertical-align:top;overflow-wrap:anywhere}
tr,.sign{break-inside:avoid}.money{text-align:right}.code{font:600 .9rem monospace;letter-spacing:.04em}
.barcode{width:100%;max-width:65mm;min-height:9mm;object-fit:contain}.controls{text-align:center;padding-top:1rem}
.sign{margin-top:{{ $compact ? '1.12rem' : '1.55rem' }}}.terms{white-space:pre-wrap}button{padding:.6rem 1rem;cursor:pointer;border:0;border-radius:.45rem;background:#145f91;color:#fff;font:inherit;font-weight:600}
@page{size:{{ $size==='80mm' ? '80mm 297mm' : $size.' portrait' }};margin:{{ $width===80 ? '2mm' : '8mm' }}}
@media print{body{background:white}main{margin:0;padding:0;width:100%;max-width:none;border:0;box-shadow:none}.controls{display:none}.receipt-id{background:white}}
@media screen and (max-width:420px){.receipt-info,.receipt-signatures{grid-template-columns:minmax(0,1fr)}}
</style>
@if($width===80)<style id="repair-print-size"></style>@endif
@include('partials.bill-logo-styles')
</head><body><div class="controls"><button onclick="window.print()">Print {{ strtolower($title) }}</button></div>
<main>
@if($preview ?? false)<p class="receipt-sample">Sample receipt · preview only</p>@endif
<x-demo-watermark :print-only="false" :width="$width"/>
@include('partials.bill-business')
<div class="receipt-id"><h2>{{ $title }}</h2><p>{{ $job->invoiceNumber() }} · {{ ($payment?->created_at ?? ($document==='invoice' ? $job->invoiced_at : $job->received_at))->format('d M Y') }}</p></div>
<div class="receipt-info">
    <p><strong>Customer</strong><br>{{ $person['name'] }} @if(filled($person['phone']??null)) · {{ $person['phone'] }} @endif</p>
    <p><strong>Device</strong><br>{{ $job->device->brand }} {{ $job->device->model }} {{ $job->device->color }}</p>
</div>
@if($document==='intake')
    <div class="receipt-detail">
        @if(filled($job->device->serial))<p><strong>IMEI / serial</strong> {{ $job->device->serial }}</p>@endif
        <p><strong>Reported issue</strong> {{ $job->faults }}</p>
        @if(filled($job->accessories))<p><strong>Items received</strong> {{ $job->accessories }}</p>@endif
        @if(filled($job->condition))<p><strong>Condition</strong> {{ $job->condition }}</p>@endif
        <p><strong>Expected by</strong> {{ $job->due_at?->format('d M Y') ?? 'To be confirmed' }}</p>
        @if($job->warranty_days)<p><strong>Warranty</strong> {{ $job->warranty_days }} days</p>@endif
    </div>
    @if($prices && $job->estimate!==null)<p><strong>Estimate:</strong> @include('partials.bill-currency') {{ Money::displayPrice($job->estimate) }}</p>@endif
    @if($inspection && $job->checklist)
    <h3>Inspection</h3><table><thead><tr><th>Feature</th><th>Result</th><th>Fault</th></tr></thead><tbody>
        @foreach($job->checklist as $check)<tr><td>{{ $check['label'] }}</td><td>{{ $check['result'] ?? '—' }}</td><td>{{ $check['fault'] ?? '—' }}</td></tr>@endforeach
    </tbody></table>
    @endif
    @if(filled($settings['repair_terms']??null))<h3>Terms</h3><p class="terms">{{ $settings['repair_terms'] }}</p>@endif
    <div class="receipt-signatures"><p class="sign">Customer signature</p><p class="sign">Staff signature</p></div>
@elseif($document==='invoice')
    @if(filled($person['address']??null))<p>{{ $person['address'] }}</p>@endif
    <table><thead><tr><th>Description</th><th class="money">Quantity</th><th class="money">Amount</th></tr></thead><tbody>
    @foreach($job->parts->where('state','CONSUMED') as $part)<tr><td>{{ $part->name }}</td><td class="money">{{ $part->quantity }}</td><td class="money">{{ Money::displayPrice(Money::mul($part->quantity,$part->price)) }}</td></tr>@endforeach
    @foreach(['labor'=>'Labor','service_fee'=>'Service fee','discount'=>'Discount','tax'=>'Tax'] as $key=>$label)
        @if(Money::compare($job->$key,0)>0)<tr><td colspan="2">{{ $label }}</td><td class="money">{{ $key==='discount' ? '−' : '' }}{{ Money::displayPrice($job->$key) }}</td></tr>@endif
    @endforeach
    </tbody></table>
    <p><strong>Invoice total:</strong> @include('partials.bill-currency') {{ Money::displayPrice($job->total) }}</p>
    @if(Money::compare($job->credited,0)>0)<p><strong>Refund credits:</strong> @include('partials.bill-currency') {{ Money::displayPrice($job->credited) }}</p>@endif
    <p><strong>Net paid:</strong> @include('partials.bill-currency') {{ Money::displayPayment($job->paid) }}</p>
    <p><strong>Balance due:</strong> @include('partials.bill-currency') {{ Money::displayCustomerAmount($job->due()) }}</p>
    @if($job->warranty_until)<p>Warranty until {{ $job->warranty_until->format('d M Y') }}</p>@endif
@else
    <p><strong>Payment #{{ $payment->id }}:</strong> {{ ucfirst(strtolower($payment->kind)) }} · {{ $payment->method_name }}</p>
    <p><strong>Amount:</strong> @include('partials.bill-currency') {{ Money::displayPayment($payment->amount) }}</p>
@endif
<div class="center receipt-track"><strong>Tracking number</strong><p class="code">{{ $job->tracking }}</p>
@if($barcode)<img class="barcode" alt="Tracking barcode" src="data:image/png;base64,{{ $barcode }}">@endif
<p><small>{{ route('repairs.track') }}</small></p></div>
</main>
@if($width===80)
<script>
function sizeRepairReceipt() {
    const height = Math.max(80, Math.ceil(document.querySelector('main').getBoundingClientRect().height * 25.4 / 96) + 6);
    document.querySelector('#repair-print-size').textContent = '@page{size:80mm ' + height + 'mm;margin:2mm}';
}
window.addEventListener('load', sizeRepairReceipt);
window.addEventListener('beforeprint', sizeRepairReceipt);
</script>
@endif
</body></html>
