@extends('layouts.app')
@section('title',$purchase->exists?'Edit purchase':'Add purchase')
@section('content')
<div class="page-heading purchase-page-heading"><div><span class="eyebrow">SUPPLIER DELIVERIES</span><h1>{{ $purchase->exists?'Edit purchase':'Receive a purchase' }}</h1><p>Bring stock in, record your payment and keep the supplier balance clear.</p></div><a class="btn secondary" href="{{ route('purchases.index') }}"><x-icon name="arrow-left"/>Purchases</a></div>
<form method="POST" action="{{ $purchase->exists?route('purchases.update',$purchase):route('purchases.store') }}" id="purchase-form" class="purchase-editor" data-quantity-precision="{{ $settings['quantity_decimals']??3 }}" data-currency="{{ $settings['currency_symbol']??'Rs.' }}" data-payment-tracked="{{ !$purchase->exists || $purchase->payment_tracking?1:0 }}">
@csrf @if($purchase->exists)@method('PUT')@else<input type="hidden" name="auto_reference" value="1"><input type="hidden" name="payment_mode" value="AUTO">@endif
<section class="card purchase-meta-card"><div class="purchase-section-heading"><span class="customer-section-icon"><x-icon name="truck"/></span><div><h2>Delivery details</h2><p>Who supplied the stock and which invoice it belongs to.</p></div><span class="badge green">{{ $purchase->exists?$purchase->reference:'New delivery' }}</span></div><div class="purchase-meta-fields">
<label class="field"><span>Reference <b class="required">*</b></span><input name="reference" value="{{ old('reference',$purchase->reference??$nextReference) }}" required maxlength="255" @if(!$purchase->exists)readonly @endif>@if(!$purchase->exists)<small class="muted">Final monthly number is assigned when saved.</small>@endif</label>
<div class="field"><label for="supplier-search">Supplier <span class="required">*</span></label><div class="purchase-supplier-control"><div class="purchase-search-container" id="supplier-search-container"><input type="hidden" name="supplier_id" id="supplier_id" value="{{ old('supplier_id',$purchase->supplier_id) }}"><input type="text" id="supplier-search" placeholder="Search supplier..." autocomplete="off" required value="{{ $suppliers->firstWhere('id',old('supplier_id',$purchase->supplier_id))?->name }}"><div id="supplier-dropdown" class="search-dropdown">@foreach($suppliers as $supplier)<button type="button" class="search-option supplier-option" data-id="{{ $supplier->id }}" data-name="{{ $supplier->name }}">{{ $supplier->name }}</button>@endforeach</div></div>@can(\App\Support\Resources::permission('suppliers','create'))<button type="button" class="btn secondary" onclick="document.getElementById('add-supplier-modal').showModal()" title="Add Supplier" aria-label="Add supplier"><x-icon name="plus"/></button>@endcan</div></div>
<label class="field"><span>Delivery date <b class="required">*</b></span><input type="date" name="purchase_date" max="{{ today()->toDateString() }}" value="{{ old('purchase_date',$purchase->purchase_date?->toDateString()??today()->toDateString()) }}" required></label>
<label class="field"><span>Notes <small class="muted">optional</small></span><input name="notes" value="{{ old('notes',$purchase->notes) }}" placeholder="Delivery notes or supplier details" maxlength="2000"></label>
</div></section>
<section class="card purchase-items-card"><div class="purchase-section-heading"><span class="customer-section-icon"><x-icon name="package-plus"/></span><div><h2>Purchase items</h2><p>Set the quantity and prices for this delivery.</p></div><span class="badge slate" id="purchase-line-count">0 items</span></div>
<div class="purchase-item-search purchase-search-container" id="product-search-container"><x-icon name="search"/><input type="text" id="product-search" placeholder="Search name, SKU or barcode to add a product..." autocomplete="off" aria-label="Find purchase products"><kbd>Search</kbd><div id="product-dropdown" class="search-dropdown">@foreach($products as $product)<button type="button" class="search-option product-option" data-id="{{ $product->id }}" data-name="{{ $product->name }}" data-sku="{{ $product->sku }}" data-barcode="{{ $product->barcode }}"><strong>{{ $product->name }}</strong> <small>· {{ $product->sku }}</small></button>@endforeach</div></div>
<div class="purchase-line-header" aria-hidden="true"><span>Product</span><span>Unit</span><span>Quantity</span><span>Cost / unit</span><span>Sell / unit</span><span class="text-right">Amount</span><span></span></div><div id="purchase-items"></div>
<div class="purchase-empty" id="purchase-empty"><x-icon name="package-open" :size="34"/><h3>Your delivery starts here</h3><p>Search above to add products to this purchase.</p></div>
<div class="purchase-stock-note"><x-icon name="info" :size="16"/>These selling prices apply to new stock. Existing stock keeps its prices.</div></section>
@include('purchases.partials.charges-form')
<div class="purchase-bottom-grid">
<section class="card purchase-payment-card"><div class="purchase-section-heading"><span class="customer-section-icon"><x-icon name="wallet"/></span><div><h2>{{ $purchase->exists?'Recorded payments':'Supplier payment' }}</h2><p>{{ $purchase->exists?'Payment history stays with this purchase.':'Enter what you give the supplier. Status and change update automatically.' }}</p></div></div>
@if($purchase->exists)<div class="purchase-payment-fields"><p class="muted">{{ $purchase->payment_tracking?'Already paid: '.($settings['currency_symbol']??'Rs.').' '.\App\Support\Money::display($purchase->paid_amount):'Payment status has not been recorded for this older purchase.' }}</p><a class="btn secondary" href="{{ route('purchases.show',$purchase) }}#purchase-payments"><x-icon name="credit-card"/>Manage payments</a><small class="muted">Saving stock changes keeps all recorded payments.</small></div>
@else
<div class="purchase-payment-fields"><p class="purchase-auto-status">Payment status <span id="purchase-auto-status" class="badge amber">Unpaid</span></p>
<div class="purchase-payment-inputs" id="purchase-payment-inputs"><label class="field">Payment method<select name="payment_method_id" id="purchase-payment-method" onchange="document.getElementById('purchase-take-register-container').style.display = (this.options[this.selectedIndex].dataset.type === 'CASH') ? 'flex' : 'none';"><option value="">Choose payment method</option>@foreach($methods as $method)<option value="{{ $method->id }}" data-type="{{ $method->type }}" @selected((string)old('payment_method_id')===(string)$method->id)>{{ $method->name }}</option>@endforeach</select></label><label class="field">Amount paid now<input type="number" name="amount_paid" id="purchase-amount-paid" min="0" max="9999999999999.99" step="0.01" value="{{ old('amount_paid',0) }}" inputmode="decimal" placeholder="0.00"></label><label class="field full"><span>Payment reference <small class="muted">optional</small></span><input name="payment_reference" maxlength="255" value="{{ old('payment_reference') }}" placeholder="Bank reference or payment note"></label><label class="field full" id="purchase-take-register-container" style="display:{{ (collect($methods)->firstWhere('id', old('payment_method_id'))?->type === 'CASH') ? 'flex' : 'none' }}; flex-direction:row; align-items:center; gap:0.5rem; margin-top:-0.5rem;"><input type="hidden" name="take_from_register" value="0"><input type="checkbox" name="take_from_register" id="purchase-take-register" value="1" @checked(old('take_from_register', true))><span>Take cash from open register drawer</span></label></div><p class="purchase-payment-note" id="purchase-payment-note"><x-icon name="info" :size="16"/><span>This purchase will be saved with the full balance due.</span></p></div>
@endif
</section>
<aside class="card purchase-total-card"><span class="eyebrow">PURCHASE SUMMARY</span><h2>Ready to receive</h2><dl><div><dt>Products subtotal</dt><dd id="purchase-subtotal">0.00</dd></div><div><dt>Extra charges</dt><dd id="purchase-charges-total">0.00</dd></div><div><dt>Purchase total</dt><dd id="purchase-total">0.00</dd></div><div><dt>{{ $purchase->exists?'Previously paid':'Paid now' }}</dt><dd id="purchase-summary-paid" data-recorded-paid="{{ $purchase->exists?$purchase->paid_amount:0 }}">0.00</dd></div><div id="purchase-change-row" hidden><dt>Change balance</dt><dd id="purchase-summary-change">0.00</dd></div><div class="purchase-due-total"><dt>Balance due</dt><dd id="purchase-summary-due">0.00</dd></div></dl><p id="purchase-payment-validation" class="text-danger" role="alert" hidden></p><button class="btn primary w-full" id="save-purchase"><x-icon name="check"/>{{ $purchase->exists?'Save purchase changes':'Receive stock & save' }}</button><a class="purchase-cancel" href="{{ route('purchases.index') }}">Cancel</a></aside>
</div>
</form>
<template id="purchase-item-template"><div class="purchase-line"><div class="purchase-line-product"><input type="hidden" data-field="product_id" required><strong data-field-display="name"></strong><span data-field-display="sku"></span><small class="purchase-current-prices" data-field-display="prices"></small><small class="text-danger" data-field-display="price_warning" hidden>Selling below cost</small></div><label class="field"><span>Unit</span><select data-field="unit_id" required></select></label><label class="field"><span>Quantity</span><input data-field="quantity" type="number" step="0.001" min="0.001" value="1" required inputmode="decimal"></label><label class="field"><span>Unit cost</span><input data-field="cost" type="number" step="0.01" min="0" value="0" required inputmode="decimal"></label><label class="field"><span>Selling price</span><input data-field="selling_price" type="number" step="0.01" min="0" value="0" required @cannot('purchases.manage_prices')readonly @endcannot inputmode="decimal"></label><strong class="purchase-line-amount" data-field-display="line_total">0.00</strong><button type="button" class="icon-button text-danger remove-line" title="Remove item"><x-icon name="trash-2"/></button></div></template>
<dialog id="add-supplier-modal" class="customer-dialog purchase-small-dialog">
    <div class="modal-heading">
        <div><span class="eyebrow">NEW SUPPLIER</span><h2>Add Supplier</h2></div>
        <button class="icon-button" type="button" onclick="document.getElementById('add-supplier-modal').close()"><x-icon name="x"/></button>
    </div>
    <div class="modal-body">
        <form id="add-supplier-form" onsubmit="submitNewSupplier(event)">
            @csrf
            <p class="text-danger" id="supplier-error" role="alert" hidden></p>
            <div class="form-grid">
                <label class="field">Name <input type="text" name="name" required></label>
                <label class="field">Phone <input type="text" name="phone"></label>
                <label class="field">Email <input type="email" name="email"></label>
            </div>
            <div style="margin-top:20px; text-align:right;">
                <button type="button" class="btn secondary" onclick="document.getElementById('add-supplier-modal').close()">Cancel</button>
                <button type="submit" class="btn primary">Save Supplier</button>
            </div>
        </form>
    </div>
</dialog>

@endsection
@push('scripts')
<script type="application/json" id="purchase-charges-initial">{!! json_encode(old('charges',$purchase->charges->map(fn($c)=>$c->only('label','amount'))->all()),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) !!}</script>
<script type="application/json" id="purchase-initial">{!! json_encode(old('items',$purchase->exists?$purchase->items->map(fn($i)=>$i->only('product_id','unit_id','quantity','cost','selling_price'))->all():[]),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) !!}</script>
<script type="application/json" id="all-products-data">{!! json_encode($products->map(fn($p) => ['id'=>$p->id, 'name'=>$p->name, 'sku'=>$p->sku, 'cost'=>$p->cost, 'price'=>$p->price, 'unit'=>$p->unit->short_name, 'prices'=>app(\App\Services\StockLayerService::class)->groups($p), 'units'=>app(\App\Services\ProductUnitService::class)->options($p)]),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) !!}</script>
@vite('resources/js/purchase.js')
<script>
function submitNewSupplier(e) {
    e.preventDefault();
    const form = e.target;
    const data = Object.fromEntries(new FormData(form));
    const error = document.getElementById('supplier-error');
    const button = form.querySelector('[type="submit"]');
    error.hidden = true;
    button.disabled = true;
    fetch('{{ route("manage.store", "suppliers") }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('input[name="_token"]').value },
        body: JSON.stringify({...data, active: 1, opening_due: 0})
    })
    .then(async r => {
        const payload = await r.json();
        if (!r.ok || !payload.id) throw new Error(Object.values(payload.errors || {}).flat().join(' ') || payload.message || 'Unable to save supplier.');
        return payload;
    })
    .then(supplier => {
        document.getElementById('supplier_id').value = supplier.id;
        document.getElementById('supplier-search').value = supplier.name;
        document.getElementById('add-supplier-modal').close();
        form.reset();
        
        // Add to dropdown
        const drop = document.getElementById('supplier-dropdown');
        const opt = document.createElement('div');
        opt.className = 'search-option supplier-option';
        opt.dataset.id = supplier.id;
        opt.dataset.name = supplier.name;
        opt.textContent = supplier.name;
        drop.appendChild(opt);
        
        // Re-bind click
        opt.addEventListener('click', () => {
            document.getElementById('supplier_id').value = supplier.id;
            document.getElementById('supplier-search').value = supplier.name;
            drop.classList.remove('show');
        });
    })
    .catch(err => { error.textContent = err.message; error.hidden = false; })
    .finally(() => { button.disabled = false; });
}
</script>
@endpush
