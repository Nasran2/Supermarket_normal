@extends('layouts.app')
@section('title','Device catalog')
@section('content')
<div class="repair-page repair-stack repair-desk">
    <header class="repair-heading">
        <div class="repair-heading-copy"><span class="repair-kicker">PHONE REPAIRS</span><h1>Device catalog</h1><p>Manage the brands and models staff can pick when receiving a device.</p></div>
        <div class="repair-actions"><a class="btn secondary" href="{{ route('repairs.settings') }}">Repair settings</a>
        @if(auth()->user()->hasPermission('repairs.view') && auth()->user()->hasPermission('repairs.create'))<a class="btn primary" href="{{ route('repairs.create') }}">Receive a device</a>@endif</div>
    </header>
    <div class="repair-catalog-layout">
        <section class="card repair-panel repair-stack">
            <div class="card-heading"><h2>Brands</h2></div>
            <form method="get" action="{{ route('repairs.catalog') }}" class="repair-stack">
                <label class="field">Choose a brand<select name="brand" required><option value="">Select brand</option>
                    @foreach($brands as $item)<option value="{{ $item->id }}" @selected($brand?->id === $item->id)>{{ $item->name }} ({{ $item->models_count }}){{ $item->active ? '' : ' · Archived' }}</option>@endforeach
                </select></label>
                <div><button class="btn secondary">Show models</button></div>
            </form>
            <details class="repair-fold"><summary>Add a brand</summary>
                <form method="post" action="{{ route('repairs.catalog.brands.store') }}" class="repair-stack">@csrf
                    <input type="hidden" name="active" value="1">
                    <label class="field">Brand name<input name="name" required maxlength="80" placeholder="e.g. Sony"></label>
                    <div><button class="btn primary">Add brand</button></div>
                </form>
            </details>
            @if($brand)
                <details class="repair-fold"><summary>Edit {{ $brand->name }}</summary>
                    <form method="post" action="{{ route('repairs.catalog.brands.update',$brand) }}" class="repair-stack">@csrf @method('PUT')
                        <label class="field">Brand name<input name="name" value="{{ $brand->name }}" required maxlength="80"></label>
                        <label class="repair-setting-check"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" @checked($brand->active)> Show this brand at intake</label>
                        <small class="muted">Archiving hides the brand and its models from new intake selections.</small>
                        <div><button class="btn primary">Save brand</button></div>
                    </form>
                </details>
            @endif
        </section>
        <section class="card repair-panel repair-stack">
            @if($brand)
                <div class="card-heading"><div><h2>{{ $brand->name }} models</h2><p class="muted">{{ $models->total() }} matching models{{ $brand->active ? '' : ' · Brand archived' }}</p></div></div>
                <form method="get" action="{{ route('repairs.catalog') }}" class="repair-inline-search">
                    <input type="hidden" name="brand" value="{{ $brand->id }}">
                    <label class="field">Search models<input type="search" name="q" value="{{ request('q') }}" maxlength="120" placeholder="e.g. iPhone 15"></label>
                    <button class="btn secondary">Search</button>
                </form>
                <details class="repair-fold"><summary>Add a model</summary>
                    @include('repairs.partials.catalog-model-form',['model'=>null])
                </details>
                <div class="repair-catalog-models">
                    @forelse($models as $model)
                    <details class="repair-fold"><summary><strong>{{ $model->name }}</strong><span class="repair-badge">{{ $model->active ? $model->inspection : 'Archived' }}</span></summary>
                        @include('repairs.partials.catalog-model-form')
                    </details>
                    @empty<p class="muted">No models match. Try a different search or add a model.</p>@endforelse
                </div>
                {{ $models->links() }}
            @else
                <p class="muted">Add a brand to start building its model list.</p>
            @endif
        </section>
    </div>
</div>
@endsection
