@extends('layouts.app')
@section('title',$record->exists?'Edit product':'Add product')
@section('content')
@push('head')
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.css" rel="stylesheet">
<style>
    .ts-control { border: 1px solid var(--border); border-radius: var(--radius-md); padding: 0.625rem; font-family: inherit; font-size: 0.9375rem; background-color: var(--card-bg); color: var(--text); box-shadow: 0 1px 2px rgba(0,0,0,0.05); transition: border-color 0.15s, box-shadow 0.15s; }
    .ts-control.focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); }
    .ts-dropdown { border-radius: var(--radius-md); border: 1px solid var(--border); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -4px rgba(0,0,0,0.1); font-size: 0.9375rem; z-index: 50; }
    .ts-dropdown .option { padding: 0.5rem 0.75rem; }
    .ts-dropdown .option.active { background-color: var(--primary-light); color: var(--primary-dark); }
    .tom-select .ts-control > div { background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius-sm); color: var(--text); padding: 2px 8px; }
</style>
@endpush
<div class="page-heading"><div><span class="eyebrow">PRODUCT CATALOGUE</span><h1>{{ $record->exists?'Edit product':'Add product' }}</h1><p>Product details, pricing and units — together in one place.</p></div><a class="btn secondary" href="{{ route('manage.index','products') }}"><x-icon name="arrow-left"/>Products</a></div>
<form class="product-editor" method="POST" enctype="multipart/form-data" action="{{ $record->exists?route('manage.update',['products',$record->id]):route('manage.store','products') }}" id="unit-editor" data-product="1">
@csrf @if($record->exists)@method('PUT')@endif
<section class="card product-details-card">
    <div class="customer-section-heading"><span class="customer-section-icon"><x-icon name="package"/></span><div><h2>Product information</h2><p>Make this item easy to find at checkout.</p></div></div>
    <div class="product-fields">
        <label class="field full"><span>Product name <b class="required">*</b></span><input name="name" value="{{ old('name',$record->name) }}" placeholder="e.g. Ceylon Tea 200g" maxlength="255" required autofocus></label>
        <label class="field full"><span>Barcode / SKU <small>optional, auto-generated if blank</small></span><input name="sku" value="{{ old('sku',$record->sku) }}" placeholder="Scan barcode or enter SKU" maxlength="255"></label>
        <label class="field full"><span>Categories</span>
            <select name="categories[]" id="categories-select" multiple placeholder="Search and select categories...">
                @foreach($options['categories'] as $id => $name)
                    <option value="{{ $id }}" @selected(in_array($id, old('categories', $record->exists ? $record->categories->pluck('id')->toArray() : [])))>{{ $name }}</option>
                @endforeach
            </select>
        </label>
        <label class="field full"><span>Supplier <small>optional</small></span>
            <select name="supplier_id" id="supplier-select" placeholder="Search and select a supplier...">
                <option value=""></option>
                @foreach($options['supplier_id'] as $id => $name)
                    <option value="{{ $id }}" @selected(old('supplier_id', $record->supplier_id) == $id)>{{ $name }}</option>
                @endforeach
            </select>
        </label>
    </div>
</section>
<aside class="card product-image-card">
    <div class="product-image-preview" id="product-image-preview">@if($record->image)<img src="{{ asset('storage/'.$record->image) }}" alt="{{ $record->name }}">@else<x-icon name="package" :size="48"/>@endif</div>
    <h2>Product image</h2><p>A clear photo helps cashiers pick the right product.</p>
    <label class="field"><span>Upload image <small>optional</small></span><input type="file" name="image" id="product-image-input" accept="image/png,image/jpeg,image/webp"><small>PNG, JPG or WebP · up to 2 MB</small></label>
    <label class="customer-active-setting"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" @checked(old('active',$record->exists?$record->active:true))><span><strong>Active product</strong><small>Available for sale at the POS.</small></span></label>
</aside>
<section class="card product-stock-card">
    <div class="customer-section-heading"><span class="customer-section-icon"><x-icon name="boxes"/></span><div><h2>Primary unit & stock</h2><p>Stock, cost and alerts are always tracked in your primary unit.</p></div></div>
    <div class="product-stock-fields">
        <label class="field"><span>Primary stock unit <b class="required">*</b></span><select name="unit_id" id="primary-unit" required><option value="">Select primary unit</option>@foreach($units as $unit)<option value="{{ $unit->id }}" data-short="{{ $unit->short_name }}" @selected(old('unit_id',$record->unit_id)==$unit->id)>{{ $unit->name }} ({{ $unit->short_name }})</option>@endforeach</select><small>{{ $record->exists?'The primary unit is protected once stock or transactions exist.':'Choose pieces, weight or volume as your stock unit.' }}</small></label>
        @if($record->exists)
        @can('products.view_cost')<label class="field"><span>Default cost / primary unit</span><input name="cost" type="number" min="0" max="999999999" step="0.01" value="{{ old('cost',$record->cost) }}" required @cannot('products.manage_prices') readonly @endcannot><small>Suggested cost for new stock. Existing stock costs stay unchanged.</small></label>@else<input type="hidden" name="cost" value="0">@endcan
        <label class="field"><span>Default selling price / primary unit</span><input name="price" id="primary-price" type="number" min="0" max="999999999" step="0.01" value="{{ old('price',$record->price) }}" required @cannot('products.manage_prices') readonly @endcannot><small>Suggested price for new stock. Manage existing prices on the product page.</small></label>
        <div class="product-stock-readout"><span>Current stock</span><strong>{{ (float)$record->stock }} <small>{{ $record->unit->short_name }}</small></strong><a class="text-link" href="{{ route('adjustments.create') }}">Adjust stock <x-icon name="arrow-right" :size="14"/></a></div>
        @endif
        <label class="field"><span>Low stock alert <b class="required">*</b></span><input name="low_stock" type="number" min="0" max="999999" step="0.001" inputmode="decimal" value="{{ old('low_stock',$record->low_stock??'0') }}" required><small>Alert level in the primary unit.</small></label>
    </div>
    @if(!$record->exists)
    <div class="opening-prices" data-opening-prices><div class="opening-prices-header"><div><h3>Opening stock & pricing</h3><p>Enter one price, or add another row for stock with a different cost or selling price.</p></div><button class="btn secondary small" type="button" data-add-opening><x-icon name="plus"/>Add another price</button></div><div data-opening-body>
    @foreach(old('opening_layers',[['quantity'=>old('stock','0'),'cost'=>old('cost','0.00'),'selling_price'=>old('price','0.00')]]) as $index=>$row)
    @include('products.partials.opening-row',['index'=>$index,'row'=>$row,'first'=>$loop->first])
    @endforeach
    </div></div>
    <template id="opening-price-template">@include('products.partials.opening-row',['index'=>'__INDEX__','row'=>['quantity'=>0,'cost'=>0,'selling_price'=>0],'first'=>false])</template>
    @endif
</section>
@include('products.partials.conversions',['product'=>true])
<div class="customer-editor-footer"><a class="btn secondary" href="{{ route('manage.index','products') }}">Cancel</a><button class="btn primary" type="submit"><x-icon name="check"/>{{ $record->exists?'Save product':'Create product' }}</button></div>
</form>
@endsection
@push('scripts')
@vite('resources/js/product-units.js')
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (document.getElementById('categories-select')) {
            new TomSelect('#categories-select', {
                plugins: ['remove_button'],
                maxOptions: 50,
                create: false,
                placeholder: 'Search and select categories...',
                dropdownParent: 'body'
            });
        }
        if (document.getElementById('supplier-select')) {
            new TomSelect('#supplier-select', {
                maxOptions: 50,
                create: false,
                placeholder: 'Search and select a supplier...',
                dropdownParent: 'body'
            });
        }
    });
</script>
@endpush
