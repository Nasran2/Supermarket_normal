<form method="post" action="{{ $action }}" class="repair-stack" @if(!$reason && $chequeMethod) data-repair-cheque-method="{{ $chequeMethod->id }}" @endif @if($reason) data-repair-confirm="Post this change to the payment ledger?" @endif>@csrf
    <div class="card-heading"><h2>{{ $title }}</h2><button type="button" class="btn secondary" data-repair-close>Close</button></div>
    <input type="hidden" name="submission" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
    <label class="field">{{ $original ? 'Replacement amount' : 'Amount' }}<input type="number" name="amount" min=".01" step=".01" value="{{ $original?->amount }}" required></label>
    <label class="field">Payment method<select name="payment_method_id" required><option value="">Choose method</option>@foreach($methods as $method)<option value="{{ $method->id }}" @selected($original?->payment_method_id===$method->id)>{{ $method->name }}</option>@endforeach @if(!$reason && $chequeMethod)<option value="{{ $chequeMethod->id }}">{{ $chequeMethod->name }}</option>@endif</select></label>
    @if(!$reason && $chequeMethod)
    <div class="repair-fields" data-repair-cheque-fields hidden>
        <label class="field">Cheque number<input name="cheque[cheque_number]" maxlength="80" disabled data-required></label>
        <label class="field">Bank<input name="cheque[bank_name]" maxlength="150" disabled data-required></label>
        <label class="field">Cheque date<input type="date" name="cheque[cheque_date]" disabled data-required></label>
        <label class="field">Branch<input name="cheque[branch]" maxlength="150" disabled @if(\App\Support\Cheques::setting('cheque_require_branch')) data-required @endif></label>
    </div>
    @endif
    @if($banks->isNotEmpty())<label class="field">Bank account (for bank, card or QR)<select name="bank_account_id"><option value="">No linked account</option>@foreach($banks as $bank)<option value="{{ $bank->id }}" @selected($original?->bank_account_id===$bank->id)>{{ $bank->bank_name }} · {{ $bank->account_name }} · {{ substr($bank->account_number,-4) }}</option>@endforeach</select></label>@endif
    <label class="field">Reference<input name="reference" maxlength="120" value="{{ $original?->reference }}"></label>
    @if($reason)<label class="field">Reason<textarea name="reason" rows="2" maxlength="500" required></textarea></label>@endif
    <div><button class="btn primary">Post entry</button></div>
</form>
