@if($settings['show_currency_symbol_receipt']??true){{ $settings['currency_symbol']??'Rs.' }} @endif
@if($settings['show_currency_receipt']??false){{ $settings['currency']??'LKR' }} @endif
