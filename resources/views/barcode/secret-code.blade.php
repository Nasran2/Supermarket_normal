@if(\App\Services\SecretCodeService::enabled() && $c['show_secret_code'] && ($c['secret_code_position']??'bottom')===$position && isset($label['secret_code']))
<div class="barcode-label-secret_code" style="text-align:{{ $c['secret_code_alignment']??'right' }}">{{ $label['secret_code'] }}</div>
@endif
