@extends('layouts.app')
@section('title','Customer profile')
@section('content')
@php($currency = $settings['currency_symbol']??'Rs.')
<div class="page-heading"><div class="customer-profile-identity"><span class="customer-avatar large">{{ mb_strtoupper(mb_substr($record->name,0,1)) }}</span><div><span class="eyebrow">CUS-{{ str_pad($record->id,5,'0',STR_PAD_LEFT) }}</span><h1>{{ $record->name }}</h1><span class="badge {{ $record->active?'green':'slate' }}">{{ $record->active?'Active customer':'Inactive customer' }}</span></div></div><div class="heading-actions"><a class="btn secondary" href="{{ route('manage.index','customers') }}"><x-icon name="arrow-left"/>Customers</a>@can('customers.ledger')<a class="btn secondary" href="{{ route('manage.customers.ledger', $record->id) }}"><x-icon name="file-text"/>Ledger</a>@endcan @can('customers.edit')<a class="btn primary" href="{{ route('manage.edit',['customers',$record->id]) }}"><x-icon name="pencil"/>Edit customer</a>@endcan</div></div>
<div class="customer-profile-stats">
    <div class="card customer-profile-due"><span class="eyebrow">OUTSTANDING DUE</span><strong>{{ $currency }} {{ \App\Support\Money::display($record->due_balance) }}</strong><div style="display:flex; justify-content:space-between; align-items:center; margin-top:8px;"><span>{{ \App\Support\Money::compare($record->due_balance,0)>0?'Opening balance + unpaid invoices':'No outstanding balance' }}</span>@if(\App\Support\Money::compare($record->due_balance,0)>0)@can('customers.collect_payment')<button class="btn primary" onclick="document.getElementById('payment-modal').showModal()">Collect Payment</button>@endcan @endif</div></div>
    <div class="card customer-profile-stat"><span>Invoiced purchases</span><strong>{{ $currency }} {{ \App\Support\Money::display($record->paid_purchases??0) }}</strong><small>Completed POS sales, including customer fees</small></div>
    <div class="card customer-profile-stat"><span>Completed purchases</span><strong>{{ $record->completed_sales_count }}</strong><small>Voided sales are excluded</small></div>
</div>
<div class="customer-profile-body">
    <section class="card customer-contact-card"><div class="customer-section-heading"><span class="customer-section-icon"><x-icon name="contact"/></span><div><h2>Contact details</h2><p>Customer account information</p></div></div><dl><div><dt>Phone</dt><dd>{{ $record->phone??'Not added' }}</dd></div><div><dt>Email</dt><dd>{{ $record->email??'Not added' }}</dd></div><div><dt>Address</dt><dd class="customer-address">{{ $record->address??'Not added' }}</dd></div><div><dt>Customer since</dt><dd>{{ $record->created_at->format('d M Y') }}</dd></div></dl><div class="customer-profile-opening"><span>Old balance / opening due</span><strong>{{ $currency }} {{ \App\Support\Money::display($record->opening_due) }}</strong><p>This is the unpaid balance brought into the customer account. New POS sales and their payments are recorded separately.</p></div></section>
    
    <div style="display: flex; flex-direction: column; gap: 24px; min-width: 0;">
        <section class="card customer-purchase-history">
            <div class="card-heading"><div><h2>Purchase history</h2><p class="muted text-sm">{{ $sales->total() }} recorded {{ \Illuminate\Support\Str::plural('sale',$sales->total()) }}</p></div></div>
            <div class="table-wrap">
                <table style="width: 100%;">
                    <thead><tr><th>Invoice</th><th>Date</th><th>Payment</th><th>Amount collected</th><th>Due</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse($sales as $sale)
                        <tr><td>@can('sales.view')<a class="text-link" href="{{ route('sales.show',$sale) }}">{{ $sale->invoice }}</a>@else{{ $sale->invoice }}@endcan </td><td>{{ $sale->sold_at->format('d/m/Y H:i') }}</td><td>{{ $sale->payment_names ?: 'On account (unpaid)' }}</td><td>{{ $currency }} {{ \App\Support\Money::display(\App\Support\Money::sub($sale->collected_total,$sale->refunded_total)) }}</td><td>{{ $currency }} {{ \App\Support\Money::display($sale->due_balance) }}</td><td><span class="badge {{ $sale->status==='ACTIVE'?(\App\Support\Money::compare($sale->due_balance,0)>0?'amber':'green'):'slate' }}">{{ $sale->payment_status }}</span></td></tr>
                        @empty
                        <tr><td colspan="6"><div class="empty-state"><x-icon name="receipt-text" :size="34"/><h3>No purchases yet</h3><p>Sales linked to this customer will appear here.</p></div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="pagination">{{ $sales->links() }}</div>
        </section>

        <section class="card customer-payment-history">
            <div class="card-heading"><div><h2>Payment history</h2><p class="muted text-sm">Customer dues collections</p></div></div>
            <div class="table-wrap">
                <table style="width: 100%;">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Method</th>
                            <th style="text-align:right;">Amount</th>
                            <th>Allocated to</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($record->customerPayments()->with(['paymentMethod', 'allocations.sale'])->latest('payment_date')->take(10)->get() as $payment)
                        <tr>
                            <td>{{ \Carbon\Carbon::parse($payment->payment_date)->format('d/m/Y') }}</td>
                            <td>{{ $payment->paymentMethod->name ?? 'Unknown' }}</td>
                            <td style="text-align:right; font-weight:500;">{{ $currency }} {{ \App\Support\Money::display($payment->amount) }}</td>
                            <td>
                                <div style="display:flex; flex-wrap:wrap; gap:4px;">
                                @foreach($payment->allocations as $alloc)
                                    <span class="badge slate" style="font-size:10px; padding:2px 6px;">{{ $alloc->type === 'OPENING_BALANCE' ? 'Old Balance' : ($alloc->sale->invoice ?? 'Invoice') }}</span>
                                @endforeach
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="4"><div class="empty-state"><x-icon name="wallet" :size="34"/><h3>No payments</h3><p>Collections for outstanding dues will appear here.</p></div></td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
</div>

@can('customers.collect_payment')<dialog id="payment-modal" class="payment-dialog">
    <div class="modal-heading">
        <div>
            <span class="eyebrow">COLLECT PAYMENT</span>
            <h2>Record Customer Payment</h2>
        </div>
        <button class="icon-button" type="button" onclick="document.getElementById('payment-modal').close()"><x-icon name="x"/></button>
    </div>
    <div class="modal-body">
        <form method="POST" action="{{ route('manage.customers.payments.store', $record) }}" id="payment-form">
            @csrf
            <div class="form-grid" style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                <label class="field">Payment Date
                    <input type="date" name="payment_date" value="{{ date('Y-m-d') }}" required>
                </label>
                <label class="field">Payment Method
                    <select name="payment_method_id" required>
                        @foreach(\App\Models\PaymentMethod::where('active',true)->orderBy('display_order')->get() as $method)
                            <option value="{{ $method->id }}">{{ $method->name }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            
            <label class="field">Payment Amount ({{ $currency }})
                <input type="number" step="0.01" name="amount" min="0.01" max="{{ $record->due_balance }}" required id="payment-amount">
            </label>

            <label class="field">Notes (Optional)
                <input type="text" name="notes">
            </label>

            <div style="margin-top:20px; border-top:1px solid var(--line); padding-top:15px;">
                <h3 style="font-size:12px; margin-bottom:10px;">Allocation Strategy</h3>
                <div style="display:flex; gap:15px; font-size:12px;">
                    <label style="display:flex; align-items:center; gap:5px;">
                        <input type="radio" name="mode" value="auto" checked onchange="toggleManualAllocations(false)"> Auto Allocate (Oldest first)
                    </label>
                    <label style="display:flex; align-items:center; gap:5px;">
                        <input type="radio" name="mode" value="manual" onchange="toggleManualAllocations(true)"> Custom Allocation
                    </label>
                </div>
            </div>

            <div id="manual-allocations" style="display:none; margin-top:15px; max-height:200px; overflow-y:auto; border:1px solid var(--line); padding:10px; border-radius:8px;">
                <?php
                    $openingRemaining = \App\Support\Money::sub($record->opening_due ?? '0', $record->opening_due_paid ?? '0');
                ?>
                @if(\App\Support\Money::compare($openingRemaining, 0) > 0)
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                        <span style="font-size:12px;">Opening Balance (Due: {{ $currency }} {{ \App\Support\Money::display($openingRemaining) }})</span>
                        <input type="number" step="0.01" name="allocations[opening_balance]" min="0" max="{{ $openingRemaining }}" class="allocation-input" style="width:100px;">
                    </div>
                @endif
                
                <?php
                    $unpaidSales = $record->sales()->where('status','ACTIVE')->get()->filter(fn($s) => \App\Support\Money::compare($s->due_balance, 0) > 0);
                ?>
                @foreach($unpaidSales as $sale)
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                        <div style="font-size:12px;">
                            <b>{{ $sale->invoice }}</b> ({{ $sale->sold_at->format('d/m/Y') }}) <br>
                            <span class="muted">Due: {{ $currency }} {{ \App\Support\Money::display($sale->due_balance) }}</span>
                        </div>
                        <input type="number" step="0.01" name="allocations[sales][{{ $sale->id }}]" min="0" max="{{ $sale->due_balance }}" class="allocation-input" style="width:100px;">
                    </div>
                @endforeach
                <div style="font-size:11px; color:#b23b44; display:none; margin-top:10px;" id="allocation-warning">Allocated amount does not match payment amount!</div>
            </div>

            <div style="margin-top:20px; display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn secondary" onclick="document.getElementById('payment-modal').close()">Cancel</button>
                <button type="submit" class="btn primary">Record Payment</button>
            </div>
        </form>
    </div>
</dialog>
<script>
    function toggleManualAllocations(show) {
        document.getElementById('manual-allocations').style.display = show ? 'block' : 'none';
        const inputs = document.querySelectorAll('.allocation-input');
        if(!show) {
            inputs.forEach(el => el.value = '');
        }
    }
    
    document.getElementById('payment-form').addEventListener('submit', function(e) {
        if (document.querySelector('input[name="mode"]:checked').value === 'manual') {
            let total = 0;
            document.querySelectorAll('.allocation-input').forEach(el => {
                if(el.value) total += parseFloat(el.value);
            });
            let expected = parseFloat(document.getElementById('payment-amount').value);
            if(Math.abs(total - expected) > 0.01) {
                e.preventDefault();
                document.getElementById('allocation-warning').style.display = 'block';
            }
        }
    });
    
    if (window.location.hash === '#payment-modal') {
        document.getElementById('payment-modal').showModal();
        // Remove hash so it doesn't reopen on reload
        history.replaceState(null, null, ' ');
    }
</script>@endcan
@endsection
