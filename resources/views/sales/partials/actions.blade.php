<div class="sale-actions {{ ($compact ?? false) ? 'compact' : '' }}">
    @if($showView ?? true)<a class="btn secondary" href="{{ route('sales.show', $sale) }}" aria-label="View {{ $sale->invoice }}"><x-icon name="eye" :size="16"/>View</a>@endif
    @if($sale->status === 'ACTIVE')
        @can('sales.edit')@if(auth()->user()->hasPermission('pos.access') && $sale->returns->isEmpty() && $sale->collections->isEmpty() && ((!$sale->register->closed_at && (int)$sale->register->open_user_id===auth()->id()) || auth()->user()->isAdministrator()))<a class="btn secondary" href="{{ route('sales.edit', $sale) }}" aria-label="Edit {{ $sale->invoice }}"><x-icon name="pencil" :size="16"/>Edit</a>@endif
@endcan
        @can('sales_returns.create')
            @if($sale->has_returnable_items)<a class="btn secondary" href="{{ route('returns.create', ['sales','original_id'=>$sale->id]) }}" aria-label="Return {{ $sale->invoice }}"><x-icon name="package-open" :size="16"/>Return</a>@endif
        @endcan
        @can('sales.delete')
            @if($sale->returns->isEmpty() && $sale->collections->isEmpty() && (!$sale->register->closed_at || auth()->user()->isAdministrator()))<a class="btn danger" href="{{ route('sales.show', [$sale, 'action'=>'delete']) }}" aria-label="Delete {{ $sale->invoice }}"><x-icon name="trash-2" :size="16"/>Delete</a>@endif
        @endcan
        @can('sales.collect_payment')@if(\App\Support\Money::compare($sale->due_balance, 0)>0)<a class="btn primary" href="{{ route('sales.show', [$sale, 'action'=>'pay']) }}" aria-label="Pay due for {{ $sale->invoice }}"><x-icon name="wallet" :size="16"/>Pay due</a>@endif
@endcan
    @endif
</div>
