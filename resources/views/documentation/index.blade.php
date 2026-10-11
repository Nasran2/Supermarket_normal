@extends('layouts.app')
@section('title','Documentation')
@section('main-class','documentation-main')
@section('content')
<div class="documentation-home" id="documentation-library" data-documentation-labels="{{ json_encode(app(\App\Services\DocumentationService::class)->labels()) }}">
    @include('documentation.languages')
    <section class="documentation-hero">
        <div class="documentation-hero-copy">
            <span class="eyebrow"><x-documentation-text text="TWINSOFTE · STAFF GUIDE"/></span>
            <h1><x-documentation-text text="A little help."/><br><x-documentation-text text="A smoother shop day."/></h1>
            <p><x-documentation-text text="Simple instructions for everyday work. Choose a task, follow the steps, and watch a small animated example."/></p>
            <label class="documentation-search"><x-icon name="search" :size="22"/><span class="sr-only"><x-documentation-text text="Search documentation"/></span><input type="search" id="documentation-search" placeholder="What would you like to do? Try sale, return or stock…" autocomplete="off"><kbd>/</kbd></label>
            <div class="documentation-hero-details"><span><x-icon name="book-open" :size="16"/><x-documentation-text text=":count easy guides" :replace="['count'=>count($guides)]"/></span><span><x-icon name="circle-check" :size="16"/><x-documentation-text text="No computer experience needed"/></span></div>
        </div>
        <div class="documentation-hero-art" aria-hidden="true"><div class="documentation-art-note"><x-documentation-text text="ONE STEP AT A TIME"/></div><div class="documentation-art-card"><span class="documentation-art-icon"><x-icon name="shopping-basket" :size="32"/></span><strong><x-documentation-text text="You’ve got this."/></strong><p><x-documentation-text text="Find it. Follow it. Finish it."/></p><div><span>1</span><i></i><span>2</span><i></i><span class="done"><x-icon name="check" :size="18"/></span></div></div><span class="documentation-art-float"><x-icon name="circle-check" :size="20"/><x-documentation-text text="Ready for the next customer"/></span></div>
    </section>

    <section class="documentation-start" aria-labelledby="documentation-start-title">
        <div><span class="eyebrow"><x-documentation-text text="NEW HERE?"/></span><h2 id="documentation-start-title"><x-documentation-text text="Start with these three."/></h2><p><x-documentation-text text="A simple path through your first shift."/></p></div>
        <div class="documentation-start-links">
            @foreach(['start-your-shift','make-a-sale','close-your-shift'] as $key)
            <a href="{{ route('documentation.show',$key) }}"><span>{{ $loop->iteration }}</span><div><strong><x-documentation-text :text="$guides[$key]['title']"/></strong><small><x-documentation-text text=":count minute guide" :replace="['count'=>$guides[$key]['minutes']]"/></small></div><x-icon name="arrow-right" :size="18"/></a>
            @endforeach
        </div>
    </section>

    <div class="documentation-browse-heading"><div><h2><x-documentation-text text="Find the help you need"/></h2><p><x-documentation-text text="Pick a topic or search for a task."/></p></div><span id="documentation-result-count" role="status"><x-documentation-text text=":count guides" :replace="['count'=>count($guides)]"/></span></div>
    <div class="documentation-categories" role="group" aria-label="Filter documentation topics"><button class="selected" type="button" data-documentation-category="" aria-pressed="true"><x-documentation-text text="All topics"/></button>@foreach($categories as $category)<button type="button" data-documentation-category="{{ $category }}" aria-pressed="false"><x-documentation-text :text="$category"/></button>@endforeach</div>
    <div class="documentation-card-grid">
        @foreach($guides as $key=>$guide)
        <a class="documentation-card" href="{{ route('documentation.show',$key) }}" data-documentation-card data-category="{{ $guide['category'] }}" data-search="{{ $guide['search'] }}">
            <span class="documentation-card-icon"><x-icon :name="$guide['icon']" :size="24"/></span><span class="documentation-card-category"><x-documentation-text :text="$guide['category']"/></span><h3><x-documentation-text :text="$guide['title']"/></h3><p><x-documentation-text :text="$guide['description']"/></p><div class="documentation-card-footer"><span><x-documentation-text text=":steps steps · :minutes min" :replace="['steps'=>count($guide['steps']),'minutes'=>$guide['minutes']]"/></span><span class="documentation-card-open"><x-documentation-text text="Read guide"/><x-icon name="arrow-right" :size="16"/></span></div>
        </a>
        @endforeach
    </div>
    <div class="documentation-empty" id="documentation-no-results" hidden><x-icon name="search-x" :size="30"/><h3><x-documentation-text text="No guides found"/></h3><p><x-documentation-text text="Try a shorter word, such as “cash”, “product” or “receipt”."/></p><button class="btn secondary" type="button" id="documentation-clear"><x-documentation-text text="Show all guides"/></button></div>

    <section class="documentation-glossary"><div><span class="eyebrow"><x-documentation-text text="PLAIN WORDS"/></span><h2><x-documentation-text text="What does that mean?"/></h2><p><x-documentation-text text="A few shop words you’ll see on screen."/></p></div><dl><div><dt><x-documentation-text text="Stock"/></dt><dd><x-documentation-text text="The goods your store has available."/></dd></div><div><dt><x-documentation-text text="Invoice"/></dt><dd><x-documentation-text text="A saved bill for a sale or purchase."/></dd></div><div><dt><x-documentation-text text="Due / balance"/></dt><dd><x-documentation-text text="Money that still needs to be paid."/></dd></div><div><dt><x-documentation-text text="Credit note"/></dt><dd><x-documentation-text text="The receipt for a return or agreed credit."/></dd></div><div><dt><x-documentation-text text="Cost and selling price"/></dt><dd><x-documentation-text text="Cost is what the store pays. Selling price is what the customer pays."/></dd></div><div><dt><x-documentation-text text="Permission"/></dt><dd><x-documentation-text text="An action your manager allows your account to do."/></dd></div></dl></section>
    <div class="documentation-help-note"><x-icon name="info" :size="20"/><p><x-documentation-text text="Missing a button mentioned in a guide? Your account or store settings may not allow it. Ask your manager. These guides use made-up examples and never change your shop records."/></p><a href="{{ route('documentation.show','common-questions') }}"><x-documentation-text text="Common questions"/><x-icon name="arrow-right" :size="16"/></a></div>
</div>
@endsection
