<form class="card repair-filters repair-fields" method="get" data-repair-filters>
    @isset($kind)<input type="hidden" name="view" value="{{ $kind }}">@endisset
    <label class="field">Search<input name="q" value="{{ request('q') }}" placeholder="Customer, device, serial or tracking" maxlength="100" data-repair-keyword></label>
    <label class="field">Status<select name="status"><option value="">All statuses</option>@foreach(\App\Support\Repairs::STATUS as $key=>$status)<option value="{{ $key }}" @selected(request('status')===$key)>{{ $status }}</option>@endforeach</select></label>
    <label class="field">Technician<select name="technician_id"><option value="">All technicians</option>@foreach($technicians as $person)<option value="{{ $person->id }}" @selected(request('technician_id')==$person->id)>{{ $person->name }}</option>@endforeach</select></label>
    <label class="field">Payment method<select name="payment_method_id"><option value="">All methods</option>@foreach($methods as $method)<option value="{{ $method->id }}" @selected(request('payment_method_id')==$method->id)>{{ $method->name }}</option>@endforeach</select></label>
    @if(isset($banks) && $banks->isNotEmpty())
        <label class="field">Bank account<select name="bank_account_id"><option value="">All accounts</option>@foreach($banks as $bank)<option value="{{ $bank->id }}" @selected(request('bank_account_id')==$bank->id)>{{ $bank->bank_name }} · {{ $bank->account_name }} · {{ substr($bank->account_number,-4) }}</option>@endforeach</select></label>
    @endif
    <label class="field">From<input type="date" name="from" value="{{ request('from') }}"></label>
    <label class="field">To<input type="date" name="to" value="{{ request('to') }}"></label>
    <label class="field">Attention<select name="due"><option value="">All jobs</option><option value="overdue" @selected(request('due')==='overdue')>Overdue</option><option value="unpaid" @selected(request('due')==='unpaid')>Unpaid</option></select></label>
    <label class="field">Sort<select name="sort"><option value="recent">Latest intake</option><option value="oldest" @selected(request('sort')==='oldest')>Earliest intake</option><option value="due" @selected(request('sort')==='due')>Due soonest</option></select></label>
    @if(request('customer_id'))<input type="hidden" name="customer_id" value="{{ request('customer_id') }}">@endif
    <div class="repair-actions"><button class="btn secondary">Apply filters</button><a class="text-link" href="{{ url()->current() }}{{ isset($kind) ? '?view='.$kind : '' }}">Reset</a></div>
</form>
