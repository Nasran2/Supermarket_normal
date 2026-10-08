@extends('layouts.app')
@section('title',$purchase->exists?'Edit purchase':'Add purchase')
@section('content')
<style>
.search-dropdown { border: 1px solid var(--line); border-radius: 8px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); background: #fff; max-height: 250px; overflow-y: auto; position: absolute; width: 100%; z-index: 50; display: none; }
.search-dropdown.show { display: block; }
.search-option { padding: 10px 15px; cursor: pointer; border-bottom: 1px solid var(--line); font-size: 14px; }
.search-option:hover, .search-option.active { background: #f0fdf4; }
.search-option:last-child { border-bottom: none; }
.product-search-input { width: 100%; padding: 14px 18px; font-size: 16px; border: 2px solid var(--primary); border-radius: 8px; outline: none; }
.product-search-input:focus { box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2); }
.purchase-line { display: grid; grid-template-columns: minmax(200px, 1fr) 150px 120px 120px 100px 40px; gap: 15px; align-items: center; background: #f9fafb; padding: 15px; border-radius: 8px; margin-bottom: 10px; border: 1px solid #e5e7eb; }
@media (max-width: 768px) {
    .purchase-line { grid-template-columns: 1fr 1fr; }
    .purchase-line > div:first-child { grid-column: 1 / -1; }
}
.purchase-line-name { font-weight: 600; font-size: 14px; color: #111827; }
.purchase-line-sku { font-size: 12px; color: #6b7280; }
</style>
<div class="page-heading"><div><span class="eyebrow">PURCHASES</span><h1>{{ $purchase->exists?'Edit':'Add' }} purchase</h1><p>Stock updates automatically when you save.</p></div><a class="btn secondary" href="{{ route('purchases.index') }}">Back</a></div>
<form class="card padded" method="POST" action="{{ $purchase->exists?route('purchases.update',$purchase):route('purchases.store') }}" id="purchase-form" data-quantity-precision="{{ $settings['quantity_decimals']??3 }}">
    @csrf @if($purchase->exists)@method('PUT')@endif
    <div class="form-grid">
        <label class="field">Reference<input name="reference" value="{{ old('reference',$purchase->reference) }}" required></label>
        
        <label class="field" style="position:relative;">Supplier
            <div style="display:flex; gap:10px;">
                <div style="position:relative; flex:1;" id="supplier-search-container">
                    <input type="hidden" name="supplier_id" id="supplier_id" value="{{ old('supplier_id',$purchase->supplier_id) }}" required>
                    <input type="text" id="supplier-search" placeholder="Search supplier..." autocomplete="off" style="width:100%" value="{{ $purchase->supplier ? $purchase->supplier->name : '' }}">
                    <div id="supplier-dropdown" class="search-dropdown">
                        @foreach($suppliers as $s)
                            <div class="search-option supplier-option" data-id="{{ $s->id }}" data-name="{{ $s->name }}">{{ $s->name }}</div>
                        @endforeach
                    </div>
                </div>
                <button type="button" class="btn secondary" onclick="document.getElementById('add-supplier-modal').showModal()" title="Add Supplier"><x-icon name="plus"/></button>
            </div>
        </label>
        
        <label class="field">Date<input type="date" name="purchase_date" value="{{ old('purchase_date',$purchase->purchase_date?->toDateString()??today()->toDateString()) }}" required></label>
        <label class="field">Notes<input name="notes" value="{{ old('notes',$purchase->notes) }}"></label>
    </div>
    
    <h2 class="mt-6 mb-4">Purchase items</h2>
    
    <div style="position:relative; margin-bottom: 20px;" id="product-search-container">
        <input type="text" id="product-search" class="product-search-input" placeholder="🔍 Search product to add..." autocomplete="off">
        <div id="product-dropdown" class="search-dropdown">
            @foreach($products as $p)
                <div class="search-option product-option" data-id="{{ $p->id }}" data-name="{{ $p->name }}">
                    <strong>{{ $p->name }}</strong> <small class="muted">· {{ $p->sku }}</small>
                </div>
            @endforeach
        </div>
    </div>

    <div id="purchase-items"></div>
    <div class="form-footer">
        <strong>Purchase total: <span id="purchase-total">0.00</span></strong>
        <button class="btn primary">Save purchase</button>
    </div>
</form>

<template id="purchase-item-template">
<div class="purchase-line">
    <div>
        <input type="hidden" data-field="product_id" required>
        <div class="purchase-line-name" data-field-display="name">Product Name</div>
        <div class="purchase-line-sku" data-field-display="sku">SKU</div>
    </div>
    <label class="field" style="margin:0;">Unit
        <select data-field="unit_id" required></select>
    </label>
    <label class="field" style="margin:0;">Quantity
        <input data-field="quantity" type="number" step="0.001" min="0.001" value="1" required>
    </label>
    <label class="field" style="margin:0;">Unit cost
        <input data-field="cost" type="number" step="0.01" min="0" value="0" required>
    </label>
    <div style="text-align:right; font-weight:bold;">
        <span data-field-display="line_total">0.00</span>
    </div>
    <button type="button" class="icon-button text-danger remove-line" title="Remove item" style="margin-left:auto;"><x-icon name="trash-2"/></button>
</div>
</template>

<dialog id="add-supplier-modal" class="payment-dialog">
    <div class="modal-heading">
        <div><span class="eyebrow">NEW SUPPLIER</span><h2>Add Supplier</h2></div>
        <button class="icon-button" type="button" onclick="document.getElementById('add-supplier-modal').close()"><x-icon name="x"/></button>
    </div>
    <div class="modal-body">
        <form id="add-supplier-form" onsubmit="submitNewSupplier(event)">
            @csrf
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
<script type="application/json" id="purchase-initial">{!! json_encode(old('items',$purchase->exists?$purchase->items->map(fn($i)=>$i->only('product_id','unit_id','quantity','cost'))->all():[]),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) !!}</script>
<script type="application/json" id="all-products-data">{!! json_encode($products->map(fn($p) => ['id'=>$p->id, 'name'=>$p->name, 'sku'=>$p->sku, 'cost'=>$p->cost, 'units'=>app(\App\Services\ProductUnitService::class)->options($p)]),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) !!}</script>
@vite('resources/js/purchase.js')
<script>
function submitNewSupplier(e) {
    e.preventDefault();
    const form = e.target;
    const data = Object.fromEntries(new FormData(form));
    fetch('{{ route("manage.store", "suppliers") }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('input[name="_token"]').value },
        body: JSON.stringify({...data, active: 1, opening_due: 0})
    })
    .then(r => r.json())
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
    .catch(err => alert("Failed to add supplier."));
}
</script>
@endpush
