@extends('layouts.app')
@section('title',$record->exists?'Edit product':'Add product')
@section('content')
<div class="page-heading"><div><span class="eyebrow">PRODUCT CATALOGUE</span><h1>{{ $record->exists?'Edit product':'Add product' }}</h1><p>Product details, pricing and units — together in one place.</p></div><a class="btn secondary" href="{{ route('manage.index','products') }}"><x-icon name="arrow-left"/>Products</a></div>
<form class="product-editor" method="POST" enctype="multipart/form-data" action="{{ $record->exists?route('manage.update',['products',$record->id]):route('manage.store','products') }}" id="unit-editor" data-product="1">
@csrf @if($record->exists)@method('PUT')@endif
<section class="card product-details-card">
    <div class="customer-section-heading"><span class="customer-section-icon"><x-icon name="package"/></span><div><h2>Product information</h2><p>Make this item easy to find at checkout.</p></div></div>
    <div class="product-fields">
        <label class="field full"><span>Product name <b class="required">*</b></span><input name="name" value="{{ old('name',$record->name) }}" placeholder="e.g. Ceylon Tea 200g" maxlength="255" required autofocus></label>
        <label class="field"><span>SKU <b class="required">*</b></span><input name="sku" value="{{ old('sku',$record->sku) }}" placeholder="Product code" maxlength="255" required></label>
        <label class="field"><span>Barcode <small>optional</small></span><input name="barcode" value="{{ old('barcode',$record->barcode) }}" placeholder="Scan or enter barcode" maxlength="255"></label>
        <label class="field full"><span>Category <b class="required">*</b></span><select name="category_id" required><option value="">Select category</option>@foreach($options['category_id'] as $id=>$name)<option value="{{ $id }}" @selected(old('category_id',$record->category_id)==$id)>{{ $name }}</option>@endforeach</select></label>
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
        <label class="field"><span>Cost / primary unit <b class="required">*</b></span><input name="cost" type="number" min="0" max="999999999" step="0.01" inputmode="decimal" value="{{ old('cost',$record->cost??'0.00') }}" required></label>
        <label class="field"><span>Selling price / primary unit <b class="required">*</b></span><input name="price" id="primary-price" type="number" min="0" max="999999999" step="0.01" inputmode="decimal" value="{{ old('price',$record->price??'0.00') }}" required></label>
        @if(!$record->exists)<label class="field"><span>Opening stock <b class="required">*</b></span><input name="stock" type="number" min="0" max="999999" step="0.001" inputmode="decimal" value="{{ old('stock','0') }}" required><small>Enter stock in the primary unit.</small></label>@else<div class="product-stock-readout"><span>Current stock</span><strong>{{ (float)$record->stock }} <small>{{ $record->unit->short_name }}</small></strong><a class="text-link" href="{{ route('stock.edit',$record) }}">Adjust stock <x-icon name="arrow-right" :size="14"/></a></div>@endif
        <label class="field"><span>Low stock alert <b class="required">*</b></span><input name="low_stock" type="number" min="0" max="999999" step="0.001" inputmode="decimal" value="{{ old('low_stock',$record->low_stock??'0') }}" required><small>Alert level in the primary unit.</small></label>
    </div>
</section>
@include('products.partials.conversions',['product'=>true])
<div class="customer-editor-footer"><a class="btn secondary" href="{{ route('manage.index','products') }}">Cancel</a><button class="btn primary" type="submit"><x-icon name="check"/>{{ $record->exists?'Save product':'Create product' }}</button></div>
</form>
@endsection
@push('scripts')@vite('resources/js/product-units.js')@endpush
