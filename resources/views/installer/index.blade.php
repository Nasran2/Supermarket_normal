@extends('installer.layout')

@section('content')
<div class="intro">
    <h1>{{ $locked ? 'Installation locked' : 'Set up your POS' }}</h1>
    <p>{{ $locked ? 'An administrator can use the installer PIN to update the shop type and enabled modules.' : 'Choose your shop type, connect an empty database and create your administrator login.' }}</p>
</div>

@if(session('installer_status'))<div class="notice success" role="status">{{ session('installer_status') }}</div>@endif
@if($errors->any())
    <div class="notice" role="alert"><strong>Check the following details</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

@if($locked && ! $canConfigure)
    <section class="panel">
        <h2>Administrator sign-in required</h2>
        <p>Sign in to the POS, then return to this page with your installer PIN.</p>
        <div class="actions"><a class="button" href="{{ route('login') }}">Go to sign-in</a></div>
    </section>
@else
    @php
        $chosen = old('shop_type', $shop);
        $chosen = is_string($chosen) && isset($shops[$chosen]) ? $chosen : (isset($shops[$shop]) ? $shop : 'supermarket');
        $customModules = old('module_selection') === 'custom';
        $enabled = $locked || $customModules
            ? (old('shop_type') ? (array) old('modules', []) : $selected)
            : $shops[$chosen]['modules'];
        $examples = array_map(fn ($profile) => [
            'modules' => $profile['modules'],
            'label' => $profile['label'],
            'count' => count($profile['products']),
            'examples' => implode(', ', array_column(array_slice($profile['products'], 0, 3), 1)),
        ], $shops);
    @endphp
    <div class="columns">
        <form class="stack" method="post" action="{{ route('installer.store') }}" data-installer>
            @csrf
            @if($locked)
                <section class="panel">
                    <h2>Unlock module changes</h2>
                    <label>Installer PIN<input name="installer_pin" type="password" inputmode="numeric" autocomplete="off" required maxlength="128"></label>
                </section>
            @endif
            <section class="panel" id="shop">
                <h2>Shop details</h2>
                <div class="fields">
                    @unless($locked)
                        <label>Shop name<input name="shop_name" value="{{ old('shop_name') }}" autocomplete="organization" maxlength="120" required></label>
                    @endunless
                    <label>Shop type
                        <select name="shop_type" required data-shop-type>
                            @foreach($shops as $key => $profile)
                                <option value="{{ $key }}" @selected($chosen === $key)>{{ $profile['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    @unless($locked)
                        <label class="wide">POS website URL<input name="app_url" type="url" value="{{ old('app_url', url('/')) }}" maxlength="255" required><small>Use the public URL where staff will sign in.</small></label>
                    @endunless
                </div>
            </section>
            <section class="panel" id="modules">
                <h2>Modules</h2>
                @if($locked)
                    <p>Select the modules you want to keep available.</p>
                @else
                    <p>We select the recommended modules for your shop type. Use Customize modules to change them.</p>
                    <label class="choice module-mode">
                        <input type="checkbox" name="module_selection" value="custom" data-custom-modules @checked($customModules)>
                        <span>Customize modules</span>
                    </label>
                @endif
                <div class="modules">
                    @foreach($modules as $key => [$label, $description])
                        <label class="choice">
                            <input type="checkbox" name="modules[]" value="{{ $key }}" @checked(in_array($key, $enabled, true))>
                            <span>{{ $label }}<small>{{ $description }}</small></span>
                        </label>
                    @endforeach
                </div>
            </section>
            @unless($locked)
                <section class="panel" id="demo">
                    <h2>Shop demo data</h2>
                    <label class="choice">
                        <input type="checkbox" name="sample_data" value="1" @checked(old('sample_data'))>
                        <span>Add sample products and stock
                            <small data-demo-preview aria-live="polite">{{ $examples[$chosen]['label'] }} · {{ $examples[$chosen]['count'] }} products. Examples: {{ $examples[$chosen]['examples'] }}.</small>
                            <small data-demo-extra @if(! (($chosen === 'mobile' && in_array('PHONE_REP', $enabled, true)) || (in_array($chosen, ['pharmacy', 'supermarket'], true) && in_array('EXPIRE', $enabled, true)))) hidden @endif>{{ $chosen === 'mobile' ? 'Includes a sample repair job and parts.' : 'Includes stock batches with expiry dates.' }}</small>
                        </span>
                    </label>
                </section>
                <section class="panel" id="database">
                    <h2>MySQL or MariaDB database</h2>
                    <p>Create an empty database in your hosting panel first. Use a database user with permission to create tables.</p>
                    <div class="fields">
                        <label>Host<input name="db_host" value="{{ old('db_host', 'localhost') }}" required maxlength="255"></label>
                        <label>Port<input name="db_port" type="number" value="{{ old('db_port', 3306) }}" min="1" max="65535" required></label>
                        <label>Database name<input name="db_database" value="{{ old('db_database') }}" maxlength="64" required></label>
                        <label>Database username<input name="db_username" value="{{ old('db_username') }}" maxlength="128" required></label>
                        <label class="wide">Database password<input name="db_password" type="password" autocomplete="off" maxlength="255"></label>
                    </div>
                </section>
                <section class="panel" id="account">
                    <h2>Administrator account</h2>
                    <div class="fields">
                        <label>Full name<input name="admin_name" value="{{ old('admin_name') }}" autocomplete="name" required maxlength="120"></label>
                        <label>Sign-in username<input name="admin_username" value="{{ old('admin_username') }}" autocomplete="username" required maxlength="64"></label>
                        <label class="wide">Email address<input name="admin_email" type="email" value="{{ old('admin_email') }}" autocomplete="email" required maxlength="150"></label>
                        <label>Password<input name="password" type="password" autocomplete="new-password" required minlength="10" maxlength="72"><small>At least 10 characters.</small></label>
                        <label>Confirm password<input name="password_confirmation" type="password" autocomplete="new-password" required minlength="10" maxlength="72"></label>
                    </div>
                </section>
            @endunless
            <div class="actions"><button class="button" type="submit">{{ $locked ? 'Save module settings' : 'Install Twin POS' }}</button></div>
        </form>
        <aside class="panel summary">
            <h2>{{ $locked ? 'Settings you can update' : 'Installation checklist' }}</h2>
            <ul>
                <li><a href="#shop">Shop details</a></li>
                <li><a href="#modules">Modules</a></li>
                @unless($locked)
                    <li><a href="#demo">Shop demo data</a></li>
                    <li><a href="#database">Database</a></li>
                    <li><a href="#account">Administrator account</a></li>
                @endunless
            </ul>
            @if($locked)<p style="margin: 1rem 0 0">The current database, accounts and business records are preserved.</p>@endif
        </aside>
    </div>
@endif
@endsection

@section('scripts')
<script>
    const form = document.querySelector('[data-installer]');
    @unless($locked)
        const profiles = {{ \Illuminate\Support\Js::from($examples ?? []) }};
        if (form) {
            const shop = form.querySelector('[data-shop-type]');
            const custom = form.querySelector('[data-custom-modules]');
            const modules = [...form.querySelectorAll('[name="modules[]"]')];
            const demo = form.querySelector('[data-demo-preview]');
            const extra = form.querySelector('[data-demo-extra]');

            function updateProfile() {
                const profile = profiles[shop.value];
                modules.forEach(module => {
                    module.disabled = !custom.checked;
                    if (!custom.checked) module.checked = profile.modules.includes(module.value);
                });
                demo.textContent = `${profile.label} · ${profile.count} products. Examples: ${profile.examples}.`;
                const enabled = key => modules.some(module => module.value === key && module.checked);
                const repairDemo = shop.value === 'mobile' && enabled('PHONE_REP');
                const expiryDemo = ['pharmacy', 'supermarket'].includes(shop.value) && enabled('EXPIRE');
                extra.hidden = !repairDemo && !expiryDemo;
                extra.textContent = repairDemo
                    ? 'Includes a sample repair job and parts.'
                    : 'Includes stock batches with expiry dates.';
            }

            shop.addEventListener('change', updateProfile);
            custom.addEventListener('change', updateProfile);
            modules.forEach(module => module.addEventListener('change', updateProfile));
            updateProfile();
        }
    @endunless
    form?.addEventListener('submit', () => {
        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        button.textContent = {{ \Illuminate\Support\Js::from($locked ? 'Saving…' : 'Installing…') }};
    });
</script>
@endsection
