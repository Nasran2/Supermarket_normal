@props(['text', 'replace' => []])
<span class="documentation-copy">@foreach(app(\App\Services\DocumentationService::class)->copies($text, $replace) as $language=>$copy)<span lang="{{ $language }}" @class(['documentation-translation'=>$language!=='en'])>{{ $copy }}</span>@endforeach</span>
