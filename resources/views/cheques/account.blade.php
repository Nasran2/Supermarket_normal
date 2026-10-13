@can('cheques.view_'.$side)
<section class="card cheque-card"><div class="card-heading"><div><h2>Cheque Hold</h2><p class="muted">Payments waiting for cheque clearance.</p></div><strong>{{ $settings['currency_symbol']??'Rs.' }} {{ \App\Support\Money::display($record->cheque_hold) }}</strong>@can('cheques.view')<a class="text-link" href="{{ route('cheques.index',[$side.'_id'=>$record->id]) }}">View cheques</a>@endcan</div>@include('cheques.table',['rows'=>$record->cheques()->visibleTo()->with('customer','supplier')->orderByDesc('id')->limit(10)->get()])</section>
@endcan
