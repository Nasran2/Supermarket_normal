@if(config('features.cheque'))
<div class="cheque-editor" data-cheque-editor data-side="{{ $side ?? 'receive' }}" @if($always ?? false) data-always="1" @else hidden @endif data-available-url="{{ auth()->user()->hasPermission('cheques.transfer') ? route('cheques.available') : '' }}" data-multiple="{{ \App\Support\Cheques::setting('cheque_allow_multiple')?1:0 }}" data-own="{{ auth()->user()->hasPermission('cheques.issue') && \App\Support\Cheques::setting('cheque_allow_own')?1:0 }}" data-transfer="{{ auth()->user()->hasPermission('cheques.transfer') && \App\Support\Cheques::setting('cheque_allow_third_party')?1:0 }}">
<h3>Cheques</h3><p class="muted">Enter each cheque separately. The total must match this payment.</p>
<script type="application/json" data-cheque-initial>@json(old('cheques', []))</script>
<div data-cheque-rows></div><button type="button" class="btn secondary small" data-add-cheque>+ Add another cheque</button><p class="muted" data-cheque-total role="status"></p>
<template data-cheque-template><fieldset class="cheque-entry"><legend>Cheque</legend><div class="form-grid">
<label class="field" data-supplier-source>Cheque source<select data-cheque-field="source"><option value="OWN">Own cheque</option><option value="THIRD_PARTY">Customer / third-party cheque</option></select></label>
<label class="field" data-transfer-field hidden>Available customer cheque<select data-cheque-field="cheque_id"><option value="">Choose a customer cheque</option></select></label>
<label class="field" data-own-field>Amount<input data-cheque-field="amount" type="number" step="0.01" min="0.01" required></label>
<label class="field" data-own-field>Cheque date<input data-cheque-field="cheque_date" type="date" required></label>
<label class="field" data-own-field>Cheque number<input data-cheque-field="cheque_number" maxlength="80" required></label>
<label class="field" data-own-field>Bank<input data-cheque-field="bank_name" maxlength="150" required></label>
<label class="field" data-own-field>Branch<input data-cheque-field="branch" maxlength="150" @required(\App\Support\Cheques::setting('cheque_require_branch'))></label>
<label class="field" data-own-field>Account holder / drawer<input data-cheque-field="drawer_name" maxlength="150"></label>
<label class="field" data-own-field>Account number<input data-cheque-field="account_number" maxlength="80"></label>
<label class="field" data-supplier-source data-own-field>Our bank account (optional)<select data-cheque-field="bank_account_id"><option value="">Enter bank details manually</option>@foreach(\App\Models\BankAccount::where('active',true)->orderBy('bank_name')->get() as $account)<option value="{{ $account->id }}" data-bank="{{ $account->bank_name }}" data-number="{{ $account->account_number }}" data-holder="{{ $account->account_name }}" data-branch="{{ $account->branch }}">{{ $account->bank_name }} · {{ $account->account_name }} · {{ $account->account_number }}</option>@endforeach</select></label>
<label class="field" data-own-field>Reference<input data-cheque-field="reference" maxlength="255"></label><label class="field" data-own-field>Notes<input data-cheque-field="notes" maxlength="2000"></label>
</div><button type="button" class="text-danger text-link" data-remove-cheque>Remove cheque</button></fieldset></template>
</div>
@endif
