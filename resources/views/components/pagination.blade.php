@if ($paginator->hasPages())
<nav class="app-pagination" role="navigation" aria-label="{{ __('Pagination Navigation') }}">
    <p class="pagination-summary">{{ __('Showing') }} {{ $paginator->firstItem() ?? 0 }} {{ __('to') }} {{ $paginator->lastItem() ?? 0 }} {{ __('of') }} {{ $paginator->total() }} {{ __('results') }}</p>
    <div class="pagination-controls">
        @if ($paginator->onFirstPage())
            <span class="pagination-control" aria-disabled="true">{{ __('Previous') }}</span>
        @else
            <a class="pagination-control" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="{{ __('pagination.previous') }}">{{ __('Previous') }}</a>
        @endif
        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="pagination-ellipsis" aria-hidden="true">{{ $element }}</span>
            @else
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="pagination-control" aria-current="page">{{ $page }}</span>
                    @else
                        <a class="pagination-control" href="{{ $url }}" aria-label="{{ __('Go to page :page', ['page' => $page]) }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach
        @if ($paginator->hasMorePages())
            <a class="pagination-control" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="{{ __('pagination.next') }}">{{ __('Next') }}</a>
        @else
            <span class="pagination-control" aria-disabled="true">{{ __('Next') }}</span>
        @endif
    </div>
</nav>
@endif
