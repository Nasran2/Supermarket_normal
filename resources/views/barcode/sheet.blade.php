@php
$preview=$preview??false;
$printing=app(\App\Services\BarcodePrintService::class);
$sheets=$printing->sheets($labels,$c,$preview);
$svgCache=[];
$pageHeight=$c['paper_height'] ?? (count($sheets[0]??[])*(float)$c['sticker_height']+max(0,count($sheets[0]??[])-1)*(float)$c['row_gap']+(float)$c['margin_top']+(float)$c['margin_bottom']+abs((float)$c['offset_y']));
$fontSizes=app(\App\Services\BarcodePresetService::class)->fontSizes($c);
@endphp
<style>
.barcode-sheet{box-sizing:border-box;width:{{ $c['paper_width'] }}mm;{{ $c['paper_height']?'height:'.$c['paper_height'].'mm;':'' }}padding:{{ $c['margin_top'] }}mm {{ $c['margin_right'] }}mm {{ $c['margin_bottom'] }}mm {{ $c['margin_left'] }}mm;background:#fff;color:#000;font-family:Arial,sans-serif;break-after:page;position:relative}
.barcode-sheet:last-child{break-after:auto}.barcode-sheet-rows{display:flex;flex-direction:column;gap:{{ $c['row_gap'] }}mm;position:relative;left:{{ $c['offset_x'] }}mm;top:{{ $c['offset_y'] }}mm}
.barcode-sheet-row{display:flex;gap:{{ $c['column_gap'] }}mm;break-inside:avoid;flex:none}
.barcode-label,.barcode-blank{box-sizing:border-box;flex:none;width:{{ $c['sticker_width'] }}mm;height:{{ $c['sticker_height'] }}mm}
.barcode-label{padding:{{ $c['padding_top'] }}mm {{ $c['padding_right'] }}mm {{ $c['padding_bottom'] }}mm {{ $c['padding_left'] }}mm;text-align:{{ $c['alignment'] }};{{ $c['print_border']?'outline:0.15mm solid #000;':'' }};background:white;color:black}
.barcode-label-content{height:100%;display:flex;flex-direction:column;justify-content:center;gap:0}.barcode-label-content>*{flex:none;box-sizing:border-box;line-height:1.08;margin:0;padding:0;max-width:100%}.barcode-label-graphic{height:{{ $c['barcode_height'] }}mm;display:flex;justify-content:{{ ['left'=>'flex-start','center'=>'center','right'=>'flex-end'][$c['barcode_alignment']] }}}
.barcode-label-graphic svg{display:block;height:{{ $c['barcode_height'] }}mm;max-width:100%;background:#fff}
@foreach(\App\Services\BarcodePresetService::CONTENT as $key)@continue($key==='unit' && ! \App\Support\Units::enabled()).barcode-label-{{ $key }}{font-size:{{ $fontSizes[$key]*0.75 }}pt;font-weight:{{ $c[$key.'_bold']?'700':'400' }}}
@endforeach
.barcode-label-product_name{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:{{ $c['product_name_lines'] }};overflow:hidden;overflow-wrap:anywhere}
.barcode-label-product_name.barcode-name-ellipsis{-webkit-line-clamp:1;display:block;white-space:nowrap;text-overflow:ellipsis;overflow:hidden}
.barcode-label-sku,.barcode-label-unit,.barcode-label-custom_text,.barcode-label-secret_code{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.barcode-preview .barcode-sheet{outline:1px solid #d1d5db}.barcode-preview .barcode-label{outline:1px dashed #cbd5e1}
@page{size:{{ $c['paper_width'] }}mm {{ $pageHeight }}mm;margin:0}
@media print{html,body{margin:0;padding:0;background:#fff}.barcode-print-toolbar{display:none!important}.barcode-sheet{margin:0;box-shadow:none}.barcode-label{print-color-adjust:exact;-webkit-print-color-adjust:exact}}
</style>
@foreach($sheets as $rows)<div class="barcode-sheet"><div class="barcode-sheet-rows">
@foreach($rows as $row)<div class="barcode-sheet-row">@foreach($row as $label)
@if($label===null)<div class="barcode-blank" aria-hidden="true"></div>@else
<div class="barcode-label"><div class="barcode-label-content">
@if($c['show_shop_name'])<div class="barcode-label-shop_name">{{ $label['shop_name'] }}</div>@endif
@if($c['show_product_name'])<div class="barcode-label-product_name barcode-name-{{ $c['product_name_overflow'] }}">{{ $label['name'] }}</div>@endif
@if($c['show_price'])<div class="barcode-label-price">{{ $label['currency'] }} {{ \App\Support\Money::display($label['price']) }}</div>@endif
@if($c['show_barcode'])@php
$svgKey=$label['barcode_type'].'|'.$label['barcode'];
$svgCache[$svgKey]??=app(\App\Services\BarcodeService::class)->svg($label['barcode'],$label['barcode_type'],(string)\Brick\Math\BigDecimal::of($c['sticker_width'])->minus($c['padding_left'])->minus($c['padding_right']),$c['barcode_height']);
@endphp<div class="barcode-label-graphic">{!! $svgCache[$svgKey] !!}</div>@endif
@if($c['show_barcode_number'])<div class="barcode-label-barcode_number">{{ $label['barcode'] }}</div>@endif
@foreach(['sku','unit','expiry','batch','custom_text','secret_code'] as $key)@continue($key==='unit' && ! \App\Support\Units::enabled())
@continue(!$c['show_'.$key] || (in_array($key,['expiry','batch']) && !config('features.expiry')))
@php($value=in_array($key,['custom_text','secret_code'])?$c[$key]:($label[$key]??null))
@if($value)<div class="barcode-label-{{ $key }}">{{ $key==='expiry'?'Exp: '.\Carbon\Carbon::parse($value)->format('d/m/Y'):($key==='batch'?'Batch: '.$value:$value) }}</div>@endif
@endforeach
</div></div>
@endif
@endforeach</div>@endforeach
</div></div>@endforeach
