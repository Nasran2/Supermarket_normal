@extends('layouts.app')
@section('title','Documentation')
@section('main-class','documentation-main')
@section('content')
<div class="documentation-home" id="documentation-library">
    <section class="documentation-hero">
        <div class="documentation-hero-copy">
            <span class="eyebrow">TWINSOFTE · STAFF GUIDE</span>
            <h1>A little help.<br>A smoother shop day.</h1>
            <p>Simple instructions for everyday work. Choose a task, follow the steps, and watch a small animated example.</p>
            <label class="documentation-search"><x-icon name="search" :size="22"/><span class="sr-only">Search documentation</span><input type="search" id="documentation-search" placeholder="What would you like to do? Try sale, return or stock…" autocomplete="off"><kbd>/</kbd></label>
            <div class="documentation-hero-details"><span><x-icon name="book-open" :size="16"/>{{ count($guides) }} easy guides</span><span><x-icon name="circle-check" :size="16"/>No computer experience needed</span></div>
        </div>
        <div class="documentation-hero-art" aria-hidden="true"><div class="documentation-art-note">ONE STEP AT A TIME</div><div class="documentation-art-card"><span class="documentation-art-icon"><x-icon name="shopping-basket" :size="32"/></span><strong>You’ve got this.</strong><p>Find it. Follow it. Finish it.</p><div><span>1</span><i></i><span>2</span><i></i><span class="done"><x-icon name="check" :size="18"/></span></div></div><span class="documentation-art-float"><x-icon name="circle-check" :size="20"/>Ready for the next customer</span></div>
    </section>

    <section class="documentation-start" aria-labelledby="documentation-start-title">
        <div><span class="eyebrow">NEW HERE?</span><h2 id="documentation-start-title">Start with these three.</h2><p>A simple path through your first shift.</p></div>
        <div class="documentation-start-links">
            @foreach(['start-your-shift','make-a-sale','close-your-shift'] as $key)
            <a href="{{ route('documentation.show',$key) }}"><span>{{ $loop->iteration }}</span><div><strong>{{ $guides[$key]['title'] }}</strong><small>{{ $guides[$key]['minutes'] }} minute guide</small></div><x-icon name="arrow-right" :size="18"/></a>
            @endforeach
        </div>
    </section>

    <div class="documentation-browse-heading"><div><h2>Find the help you need</h2><p>Pick a topic or search for a task.</p></div><span id="documentation-result-count" role="status">{{ count($guides) }} guides</span></div>
    <div class="documentation-categories" role="group" aria-label="Filter documentation topics"><button class="selected" type="button" data-documentation-category="" aria-pressed="true">All topics</button>@foreach($categories as $category)<button type="button" data-documentation-category="{{ $category }}" aria-pressed="false">{{ $category }}</button>@endforeach</div>
    <div class="documentation-card-grid">
        @foreach($guides as $key=>$guide)
        <a class="documentation-card" href="{{ route('documentation.show',$key) }}" data-documentation-card data-category="{{ $guide['category'] }}" data-search="{{ mb_strtolower($guide['title'].' '.$guide['description'].' '.$guide['path'].' '.implode(' ',array_column($guide['steps'],'text'))) }}">
            <span class="documentation-card-icon"><x-icon :name="$guide['icon']" :size="24"/></span><span class="documentation-card-category">{{ $guide['category'] }}</span><h3>{{ $guide['title'] }}</h3><p>{{ $guide['description'] }}</p><div class="documentation-card-footer"><span>{{ count($guide['steps']) }} steps · {{ $guide['minutes'] }} min</span><span class="documentation-card-open">Read guide<x-icon name="arrow-right" :size="16"/></span></div>
        </a>
        @endforeach
    </div>
    <div class="documentation-empty" id="documentation-no-results" hidden><x-icon name="search-x" :size="30"/><h3>No guides found</h3><p>Try a shorter word, such as “cash”, “product” or “receipt”.</p><button class="btn secondary" type="button" id="documentation-clear">Show all guides</button></div>

    <section class="documentation-glossary"><div><span class="eyebrow">PLAIN WORDS</span><h2>What does that mean?</h2><p>A few shop words you’ll see on screen.</p></div><dl><div><dt>Stock</dt><dd>The goods your store has available.</dd></div><div><dt>Invoice</dt><dd>A saved bill for a sale or purchase.</dd></div><div><dt>Due / balance</dt><dd>Money that still needs to be paid.</dd></div><div><dt>Credit note</dt><dd>The receipt for a return or agreed credit.</dd></div><div><dt>Cost and selling price</dt><dd>Cost is what the store pays. Selling price is what the customer pays.</dd></div><div><dt>Permission</dt><dd>An action your manager allows your account to do.</dd></div></dl></section>
    <div class="documentation-help-note"><x-icon name="info" :size="20"/><p>Missing a button mentioned in a guide? Your account or store settings may not allow it. Ask your manager. These guides use made-up examples and never change your shop records.</p><a href="{{ route('documentation.show','common-questions') }}">Common questions<x-icon name="arrow-right" :size="16"/></a></div>
</div>
@endsection
