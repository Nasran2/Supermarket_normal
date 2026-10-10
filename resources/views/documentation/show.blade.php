@extends('layouts.app')
@section('title',$guide['title'])
@section('main-class','documentation-main')
@section('content')
<article class="documentation-guide" id="documentation-guide">
    <a class="documentation-back" href="{{ route('documentation.index') }}"><x-icon name="arrow-left" :size="17"/>All documentation</a>
    <header class="documentation-guide-header"><div><span class="eyebrow">{{ $guide['category'] }} · {{ $guide['minutes'] }} MINUTE GUIDE</span><h1>{{ $guide['title'] }}</h1><p>{{ $guide['description'] }}</p><div class="documentation-location"><x-icon name="menu" :size="17"/><span><strong>Where to go</strong>{{ $guide['path'] }}</span></div></div><button type="button" class="btn secondary documentation-print" data-documentation-print><x-icon name="printer"/>Print steps</button></header>
    @if($guide['before'])<div class="documentation-before"><x-icon name="info" :size="20"/><p><strong>Before you start</strong>{{ $guide['before'] }}</p></div>@endif
    <div class="documentation-guide-layout">
        <div class="documentation-written-steps">
            <div class="documentation-steps-heading"><h2>Follow these steps</h2><span>{{ count($guide['steps']) }} simple steps</span></div>
            <ol class="documentation-steps">
                @foreach($guide['steps'] as $step)
                <li class="documentation-step {{ $loop->first?'current':'' }}" id="step-{{ $loop->iteration }}" data-written-step="{{ $loop->index }}"><span class="documentation-step-number">{{ $loop->iteration }}</span><div><h3>{{ $step['title'] }}</h3><p>{{ $step['text'] }}</p><button type="button" class="documentation-watch-step" data-watch-step="{{ $loop->index }}" disabled><x-icon name="eye" :size="16"/>Show this step<span class="sr-only">: {{ $step['title'] }}</span></button></div></li>
                @endforeach
            </ol>
            @if($guide['tip'])<aside class="documentation-tip"><span><x-icon name="circle-check" :size="21"/></span><div><h3>Good to know</h3><p>{{ $guide['tip'] }}</p></div></aside>@endif
            <div class="documentation-finish"><x-icon name="circle-check" :size="27"/><div><h3>Ready to try it?</h3><p>Follow the steps at your own pace. If something looks different, ask your manager.</p></div>@if($screenUrl)<a class="btn primary" href="{{ $screenUrl }}">Open this screen<x-icon name="arrow-right" :size="17"/></a>@endif</div>
        </div>
        <aside class="documentation-player" aria-label="Animated example">
            <div class="documentation-player-heading"><span class="documentation-live-dot"></span><strong>Watch the real screens</strong><span>DEMO</span></div>
            <p class="documentation-player-intro">Real screens with sample details. Follow the highlighted area. This example does not change your store.</p>
            <div class="documentation-screen-tools"><button type="button" data-demo-expand disabled><x-icon name="maximize" :size="16"/>View larger</button><button type="button" data-demo-overview aria-pressed="false" disabled>Show full screen</button></div>
            <div class="documentation-demo-viewport documentation-real-demo" id="documentation-demo">
                @foreach($guide['steps'] as $step)
                <section class="documentation-demo-scene" data-demo-scene="{{ $loop->index }}" @if(!$loop->first)hidden @endif aria-label="Example for step {{ $loop->iteration }}">
                    @foreach($step['frames'] as $frame)
                    <div class="documentation-screen-frame" data-demo-frame @if(!$loop->first)hidden @endif>
                        <div class="documentation-screen-stage" style="--screen-ratio:{{ $frame['width'] / $frame['height'] }};--screen-scale:{{ $frame['scale'] }};--screen-pan-x:{{ $frame['panX'] }}%;--screen-pan-y:{{ $frame['panY'] }}%">
                            <div class="documentation-screen-canvas">
                                <img src="{{ asset('help-assets/screens/'.$frame['screen'].'.png') }}" width="{{ $frame['width'] }}" height="{{ $frame['height'] }}" alt="{{ $frame['caption'] }}" draggable="false" decoding="async">
                                @if($frame['box'])
                                <span class="documentation-screen-focus" style="left:{{ $frame['box'][0] }}%;top:{{ $frame['box'][1] }}%;width:{{ $frame['box'][2] }}%;height:{{ $frame['box'][3] }}%" aria-hidden="true"></span>
                                <svg class="documentation-screen-pointer" style="left:{{ $frame['box'][0] + min($frame['box'][2] * .7, 15) }}%;top:{{ $frame['box'][1] + $frame['box'][3] * .55 }}%" viewBox="0 0 28 34" aria-hidden="true"><path d="M3 2v25l7-7 5 11 6-3-5-10h10Z" fill="#173e32" stroke="white" stroke-width="2" stroke-linejoin="round"/></svg>
                                @endif
                            </div>
                            <span class="documentation-screen-badge">REAL UI · SAMPLE STORE</span>
                        </div>
                        <p class="documentation-screen-instruction"><x-icon name="mouse-pointer-2" :size="18"/><span>{{ $frame['caption'] }}</span></p>
                    </div>
                    @endforeach
                </section>
                @endforeach
            </div>
            <div class="documentation-player-caption"><span id="documentation-demo-step">Step 1 of {{ count($guide['steps']) }}</span><strong id="documentation-demo-caption">{{ $guide['steps'][0]['title'] }}</strong></div>
            <div class="documentation-player-progress" role="group" aria-label="Choose an example step">@foreach($guide['steps'] as $step)<button type="button" data-demo-seek="{{ $loop->index }}" class="{{ $loop->first?'selected':'' }}" aria-label="Watch step {{ $loop->iteration }}: {{ $step['title'] }}" @if($loop->first)aria-current="step"@endif disabled></button>@endforeach</div>
            <div class="documentation-player-controls"><button class="btn secondary" type="button" data-demo-previous disabled aria-label="Previous example step"><x-icon name="arrow-left" :size="17"/></button><button class="btn primary" type="button" data-demo-play disabled>Play example</button><button class="btn secondary" type="button" data-demo-next disabled aria-label="Next example step"><x-icon name="arrow-right" :size="17"/></button></div>
            <button class="documentation-replay" type="button" data-demo-replay disabled>Replay from the beginning</button>
            <p class="documentation-player-hint">Use “Show this step” to replay a step. Pause or choose Show full screen whenever you need.</p>
            <noscript><p class="documentation-player-hint">Animated examples need JavaScript. You can still read every step on this page.</p></noscript>
        </aside>
    </div>
    <dialog class="documentation-expanded" id="documentation-expanded" aria-label="Larger walkthrough"><div class="documentation-expanded-heading"><strong>{{ $guide['title'] }} · Example</strong><button type="button" class="icon-button" data-demo-collapse aria-label="Close larger walkthrough"><x-icon name="x" :size="22"/></button></div><div data-demo-expanded-host></div></dialog>
    @if(count($related))<section class="documentation-related"><h2>You might also need</h2><div>@foreach(array_slice($related,0,3,true) as $key=>$other)<a href="{{ route('documentation.show',$key) }}"><x-icon :name="$other['icon']" :size="20"/><strong>{{ $other['title'] }}</strong><x-icon name="arrow-right" :size="17"/></a>@endforeach</div></section>@endif
</article>
@endsection
