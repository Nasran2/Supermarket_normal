@extends('developer.layout')
@section('title', 'Feature access controls')
@section('header-actions')
<form method="POST" action="{{ route('developer.logout') }}">@csrf<button class="btn">Sign out</button></form>
@endsection
@section('content')
@php($selected = old('enabled', array_keys(array_filter($catalog, fn($entry, $key) => $access->selected($key), ARRAY_FILTER_USE_BOTH))))
<div class="developer-intro"><span class="eyebrow">Store feature access</span><h1>Choose what your store can use.</h1><p class="muted">Control every sidebar module, report, dashboard card and action. Turning off a main module hides its actions and blocks direct links for all store accounts, including administrators.</p></div>
@if(session('success'))<div class="notice notice-success" role="status">{{ session('success') }}</div>@endif
@if($errors->any())<div class="notice notice-error" role="alert"><strong>Changes were not saved.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<form method="POST" action="{{ route('developer.update') }}" id="developer-controls-form">
    @csrf @method('PUT')
    <div class="toolbar"><input type="search" id="control-search" aria-label="Find a module or action" placeholder="Find a module, report or action…"><button type="button" class="btn" data-set-controls="enable">Enable all</button><button type="button" class="btn" data-set-controls="disable">Disable all</button></div>
    <div class="developer-grid">
        <nav class="developer-nav" aria-label="Control sections"><strong>Feature groups</strong>@foreach(['Workspace','Inventory','Management'] as $section)<a href="#controls-{{ strtolower($section) }}">{{ $section }}</a>@endforeach<p>Existing role permissions and .env feature switches also apply. Child selections are kept when a main module is off.</p></nav>
        <div>
            @foreach(['Workspace','Inventory','Management'] as $section)
                <div class="section-heading" id="controls-{{ strtolower($section) }}"><h2>{{ $section }}</h2><p>Enable a module to manage its individual actions.</p></div>
                @foreach($modules as $key => $module)@if($module['section'] === $section && !$module['parent'])@include('developer.module')@endif @endforeach
            @endforeach
            <input type="hidden" name="controls_complete" value="1">
            <div class="save-bar"><div><strong id="control-save-state">Your selections apply after saving.</strong><p>Disabled pages show the developer’s contact details.</p></div><button class="btn btn-primary" type="submit">Save access changes</button></div>
        </div>
    </div>
</form>
@endsection
@push('scripts')
<script>
(() => {
    const form = document.getElementById('developer-controls-form');
    const modules = @json($modules);
    const extraParents = @json(\App\Services\DeveloperAccess::dependencies());
    const toggles = new Map([...form.querySelectorAll('[data-module-toggle]')].map(input => [input.dataset.moduleToggle, input]));
    const enabled = key => {
        if (!toggles.get(key)?.checked) return false;
        const parent = modules[key]?.parent;
        return (!parent || enabled(parent)) && (!extraParents[key] || enabled(extraParents[key]));
    };
    const sync = () => {
        form.querySelectorAll('[data-module]').forEach(card => {
            const key = card.dataset.module;
            const active = enabled(key);
            card.querySelector(':scope > [data-module-body]').hidden = !active;
            card.querySelector(':scope > [data-module-off]').hidden = active;
            card.querySelector(':scope > .module-heading [data-module-status]').textContent = active ? 'Enabled' : 'Disabled';
        });
    };
    form.addEventListener('change', () => { sync(); document.getElementById('control-save-state').textContent = 'You have unsaved changes.'; });
    form.querySelectorAll('[data-set-controls]').forEach(button => button.addEventListener('click', () => {
        form.querySelectorAll('input[type="checkbox"]').forEach(input => { input.checked = button.dataset.setControls === 'enable'; });
        form.dispatchEvent(new Event('change'));
    }));
    document.getElementById('control-search').addEventListener('input', event => {
        const query = event.target.value.trim().toLowerCase();
        form.querySelectorAll('[data-root-module]').forEach(card => { card.hidden = query !== '' && !card.textContent.toLowerCase().includes(query); });
    });
    sync();
})();
</script>
@endpush
