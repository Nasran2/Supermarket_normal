<section class="card repair-panel repair-stack">
    <div class="card-heading"><div><h2>Parts & direct costs</h2><p class="muted">Planned parts leave stock when you mark them consumed.</p></div></div>
    <div class="repair-table"><table><thead><tr><th>Part</th><th>Quantity</th><th>Unit price</th>@if($canCost)<th>Total cost</th>@endif<th>State</th><th>Actions</th></tr></thead><tbody>
    @forelse($job->parts as $part)
        <tr><td>{{ $part->name }}</td><td>{{ $part->quantity }}</td><td>{{ \App\Support\Money::displayPrice($part->price) }}</td>
        @if($canCost)<td>{{ \App\Support\Money::displayCost($part->cost) }}</td>@endif<td>{{ ucfirst(strtolower($part->state)) }}</td>
        <td><div class="repair-actions">@if($editable && $canParts && $part->state !== 'REMOVED')
            @foreach($part->state==='PLANNED' ? ['consume','remove'] : ['remove'] as $action)
            <form method="post" action="{{ route('repairs.parts.change',[$job,$part,$action]) }}" data-repair-confirm="{{ $action==='consume' ? 'Deduct this part from stock?' : 'Remove this part and restore any consumed stock?' }}">@csrf<button class="btn secondary">{{ ucfirst($action) }}</button></form>
            @endforeach
        @endif</div></td></tr>
    @empty<tr><td colspan="{{ $canCost ? 6 : 5 }}" class="muted">No parts planned yet.</td></tr>@endforelse
    </tbody></table></div>
    @if($editable && $canParts)
    <form method="post" action="{{ route('repairs.parts.add',$job) }}" class="repair-fields" data-repair-part>@csrf
        <input type="hidden" name="submission" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
        <div class="repair-picker" data-repair-picker="{{ route('repairs.products') }}"><label class="field">Repair product<input data-picker-query type="search" autocomplete="off" placeholder="Search by name or SKU" required></label>
            <input type="hidden" name="product_id" data-picker-value><div class="repair-picker-results" data-picker-results hidden></div></div>
        <label class="field">Quantity<input type="number" name="quantity" value="1" step=".001" min=".001" required></label>
        <label class="field">Unit price<input type="number" name="price" step=".01" min="0" placeholder="Product default"></label>
        <div class="repair-actions"><button class="btn secondary">Plan part</button></div>
    </form>
    @endif
    @if($canCost && $job->costs->isNotEmpty())
        <ul class="repair-history">@foreach($job->costs as $cost)<li>{{ $cost->description }} · {{ \App\Support\Money::displayCost($cost->amount) }}</li>@endforeach</ul>
    @endif
    @if($editable && $canEdit && auth()->user()->hasPermission('expenses.create'))
    <details><summary>Record an outside repair or supplier cost</summary>
        <form method="post" action="{{ route('repairs.cost',$job) }}" class="repair-fields repair-detail-form">@csrf
            <input type="hidden" name="submission" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <label class="field">Description<input name="description" maxlength="250" required></label>
            <label class="field">Supplier<select name="supplier_id"><option value="">No linked supplier</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach</select></label>
            <label class="field">Amount<input name="amount" type="number" min=".01" step=".01" required></label>
            <label class="field">Paid by<select name="payment_method_id"><option value="">Accrued cost / no payment recorded</option>@foreach($methods as $method)<option value="{{ $method->id }}">{{ $method->name }}</option>@endforeach</select></label>
            @if($banks->isNotEmpty())<label class="field">Bank account (optional)<select name="bank_account_id"><option value="">No linked account</option>@foreach($banks as $bank)<option value="{{ $bank->id }}">{{ $bank->bank_name }} · {{ $bank->account_name }} · {{ substr($bank->account_number,-4) }}</option>@endforeach</select></label>@endif
            <div><button class="btn secondary">Record cost</button></div>
        </form>
    </details>
    @endif
</section>
