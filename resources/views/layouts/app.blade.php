<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>@yield('title','Dashboard') · {{ $settings['business_name'] ?? 'Twinsofte' }}</title>@vite(['resources/css/app.css','resources/js/app.js'])@stack('head')</head>
<body>
@php($currentRegister = app(\App\Services\RegisterService::class)->current(auth()->id()))
<div class="nav-overlay" id="nav-overlay"></div>
<aside class="sidebar" id="sidebar">
 <a class="brand" href="{{ \App\Support\Navigation::home(auth()->user()) }}">@if(!empty($settings['logo']))<img src="{{ asset('storage/'.$settings['logo']) }}" alt="Business logo">@else<span class="brand-mark"><x-icon name="shopping-basket" :size="23"/></span>@endif<div class="brand-copy"><strong>{{ $settings['business_name'] ?? 'Twinsofte' }}</strong><span>SUPERMARKET POS</span></div></a>
 <nav class="nav-links" aria-label="Main navigation">
 @foreach([['dashboard.view','dashboard','layout-dashboard','Dashboard'],['pos.access','pos.index','scan-line','Point of sale'],['sales.view','sales.index','receipt-text','Sales'],['purchases.view','purchases.index','package-open','Purchases'],['register.view','register.index','wallet','Daily register']] as [$permission,$route,$icon,$label])
 @can($permission)<a href="{{ route($route) }}" class="nav-link {{ request()->routeIs($route) || ($route==='sales.index' && request()->is('sales/*')) || ($route==='purchases.index' && request()->is('purchases/*')) ? 'active':'' }}" title="{{ $label }}"><x-icon :name="$icon"/><span>{{ $label }}</span></a>@endcan @endforeach
 <div class="nav-section">INVENTORY</div>
 @foreach([['products','products.view','package','Products'],['categories','products.view','tags','Categories'],['units','units.view','ruler','Units'],['suppliers','purchases.view','truck','Suppliers'],['customers','sales.view','contact','Customers'],['expenses','expenses.view','circle-dollar-sign','Expenses']] as [$resource,$permission,$icon,$label])
 @can($permission)<a class="nav-link {{ request()->route('resource')===$resource?'active':'' }}" href="{{ route('manage.index',$resource) }}" title="{{ $label }}"><x-icon :name="$icon"/><span>{{ $label }}</span></a>@endcan @endforeach
 <div class="nav-section">MANAGEMENT</div>
 @if(auth()->user()->role->permissions->contains(fn($p)=>str_starts_with($p->name,'reports.')))<a class="nav-link {{ request()->is('reports*')?'active':'' }}" href="{{ route('reports.index') }}" title="Reports"><x-icon name="chart-no-axes-combined"/><span>Reports</span></a>@endif
 @can('users.view')<a class="nav-link {{ request()->route('resource')==='users'?'active':'' }}" href="{{ route('manage.index','users') }}" title="Users"><x-icon name="users"/><span>Users</span></a>@endcan
 @can('roles.view')<a class="nav-link {{ request()->route('resource')==='roles'?'active':'' }}" href="{{ route('manage.index','roles') }}" title="Roles & permissions"><x-icon name="shield-check"/><span>Roles & permissions</span></a>@endcan
 @can('settings.view')<a class="nav-link {{ request()->is('settings*') || in_array(request()->route('resource'),['payment-methods','payment-rules'])?'active':'' }}" href="{{ route('settings.index') }}" title="Settings"><x-icon name="settings-2"/><span>Settings</span></a>@endcan
 </nav><div class="sidebar-footer"><span class="status-dot"></span><span class="brand-copy">Twinsofte · Store workspace</span></div>
</aside>
<div class="app-shell"><header class="topbar"><div class="flex items-center gap-3 min-w-0"><button class="icon-button" id="nav-toggle" aria-label="Toggle navigation" aria-expanded="true"><x-icon name="panel-left"/></button><div class="breadcrumb"><span>Workspace</span><x-icon name="chevron-right" :size="14"/><strong>@yield('title','Dashboard')</strong></div></div><div class="topbar-actions">
 @can('register.view')<a href="{{ route('register.index') }}" class="register-badge {{ $currentRegister?'open':'' }}"><span class="status-dot"></span><span>Register {{ $currentRegister?'open':'closed' }}</span></a>@endcan
 @can('products.view')<details class="notifications"><summary class="icon-button" aria-label="Notifications"><x-icon name="bell"/></summary><div class="popover"><strong>Stock alerts</strong>@php($lowCount=\App\Models\Product::where('active',true)->whereColumn('stock','<=','low_stock')->count())<p>{{ $lowCount }} products are at or below their stock alert level.</p><a class="text-link" href="{{ route('manage.index',['resource'=>'products','low_stock'=>1]) }}">Review low stock <x-icon name="arrow-right" :size="14"/></a></div></details>@endcan
 <details class="user-menu"><summary><span class="avatar">{{ mb_substr(auth()->user()->name,0,1) }}</span><span class="user-info"><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->role?->name }}</small></span><x-icon name="chevron-down" :size="14"/></summary><div class="popover"><a href="{{ route('profile') }}">Change password</a><form method="POST" action="{{ route('logout') }}">@csrf<button class="text-danger">Sign out</button></form></div></details>
 </div></header>
<main class="main-content @yield('main-class')"><div class="print-brand">@if(!empty($settings['logo']))<img src="{{ asset('storage/'.$settings['logo']) }}" alt="Logo">@endif<strong>{{ $settings['business_name']??'' }}</strong><p>{{ $settings['address']??'' }} {{ $settings['phone']??'' }}</p></div>
 @if(session('success'))<div class="notice success" role="status"><x-icon name="circle-check"/><span>{{ session('success') }}</span><button class="icon-button" data-dismiss aria-label="Dismiss"><x-icon name="x" :size="16"/></button></div>@endif
 @if($errors->any())<div class="notice error" role="alert"><x-icon name="circle-alert"/><div><strong>Please check the following</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>@endif
 @yield('content')
</main><footer class="app-footer">Powered by Twinsofte <span>{{ $settings['currency']??'LKR' }} · {{ $settings['timezone']??'Asia/Colombo' }}</span></footer></div>@stack('scripts')
</body></html>
