<div id="sale-workspace" data-open-action="{{ $openAction }}" data-due="{{ $sale->due_balance }}" data-currency="{{ $currency }}"></div>
@if($sale->status==='ACTIVE')
@can('sales.collect_payment')
@if(\App\Support\Money::compare($sale->due_balance,0)>0)
<dialog id="sale-pay-dialog" class="sale-dialog" aria-labelledby="sale-pay-title">
    <div class="modal-heading"><div><span class="eyebrow">{{ $sale->invoice }}</span><h2 id="sale-pay-title">Receive due payment</h2></div><button type="button" class="icon-button" data-close-sale-dialog aria-label="Close due payment"><x-icon name="x"/></button></div>
    <form method="POST" action="{{ route('sales.collections.store',$sale) }}" class="sale-dialog-body" id="sale-collection-form">@csrf<input type="hidden" name="_action" value="pay"><input type="hidden" name="token" value="{{ old('_action')==='pay'?old('token'):Str::uuid() }}">
        <div class="sale-popup-total"><span>Outstanding balance</span><strong>{{ $currency }} {{ \App\Support\Money::display($sale->due_balance) }}</strong></div>
        <p class="muted">Pay the full balance or enter a partial payment. Original invoice fees are already included; no extra fee is added.</p>
        <label class="field">Payment method<select name="payment_method_id" id="due-payment-method" required>@foreach($methods as $method)<option value="{{ $method->id }}" data-type="{{ $method->type }}" @selected(old('payment_method_id')==$method->id)>{{ $method->name }}</option>@endforeach</select></label>
        <div class="two-col"><label class="field">Amount to pay<input id="due-payment-amount" type="text" inputmode="decimal" name="amount" value="{{ old('_action')==='pay'?old('amount'):$sale->due_balance }}" pattern="[0-9]+(\.[0-9]{1,2})?" maxlength="15" required></label><label class="field"><span id="due-tender-label">Cash received</span><input id="due-payment-tender" type="text" inputmode="decimal" name="amount_paid" value="{{ old('_action')==='pay'?old('amount_paid'):$sale->due_balance }}" pattern="[0-9]+(\.[0-9]{1,2})?" maxlength="15" required></label></div>
        <label class="field">Reference <span class="muted">optional</span><input name="reference" value="{{ old('_action')==='pay'?old('reference'):'' }}" maxlength="255"></label>
        <div class="sale-payment-preview"><div><span>Balance after payment</span><strong id="due-after">—</strong></div><div><span>Cash change</span><strong id="due-change">—</strong></div></div>
        <p class="notice error" id="due-payment-error" hidden role="alert"></p>
        <div class="sale-dialog-actions"><button class="btn secondary" type="button" data-close-sale-dialog>Cancel</button><button class="btn primary" id="save-due-payment"><x-icon name="check"/>Receive payment</button></div>
    </form>
</dialog>
@endif
@endcan
@can('sales.delete')
@if(!$sale->register->closed_at && $sale->returns->isEmpty() && $sale->collections->isEmpty())
<dialog id="sale-delete-dialog" class="sale-dialog" aria-labelledby="sale-delete-title"><div class="modal-heading"><div><span class="eyebrow">{{ $sale->invoice }}</span><h2 id="sale-delete-title">Delete sale</h2></div><button type="button" class="icon-button" data-close-sale-dialog aria-label="Close delete"><x-icon name="x"/></button></div>
    <form class="sale-dialog-body" action="{{ route('sales.destroy',$sale) }}" method="POST">@csrf @method('DELETE')<input type="hidden" name="_action" value="delete"><p>Reverse this invoice, restore stock and reverse its business-paid processing expenses. The invoice remains in history as voided.</p><label class="field">Reason<textarea name="reason" required minlength="3" maxlength="1000" rows="3" placeholder="Why is this sale being deleted?">{{ old('_action')==='delete'?old('reason'):'' }}</textarea></label><div class="sale-dialog-actions"><button class="btn secondary" type="button" data-close-sale-dialog>Keep sale</button><button class="btn danger"><x-icon name="trash-2"/>Delete sale</button></div></form>
</dialog>
@endif
@endcan
@can('sales.return')
@if($itemsRemaining)
<dialog id="sale-return-dialog" class="sale-dialog sale-return-dialog" aria-labelledby="sale-return-title">
    <div class="modal-heading"><div><span class="eyebrow">{{ $sale->invoice }}</span><h2 id="sale-return-title">Return items</h2></div><button type="button" class="icon-button" data-close-sale-dialog aria-label="Close return"><x-icon name="x"/></button></div>
    <form class="sale-dialog-body" method="POST" action="{{ route('sales.returns.store',$sale) }}" id="sale-return-form">@csrf<input type="hidden" name="_action" value="return"><input type="hidden" name="token" value="{{ old('_action')==='return'?old('token'):Str::uuid() }}">
        <div class="sale-return-intro"><p class="muted">Choose quantities to return. Stock goes back to inventory. Refunds use the original prices and discounts.</p><button class="btn secondary" type="button" id="return-all">Return all remaining</button></div>
        <div class="table-wrap"><table class="return-products"><thead><tr><th>Product</th><th>Available</th><th>Return quantity</th><th class="text-right">Return value</th></tr></thead><tbody>
        @foreach($returnable as $index=>$line)@if(\App\Support\Money::compare($line['remaining'],0)>0)
        <tr data-return-line data-whole="{{ $line['item']->quantity }}" data-net="{{ $line['net'] }}" data-returned="{{ $line['returned'] }}" data-refunded="{{ \App\Support\Money::sum($line['item']->returns->pluck('amount')) }}" data-remaining="{{ $line['remaining'] }}"><td><strong>{{ $line['item']->name }}</strong><small class="cell-note">{{ $line['item']->sku }} · {{ $line['item']->unit }}</small><input type="hidden" name="items[{{ $index }}][sale_item_id]" value="{{ $line['item']->id }}"></td><td>{{ rtrim(rtrim($line['remaining'],'0'),'.') }} {{ $line['item']->unit }}</td><td><input type="number" inputmode="decimal" name="items[{{ $index }}][quantity]" value="{{ old('_action')==='return'?old('items.'.$index.'.quantity','0'):'0' }}" min="0" max="{{ $line['remaining'] }}" step="{{ ($line['item']->unitRecord ?? $line['item']->product?->unit)?->allow_decimal ? '0.001' : '1' }}" required data-return-quantity aria-label="Return quantity for {{ $line['item']->name }}"></td><td class="text-right"><strong data-return-value>0.00</strong></td></tr>
        @endif
@endforeach
        </tbody></table></div>
        <div class="sale-payment-preview"><div><span>Items return value</span><strong id="return-total">—</strong></div><div><span>Reduce outstanding due first</span><strong id="return-due-reduction">—</strong></div><div><span>Refund to customer</span><strong id="return-refund">—</strong></div></div>
        <p class="muted text-xs">Payment processing fees remain on the original invoice. Final return value is checked when saved.</p>
        <div class="two-col"><label class="field">Refund method<select name="payment_method_id" id="return-payment-method"><option value="">Choose refund method</option>@foreach($methods as $method)<option value="{{ $method->id }}" @selected(old('_action')==='return'&&old('payment_method_id')==$method->id)>{{ $method->name }}</option>@endforeach</select></label><label class="field">Reason<input name="reason" required minlength="3" maxlength="1000" placeholder="e.g. Customer returned unopened items" value="{{ old('_action')==='return'?old('reason'):'' }}"></label></div>
        <p class="notice error" id="return-error" hidden role="alert"></p><div class="sale-dialog-actions"><button class="btn secondary" type="button" data-close-sale-dialog>Cancel</button><button class="btn primary" id="save-sale-return"><x-icon name="package-open"/>Save return</button></div>
    </form>
</dialog>
@endif
@endcan
@endif
