@extends('superadmin.layout')

@section('title', 'Dashboard Controls')

@section('content')
<div class="page-heading">
    <div>
        <span class="eyebrow">DEVELOPER OPTION</span>
        <h1>Secret Dashboard</h1>
        <p>Manage visibility controls, profit scaling, and system access.</p>
    </div>
    <div style="display:flex;gap:0.75rem;align-items:center;">
        <a href="{{ route('superadmin.dashboard') }}" class="btn secondary">
            <x-icon name="layout-dashboard"/>
            View Overview
        </a>
        <form method="POST" action="{{ route('superadmin.toggle') }}">
            @csrf
            <button type="submit" class="btn {{ $systemActive ? 'secondary' : 'primary' }}"
                    style="{{ $systemActive ? 'border-color:#ef4444;color:#ef4444;' : 'background:#16a34a;' }}"
                    onclick="return confirm('Are you sure you want to {{ $systemActive ? 'DISABLE' : 'ENABLE' }} the system?')">
                <x-icon name="power"/>
                {{ $systemActive ? 'Kill Switch (Disable System)' : 'Enable System' }}
            </button>
        </form>
    </div>
</div>

<form method="POST" action="{{ route('superadmin.controls.save') }}" id="controls-form">
    @csrf

    <div class="card" style="margin-bottom:24px;">
        <div class="card-heading" style="margin-bottom:12px;">
            <div>
                <h2 style="font-size:18px;font-weight:700;">Dashboard Controls</h2>
                <p style="color:var(--muted);font-size:13px;margin-top:2px;">
                    Use these switches to decide what normal users can see on dashboards and reports. Super Admin is not affected.
                </p>
            </div>
            <span class="secret-badge">
                <x-icon name="shield-alert" :size="15"/>
                Super Admin Active
            </span>
        </div>

        <div style="padding: 0 24px 24px;">
        {{-- Visible profit percentage --}}
        <div style="background:var(--bg, #f8fafc);border:1px solid var(--border,#e2e8f0);border-radius:12px;padding:20px;margin-bottom:24px;">
            <label for="visible_profit_percentage" style="display:block;font-size:14px;font-weight:600;margin-bottom:6px;color:var(--ink);">
                Visible profit percentage
            </label>
            <div style="display:flex;align-items:center;gap:12px;max-width:320px;">
                <input type="number"
                       id="visible_profit_percentage"
                       name="visible_profit_percentage"
                       min="0"
                       max="100"
                       step="1"
                       value="{{ old('visible_profit_percentage', $percentage) }}"
                       style="font-size:22px;font-weight:700;padding:8px 16px;border-radius:8px;border:1.5px solid var(--border);width:100%;color:#4f46e5;background:#fff;"
                       required>
                <span style="font-size:20px;font-weight:700;color:var(--muted);">%</span>
            </div>
            <p style="font-size:12px;color:var(--muted);margin-top:8px;line-height:1.5;">
                <strong>How this works:</strong> 100 means show full actual values. <strong>50 means show half (50%)</strong> of cost, selling price and profit to normal users and admins. Use 0 or the switch below to remove profit entirely.
            </p>

            <div style="margin-top:14px;padding:12px 16px;background:#fff;border-radius:8px;border:1px dashed #cbd5e1;display:flex;align-items:center;gap:20px;flex-wrap:wrap;font-size:13px;">
                <span style="color:#64748b;">Example preview at <strong>{{ $percentage }}%</strong>:</span>
                <span>Original Cost: <strong>100</strong> &rarr; Shown: <strong style="color:#2563eb;">{{ round(100 * ($percentage/100), 2) }}</strong></span>
                <span>Original Price: <strong>200</strong> &rarr; Shown: <strong style="color:#2563eb;">{{ round(200 * ($percentage/100), 2) }}</strong></span>
                <span>Original Profit: <strong>100</strong> &rarr; Shown: <strong style="color:#16a34a;">{{ round(100 * ($percentage/100), 2) }}</strong></span>
            </div>
        </div>

        {{-- Switch Grid (2 Columns, matching Image 3) --}}
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(360px, 1fr));gap:14px;">

            {{-- 1. Hide top summary cards --}}
            <div class="switch-wrap">
                <div class="switch-info">
                    <strong>Hide top summary cards</strong>
                    <small>Removes the main financial cards from the normal dashboard.</small>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" name="hide_top_summary" value="1" @checked($switches['hide_top_summary'])>
                    <span class="toggle-slider"></span>
                </label>
            </div>

            {{-- 2. Hide total sales value --}}
            <div class="switch-wrap">
                <div class="switch-info">
                    <strong>Hide total sales value</strong>
                    <small>Shows sales total as hidden or zero in normal views and reports.</small>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" name="hide_sales_value" value="1" @checked($switches['hide_sales_value'])>
                    <span class="toggle-slider"></span>
                </label>
            </div>

            {{-- 3. Hide total purchase value --}}
            <div class="switch-wrap">
                <div class="switch-info">
                    <strong>Hide total purchase value</strong>
                    <small>Hides purchase total values from normal users.</small>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" name="hide_purchases_value" value="1" @checked($switches['hide_purchases_value'])>
                    <span class="toggle-slider"></span>
                </label>
            </div>

            {{-- 4. Hide profit and loss --}}
            <div class="switch-wrap">
                <div class="switch-info">
                    <strong>Hide profit and loss</strong>
                    <small>Protects profit, margin, and loss values from normal users.</small>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" name="hide_profit" value="1" @checked($switches['hide_profit'])>
                    <span class="toggle-slider"></span>
                </label>
            </div>

            {{-- 5. Hide stock money values --}}
            <div class="switch-wrap">
                <div class="switch-info">
                    <strong>Hide stock money values</strong>
                    <small>Hides stock cost and selling value totals in stock summaries.</small>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" name="hide_stock_money" value="1" @checked($switches['hide_stock_money'])>
                    <span class="toggle-slider"></span>
                </label>
            </div>

            {{-- 6. Hide stock item counts --}}
            <div class="switch-wrap">
                <div class="switch-info">
                    <strong>Hide stock item counts</strong>
                    <small>Hides exact stock counts on dashboard summaries.</small>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" name="hide_stock_counts" value="1" @checked($switches['hide_stock_counts'])>
                    <span class="toggle-slider"></span>
                </label>
            </div>

            {{-- 7. Hide charts and graphs --}}
            <div class="switch-wrap">
                <div class="switch-info">
                    <strong>Hide charts and graphs</strong>
                    <small>Removes dashboard charts and sales overview from normal users.</small>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" name="hide_charts" value="1" @checked($switches['hide_charts'])>
                    <span class="toggle-slider"></span>
                </label>
            </div>

            {{-- 8. Hide data tables --}}
            <div class="switch-wrap">
                <div class="switch-info">
                    <strong>Hide data tables</strong>
                    <small>Hides dashboard tables and recent sales tables where supported.</small>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" name="hide_tables" value="1" @checked($switches['hide_tables'])>
                    <span class="toggle-slider"></span>
                </label>
            </div>

            {{-- 9. Hide small widgets --}}
            <div class="switch-wrap">
                <div class="switch-info">
                    <strong>Hide small widgets</strong>
                    <small>Hides low stock, recent expenses and small dashboard widgets.</small>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" name="hide_small_widgets" value="1" @checked($switches['hide_small_widgets'])>
                    <span class="toggle-slider"></span>
                </label>
            </div>

            {{-- 10. Disable reports pages --}}
            <div class="switch-wrap">
                <div class="switch-info">
                    <strong>Disable reports pages</strong>
                    <small>Blocks report pages and profit reports for normal users.</small>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" name="disable_reports" value="1" @checked($switches['disable_reports'])>
                    <span class="toggle-slider"></span>
                </label>
            </div>

        </div>

    </div>

    {{-- System Status Banner / Kill Switch info --}}
    <div class="card" style="margin-bottom:24px;border-left:4px solid {{ $systemActive ? '#16a34a' : '#dc2626' }};">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;">
            <div>
                <h3 style="font-size:15px;font-weight:700;margin:0 0 4px;color:{{ $systemActive ? '#15803d' : '#b91c1c' }};">
                    Complete System Status: {{ $systemActive ? 'ONLINE' : 'DISABLED (OFFLINE)' }}
                </h3>
                <p style="font-size:13px;color:var(--muted);margin:0;">
                    @if($systemActive)
                        Normal users and admins can log in and access POS, inventory and sales.
                    @else
                        The system is locked. Normal users see a fake 500 Server Error screen. Only Super Admin has access.
                    @endif
                </p>
            </div>
        </div>
        </div>
    </div>

    {{-- Sticky bottom action bar (Image 3) --}}
    <div class="sticky-action-bar">
        <span>
            <x-icon name="info" :size="18"/>
            Saving affects normal users and admins immediately. Super Admin stays unrestricted.
        </span>
        <button type="submit" class="btn primary" style="background:#4f46e5;border-color:#4f46e5;padding:10px 24px;font-weight:600;font-size:14px;">
            <x-icon name="save" :size="16"/>
            Save This Section
        </button>
    </div>

</form>
@endsection

