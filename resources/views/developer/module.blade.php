@php
    $module = $modules[$key];
    $actions = array_filter($catalog, fn($entry) => $entry['type'] === 'permission' && $entry['module'] === $key);
    $children = array_filter($modules, fn($entry) => $entry['parent'] === $key);
@endphp
<section class="module-card" data-module="{{ $key }}" @if(!$module['parent'])data-root-module @endif>
    <div class="module-heading">
        <div><h3>{{ $module['label'] }}</h3>@if($module['requires'])<small>Also requires {{ $module['requires'] }}=true in .env</small>@endif</div>
        <label class="toggle"><span data-module-status>Enabled</span><input type="checkbox" name="enabled[]" value="module:{{ $key }}" data-module-toggle="{{ $key }}" aria-label="Enable {{ $module['label'] }}" @checked(in_array('module:'.$key, $selected, true))><span class="toggle-track" aria-hidden="true"></span></label>
    </div>
    <p class="module-off" data-module-off hidden>Disabled. Its pages and actions are unavailable to store users.</p>
    <div class="module-body" data-module-body @if(!$access->moduleAllowed($key))hidden @endif>
        @if($actions)<div class="action-grid">@foreach($actions as $controlKey => $entry)
            <label class="action-option"><input type="checkbox" name="enabled[]" value="{{ $controlKey }}" @checked(in_array($controlKey, $selected, true))><span><strong>{{ $entry['label'] }}</strong><small>{{ $entry['description'] }}</small></span></label>
        @endforeach</div>@endif
        @foreach($children as $childKey => $child)@include('developer.module', ['key' => $childKey])@endforeach
        @if(!$actions && !$children)<p class="muted" style="font-size:12px;margin:16px 0 0">This switch controls access to the page and its sidebar link.</p>@endif
    </div>
</section>
