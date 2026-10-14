@extends('layouts.app')
@section('title','Cheque #'.$cheque->cheque_number)
@section('content')
<div class="page-heading"><div><h1>Cheque #{{ $cheque->cheque_number }}</h1><p>{{ $cheque->type_label }} · {{ $cheque->parties }}</p></div><a class="btn secondary" href="{{ route('cheques.index') }}">All cheques</a></div>
<section class="card cheque-card"><dl class="detail-grid"><div><dt>Status</dt><dd>{{ ucfirst(strtolower($cheque->status)) }} @if($cheque->overdue)· Overdue @endif</dd></div><div><dt>Amount</dt><dd>{{ $settings['currency_symbol']??'Rs.' }} {{ \App\Support\Money::display($cheque->amount) }}</dd></div><div><dt>Cheque date</dt><dd>{{ $cheque->cheque_date->format('d/m/Y') }}</dd></div><div><dt>Bank / branch</dt><dd>{{ $cheque->bank_name }} · {{ $cheque->branch??'—' }}</dd></div><div><dt>Drawer / account</dt><dd>{{ $cheque->drawer_name??'—' }} · {{ $cheque->account_number??'—' }}</dd></div><div><dt>Reference</dt><dd>{{ $cheque->reference??'—' }}</dd></div>@if($cheque->sale)<div><dt>Sale</dt><dd>@can('sales.view')<a href="{{ route('sales.show',$cheque->sale) }}">{{ $cheque->sale->invoice }}</a>@else{{ $cheque->sale->invoice }}@endcan</dd></div>@endif @if($cheque->purchase)<div><dt>Purchase</dt><dd>@can('purchases.view')<a href="{{ route('purchases.show',$cheque->purchase) }}">{{ $cheque->purchase->reference }}</a>@else{{ $cheque->purchase->reference }}@endcan</dd></div>@endif<div><dt>Notes</dt><dd>{{ $cheque->notes??'—' }}</dd></div></dl>
@if($cheque->image_path)<p><a class="text-link" href="{{ route('cheques.image.download',$cheque) }}">Download cheque attachment</a></p>@endif
@include('cheques.actions')
@if(in_array($cheque->status,['PENDING','TRANSFERRED']))
@if(!$cheque->action_available)<p class="muted">Upcoming · ordinary Pass/Return actions start {{ $cheque->cheque_date->copy()->subDays((int)\App\Support\Cheques::setting('cheque_alert_days'))->format('d/m/Y') }}.</p>@endif
@if($cheque->status==='PENDING' && $cheque->type==='CUSTOMER_RECEIVED')@can('cheques.transfer')@can('purchases.pay')<details><summary>Transfer customer cheque to a supplier</summary><form method="POST" action="{{ route('cheques.transfer',$cheque) }}">@csrf<input type="hidden" name="token" value="{{ \Illuminate\Support\Str::uuid() }}"><input type="hidden" name="confirmed" value="1"><label class="field">Outstanding supplier purchase<select name="purchase_id" required><option value="">Choose purchase</option>@foreach(\App\Models\Purchase::where('status','ACTIVE')->where('payment_tracking',true)->with('supplier')->get()->filter(fn($p)=>\App\Support\Money::compare($p->due_amount,$cheque->amount)>=0) as $p)<option value="{{ $p->id }}">{{ $p->reference }} · {{ $p->supplier->name }} · Due {{ \App\Support\Money::display($p->due_amount) }}</option>@endforeach</select></label><p class="muted">Transfer the full physical cheque to this supplier. No business cash/bank movement is recorded.</p><button class="btn primary">Confirm transfer</button></form></details>@endcan @endcan @endif
@if($cheque->status==='TRANSFERRED')@can('cheques.reverse_transfer')<details><summary>Reverse transfer</summary><form method="POST" action="{{ route('cheques.reverse-transfer',$cheque) }}">@csrf<input type="hidden" name="confirmed" value="1"><p>Confirm the physical cheque has been returned by the supplier. Supplier Due is restored and the customer cheque remains pending.</p><label class="field">Reason<input name="reason" required maxlength="255"></label><button class="btn secondary">Confirm transfer reversal</button></form></details>@endcan @endif
@can('cheques.create')@if(\App\Support\Cheques::setting('cheque_allow_image'))<form method="POST" enctype="multipart/form-data" action="{{ route('cheques.image',$cheque) }}">@csrf<label class="field">Scanned cheque (image or PDF, up to 5 MB)<input name="image" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" required></label><button class="btn secondary">Upload attachment</button></form>@endif @endcan
@endif</section>
@if($cheque->return_reason)<section class="card cheque-card"><h2>Return reason</h2><p>{{ $cheque->return_reason }}</p></section>@endif
@if($cheque->replacementSources->isNotEmpty())<section class="card cheque-card"><h2>Replaces</h2>@foreach($cheque->replacementSources as $source)<p>@can('cheques.view')<a class="text-link" href="{{ route('cheques.show',$source->cheque) }}">Cheque #{{ $source->cheque->cheque_number }}</a>@else Cheque #{{ $source->cheque->cheque_number }} @endcan</p>@endforeach</section>@endif
@if($cheque->resolutions->isNotEmpty())
<section class="card cheque-card">
<h2>Return resolutions</h2>
<p class="muted">Replacement cheques remain on Hold until passed. The original cheque stays Returned.</p>
@foreach(['CUSTOMER'=>$cheque->origin_customer_id,'SUPPLIER'=>$cheque->destination_supplier_id] as $side=>$party)
@if($party)<p><strong>{{ ucfirst(strtolower($side)) }} return balance still to handle:</strong> {{ $settings['currency_symbol']??'Rs.' }} {{ \App\Support\Money::display($cheque->recoveryRemaining($side)) }}</p>@endif
@endforeach
<div class="cheque-resolution-history">
@foreach($cheque->resolutions as $resolution)
<article>
<strong>{{ match($resolution->resolution){'DUE'=>'Balance left due','REPLACE'=>'Replacement cheques recorded',default=>'Payment recorded'} }}</strong>
<small>{{ $resolution->created_at->format('d/m/Y h:i A') }} · {{ $resolution->user?->name }} · {{ ucfirst(strtolower($resolution->side)) }} · {{ $settings['currency_symbol']??'Rs.' }} {{ \App\Support\Money::display($resolution->amount) }} @if($resolution->paymentMethod)· {{ $resolution->paymentMethod->name }}@endif</small>
@foreach($resolution->replacements as $replacement)<p><a class="text-link" href="{{ route('cheques.show',$replacement) }}">#{{ $replacement->cheque_number }}</a> · {{ $replacement->bank_name }} · {{ \App\Support\Money::display($replacement->amount) }} · {{ ucfirst(strtolower($replacement->status)) }}</p>@endforeach
@if($resolution->metadata['reference']??null)<p>Reference: {{ $resolution->metadata['reference'] }}</p>@endif
@if($resolution->metadata['notes']??null)<p class="muted">{{ $resolution->metadata['notes'] }}</p>@endif
</article>
@endforeach
</div>
</section>
@endif
<section class="card cheque-card"><h2>Timeline</h2>@foreach($cheque->events as $event)<p><strong>{{ str_replace('_',' ',$event->event) }}</strong> · {{ $event->created_at->format('d/m/Y H:i') }} · {{ $event->user->name }}<br><span class="muted">{{ $event->notes }} {{ $event->metadata['reason']??'' }}</span></p>@endforeach</section>
@endsection
