@extends('layouts.app')
@section('title',$record->exists?'Edit unit preset':'Add unit preset')
@section('content')
<div class="page-heading"><div><span class="eyebrow">MULTIPLE UNITS</span><h1>{{ $record->exists?'Edit':'Add' }} unit preset</h1><p>Save conversions once, then load them into matching products.</p></div><a class="btn secondary" href="{{ route('manage.index','unit-presets') }}"><x-icon name="arrow-left"/>Multiple units</a></div>
<form class="product-editor preset-editor" method="POST" action="{{ $record->exists?route('manage.update',['unit-presets',$record->id]):route('manage.store','unit-presets') }}" id="unit-editor">
@csrf @if($record->exists)@method('PUT')@endif
<section class="card product-details-card"><div class="customer-section-heading"><span class="customer-section-icon"><x-icon name="ruler"/></span><div><h2>Preset information</h2><p>Choose the primary unit these conversions are based on.</p></div></div><div class="product-fields"><label class="field"><span>Preset name <b class="required">*</b></span><input name="name" value="{{ old('name',$record->name) }}" placeholder="e.g. Pieces & dozen" maxlength="255" required autofocus></label><label class="field"><span>Primary stock unit <b class="required">*</b></span><select id="primary-unit" name="unit_id" required><option value="">Select primary unit</option>@foreach($units as $unit)<option value="{{ $unit->id }}" data-short="{{ $unit->short_name }}" @selected(old('unit_id',$record->unit_id)==$unit->id)>{{ $unit->name }} ({{ $unit->short_name }})</option>@endforeach</select></label></div></section>
@include('products.partials.conversions',['product'=>false])
<div class="customer-editor-footer"><a class="btn secondary" href="{{ route('manage.index','unit-presets') }}">Cancel</a><button class="btn primary" type="submit"><x-icon name="check"/>Save preset</button></div>
</form>
@endsection
@push('scripts')@vite('resources/js/product-units.js')@endpush
