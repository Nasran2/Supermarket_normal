<fieldset class="mt-6" data-secret-settings>
<legend>Secret cost code</legend>
<p class="muted">Choose one unique letter for each digit. Letter case is preserved. Only the code prints on labels; the mapping stays in these settings.</p>
<div class="form-grid">
@for($digit=0;$digit<=9;$digit++)
<label class="field">Digit {{ $digit }} → letter<input name="scode_digit_{{ $digit }}" value="{{ old('scode_digit_'.$digit,$secretCode['scode_digit_'.$digit]) }}" pattern="[a-zA-Z]" maxlength="1" required autocomplete="off" spellcheck="false"></label>
@endfor
</div>
<div class="form-grid mt-6">
<label class="field">Disguise code length<select name="scode_padding">@foreach(['PRICE'=>'Match selling-price digit count (and minimum length)','FIXED'=>'Pad to minimum length','NONE'=>'No filler letters'] as $value=>$text)<option value="{{ $value }}" @selected(old('scode_padding',$secretCode['scode_padding'])===$value)>{{ $text }}</option>@endforeach</select></label>
<label class="field">Filler position<select name="scode_padding_side">@foreach(['PREFIX'=>'Before the cost code','SUFFIX'=>'After the cost code'] as $value=>$text)<option value="{{ $value }}" @selected(old('scode_padding_side',$secretCode['scode_padding_side'])===$value)>{{ $text }}</option>@endforeach</select></label>
<label class="field">Filler letters<input name="scode_fillers" value="{{ old('scode_fillers',$secretCode['scode_fillers']) }}" maxlength="16" pattern="[a-zA-Z]+" required autocomplete="off" spellcheck="false"><small>Separate letters from the digit mapping. Random fillers come from this set; staff ignore them when decoding.</small></label>
<label class="field">Minimum code length<input name="scode_min_length" type="number" value="{{ old('scode_min_length',$secretCode['scode_min_length']) }}" min="1" max="32" required><small>Longer real costs are kept in full. Codes are never cut short.</small></label>
<label class="field">Cost precision<select name="scode_decimals">@foreach([0=>'Whole units (round to nearest whole amount)',2=>'Exact cents (last two decoded digits are cents)'] as $value=>$text)<option value="{{ $value }}" @selected((int)old('scode_decimals',$secretCode['scode_decimals'])===$value)>{{ $text }}</option>@endforeach</select><small>Price length uses the same precision. No decimal point prints in the code.</small></label>
<label class="field">Default code size · px<input name="scode_font_size" type="number" min="6" max="32" value="{{ old('scode_font_size',$secretCode['scode_font_size']) }}" required></label>
<label class="field">Default code position<select name="scode_position">@foreach(['top'=>'Top of label','before_price'=>'Before selling price','after_price'=>'After selling price','before_barcode'=>'Before barcode','bottom'=>'Bottom of label'] as $value=>$text)<option value="{{ $value }}" @selected(old('scode_position',$secretCode['scode_position'])===$value)>{{ $text }}</option>@endforeach</select></label>
<label class="field">Default code alignment<select name="scode_alignment">@foreach(['left','center','right'] as $value)<option value="{{ $value }}" @selected(old('scode_alignment',$secretCode['scode_alignment'])===$value)>{{ ucfirst($value) }}</option>@endforeach</select></label>
<label class="check-card"><input type="hidden" name="scode_show" value="0"><input type="checkbox" name="scode_show" value="1" @checked(old('scode_show',$secretCode['scode_show']))><span>Show secret cost code on labels</span></label>
</div>
<div class="notice info mt-6"><p>Example: with 8→G, 5→T, 0→K and filler X, cost 850 and selling price 1250 print as <strong>GTKX</strong> (suffix) or <strong>XGTK</strong> (prefix). The minimum length can also pad codes when cost and price have the same digit count. Label Presets and Advanced Label Setup can override the default size and position.</p></div>
<p class="muted mt-6">Changing the mapping affects newly generated labels. Saved print jobs keep their original code, so retain the old mapping for labels already in use.</p>
</fieldset>
