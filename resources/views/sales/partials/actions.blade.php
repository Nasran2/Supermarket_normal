<div class="sale-actions {{ ($compact ?? false) ? 'compact' : '' }}">
    @if($showView ?? true)<a class="btn secondary" href="{{ route('sales.show', $sale) }}" aria-label="View {{ $sale->invoice }}"><x-icon name="eye" :size="16"/>View</a>@endif
    @if($sale->status === 'ACTIVE')
        @can('sales.edit')@if(auth()->user()->hasPermission('pos.access') && !$sale->register->closed_at && (int)$sale->register->open_user_id===auth()->id() && $sale->returns->isEmpty() && $sale->collections->isEmpty())<a class="btn secondary" href="{{ route('sales.edit', $sale) }}" aria-label="Edit {{ $sale->invoice }}"><x-icon name="pencil" :size="16"/>Edit</a>@endif
@endcan
        @can('sales.void')
            @if($sale->has_returnable_items)<a class="btn secondary" href="{{ route('sales.show', [$sale, 'action'=>'return']) }}" aria-label="Return {{ $sale->invoice }}"><x-icon name="package-open" :size="16"/>Return</a>@endif
            @if(!$sale->register->closed_at && $sale->returns->isEmpty() && $sale->collections->isEmpty())<a class="btn danger" href="{{ route('sales.show', [$sale, 'action'=>'delete']) }}" aria-label="Delete {{ $sale->invoice }}"><x-icon name="trash-2" :size="16"/>Delete</a>@endif
        @endcan
        @can('sales.edit')@if(\App\Support\Money::compare($sale->due_balance, 0)>0)<a class="btn primary" href="{{ route('sales.show', [$sale, 'action'=>'pay']) }}" aria-label="Pay due for {{ $sale->invoice }}"><x-icon name="wallet" :size="16"/>Pay due</a>@endif
@endcan
    @endif
</div>
