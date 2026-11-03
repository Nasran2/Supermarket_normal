@extends('superadmin.layout')

@section('title', 'Privacy Mode')

@section('content')
<div class="page-heading">
    <div>
        <span class="eyebrow">DEVELOPER OPTION</span>
        <h1>Secret Dashboard</h1>
        <p>Comprehensive system privacy and store obfuscation controls.</p>
    </div>
</div>

<div class="card" style="margin-bottom:24px;">
    <div class="card-heading">
        <div>
            <h2 style="font-size:18px;font-weight:700;">Privacy Mode Settings</h2>
            <p style="color:var(--muted);font-size:13px;margin-top:2px;">
                Toggle full privacy mode to mask confidential financial figures, customer names, and store identifiers.
            </p>
        </div>
        <span class="secret-badge">
            <x-icon name="shield-alert" :size="15"/>
            Super Admin Active
        </span>
    </div>

    <div style="padding:20px 0;">
        <div style="background:#f8fafc;border:1px dashed #cbd5e1;border-radius:12px;padding:32px;text-align:center;">
            <x-icon name="shield-check" :size="40" style="color:#64748b;margin-bottom:12px;"/>
            <h3 style="font-size:16px;font-weight:700;color:#0f172a;margin-bottom:6px;">Storewide Privacy Controls</h3>
            <p style="font-size:13px;color:#64748b;max-width:480px;margin:0 auto 16px;">
                Configure customer phone masking, cashier receipt names, and audit obfuscation. For dashboard and report widgets, use <a href="{{ route('superadmin.controls') }}" style="color:#4f46e5;font-weight:600;">Dashboard Controls</a>.
            </p>
            <a href="{{ route('superadmin.controls') }}" class="btn primary" style="background:#4f46e5;color:#fff;">
                Configure Dashboard Controls &rarr;
            </a>
        </div>
    </div>
</div>
@endsection

