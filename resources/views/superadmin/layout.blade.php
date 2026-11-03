@php
    $settings = app(\App\Services\SettingsService::class)->all();
    $branding = \App\Support\Branding::values($settings ?? []);
    $systemActive = (bool) \App\Support\SecretControl::get('system_active', 1);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Secret Dashboard') · {{ $branding['brand_name'] }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.branding-theme')
    <style>
        /* Modern Switch Styles matching Image 3 */
        .switch-wrap {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--card-bg, #fff);
            border: 1px solid var(--border, #e2e8f0);
            border-radius: 12px;
            padding: 16px 20px;
            transition: border-color 0.15s ease;
        }
        .switch-wrap:hover {
            border-color: #cbd5e1;
        }
        .switch-info strong {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: var(--ink, #0f172a);
            margin-bottom: 3px;
        }
        .switch-info small {
            display: block;
            font-size: 12px;
            color: var(--muted, #64748b);
            line-height: 1.4;
        }
        /* The Switch toggle */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
            flex-shrink: 0;
            margin-left: 16px;
        }
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background-color: #cbd5e1;
            transition: .25s ease;
            border-radius: 24px;
        }
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .25s ease;
            border-radius: 50%;
            box-shadow: 0 1px 3px rgba(0,0,0,0.25);
        }
        input:checked + .toggle-slider {
            background-color: #4f46e5;
        }
        input:checked + .toggle-slider:before {
            transform: translateX(20px);
        }
        /* Sticky action bar */
        .sticky-action-bar {
            position: sticky;
            bottom: 16px;
            z-index: 40;
            margin-top: 24px;
            background: #0f172a;
            color: #f8fafc;
            border-radius: 12px;
            padding: 14px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.4);
            flex-wrap: wrap;
            gap: 12px;
        }
        .sticky-action-bar span {
            font-size: 13px;
            color: #cbd5e1;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .secret-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            background: #ede9fe;
            color: #6d28d9;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .system-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .system-pill.online { background: #dcfce7; color: #15803d; }
        .system-pill.offline { background: #fee2e2; color: #b91c1c; }

        /* Super Admin Table Styling */
        .superadmin-table {
            width: 100%;
            border-collapse: collapse;
        }
        .superadmin-table th {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #64748b;
            background: #f8fafc;
            padding: 13px 20px;
            border-bottom: 1px solid var(--border, #e2e8f0);
            white-space: nowrap;
        }
        .superadmin-table td {
            font-size: 13px;
            padding: 14px 20px;
            border-bottom: 1px solid var(--border, #f1f5f9);
            vertical-align: middle;
        }
        .superadmin-table tbody tr:hover {
            background: #f8fafc;
        }
        .superadmin-table .cell-note {
            font-size: 11px;
            color: #64748b;
            margin-top: 3px;
        }
    </style>
    @stack('head')
</head>
<body>
    <div class="nav-overlay" id="nav-overlay"></div>
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="{{ route('superadmin.dashboard') }}">
            @if(!empty($settings['logo']))
                <img src="{{ asset('storage/'.$settings['logo']) }}" alt="Logo">
            @else
                <span class="brand-mark"><x-icon name="shield-check" :size="23"/></span>
            @endif
            <div class="brand-copy">
                <strong>{{ $branding['brand_name'] }}</strong>
                <span>Super Admin</span>
            </div>
        </a>

        <nav class="nav-links" aria-label="Super Admin navigation">
            <a href="{{ route('superadmin.dashboard') }}"
               class="nav-link {{ request()->routeIs('superadmin.dashboard') ? 'active' : '' }}">
                <x-icon name="layout-dashboard"/>
                <span>Overview</span>
            </a>

            <div class="nav-section">DEVELOPER OPTION</div>

            <details class="nav-group" open>
                <summary class="nav-link active">
                    <x-icon name="landmark"/>
                    <span>Secret Dashboard</span>
                    <x-icon name="chevron-down" :size="14" class="nav-chevron"/>
                </summary>
                <div class="nav-children">
                    <a class="nav-child {{ request()->routeIs('superadmin.dashboard') ? 'active' : '' }}"
                       href="{{ route('superadmin.dashboard') }}">
                        Overview
                    </a>
                    <a class="nav-child {{ request()->routeIs('superadmin.controls*') ? 'active' : '' }}"
                       href="{{ route('superadmin.controls') }}">
                        Dashboard Controls
                    </a>
                    <a class="nav-child {{ request()->routeIs('superadmin.stock*') || request()->routeIs('superadmin.inventory*') ? 'active' : '' }}"
                       href="{{ route('superadmin.stock') }}">
                        Product &amp; Stock
                    </a>
                    <a class="nav-child {{ request()->routeIs('superadmin.hide-records*') ? 'active' : '' }}"
                       href="{{ route('superadmin.hide-records') }}">
                        Hide Records
                    </a>
                    <a class="nav-child {{ request()->routeIs('superadmin.privacy-mode*') ? 'active' : '' }}"
                       href="{{ route('superadmin.privacy-mode') }}">
                        Privacy Mode
                    </a>
                </div>
            </details>

            <div class="nav-section">REAL DATA (UNRESTRICTED)</div>

            <a href="{{ route('superadmin.sales') }}"
               class="nav-link {{ request()->routeIs('superadmin.sales') ? 'active' : '' }}">
                <x-icon name="receipt-text"/>
                <span>Sales (Real Numbers)</span>
            </a>

            <a href="{{ route('superadmin.products') }}"
               class="nav-link {{ request()->routeIs('superadmin.products') ? 'active' : '' }}">
                <x-icon name="package"/>
                <span>Products (Real Prices)</span>
            </a>

            <a href="{{ route('superadmin.profit') }}"
               class="nav-link {{ request()->routeIs('superadmin.profit') ? 'active' : '' }}">
                <x-icon name="trending-up"/>
                <span>Profit &amp; Loss (Real Numbers)</span>
            </a>

            <div class="nav-section">NORMAL USER VIEW</div>

            <a href="{{ route('dashboard') }}" class="nav-link" target="_blank" title="Preview normal user dashboard">
                <x-icon name="external-link"/>
                <span>Normal Dashboard</span>
            </a>

            <a href="{{ route('sales.index') }}" class="nav-link" target="_blank" title="Preview normal sales list">
                <x-icon name="external-link"/>
                <span>Normal Sales List</span>
            </a>

            <a href="{{ route('reports.index') }}" class="nav-link" target="_blank">
                <x-icon name="chart-no-axes-combined"/>
                <span>Reports</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <span class="status-dot {{ $systemActive ? 'online' : 'offline' }}"></span>
            <span class="brand-copy">Super Admin · Full Access</span>
        </div>
    </aside>

    <div class="app-shell">
        <header class="topbar">
            <div class="flex items-center gap-3 min-w-0">
                <button class="icon-button" id="nav-toggle" aria-label="Toggle navigation" aria-controls="sidebar">
                    <x-icon name="panel-left"/>
                </button>
                <div class="breadcrumb">
                    <span>Developer Option</span>
                    <x-icon name="chevron-right" :size="14"/>
                    <span>Secret Dashboard</span>
                    <x-icon name="chevron-right" :size="14"/>
                    <strong>@yield('title', 'Controls')</strong>
                </div>
            </div>

            <div class="topbar-actions">
                <span class="system-pill {{ $systemActive ? 'online' : 'offline' }}">
                    <span class="status-dot"></span>
                    System {{ $systemActive ? 'Online' : 'Disabled' }}
                </span>

                <details class="user-menu">
                    <summary>
                        <span class="avatar" style="background:#4f46e5;color:#fff;">S</span>
                        <span class="user-info">
                            <strong>superadmin</strong>
                            <small>Super Administrator</small>
                        </span>
                        <x-icon name="chevron-down" :size="14"/>
                    </summary>
                    <div class="popover">
                        <a href="{{ route('superadmin.controls') }}">Dashboard Controls</a>
                        <form method="POST" action="{{ route('superadmin.logout') }}">
                            @csrf
                            @method('DELETE')
                            <button class="text-danger">Sign out</button>
                        </form>
                    </div>
                </details>
            </div>
        </header>

        <main class="main-content">
            @if(session('success'))
                <div class="notice success" role="status">
                    <x-icon name="circle-check"/>
                    <span>{{ session('success') }}</span>
                    <button class="icon-button" data-dismiss aria-label="Dismiss">
                        <x-icon name="x" :size="16"/>
                    </button>
                </div>
            @endif

            @if(!empty($errors) && $errors->any())
                <div class="notice error" role="alert">
                    <x-icon name="circle-alert"/>
                    <div>
                        <strong>Please check the following:</strong>
                        <ul>
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            @yield('content')
        </main>

        <footer class="app-footer">
            Powered by Twinsofte · <strong>Super Admin Restricted Panel</strong>
        </footer>
    </div>

    @stack('scripts')
</body>
</html>

