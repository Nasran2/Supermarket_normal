<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Sign in · {{ $settings['business_name'] ?? 'Twinsofte' }}</title>
    @vite(['resources/css/app.css','resources/js/app.js'])
    <style>
        .supermarket-bg {
            background-color: #e6fcf0;
            background-image: 
                radial-gradient(circle at 0% 0%, #d1f7e0 0%, transparent 40%),
                radial-gradient(circle at 100% 100%, #c6f3d5 0%, transparent 50%),
                radial-gradient(circle at 50% 50%, #effff6 0%, transparent 70%);
            overflow: hidden;
            position: relative;
        }
        /* Decorative background circles */
        .supermarket-bg::before, .supermarket-bg::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            border: 2px solid rgba(16, 185, 129, 0.03);
        }
        .supermarket-bg::before {
            width: 800px;
            height: 800px;
            top: -200px;
            left: -300px;
            box-shadow: inset 0 0 100px rgba(16, 185, 129, 0.05);
        }
        .supermarket-bg::after {
            width: 600px;
            height: 600px;
            bottom: -150px;
            right: -150px;
            background: radial-gradient(circle, rgba(16, 185, 129, 0.08) 0%, transparent 70%);
        }
        
        .login-card {
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 25px 60px -10px rgba(0, 160, 80, 0.1);
            position: relative;
            z-index: 10;
        }
        
        .btn-green-gradient {
            background: linear-gradient(135deg, #0ba360 0%, #0db168 100%);
            box-shadow: 0 10px 20px -8px rgba(13, 177, 104, 0.5);
        }
        .btn-green-gradient:hover {
            background: linear-gradient(135deg, #098f53 0%, #0a9e5b 100%);
            box-shadow: 0 12px 25px -8px rgba(13, 177, 104, 0.6);
        }

        .input-icon-left {
            padding-left: 2.75rem;
        }
    </style>
</head>
<body class="supermarket-bg min-h-screen flex items-center justify-center p-6 font-sans antialiased text-gray-800">
    
    <!-- Background decorative arcs -->
    <div class="absolute inset-0 z-0 overflow-hidden pointer-events-none">
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[120vw] h-[120vw] max-w-[1200px] max-h-[1200px] rounded-full border border-green-500/5"></div>
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[90vw] h-[90vw] max-w-[900px] max-h-[900px] rounded-full border border-green-500/5"></div>
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[60vw] h-[60vw] max-w-[600px] max-h-[600px] rounded-full border border-green-500/5 bg-white/20"></div>
    </div>

    <main class="login-card w-full max-w-[420px] p-8 sm:p-10 transition-all duration-300">
        
        <!-- Header -->
        <div class="text-center mb-8">
            @if(!empty($settings['logo']))
                <img src="{{ asset('storage/'.$settings['logo']) }}" alt="Business logo" class="h-16 mx-auto object-contain mb-5">
            @else
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-[1.25rem] bg-[#0db168] text-white mb-5 shadow-[0_8px_16px_-4px_rgba(13,177,104,0.4)]">
                    <x-icon name="shopping-basket" :size="32"/>
                </div>
            @endif
            
            <span class="block text-[10px] font-bold tracking-[0.15em] text-[#0db168] uppercase mb-2">
                TWINSOFTE SUPERMARKET POS
            </span>
            <h1 class="text-[28px] font-extrabold tracking-tight text-[#111827] mb-1">Welcome back</h1>
            <p class="text-[13px] text-gray-500 font-medium">Sign in to continue to your POS.</p>
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
                        class="w-full pr-4 py-2.5 rounded-lg border border-gray-200 focus:border-[#0db168] focus:ring-1 focus:ring-[#0db168] transition-colors bg-white text-[13px] text-gray-900 shadow-sm outline-none placeholder:text-gray-400 placeholder:font-medium">
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
                        class="w-full pr-10 py-2.5 rounded-lg border border-gray-200 focus:border-[#0db168] focus:ring-1 focus:ring-[#0db168] transition-colors bg-white text-[13px] text-gray-900 shadow-sm outline-none placeholder:text-gray-400 placeholder:font-medium">
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

            <!-- Remember me (No forgot password) -->
            <div class="flex items-center">
                <label class="flex items-center gap-2 cursor-pointer group">
                    <div class="relative flex items-center justify-center">
                        <input type="checkbox" name="remember" class="peer appearance-none w-4 h-4 rounded border-gray-300 border bg-white checked:bg-[#0db168] checked:border-[#0db168] focus:ring-2 focus:ring-[#0db168]/20 transition-all cursor-pointer shadow-sm">
                        <svg class="absolute w-3 h-3 text-white pointer-events-none opacity-0 peer-checked:opacity-100 transition-opacity" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                    </div>
                    <span class="text-[13px] font-bold text-[#374151] select-none group-hover:text-gray-900 transition-colors">Remember me</span>
                </label>
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
                <div class="relative bg-white px-3 flex items-center gap-1.5 text-xs font-semibold text-[#0db168]">
                    <x-icon name="shield-check" :size="14" class="fill-[#0db168] text-white"/> 
                    <span>Secure POS Access</span>
                </div>
            </div>
            
            <p class="text-center text-[11px] text-gray-500 font-medium mt-4">
                Fast billing. Simple stock control. Better business management.
            </p>
            
            <div class="mt-4 pt-4 border-t border-gray-100/80">
                <p class="text-center text-[10px] text-gray-400 font-medium">
                    Powered by <span class="font-bold text-[#0db168]">Twinsofte Solution</span>
                </p>
            </div>
        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
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
