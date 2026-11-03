@extends('superadmin.layout')

@section('title', 'Hide Records')

@section('content')
<div class="page-heading">
    <div>
        <span class="eyebrow">DEVELOPER OPTION</span>
        <h1>Secret Dashboard</h1>
        <p>Hide specific sales, invoices, customers, and transactions from normal user views.</p>
    </div>
</div>

<div class="card" style="margin-bottom:24px;">
    <div class="card-heading">
        <div>
            <h2 style="font-size:18px;font-weight:700;">Hide Records Controls</h2>
            <p style="color:var(--muted);font-size:13px;margin-top:2px;">
                Filter and exclude specific invoice ranges, dates, or payment methods from dashboards and reports.
            </p>
        </div>
        <span class="secret-badge">
            <x-icon name="shield-alert" :size="15"/>
            Super Admin Active
        </span>
    </div>

    <div style="padding:20px 0;">
        <div style="background:#f8fafc;border:1px dashed #cbd5e1;border-radius:12px;padding:32px;text-align:center;">
            <x-icon name="eye-off" :size="40" style="color:#64748b;margin-bottom:12px;"/>
            <h3 style="font-size:16px;font-weight:700;color:#0f172a;margin-bottom:6px;">Record Filtering &amp; Masking</h3>
            <p style="font-size:13px;color:#64748b;max-width:480px;margin:0 auto 16px;">
                Use this section to selectively hide sensitive transaction records. To hide entire product categories, prices, or stock, use <a href="{{ route('superadmin.stock') }}" style="color:#4f46e5;font-weight:600;">Product &amp; Stock</a>.
            </p>
            <a href="{{ route('superadmin.stock') }}" class="btn primary" style="background:#4f46e5;color:#fff;">
                Configure Product &amp; Stock &rarr;
            </a>
        </div>
    </div>
</div>
@endsection

