@extends('layouts.app')
@section('title','Edit cheque #'.$cheque->cheque_number)
@section('content')
<div class="page-heading"><div><span class="eyebrow">PAYMENTS</span><h1>Edit cheque #{{ $cheque->cheque_number }}</h1><p>Correct the cheque details and keep a record of each change.</p></div><a class="btn secondary" href="{{ route('cheques.show',$cheque) }}">Back to cheque</a></div>
<form class="card cheque-card" method="POST" action="{{ route('cheques.update',$cheque) }}" data-cheque-edit>
@csrf @method('PUT')<input type="hidden" name="version" value="{{ old('version',$version) }}">
<div class="notice info"><x-icon name="info"/><p>{{ $cheque->parties }} · {{ $settings['currency_symbol']??'Rs.' }} {{ \App\Support\Money::display($cheque->amount) }}. The amount and linked accounts come from the original payment. To change the amount, return or cancel this cheque and record its replacement.</p></div>
<div class="form-grid mt-6">
<label class="field">Cheque number<input name="cheque_number" maxlength="80" required value="{{ old('cheque_number',$cheque->cheque_number) }}"></label>
<label class="field">Cheque date<input name="cheque_date" type="date" required value="{{ old('cheque_date',$cheque->cheque_date->toDateString()) }}"></label>
@if($cheque->type==='OWN_CHEQUE')<label class="field">Our bank account (optional)<select name="bank_account_id" data-edit-bank-account><option value="">Enter bank details manually</option>@foreach($accounts as $account)<option value="{{ $account->id }}" data-bank="{{ $account->bank_name }}" data-number="{{ $account->account_number }}" data-holder="{{ $account->account_name }}" data-branch="{{ $account->branch }}" @selected(old('bank_account_id',$cheque->bank_account_id)==$account->id)>{{ $account->bank_name }} · {{ $account->account_name }} · {{ $account->account_number }}</option>@endforeach</select></label>@endif
<label class="field">Bank<input name="bank_name" maxlength="150" required value="{{ old('bank_name',$cheque->bank_name) }}"></label>
<label class="field">Branch @if(!\App\Support\Cheques::setting('cheque_require_branch'))<small>optional</small>@endif<input name="branch" maxlength="150" @required(\App\Support\Cheques::setting('cheque_require_branch')) value="{{ old('branch',$cheque->branch) }}"></label>
<label class="field">Account holder / drawer <small>optional</small><input name="drawer_name" maxlength="150" value="{{ old('drawer_name',$cheque->drawer_name) }}"></label>
<label class="field">Account number <small>optional</small><input name="account_number" maxlength="80" value="{{ old('account_number',$cheque->account_number) }}"></label>
<label class="field">Reference <small>optional</small><input name="reference" maxlength="255" value="{{ old('reference',$cheque->reference) }}"></label>
<label class="field">Notes <small>optional</small><textarea name="notes" maxlength="2000" rows="3">{{ old('notes',$cheque->notes) }}</textarea></label>
</div><div class="form-footer"><a class="btn secondary" href="{{ route('cheques.show',$cheque) }}">Cancel</a><button type="submit" class="btn primary"><x-icon name="check"/>Save changes</button></div>
</form>
@endsection
