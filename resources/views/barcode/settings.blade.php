@extends('layouts.app')
@section('title','Barcode Settings')
@section('content')
<div class="page-heading"><div><span class="eyebrow">SETTINGS</span><h1>Barcode Settings</h1><p>Barcode is enabled by the server BARCODE configuration.</p></div><div class="heading-actions">@can('barcode.print')<a class="btn secondary" href="{{ route('barcode.calibration') }}" target="_blank" rel="noopener"><x-icon name="printer"/>Print Test Label</a>@endcan @can('barcode.manage_presets')<a class="btn secondary" href="{{ route('barcode.presets.index') }}">Label Presets</a>@endcan</div></div>
<form class="card form-card wide" method="POST" action="{{ route('barcode.settings.update') }}">@csrf @method('PUT')<div class="form-grid">
@foreach($fields as $key=>$f)@php([$label,$type,$default]=$f)@php($value=old($key,$values[$key]??$default))
@if($type==='checkbox')<label class="check-card"><input type="hidden" name="{{ $key }}" value="0"><input type="checkbox" name="{{ $key }}" value="1" @checked($value)><span>{{ $label }}</span></label>
@else<label class="field">{{ $label }}@if(in_array($type,['type','printer']))<select name="{{ $key }}">@foreach($type==='type'?array_combine(\App\Services\BarcodeService::TYPES,\App\Services\BarcodeService::TYPES):['LABEL'=>'Barcode / Label Printer','NORMAL'=>'Normal Printer','THERMAL'=>'Thermal Roll Printer','CUSTOM'=>'Custom'] as $v=>$text)<option value="{{ $v }}" @selected($value===$v)>{{ $text }}</option>@endforeach</select>@else<input name="{{ $key }}" type="{{ $type }}" value="{{ $value }}" @if($type==='number')min="1" step="1"@endif>@endif</label>@endif
@endforeach
</div><p class="muted">Generated EAN/UPC values include valid check digits. Internal generated codes do not establish a registered retail product identifier. Physical paper sizes and the default preset are configured under Label Presets.</p><div class="form-footer"><button class="btn primary">Save Settings</button></div></form>
@endsection
