<section class="card repair-panel repair-stack">
    <div class="card-heading"><div><h2>Billing & payments</h2><p class="muted">{{ $job->invoiced_at ? 'Invoice '.$job->invoiceNumber().' · '.$job->invoiced_at->format('d M Y') : 'Advances are applied when the invoice is finalized.' }}</p></div></div>
    <dl class="repair-fields repair-data">
        <div><dt>Invoice total</dt><dd>{{ $job->invoiced_at ? \App\Support\Money::displayPrice($job->total) : 'Pending' }}</dd></div>
        <div><dt>Net paid</dt><dd>{{ \App\Support\Money::displayPayment($job->paid) }}</dd></div>
        <div><dt>Balance due</dt><dd>{{ $job->invoiced_at ? \App\Support\Money::displayCustomerAmount($job->due()) : '—' }}</dd></div>
        <div><dt>Refunded</dt><dd>{{ \App\Support\Money::displayPayment(\App\Support\Money::sub('0', \App\Support\Money::sum($job->payments->where('kind','REFUND')->pluck('amount')))) }}</dd></div>
    </dl>
    @if($heldCheques->isNotEmpty())
    <ul class="repair-history">@foreach($heldCheques as $cheque)
        <li>Pending cheque · {{ \App\Support\Money::displayCheque($cheque->amount) }} · {{ $cheque->cheque_date->format('d M Y') }}
        @can('cheques.view')<a class="text-link" href="{{ route('cheques.show',$cheque) }}">Review cheque</a>@endcan</li>
    @endforeach</ul>
    @endif
    <div class="repair-table"><table><thead><tr><th>Date</th><th>Entry</th><th>Method</th><th>Amount</th><th>Reason / reference</th><th>Actions</th></tr></thead><tbody>
    @forelse($job->payments->sortByDesc('id') as $payment)
        <tr><td>{{ $payment->created_at->format('d M Y H:i') }}</td><td>{{ ucfirst(strtolower($payment->kind)) }}</td>
            <td>{{ $payment->method_name }}</td><td>{{ \App\Support\Money::displayPayment($payment->amount) }}</td><td>{{ $payment->reason ?? $payment->reference ?? '—' }}</td>
            <td><div class="repair-actions">
                <a class="text-link" href="{{ route('repairs.print',[$job,'payment','payment'=>$payment->id,'size'=>'80mm']) }}" target="_blank" rel="noopener">Receipt</a>
                @if($canCorrect && !$closedWithoutInvoice && in_array($payment->kind,['ADVANCE','PAYMENT','CORRECTION']) && $payment->method_type!=='CHEQUE' && !$payment->reversal)
                <button type="button" class="btn secondary" data-repair-open="repair-correction-{{ $payment->id }}">Correct</button>
                @endif
            </div></td></tr>
    @empty<tr><td colspan="6" class="muted">No payments recorded.</td></tr>@endforelse
    </tbody></table></div>
    @foreach($job->payments as $payment)
        @if($canCorrect && !$closedWithoutInvoice && in_array($payment->kind,['ADVANCE','PAYMENT','CORRECTION']) && $payment->method_type!=='CHEQUE' && !$payment->reversal)
        <dialog class="card repair-dialog" id="repair-correction-{{ $payment->id }}">
            @include('repairs.partials.payment-form',['title'=>'Correct payment','action'=>route('repairs.payment.correct',[$job,$payment]),'reason'=>true,'dialog'=>true,'original'=>$payment])
        </dialog>
        @endif
    @endforeach
    <div class="repair-actions">
        @if($canPay && !$closedWithoutInvoice && (!$job->invoiced_at || \App\Support\Money::compare($job->due(),0)>0))
        <button type="button" class="btn primary" data-repair-open="repair-payment">{{ $job->invoiced_at ? 'Collect payment' : 'Take advance' }}</button>
        <dialog class="card repair-dialog" id="repair-payment">
            @include('repairs.partials.payment-form',['title'=>$job->invoiced_at ? 'Collect payment' : 'Take advance','action'=>route('repairs.payment',[$job,'collect']),'reason'=>false,'dialog'=>true,'original'=>null])
        </dialog>
        @endif
        @if($canCorrect && !$closedWithoutInvoice && \App\Support\Money::compare($job->paid,0)>0)
        <button type="button" class="btn secondary" data-repair-open="repair-refund">Record refund</button>
        <dialog class="card repair-dialog" id="repair-refund">
            @include('repairs.partials.payment-form',['title'=>'Record refund','action'=>route('repairs.payment',[$job,'refund']),'reason'=>true,'dialog'=>true,'original'=>null])
        </dialog>
        @endif
    </div>
</section>
