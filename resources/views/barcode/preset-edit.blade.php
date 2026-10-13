@extends('layouts.app')
@section('title',$preset?'Edit Label Preset':'New Label Preset')
@section('content')
<div class="page-heading"><div><span class="eyebrow">BARCODE LABELS</span><h1>{{ $preset?'Edit Label Preset':'New Label Preset' }}</h1><p>Save physical dimensions, content and typography for this paper.</p></div><a class="btn secondary" href="{{ route('barcode.presets.index') }}">All Presets</a></div>
<form id="barcode-preset-editor" data-url="{{ $preset?route('barcode.presets.update',$preset):route('barcode.presets.store') }}" data-method="{{ $preset?'PUT':'POST' }}" data-return="{{ route('barcode.presets.index') }}" class="card barcode-card">
<script type="application/json" id="preset-editor-configuration">@json($configuration)</script>
<div class="notice error" id="preset-editor-error" role="alert" hidden></div><div class="form-grid"><label class="field">Preset Name<input id="preset-editor-name" value="{{ $preset?->name }}" required maxlength="150"></label><label class="inline-check"><input type="checkbox" id="preset-editor-default" @checked($preset?->is_default)>Set as Default</label></div>
@include('barcode.setup')
<div class="form-footer"><button class="btn primary" type="submit">Save Preset</button></div>
</form>
@endsection
@push('scripts')@vite('resources/js/barcode-preset.js')@endpush
