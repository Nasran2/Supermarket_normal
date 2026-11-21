@php($branding = \App\Support\Branding::values($settings ?? []))
<!DOCTYPE html>
<html lang="en" data-units="{{ config('features.unit') ? '1' : '0' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Add product</title>
    @vite(['resources/css/app.css','resources/js/app.js'])
    @include('partials.branding-theme')
    @stack('head')
</head>
<body class="product-popup-body">
    <main class="product-popup-content">
        <div class="notice error" role="alert" tabindex="-1" data-product-popup-error hidden></div>
        @yield('content')
    </main>
    @stack('scripts')
    @vite('resources/js/product-popup.js')
</body>
</html>
