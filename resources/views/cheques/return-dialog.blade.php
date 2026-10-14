@php
$returning=$returning??true;
$dialogId='cheque-'.($returning?'return':'resolve').'-'.$cheque->id;
$restore=old('_cheque_dialog')===$dialogId;
$oldValue=fn($key,$default=null)=>$restore?old($key,$default):$default;
$customerSide=(bool)$cheque->origin_customer_id;
$supplierSide=(bool)$cheque->destination_supplier_id;
$side=$oldValue('recovery_side',$customerSide?'CUSTOMER':'SUPPLIER');
$canCustomer=auth()->user()->hasPermission('customers.collect_payment') || ($cheque->sale_id && auth()->user()->hasPermission('sales.collect_payment'));
$canSupplier=auth()->user()->hasPermission('purchases.pay');
$canResolve=auth()->user()->hasPermission('cheques.resolve_return');
$canReplace=$canResolve && (($customerSide && $canCustomer && auth()->user()->hasPermission('cheques.receive') && \App\Support\Cheques::setting('cheque_allow_customer')) || ($supplierSide && $canSupplier && ((auth()->user()->hasPermission('cheques.issue') && \App\Support\Cheques::setting('cheque_allow_own')) || (auth()->user()->hasPermission('cheques.transfer') && \App\Support\Cheques::setting('cheque_allow_third_party')))));
$canPay=$canResolve && (($customerSide && $canCustomer) || ($supplierSide && $canSupplier));
@endphp
<dialog id="{{ $dialogId }}" class="sale-dialog cheque-return-dialog" aria-labelledby="{{ $dialogId }}-title" @if($restore && $errors->any())data-reopen-cheque @endif>
<div class="modal-heading"><div><span class="eyebrow">CHEQUE #{{ $cheque->cheque_number }}</span><h2 id="{{ $dialogId }}-title">{{ $returning?'Return cheque':'Resolve returned cheque' }}</h2></div><button type="button" class="icon-button" data-close-cheque-dialog aria-label="Close"><x-icon name="x"/></button></div>
<form class="sale-dialog-body" method="POST" action="{{ $returning?route('cheques.return',$cheque):route('cheques.resolve-return',$cheque) }}" data-cheque-recovery data-returning="{{ $returning?'1':'0' }}" data-customer-allowed="{{ $canCustomer?'1':'0' }}" data-supplier-allowed="{{ $canSupplier?'1':'0' }}" data-customer-replace="{{ auth()->user()->hasPermission('cheques.receive') && \App\Support\Cheques::setting('cheque_allow_customer')?'1':'0' }}" data-currency="{{ $settings['currency_symbol']??'Rs.' }}" data-customer-limit="{{ $cheque->recoveryRemaining('CUSTOMER') }}" data-supplier-limit="{{ $cheque->recoveryRemaining('SUPPLIER') }}">
@csrf<input type="hidden" name="confirmed" value="1"><input type="hidden" name="token" value="{{ $oldValue('token',(string)\Illuminate\Support\Str::uuid()) }}"><input type="hidden" name="_cheque_dialog" value="{{ $dialogId }}">
<div class="cheque-return-summary"><strong>{{ $settings['currency_symbol']??'Rs.' }} {{ \App\Support\Money::display($cheque->amount) }}</strong><span>{{ $cheque->parties }}</span><small>{{ $cheque->bank_name }} · {{ $cheque->cheque_date->format('d M Y') }}</small></div>
<p class="muted mt-6">{{ $returning?($cheque->type==='THIRD_PARTY'?'Returning this cheque removes both holds and restores the customer and supplier balances to Due.':'Returning this cheque removes its hold and restores the unpaid balance to Due.'):'The original cheque remains Returned. Record a replacement or payment against its original balance.' }}</p>
@if($returning)<label class="field mt-6">Return reason <small>optional</small><textarea name="reason" rows="2" maxlength="255" placeholder="e.g. Insufficient funds, signature mismatch" autofocus>{{ $oldValue('reason',\App\Support\Cheques::setting('cheque_default_return_reason')) }}</textarea></label>@endif
@if($customerSide && $supplierSide)<label class="field mt-6">Balance to handle<select name="recovery_side"><option value="CUSTOMER" @selected($side==='CUSTOMER')>Customer · {{ $cheque->customer?->name }}</option><option value="SUPPLIER" @selected($side==='SUPPLIER')>Supplier · {{ $cheque->supplier?->name }}</option></select><small>The other account remains due until you record a separate payment or replacement.</small></label>@else<input type="hidden" name="recovery_side" value="{{ $side }}">@endif
<fieldset class="cheque-return-options mt-6"><legend>{{ $returning?'What happens to the balance?':'How will the balance be paid?' }}</legend>
@if($returning)<label><input type="radio" name="resolution" value="DUE" @checked($oldValue('resolution','DUE')==='DUE')><span><strong>Leave balance due</strong><small>Record the return now and collect or pay the balance later.</small></span></label>@endif
@if($canReplace)<label><input type="radio" name="resolution" value="REPLACE" @checked($oldValue('resolution',$returning?'DUE':'REPLACE')==='REPLACE')><span><strong>{{ $customerSide?'Get replacement cheque(s)':'Issue replacement cheque(s)' }}</strong><small>Add one or more cheques. A smaller total leaves the difference due.</small></span></label>@endif
@if($canPay)<label><input type="radio" name="resolution" value="PAYMENT" @checked($oldValue('resolution',$returning?'DUE':($canReplace?'REPLACE':'PAYMENT'))==='PAYMENT')><span><strong>Record cash / bank / card payment</strong><small>Record the amount paid now and leave any remainder due.</small></span></label>@endif
</fieldset>
@include('cheques.editor',['side'=>$side==='SUPPLIER'?'supplier':'receive','recovery'=>true,'initialCheques'=>$oldValue('cheques',[])])
<div class="form-grid mt-6" data-recovery-payment hidden>
<label class="field">Payment method<select name="payment_method_id" disabled><option value="">Choose payment method</option>@foreach(\App\Models\PaymentMethod::where('active',true)->where('type','!=','CHEQUE')->orderBy('display_order')->get() as $method)<option value="{{ $method->id }}" data-type="{{ $method->type }}" @selected($oldValue('payment_method_id')==$method->id)>{{ $method->name }}</option>@endforeach</select></label>
<label class="field">Payment reference <small>optional</small><input name="reference" maxlength="255" value="{{ $oldValue('reference') }}" disabled></label>
<label class="inline-check" data-recovery-register hidden><input type="checkbox" name="take_from_register" value="1" @checked($oldValue('take_from_register',false)) disabled>Take supplier cash payment from my register</label>
</div>
<label class="field mt-6" data-recovery-amount hidden><span data-recovery-amount-label>Amount</span><input name="amount" type="number" min="0.01" step="0.01" value="{{ $oldValue('amount','0.00') }}" disabled required><small data-recovery-balance role="status"></small></label>
<label class="field mt-6">Notes <small>optional</small><textarea name="notes" maxlength="2000" rows="2">{{ $oldValue('notes') }}</textarea></label>
@if($restore && $errors->any())<div class="notice error mt-6"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif
<div class="form-footer"><button type="button" class="btn secondary" data-close-cheque-dialog>Cancel</button><button type="submit" class="btn {{ $returning?'danger':'primary' }}" data-recovery-submit>{{ $returning?'Confirm return':'Save resolution' }}</button></div>
</form>
</dialog>
