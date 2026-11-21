@php
    $branding = \App\Support\Branding::values($settings ?? []);
    $developerName = trim((string) config('app.developer.name'));
    $developerPhone = trim((string) config('app.developer.phone'));
    $developerWeb = trim((string) config('app.developer.web'));
    $developerUrl = preg_match('~^https?://~i', $developerWeb) ? $developerWeb : 'https://'.$developerWeb;
    $developerUrl = filter_var($developerUrl, FILTER_VALIDATE_URL) ? $developerUrl : null;
    $developerTel = preg_replace('/[^0-9+]/', '', $developerPhone);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Sign in · {{ $settings['business_name'] ?? 'Twinsofte' }}</title>
    @vite(['resources/css/app.css','resources/js/app.js'])
    @include('partials.branding-theme')
    <style>
        .supermarket-bg {
            background-color: var(--login-background);
            background-image: radial-gradient(circle at 0% 0%, color-mix(in srgb, var(--login-accent) 10%, transparent), transparent 50%), radial-gradient(circle at 100% 100%, color-mix(in srgb, var(--login-accent) 14%, transparent), transparent 55%);
            position: relative;
        }
        .login-card {
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 25px 60px -10px color-mix(in srgb, var(--login-accent) 15%, transparent);
            position: relative;
            z-index: 10;
        }
        .btn-green-gradient {
            background: linear-gradient(135deg, var(--login-accent-dark), var(--login-accent));
            color: var(--login-foreground);
            box-shadow: 0 10px 20px -8px color-mix(in srgb, var(--login-accent) 50%, transparent);
        }
        .btn-green-gradient:hover { background: var(--login-accent-dark); color: {{ \App\Support\Branding::foreground(\App\Support\Branding::darken($branding['login_accent_color'])) }}; }
        .login-accent-text { color: var(--login-accent); }
        .login-icon-tile { background: var(--login-accent); color: var(--login-foreground); box-shadow: 0 8px 16px -4px color-mix(in srgb, var(--login-accent) 40%, transparent); }
        .login-heading { color: var(--login-title-color); overflow-wrap: anywhere; }
        .login-description { color: var(--login-description-color); overflow-wrap: anywhere; }
        .login-brand-heading { overflow-wrap: anywhere; }
        .login-card input:focus { border-color: var(--login-accent); box-shadow: 0 0 0 1px var(--login-accent); }
        .login-card input[type="checkbox"]:checked { background: var(--login-accent); border-color: var(--login-accent); }
        .login-decoration { border-color: color-mix(in srgb, var(--login-accent) 6%, transparent); }
        .input-icon-left {
            padding-left: 2.75rem;
        }
        .login-options { justify-content: space-between; flex-wrap: wrap; gap: 8px 12px; }
        .login-forgot { color: var(--login-accent); font-size: 13px; font-weight: 600; min-height: 32px; }
        .login-forgot:hover { text-decoration: underline; }
        .login-support-dialog { width: min(460px, calc(100vw - 24px)); }
        .login-support-dialog .modal-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .login-support-dialog h2 { font-size: 18px; font-weight: 700; }
        .login-support-dialog p { font-size: 14px; line-height: 1.6; }
        .login-support-details { display: grid; gap: 16px; margin: 20px 0; }
        .login-support-details dt { color: var(--muted); font-size: 12px; margin-bottom: 4px; }
        .login-support-details dd { margin: 0; overflow-wrap: anywhere; }
        .login-support-details a { color: var(--login-accent); text-decoration: underline; }
    </style>
</head>
<body class="supermarket-bg min-h-screen flex items-center justify-center p-6 font-sans antialiased text-gray-800 {{ \App\Support\Repairs::enabled() ? 'phone-repair-theme' : '' }}">

    <!-- Background decorative arcs -->
    <div class="absolute inset-0 z-0 overflow-hidden pointer-events-none">
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[120vw] h-[120vw] max-w-[1200px] max-h-[1200px] rounded-full border login-decoration"></div>
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[90vw] h-[90vw] max-w-[900px] max-h-[900px] rounded-full border login-decoration"></div>
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[60vw] h-[60vw] max-w-[600px] max-h-[600px] rounded-full border login-decoration bg-white/20"></div>
    </div>

    <main class="login-card w-full max-w-[420px] p-8 sm:p-10 transition-all duration-300">

        <x-demo-notice/>
        <!-- Header -->
        <div class="text-center mb-8">
            @if(!empty($branding['login_image']))
                <img src="{{ asset('storage/'.$branding['login_image']) }}" alt="Login logo" class="h-16 max-w-full mx-auto object-contain mb-5">
            @else
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-[1.25rem] login-icon-tile mb-5">
                    <x-icon :name="$branding['login_icon']" :size="32"/>
                </div>
            @endif

            <span class="block text-[10px] font-bold tracking-[0.15em] login-brand-heading login-accent-text uppercase mb-2">
                {{ $branding['login_brand_text'] }}
            </span>
            <h1 class="text-[28px] font-extrabold tracking-tight login-heading mb-1">{{ $branding['login_title'] }}</h1>
            <p class="text-[13px] login-description font-medium">{{ $branding['login_description'] }}</p>
        </div>

        @if($errors->any())
            <div class="bg-red-50 text-red-700 p-4 rounded-xl mb-6 border border-red-100 flex items-start text-sm" role="alert">
                <div class="mr-2 flex-shrink-0 mt-0.5">
                    <x-icon name="alert-circle" :size="16"/>
                </div>
                <span>{{ $errors->first() }}</span>
            </div>
        @endif

        <form action="{{ url('/login') }}" method="POST" class="space-y-5">
            @csrf

            <!-- Username/Email Field -->
            <div>
                <label class="block text-[13px] font-bold text-[#1f2937] mb-2">Username or email</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-gray-400">
                        <x-icon name="user" :size="18"/>
                    </div>
                    <input type="text" name="login" value="{{ old('login', old('email')) }}" maxlength="255" autocapitalize="none" spellcheck="false" autocomplete="username" placeholder="Enter your username or email" required autofocus
                        style="padding-left: 2.75rem;"
                        class="w-full pr-4 py-2.5 rounded-lg border border-gray-200 transition-colors bg-white text-[13px] text-gray-900 shadow-sm outline-none placeholder:text-gray-400 placeholder:font-medium">
                </div>
            </div>

            <!-- Password Field -->
            <div>
                <label class="block text-[13px] font-bold text-[#1f2937] mb-2">Password</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-gray-400">
                        <x-icon name="lock" :size="18"/>
                    </div>
                    <input type="password" id="password-input" name="password" autocomplete="current-password" placeholder="Enter your password" required
                        style="padding-left: 2.75rem;"
                        class="w-full pr-10 py-2.5 rounded-lg border border-gray-200 transition-colors bg-white text-[13px] text-gray-900 shadow-sm outline-none placeholder:text-gray-400 placeholder:font-medium">
                    <button type="button" id="toggle-password" class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-gray-400 hover:text-gray-600 focus:outline-none transition-colors" aria-label="Toggle password visibility">
                        <svg id="eye-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                        <svg id="eye-off-icon" class="hidden" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"></path>
                            <path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"></path>
                            <path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"></path>
                            <line x1="2" y1="2" x2="22" y2="22"></line>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Remember me and password help -->
            <div class="flex items-center login-options">
                <label class="flex items-center gap-2 cursor-pointer group">
                    <div class="relative flex items-center justify-center">
                        <input type="checkbox" name="remember" class="peer appearance-none w-4 h-4 rounded border-gray-300 border bg-white  transition-all cursor-pointer shadow-sm">
                        <svg class="absolute w-3 h-3 text-white pointer-events-none opacity-0 peer-checked:opacity-100 transition-opacity" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                    </div>
                    <span class="text-[13px] font-bold text-[#374151] select-none group-hover:text-gray-900 transition-colors">Remember me</span>
                </label>
                <button type="button" id="forgot-password" class="login-forgot" aria-haspopup="dialog" aria-controls="developer-contact-dialog">Forgot password?</button>
            </div>

            <button type="submit" class="w-full flex justify-center items-center gap-2 btn-green-gradient text-white font-bold text-[14px] py-3 px-4 rounded-xl transition-all active:scale-[0.98] mt-2">
                Sign in
                <x-icon name="arrow-right" :size="16" class="stroke-[2.5]"/>
            </button>
        </form>

        <!-- Footer -->
        <div class="mt-8">
            <div class="relative flex items-center justify-center">
                <div class="absolute inset-0 flex items-center" aria-hidden="true">
                    <div class="w-full border-t border-gray-100"></div>
                </div>
                <div class="relative bg-white px-3 flex items-center gap-1.5 text-xs font-semibold login-accent-text">
                    <x-icon name="shield-check" :size="14" class="login-accent-text"/>
                    <span>Secure POS Access</span>
                </div>
            </div>

            <p class="text-center text-[11px] text-gray-500 font-medium mt-4">
                Fast billing. Simple stock control. Better business management.
            </p>

            <div class="mt-4 pt-4 border-t border-gray-100/80">
                <p class="text-center text-[10px] text-gray-400 font-medium">
                    Powered by <span class="font-bold login-accent-text">@if($developerUrl)<a href="{{ $developerUrl }}" target="_blank" rel="noopener noreferrer">{{ $developerName }}</a>@else{{ $developerName }}@endif</span>
                </p>
            </div>
        </div>
        @if(\App\Support\Repairs::enabled())<p class="text-center mt-5"><a class="text-link" href="{{ route('repairs.track') }}">Track your repair</a></p>@endif
    </main>

    <dialog id="developer-contact-dialog" class="customer-dialog login-support-dialog" aria-labelledby="developer-contact-title" aria-describedby="developer-contact-description">
        <div class="modal-heading">
            <h2 id="developer-contact-title">Contact the developer</h2>
            <button type="button" class="icon-button" data-close-developer-contact aria-label="Close developer contact"><x-icon name="x"/></button>
        </div>
        <div class="customer-dialog-body">
            <p id="developer-contact-description">Forgot your password? Contact the developer for help resetting it.</p>
            <dl class="login-support-details">
                @if($developerName)<div><dt>Developer</dt><dd>{{ $developerName }}</dd></div>@endif
                @if($developerPhone)<div><dt>Phone</dt><dd>@if($developerTel)<a href="tel:{{ $developerTel }}">{{ $developerPhone }}</a>@else{{ $developerPhone }}@endif</dd></div>@endif
                @if($developerWeb)<div><dt>Website</dt><dd>@if($developerUrl)<a href="{{ $developerUrl }}" target="_blank" rel="noopener noreferrer">{{ $developerWeb }}</a>@else{{ $developerWeb }}@endif</dd></div>@endif
            </dl>
            @if(!$developerPhone && !$developerWeb)<p class="muted">Contact your administrator for developer contact details.</p>@endif
            <div class="customer-dialog-actions"><button type="button" class="btn btn-green-gradient" data-close-developer-contact>Close</button></div>
        </div>
    </dialog>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const contactDialog = document.getElementById('developer-contact-dialog');
            document.getElementById('forgot-password').addEventListener('click', () => contactDialog.showModal());
            document.querySelectorAll('[data-close-developer-contact]').forEach(button => {
                button.addEventListener('click', () => contactDialog.close());
            });
            const toggleButton = document.getElementById('toggle-password');
            const passwordInput = document.getElementById('password-input');
            const eyeIcon = document.getElementById('eye-icon');
            const eyeOffIcon = document.getElementById('eye-off-icon');

            toggleButton.addEventListener('click', function() {
                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    eyeIcon.classList.add('hidden');
                    eyeOffIcon.classList.remove('hidden');
                } else {
                    passwordInput.type = 'password';
                    eyeIcon.classList.remove('hidden');
                    eyeOffIcon.classList.add('hidden');
                }
            });
        });
    </script>
</body>
</html>
