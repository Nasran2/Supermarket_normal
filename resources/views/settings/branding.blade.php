@extends('layouts.app')
@section('title','Appearance & branding')
@section('content')
<div class="page-heading"><div><span class="eyebrow">YOUR STORE, YOUR WAY</span><h1>Appearance & branding</h1><p>Personalize the workspace and sign-in page for your store.</p></div><a class="btn secondary" href="{{ route('settings.index') }}"><x-icon name="arrow-left"/>All settings</a></div>
<form method="POST" enctype="multipart/form-data" action="{{ route('settings.update','branding') }}" data-branding-form data-business-name="{{ $values['business_name'] ?? 'Twinsofte' }}" data-business-logo="{{ !empty($values['logo']) ? asset('storage/'.$values['logo']) : '' }}" data-login-logo="{{ !empty($values['login_logo']) ? asset('storage/'.$values['login_logo']) : '' }}">
@csrf @method('PUT')
<div class="branding-layout">
    <div class="branding-controls">
    @foreach([
        ['Names & login text', 'Leave the main name blank to use your business name. Leave the login brand heading blank to use the main name followed by POS.', ['brand_name','brand_subtitle','login_brand_text','login_title','login_description']],
        ['Colors', 'Choose any color using the picker or enter its six-digit hex code. App accent colors apply to buttons and navigation throughout the workspace.', ['theme_color','brand_name_color','brand_subtitle_color','login_accent_color','login_background_color','login_title_color','login_description_color']],
        ['Login icon & image', 'Use your business logo, choose an icon, or upload a separate login image. If no image is available, the selected icon is shown.', ['login_icon_mode','login_icon','login_logo']],
    ] as [$heading,$description,$keys])
    <section class="card branding-section"><h2>{{ $heading }}</h2><p class="muted">{{ $description }}</p><div class="form-grid">
        @foreach($keys as $key)
        @php([$label,$type,$default] = $fields[$key])
        @php($value = old($key, array_key_exists($key,$values) ? $values[$key] : $default))
        @if($key==='login_icon')
        <fieldset class="branding-icon-field full"><legend>{{ $label }}</legend><div class="branding-icon-options">
            @foreach(\App\Support\Branding::icons() as $icon=>$name)
            <label><input type="radio" name="login_icon" value="{{ $icon }}" @checked($value===$icon)><x-icon :name="$icon" :size="24"/><span>{{ $name }}</span></label>
            @endforeach
        </div>@error($key)<small class="text-danger">{{ $message }}</small>@enderror</fieldset>
        @elseif($type==='file')
        <div class="field full"><label for="setting-login_logo">{{ $label }}</label><input id="setting-login_logo" type="file" name="login_logo" accept="image/png,image/jpeg,image/webp"><input type="hidden" name="remove_login_logo" value="{{ old('remove_login_logo',0) }}">
        <small>Choose an image, select “Custom login image” above, then save. This image is separate from your bill logos.</small>
        <div class="branding-upload-current" @if(empty($values['login_logo'])) hidden @endif><img src="{{ !empty($values['login_logo']) ? asset('storage/'.$values['login_logo']) : '' }}" alt="Saved custom login image" class="image-preview"><button class="btn danger" type="button" data-remove-login-logo>Remove custom image</button></div>
        @error($key)<small class="text-danger">{{ $message }}</small>@enderror</div>
        @else
        <div class="field {{ $type==='color'?'branding-color-field':'' }}"><label for="setting-{{ $key }}">{{ $label }}</label>
        @if($type==='color')
        <div class="branding-color-control"><input id="setting-{{ $key }}" type="color" value="{{ preg_match('/^#[0-9a-fA-F]{6}$/',(string)$value) ? $value : $default }}" data-color-picker="{{ $key }}" aria-label="{{ $label }} picker"><input type="text" name="{{ $key }}" value="{{ $value }}" data-color-value="{{ $key }}" aria-label="{{ $label }} hex code" pattern="#[0-9a-fA-F]{6}" maxlength="7" required spellcheck="false"></div>
        @elseif($key==='login_icon_mode')
        <select id="setting-{{ $key }}" name="{{ $key }}">@foreach(['business-logo'=>'Business logo','icon'=>'Built-in icon','custom-logo'=>'Custom login image'] as $id=>$name)<option value="{{ $id }}" @selected($value===$id)>{{ $name }}</option>@endforeach</select>
        @else
        <input id="setting-{{ $key }}" type="text" name="{{ $key }}" value="{{ $value }}" maxlength="{{ $key==='login_description'?250:($key==='login_brand_text'?180:($key==='brand_name'?150:100)) }}" @required(str_starts_with($fields[$key][3],'required')) @if($key==='brand_name')placeholder="{{ $values['business_name'] ?? 'Twinsofte' }}"@elseif($key==='login_brand_text')placeholder="Main name + POS"@endif>
        @endif
        @error($key)<small class="text-danger">{{ $message }}</small>@enderror</div>
        @endif
        @endforeach
    </div></section>
    @endforeach
    </div>
    <aside class="card branding-preview" aria-label="Branding preview">
        <h2>Live preview</h2><p class="muted">Changes go live after you save.</p>
        <div class="branding-sidebar-preview"><span class="brand-mark">@if(!empty($values['logo']))<img src="{{ asset('storage/'.$values['logo']) }}" alt="Business logo">@else<x-icon name="shopping-basket" :size="24"/>@endif</span><div><strong data-preview="brand_name"></strong><span data-preview="brand_subtitle"></span></div></div>
        <div class="branding-nav-preview"><x-icon name="layout-dashboard"/>Dashboard</div>
        <div class="branding-login-preview">
            <div class="branding-login-card">
                <img data-preview-image alt="Login image" hidden>
                <span class="branding-preview-icon">@foreach(\App\Support\Branding::icons() as $icon=>$name)<span data-preview-icon="{{ $icon }}" hidden><x-icon :name="$icon" :size="30"/></span>@endforeach</span>
                <strong class="branding-preview-heading" data-preview="login_brand_text"></strong>
                <h3 data-preview="login_title"></h3><p data-preview="login_description"></p>
                <div class="branding-preview-input">Username or email</div><div class="branding-preview-input">Password</div>
                <span class="branding-preview-button">Sign in <x-icon name="arrow-right" :size="16"/></span>
            </div>
        </div>
        <button type="button" class="btn secondary" data-branding-reset>Reset appearance to defaults</button>
        <small class="muted">Reset keeps your uploaded image. Save to apply the defaults.</small>
    </aside>
</div>
<div class="card form-footer branding-save"><a class="btn secondary" href="{{ route('settings.index') }}">Cancel</a><button class="btn primary" type="submit"><x-icon name="check"/>Save changes</button></div>
<script type="application/json" data-branding-defaults>@json(collect($fields)->map(fn($field)=>$field[2]))</script>
</form>
@endsection
