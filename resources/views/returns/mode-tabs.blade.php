<nav class="return-mode-tabs" aria-label="Return method">
    <a class="btn {{ $withoutBill?'secondary':'primary' }}" @if(!$withoutBill)aria-current="page"@endif href="{{ route('returns.create','sales') }}"><x-icon name="receipt"/>Return With Bill</a>
    @can('sales_returns.no_receipt')
    @if($settings['no_receipt_enabled']??true)<a class="btn {{ $withoutBill?'primary':'secondary' }}" @if($withoutBill)aria-current="page"@endif href="{{ route('returns.create',['sales','return_type'=>'NO_RECEIPT']) }}"><x-icon name="package"/>Return Without Bill</a>@endif
    @endcan
</nav>
