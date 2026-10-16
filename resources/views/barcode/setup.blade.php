@php($measureLabels=['sticker_width'=>'Sticker Width','sticker_height'=>'Sticker Height','paper_width'=>'Paper Width','paper_height'=>'Paper Height (blank = auto / roll)','row_gap'=>'Row Gap (Vertical)','column_gap'=>'Column Gap (Horizontal)','margin_top'=>'Paper Top Margin','margin_bottom'=>'Paper Bottom Margin','margin_left'=>'Paper Left Margin','margin_right'=>'Paper Right Margin','padding_top'=>'Sticker Top Padding','padding_bottom'=>'Sticker Bottom Padding','padding_left'=>'Sticker Left Padding','padding_right'=>'Sticker Right Padding','barcode_height'=>'Barcode Height','offset_x'=>'Horizontal Calibration Offset','offset_y'=>'Vertical Calibration Offset'])
<div class="barcode-setup">
<fieldset><legend>Label dimensions · cm</legend><p class="muted">Paper margins are outside the stickers. Padding is inside each sticker.</p><div class="form-grid">
@foreach($measureLabels as $key=>$label)<label class="field">{{ $label }}<input type="number" data-config="{{ $key }}" data-mm step="0.001" min="{{ str_starts_with($key,'offset_')?'-2':'0' }}" value="{{ $configuration[$key]===null?'':(float)$configuration[$key]/10 }}" @if($key!=='paper_height')required @endif></label>@endforeach
<label class="field">Labels Per Row<input type="number" data-config="labels_per_row" min="1" max="12" value="{{ $configuration['labels_per_row'] }}" required></label><label class="field">Skip Labels (partly used sheet)<input type="number" data-config="skip_labels" min="0" max="1000" value="{{ $configuration['skip_labels'] }}"></label>
</div></fieldset>
<fieldset><legend>Label content</legend><div class="barcode-content-options">
@foreach(\App\Services\BarcodePresetService::CONTENT as $key)@continue($key==='secret_code')@continue($key==='unit' && ! \App\Support\Units::enabled())@continue(in_array($key,['expiry','batch']) && !config('features.expiry'))
<label class="inline-check"><input type="checkbox" data-config="show_{{ $key }}" @checked($configuration['show_'.$key])>{{ ucwords(str_replace('_',' ',$key)) }}</label>
@endforeach
</div><div class="form-grid"><label class="field">Custom Text<input data-config="custom_text" value="{{ $configuration['custom_text'] }}" maxlength="120"></label></div></fieldset>
@if(\App\Services\SecretCodeService::enabled())
<fieldset data-secret-layout><legend>Secret cost code</legend>
<p class="muted">Generated from each selected stock group's unit cost. Configure digit letters and filler rules in Barcode Settings.</p>
<label class="inline-check"><input type="checkbox" data-config="secret_code_use_defaults" @checked($configuration['secret_code_use_defaults'])>Use size, position and visibility from Barcode Settings</label>
<div class="form-grid mt-6" data-secret-overrides>
<label class="inline-check"><input type="checkbox" data-config="show_secret_code" @checked($configuration['show_secret_code'])>Show secret cost code</label>
<label class="field">Code size · px<input type="number" data-config="secret_code_font_size" min="6" max="32" required value="{{ $configuration['secret_code_font_size'] }}"></label>
<label class="field">Code position<select data-config="secret_code_position">@foreach(['top'=>'Top of label','before_price'=>'Before selling price','after_price'=>'After selling price','before_barcode'=>'Before barcode','bottom'=>'Bottom of label'] as $value=>$text)<option value="{{ $value }}" @selected($configuration['secret_code_position']===$value)>{{ $text }}</option>@endforeach</select></label>
<label class="field">Code alignment<select data-config="secret_code_alignment">@foreach(['left','center','right'] as $value)<option value="{{ $value }}" @selected($configuration['secret_code_alignment']===$value)>{{ ucfirst($value) }}</option>@endforeach</select></label>
</div><label class="inline-check"><input type="checkbox" data-config="secret_code_bold" @checked($configuration['secret_code_bold'])>Bold code</label>
</fieldset>
@endif
<fieldset><legend>Typography · px</legend><div class="barcode-fonts">
@foreach(\App\Services\BarcodePresetService::CONTENT as $key)@continue($key==='secret_code')@continue($key==='unit' && ! \App\Support\Units::enabled())@continue($key==='barcode' || (in_array($key,['expiry','batch']) && !config('features.expiry')))
<div><label class="field">{{ ucwords(str_replace('_',' ',$key)) }}<input type="number" data-config="{{ $key }}_font_size" value="{{ $configuration[$key.'_font_size'] }}" min="6" max="32" required></label><label class="inline-check"><input type="checkbox" data-config="{{ $key }}_bold" @checked($configuration[$key.'_bold'])>Bold</label></div>
@endforeach
</div></fieldset>
<fieldset><legend>Barcode & printer</legend><div class="form-grid">
<label class="field">Barcode Format<select data-config="barcode_type">@foreach(['PRODUCT'=>'Use product format','CODE128'=>'CODE128','EAN13'=>'EAN-13','EAN8'=>'EAN-8','UPCA'=>'UPC-A'] as $v=>$label)<option value="{{ $v }}" @selected($configuration['barcode_type']===$v)>{{ $label }}</option>@endforeach</select><small>Black bars, white background and automatic quiet zones.</small></label>
@foreach(['alignment'=>'Content Alignment','barcode_alignment'=>'Barcode Alignment'] as $key=>$label)<label class="field">{{ $label }}<select data-config="{{ $key }}">@foreach(['left','center','right'] as $v)<option @selected($configuration[$key]===$v)>{{ $v }}</option>@endforeach</select></label>@endforeach
<label class="field">Orientation<select data-config="orientation">@foreach(['portrait','landscape'] as $v)<option @selected($configuration['orientation']===$v)>{{ $v }}</option>@endforeach</select><small>For fixed pages, changing orientation swaps paper width and height. For rolls, enter the width measured across the roll.</small></label>
<label class="field">Product Name Maximum Lines<select data-config="product_name_lines">@foreach([1,2] as $v)<option @selected($configuration['product_name_lines']===$v)>{{ $v }}</option>@endforeach</select></label>
<label class="field">Product Name Overflow<select data-config="product_name_overflow">@foreach(['ellipsis','wrap','shrink'] as $v)<option @selected($configuration['product_name_overflow']===$v)>{{ $v }}</option>@endforeach</select></label>
<label class="inline-check"><input type="checkbox" data-config="auto_fit" @checked($configuration['auto_fit'])>Auto Fit Text (barcode size stays fixed)</label><label class="inline-check"><input type="checkbox" data-config="print_border" @checked($configuration['print_border'])>Print Sticker Border</label>
</div></fieldset>
</div>
