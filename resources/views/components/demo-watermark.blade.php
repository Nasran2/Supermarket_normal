@props(['printOnly' => true, 'width' => null])
@if(\App\Support\Demo::enabled())
<style>
.demo-watermark{position:fixed;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;z-index:2147483647;overflow:hidden}
.demo-watermark span{font:800 clamp(42px,20vw,180px)/1 Arial,sans-serif;color:rgba(51,107,89,.10);transform:rotate(-30deg);letter-spacing:.03em}
.demo-watermark.print-only{display:none}
.demo-document-label{text-align:center;font:700 11px/1.6 Arial,sans-serif;color:#61776d;margin:8px 0}
@media print{.demo-watermark.print-only{display:flex}.demo-watermark span{color:rgba(51,107,89,.10);-webkit-print-color-adjust:exact;print-color-adjust:exact}}
</style>
<div class="demo-watermark {{ $printOnly ? 'print-only' : '' }}" @if($width)style="width:{{ $width }}mm;left:50%;right:auto;transform:translateX(-50%)"@endif aria-hidden="true"><span @if($width)style="font-size:{{ $width * .25 }}mm"@endif>DEMO</span></div>
@endif
