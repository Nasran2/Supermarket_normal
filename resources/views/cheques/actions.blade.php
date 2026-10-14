<div class="heading-actions">
@if(in_array($cheque->status,['PENDING','TRANSFERRED']))
@can('cheques.edit')<a class="btn secondary" href="{{ route('cheques.edit',$cheque) }}"><x-icon name="pencil" :size="14"/>Edit</a>@endcan
@foreach(['pass'=>'Passed','return'=>'Returned','cancel'=>'Cancelled'] as $action=>$label)
@can('cheques.'.$action)
@if(($action==='cancel' && $cheque->status==='PENDING') || ($action!=='cancel' && ($cheque->action_available || auth()->user()->hasPermission('cheques.override_action_date'))))
<button class="btn {{ $action==='pass'?'primary':($action==='return'?'danger':'secondary') }}" type="button" onclick="document.getElementById('cheque-{{ $action }}-{{ $cheque->id }}').showModal()">Mark {{ $label }}</button>
@if($action==='return')
@include('cheques.return-dialog',['returning'=>true])
@else
<dialog id="cheque-{{ $action }}-{{ $cheque->id }}" class="payment-dialog"><div class="modal-heading"><h2>Mark cheque {{ $label }}?</h2></div><form class="modal-body" method="POST" action="{{ route('cheques.action',[$cheque,$action]) }}">@csrf<input type="hidden" name="confirmed" value="1"><p>#{{ $cheque->cheque_number }} · {{ \App\Support\Money::display($cheque->amount) }} · {{ $cheque->cheque_date->format('d/m/Y') }} · {{ $cheque->parties }}</p><p>{{ $action==='pass'?'This will settle the cheque hold.':'This will remove Hold and restore the amount to Due.' }}</p>@if($action==='cancel')<label class="field">Reason<input name="reason" maxlength="255" required></label>@endif<label class="field">Notes<input name="notes" maxlength="2000"></label><div class="heading-actions"><button type="button" class="btn secondary" onclick="this.closest('dialog').close()">Cancel</button><button class="btn primary">Confirm {{ $label }}</button></div></form></dialog>
@endif
@endif
@endcan
@endforeach
@elseif($cheque->status==='RETURNED')
@can('cheques.resolve_return')
@if(($cheque->origin_customer_id && (auth()->user()->hasPermission('customers.collect_payment') || ($cheque->sale_id && auth()->user()->hasPermission('sales.collect_payment'))) && \App\Support\Money::compare($cheque->recoveryRemaining('CUSTOMER'),0)>0) || ($cheque->destination_supplier_id && auth()->user()->hasPermission('purchases.pay') && \App\Support\Money::compare($cheque->recoveryRemaining('SUPPLIER'),0)>0))
<button type="button" class="btn secondary" onclick="document.getElementById('cheque-resolve-{{ $cheque->id }}').showModal()">Resolve return</button>
@include('cheques.return-dialog',['returning'=>false])
@endif
@endcan
@endif
</div>
