@extends('superadmin.layout')

@section('title', 'Hide Records')

@section('content')
<div class="page-heading">
    <div>
        <span class="eyebrow">DEVELOPER OPTION</span>
        <h1>Secret Dashboard</h1>
        <p>Hide specific sales, invoices, customers, and transactions from normal user views.</p>
    </div>
    <div style="display:flex;gap:0.75rem;align-items:center;">
        <a href="{{ route('superadmin.controls') }}" class="btn secondary">
            <x-icon name="sliders-horizontal"/>
            Dashboard Controls
        </a>
        <a href="{{ route('superadmin.stock') }}" class="btn secondary">
            <x-icon name="package"/>
            Product &amp; Stock
        </a>
    </div>
</div>

<form method="POST" action="{{ route('superadmin.hide-records.save') }}" id="hide-records-form">
    @csrf

    <div class="card" style="margin-bottom:24px;">
        <div class="card-heading" style="margin-bottom:16px;">
            <div>
                <h2 style="font-size:18px;font-weight:700;">Hide Records Controls</h2>
                <p style="color:var(--muted);font-size:13px;margin-top:2px;">
                    Control invoice masking, customer/supplier hiding, and transaction limits for normal users.
                </p>
            </div>
            <span class="secret-badge">
                <x-icon name="shield-alert" :size="15"/>
                Super Admin Active
            </span>
        </div>

        <div style="padding: 0 24px 24px;">

            {{-- 2-Column Switches Grid matching screenshot --}}
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;" class="records-control-grid">

                {{-- LEFT COLUMN --}}
                <div style="display:flex;flex-direction:column;gap:18px;">

                    {{-- Mask supplier/customer names --}}
                    <div class="switch-wrap">
                        <div class="switch-info">
                            <strong>Mask supplier/customer names</strong>
                            <small>Shows names as Hidden where records are still visible.</small>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="mask_supplier_customer_names" value="1" @checked($switches['mask_supplier_customer_names'])>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    {{-- Hide all customer accounts fully --}}
                    <div class="switch-wrap">
                        <div class="switch-info">
                            <strong>Hide all customer accounts fully</strong>
                            <small>Completely removes customer accounts from directories, stat cards, and reports.</small>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="hide_all_customers" value="1" @checked($switches['hide_all_customers'] ?? false)>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    {{-- Mask invoice numbers/details --}}
                    <div class="switch-wrap">
                        <div class="switch-info">
                            <strong>Mask invoice numbers/details</strong>
                            <small>Masks invoice numbers instead of showing the real number.</small>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="mask_invoice_numbers" value="1" @checked($switches['mask_invoice_numbers'])>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    {{-- Hide monthly purchase info --}}
                    <div class="switch-wrap">
                        <div class="switch-info">
                            <strong>Hide monthly purchase info</strong>
                            <small>Protects monthly purchase summaries.</small>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="hide_monthly_purchase_info" value="1" @checked($switches['hide_monthly_purchase_info'])>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    @if(config('features.cheque'))
                    {{-- Hide own cheques --}}
                    <div class="switch-wrap">
                        <div class="switch-info">
                            <strong>Hide own cheques</strong>
                            <small>Hides cheques issued by your shop.</small>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="hide_own_cheques" value="1" @checked($switches['hide_own_cheques'])>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    {{-- Hide customer cheques --}}
                    <div class="switch-wrap">
                        <div class="switch-info">
                            <strong>Hide customer cheques</strong>
                            <small>Hides cheques directly from your customers.</small>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="hide_customer_cheques" value="1" @checked($switches['hide_customer_cheques'])>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    @endif

                </div>

                {{-- RIGHT COLUMN --}}
                <div style="display:flex;flex-direction:column;gap:18px;">

                    {{-- Hide all supplier accounts fully --}}
                    <div class="switch-wrap">
                        <div class="switch-info">
                            <strong>Hide all supplier accounts fully</strong>
                            <small>Completely removes supplier accounts from directories, stat cards, and reports.</small>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="hide_all_suppliers" value="1" @checked($switches['hide_all_suppliers'] ?? false)>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    {{-- Mask payment amounts --}}
                    <div class="switch-wrap">
                        <div class="switch-info">
                            <strong>Mask payment amounts</strong>
                            <small>Hides paid/due payment amounts where supported.</small>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="mask_payment_amounts" value="1" @checked($switches['mask_payment_amounts'])>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    {{-- Hide weekly purchase info --}}
                    <div class="switch-wrap">
                        <div class="switch-info">
                            <strong>Hide weekly purchase info</strong>
                            <small>Protects weekly purchase summaries.</small>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="hide_weekly_purchase_info" value="1" @checked($switches['hide_weekly_purchase_info'])>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    {{-- Hide genuine stock values --}}
                    <div class="switch-wrap">
                        <div class="switch-info">
                            <strong>Hide genuine stock values</strong>
                            <small>Hides genuine stock metrics where supported.</small>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="hide_genuine_stock_values" value="1" @checked($switches['hide_genuine_stock_values'])>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    @if(config('features.cheque'))
                    {{-- Hide party cheques --}}
                    <div class="switch-wrap">
                        <div class="switch-info">
                            <strong>Hide party cheques</strong>
                            <small>Hides cheques from third-party customers.</small>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="hide_party_cheques" value="1" @checked($switches['hide_party_cheques'])>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    @endif

                </div>

            </div>

            {{-- Visible customer amount percentage --}}
            <div style="background:var(--bg, #f8fafc);border:1px solid var(--border,#e2e8f0);border-radius:12px;padding:16px;margin-top:20px;">
                <label for="visible_customer_amount_percentage" style="display:block;font-size:14px;font-weight:600;margin-bottom:6px;color:var(--ink);">
                    Visible customer amount percentage
                </label>
                <div style="display:flex;align-items:center;gap:10px;">
                    <input type="number"
                           id="visible_customer_amount_percentage"
                           name="visible_customer_amount_percentage"
                           min="0"
                           max="100"
                           step="1"
                           value="{{ old('visible_customer_amount_percentage', $customerPercentage) }}"
                           style="font-size:18px;font-weight:700;padding:8px 14px;border-radius:8px;border:1.5px solid var(--border);width:100%;color:#4f46e5;background:#fff;"
                           required>
                    <span style="font-size:16px;font-weight:700;color:var(--muted);">%</span>
                </div>
                <p style="font-size:12px;color:var(--muted);margin-top:6px;margin-bottom:0;">
                    Controls customer balances, purchase history, customer due reports, and customer payment views for normal users. Example: 50 shows half, 70 shows 70%.
                </p>
            </div>

            {{-- Advanced Cheque Rules (Shown ONLY when CHEQUE=true in .env) --}}
            @if(config('features.cheque'))
            <div style="background:var(--bg, #f8fafc);border:1px solid var(--border,#e2e8f0);border-radius:12px;padding:20px;margin-top:20px;">
                <h3 style="font-size:15px;font-weight:700;margin-bottom:14px;color:var(--ink);">Advanced Cheque Rules</h3>

                <div style="margin-bottom:16px;">
                    <label for="visible_cheque_amount_percentage" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:var(--ink);">
                        Visible Cheque Amount Percentage
                    </label>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <input type="number"
                               id="visible_cheque_amount_percentage"
                               name="visible_cheque_amount_percentage"
                               min="0"
                               max="100"
                               step="1"
                               value="{{ old('visible_cheque_amount_percentage', $chequePercentage) }}"
                               style="font-size:18px;font-weight:700;padding:8px 14px;border-radius:8px;border:1.5px solid var(--border);width:100%;color:#4f46e5;background:#fff;">
                        <span style="font-size:16px;font-weight:700;color:var(--muted);">%</span>
                    </div>
                    <p style="font-size:12px;color:var(--muted);margin-top:6px;margin-bottom:0;">
                        Scales down displayed cheque amounts. Example: 50 shows half the real amount.
                    </p>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;" class="cheque-rules-grid">
                    <div>
                        <label for="cheque_count_rule" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:var(--ink);">
                            Cheque Count Rule
                        </label>
                        <select id="cheque_count_rule" name="cheque_count_rule"
                                style="width:100%;padding:9px 14px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:#fff;">
                            <option value="NONE" @selected($chequeCountRule === 'NONE')>No Rule (Show All)</option>
                            <option value="HIDE_TOP_HIGHEST" @selected($chequeCountRule === 'HIDE_TOP_HIGHEST')>Hide Top Highest</option>
                            <option value="HIDE_TOP_LOWEST" @selected($chequeCountRule === 'HIDE_TOP_LOWEST')>Hide Top Lowest</option>
                            <option value="HIDE_ALL" @selected($chequeCountRule === 'HIDE_ALL')>Hide All Cheques</option>
                        </select>
                    </div>
                    <div>
                        <label for="cheque_rule_limit" style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:var(--ink);">
                            Rule Limit
                        </label>
                        <input type="number"
                               id="cheque_rule_limit"
                               name="cheque_rule_limit"
                               min="0"
                               step="1"
                               value="{{ old('cheque_rule_limit', $chequeRuleLimit) }}"
                               style="width:100%;padding:9px 14px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:#fff;">
                    </div>
                </div>
                <p style="font-size:12px;color:var(--muted);margin-top:8px;margin-bottom:0;">
                    Example: Selecting 'Hide Top Highest' with limit '5' will hide the 5 largest cheques entirely.
                </p>
            </div>
            @endif

            {{-- 3-Column Search & Ranges Grid matching Screenshot --}}
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:20px;margin-top:20px;" class="records-filter-grid">

                {{-- COLUMN 1: Suppliers --}}
                <div style="display:flex;flex-direction:column;gap:16px;">
                    <div style="background:var(--bg, #f8fafc);border:1px solid var(--border,#e2e8f0);border-radius:12px;padding:16px;">
                        <strong style="display:block;font-size:14px;font-weight:600;color:var(--ink);">Hide suppliers</strong>
                        <small style="display:block;font-size:12px;color:var(--muted);margin-top:3px;margin-bottom:10px;">
                            Search by supplier name or phone number.
                        </small>
                        <div style="display:flex;gap:8px;position:relative;">
                            <input type="text"
                                   id="supplier-search-input"
                                   placeholder="Supplier name / phone"
                                   style="flex:1;padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:#fff;"
                                   autocomplete="off">
                            <button type="button"
                                    id="btn-add-supplier"
                                    class="btn primary"
                                    style="background:#2563eb;color:#fff;border-radius:8px;padding:8px 16px;font-size:13px;font-weight:600;">
                                Add
                            </button>
                            <div id="supplier-search-results"
                                 style="display:none;position:absolute;top:100%;left:0;right:60px;background:#fff;border:1px solid var(--border);border-radius:8px;box-shadow:0 10px 25px rgba(0,0,0,0.1);z-index:50;max-height:200px;overflow-y:auto;margin-top:4px;">
                            </div>
                        </div>

                        <div id="hidden-suppliers-container" style="margin-top:12px;">
                            @if(empty($hiddenSuppliers))
                                <span id="no-suppliers-placeholder" style="font-size:12px;color:var(--muted);font-style:italic;">No items added yet.</span>
                            @else
                                <div id="hidden-suppliers-chips" style="display:flex;flex-wrap:wrap;gap:6px;">
                                    @foreach($hiddenSuppliers as $hs)
                                        <span class="hidden-item-chip" data-id="{{ $hs['id'] }}"
                                              style="display:inline-flex;align-items:center;gap:6px;background:#fee2e2;color:#991b1b;border:1px solid #fecaca;padding:3px 8px;border-radius:6px;font-size:12px;font-weight:600;">
                                            <span>{{ $hs['name'] }}</span>
                                            <button type="button" onclick="removeHiddenSupplier({{ $hs['id'] }}, this)" style="background:transparent;border:none;cursor:pointer;color:#dc2626;font-size:14px;line-height:1;padding:0;">&times;</button>
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- Hide sales amount ranges --}}
                    <div style="background:var(--bg, #f8fafc);border:1px solid var(--border,#e2e8f0);border-radius:12px;padding:16px;">
                        <label for="hide_sales_amount_ranges" style="display:block;font-size:14px;font-weight:600;margin-bottom:6px;color:var(--ink);">
                            Hide sales amount ranges
                        </label>
                        <input type="text"
                               id="hide_sales_amount_ranges"
                               name="hide_sales_amount_ranges"
                               value="{{ old('hide_sales_amount_ranges', $hideSalesAmountRanges) }}"
                               placeholder="Example: 0-100,500-1000"
                               style="width:100%;padding:9px 14px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:#fff;">
                    </div>
                </div>

                {{-- COLUMN 2: Customers --}}
                <div style="display:flex;flex-direction:column;gap:16px;">
                    <div style="background:var(--bg, #f8fafc);border:1px solid var(--border,#e2e8f0);border-radius:12px;padding:16px;">
                        <strong style="display:block;font-size:14px;font-weight:600;color:var(--ink);">Hide customers</strong>
                        <small style="display:block;font-size:12px;color:var(--muted);margin-top:3px;margin-bottom:10px;">
                            Search by customer name, phone, or email.
                        </small>
                        <div style="display:flex;gap:8px;position:relative;">
                            <input type="text"
                                   id="customer-search-input"
                                   placeholder="Customer name / phone"
                                   style="flex:1;padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:#fff;"
                                   autocomplete="off">
                            <button type="button"
                                    id="btn-add-customer"
                                    class="btn primary"
                                    style="background:#2563eb;color:#fff;border-radius:8px;padding:8px 16px;font-size:13px;font-weight:600;">
                                Add
                            </button>
                            <div id="customer-search-results"
                                 style="display:none;position:absolute;top:100%;left:0;right:60px;background:#fff;border:1px solid var(--border);border-radius:8px;box-shadow:0 10px 25px rgba(0,0,0,0.1);z-index:50;max-height:200px;overflow-y:auto;margin-top:4px;">
                            </div>
                        </div>

                        <div id="hidden-customers-container" style="margin-top:12px;">
                            @if(empty($hiddenCustomers))
                                <span id="no-customers-placeholder" style="font-size:12px;color:var(--muted);font-style:italic;">No items added yet.</span>
                            @else
                                <div id="hidden-customers-chips" style="display:flex;flex-wrap:wrap;gap:6px;">
                                    @foreach($hiddenCustomers as $hc)
                                        <span class="hidden-item-chip" data-id="{{ $hc['id'] }}"
                                              style="display:inline-flex;align-items:center;gap:6px;background:#fee2e2;color:#991b1b;border:1px solid #fecaca;padding:3px 8px;border-radius:6px;font-size:12px;font-weight:600;">
                                            <span>{{ $hc['name'] }}</span>
                                            <button type="button" onclick="removeHiddenCustomer({{ $hc['id'] }}, this)" style="background:transparent;border:none;cursor:pointer;color:#dc2626;font-size:14px;line-height:1;padding:0;">&times;</button>
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- Hide purchase amount ranges --}}
                    <div style="background:var(--bg, #f8fafc);border:1px solid var(--border,#e2e8f0);border-radius:12px;padding:16px;">
                        <label for="hide_purchase_amount_ranges" style="display:block;font-size:14px;font-weight:600;margin-bottom:6px;color:var(--ink);">
                            Hide purchase amount ranges
                        </label>
                        <input type="text"
                               id="hide_purchase_amount_ranges"
                               name="hide_purchase_amount_ranges"
                               value="{{ old('hide_purchase_amount_ranges', $hidePurchaseAmountRanges) }}"
                               placeholder="Example: 100-500,1000-5000"
                               style="width:100%;padding:9px 14px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:#fff;">
                    </div>
                </div>

                {{-- COLUMN 3: Sales Bills --}}
                <div style="display:flex;flex-direction:column;gap:16px;">
                    <div style="background:var(--bg, #f8fafc);border:1px solid var(--border,#e2e8f0);border-radius:12px;padding:16px;">
                        <strong style="display:block;font-size:14px;font-weight:600;color:var(--ink);">Hide sales bills</strong>
                        <small style="display:block;font-size:12px;color:var(--muted);margin-top:3px;margin-bottom:10px;">
                            Search by invoice number, bill ID, or customer name.
                        </small>
                        <div style="display:flex;gap:8px;position:relative;">
                            <input type="text"
                                   id="sale-search-input"
                                   placeholder="Invoice / bill / customer"
                                   style="flex:1;padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:#fff;"
                                   autocomplete="off">
                            <button type="button"
                                    id="btn-add-sale"
                                    class="btn primary"
                                    style="background:#2563eb;color:#fff;border-radius:8px;padding:8px 16px;font-size:13px;font-weight:600;">
                                Add
                            </button>
                            <div id="sale-search-results"
                                 style="display:none;position:absolute;top:100%;left:0;right:60px;background:#fff;border:1px solid var(--border);border-radius:8px;box-shadow:0 10px 25px rgba(0,0,0,0.1);z-index:50;max-height:200px;overflow-y:auto;margin-top:4px;">
                            </div>
                        </div>

                        <div id="hidden-sales-container" style="margin-top:12px;">
                            @if(empty($hiddenSalesBills))
                                <span id="no-sales-placeholder" style="font-size:12px;color:var(--muted);font-style:italic;">No items added yet.</span>
                            @else
                                <div id="hidden-sales-chips" style="display:flex;flex-wrap:wrap;gap:6px;">
                                    @foreach($hiddenSalesBills as $hsb)
                                        <span class="hidden-item-chip" data-id="{{ $hsb['id'] }}"
                                              style="display:inline-flex;align-items:center;gap:6px;background:#fee2e2;color:#991b1b;border:1px solid #fecaca;padding:3px 8px;border-radius:6px;font-size:12px;font-weight:600;">
                                            <span>{{ $hsb['invoice'] }}</span>
                                            <button type="button" onclick="removeHiddenSale({{ $hsb['id'] }}, this)" style="background:transparent;border:none;cursor:pointer;color:#dc2626;font-size:14px;line-height:1;padding:0;">&times;</button>
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- Hide customer purchase ranges --}}
                    <div style="background:var(--bg, #f8fafc);border:1px solid var(--border,#e2e8f0);border-radius:12px;padding:16px;">
                        <label for="hide_customer_purchase_ranges" style="display:block;font-size:14px;font-weight:600;margin-bottom:6px;color:var(--ink);">
                            Hide customer purchase ranges
                        </label>
                        <input type="text"
                               id="hide_customer_purchase_ranges"
                               name="hide_customer_purchase_ranges"
                               value="{{ old('hide_customer_purchase_ranges', $hideCustomerPurchaseRanges) }}"
                               placeholder="Example: 100-1000,3000-10000"
                               style="width:100%;padding:9px 14px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:#fff;">
                    </div>
                </div>

            </div>

        </div>
    </div>

    {{-- Sticky action bar matching screenshot --}}
    <div class="sticky-action-bar" style="background:#0f172a;border-radius:12px;padding:14px 24px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 10px 25px -5px rgba(15, 23, 42, 0.4);">
        <span style="font-size:13px;color:#cbd5e1;display:flex;align-items:center;gap:8px;">
            <x-icon name="info" :size="16"/>
            Saving affects normal users and admins immediately. Super Admin stays unrestricted.
        </span>
        <button type="submit" class="btn primary" style="background:#4f46e5;color:#fff;border-radius:8px;padding:10px 24px;font-weight:600;display:inline-flex;align-items:center;gap:8px;box-shadow:0 4px 12px rgba(79, 70, 229, 0.4);">
            <x-icon name="save" :size="16"/>
            Save This Section
        </button>
    </div>
</form>

<style>
@media (max-width: 900px) {
    .records-control-grid {
        grid-template-columns: 1fr !important;
    }
    .cheque-rules-grid {
        grid-template-columns: 1fr !important;
    }
    .records-filter-grid {
        grid-template-columns: 1fr !important;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '{{ csrf_token() }}';

    // Generic setup for search, add, remove
    function setupSearch(inputEl, resultsEl, searchUrl, onSelect) {
        if (!inputEl || !resultsEl) return;
        let debounceTimer;

        inputEl.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            const q = this.value.trim();
            if (q.length < 1) {
                resultsEl.style.display = 'none';
                resultsEl.innerHTML = '';
                return;
            }

            debounceTimer = setTimeout(() => {
                fetch(`${searchUrl}?q=${encodeURIComponent(q)}`, {
                    headers: { 'Accept': 'application/json' }
                })
                .then(r => r.json())
                .then(items => {
                    resultsEl.innerHTML = '';
                    if (!items || items.length === 0) {
                        resultsEl.innerHTML = '<div style="padding:10px;font-size:13px;color:#94a3b8;font-style:italic;">No matches found</div>';
                    } else {
                        items.forEach(item => {
                            const row = document.createElement('div');
                            row.style.cssText = 'padding:9px 12px;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:13px;display:flex;justify-content:space-between;align-items:center;';
                            row.onmouseover = () => row.style.background = '#f8fafc';
                            row.onmouseout = () => row.style.background = '#fff';
                            
                            const label = item.invoice ? `${item.invoice} (${item.customer_name})` : (item.name + (item.phone ? ' · ' + item.phone : ''));
                            row.innerHTML = `<span><strong>${label}</strong></span><span style="font-size:11px;color:#64748b;background:#f1f5f9;padding:2px 6px;border-radius:4px;">ID #${item.id}</span>`;
                            row.addEventListener('click', () => {
                                onSelect(item);
                                resultsEl.style.display = 'none';
                                resultsEl.innerHTML = '';
                            });
                            resultsEl.appendChild(row);
                        });
                    }
                    resultsEl.style.display = 'block';
                })
                .catch(() => { resultsEl.style.display = 'none'; });
            }, 250);
        });

        document.addEventListener('click', function (e) {
            if (!inputEl.contains(e.target) && !resultsEl.contains(e.target)) {
                resultsEl.style.display = 'none';
            }
        });
    }

    // 1. Suppliers
    const suppInput = document.getElementById('supplier-search-input');
    const suppBtn = document.getElementById('btn-add-supplier');
    const suppResults = document.getElementById('supplier-search-results');
    const suppContainer = document.getElementById('hidden-suppliers-container');

    setupSearch(suppInput, suppResults, '{{ route('superadmin.hide-records.search-suppliers') }}', function(item) {
        suppInput.value = item.id;
        suppBtn.click();
    });

    suppBtn?.addEventListener('click', function () {
        const val = suppInput.value.trim();
        if (!val) return;
        fetch('{{ route('superadmin.hide-records.hidden-supplier.add') }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ supplier: val })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                suppInput.value = '';
                renderSuppliers(data.hidden);
            } else if (data.message) {
                alert(data.message);
            }
        });
    });

    window.removeHiddenSupplier = function(id, btn) {
        fetch(`{{ url('/superadmin/hide-records/hidden-supplier') }}/${id}`, {
            method: 'DELETE',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken }
        })
        .then(r => r.json())
        .then(data => { if (data.success) renderSuppliers(data.hidden); });
    };

    function renderSuppliers(list) {
        if (!list || list.length === 0) {
            suppContainer.innerHTML = '<span id="no-suppliers-placeholder" style="font-size:12px;color:var(--muted);font-style:italic;">No items added yet.</span>';
            return;
        }
        let html = '<div id="hidden-suppliers-chips" style="display:flex;flex-wrap:wrap;gap:6px;">';
        list.forEach(s => {
            html += `<span class="hidden-item-chip" data-id="${s.id}" style="display:inline-flex;align-items:center;gap:6px;background:#fee2e2;color:#991b1b;border:1px solid #fecaca;padding:3px 8px;border-radius:6px;font-size:12px;font-weight:600;">
                <span>${s.name}</span>
                <button type="button" onclick="removeHiddenSupplier(${s.id}, this)" style="background:transparent;border:none;cursor:pointer;color:#dc2626;font-size:14px;line-height:1;padding:0;">&times;</button>
            </span>`;
        });
        html += '</div>';
        suppContainer.innerHTML = html;
    }

    // 2. Customers
    const custInput = document.getElementById('customer-search-input');
    const custBtn = document.getElementById('btn-add-customer');
    const custResults = document.getElementById('customer-search-results');
    const custContainer = document.getElementById('hidden-customers-container');

    setupSearch(custInput, custResults, '{{ route('superadmin.hide-records.search-customers') }}', function(item) {
        custInput.value = item.id;
        custBtn.click();
    });

    custBtn?.addEventListener('click', function () {
        const val = custInput.value.trim();
        if (!val) return;
        fetch('{{ route('superadmin.hide-records.hidden-customer.add') }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ customer: val })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                custInput.value = '';
                renderCustomers(data.hidden);
            } else if (data.message) {
                alert(data.message);
            }
        });
    });

    window.removeHiddenCustomer = function(id, btn) {
        fetch(`{{ url('/superadmin/hide-records/hidden-customer') }}/${id}`, {
            method: 'DELETE',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken }
        })
        .then(r => r.json())
        .then(data => { if (data.success) renderCustomers(data.hidden); });
    };

    function renderCustomers(list) {
        if (!list || list.length === 0) {
            custContainer.innerHTML = '<span id="no-customers-placeholder" style="font-size:12px;color:var(--muted);font-style:italic;">No items added yet.</span>';
            return;
        }
        let html = '<div id="hidden-customers-chips" style="display:flex;flex-wrap:wrap;gap:6px;">';
        list.forEach(c => {
            html += `<span class="hidden-item-chip" data-id="${c.id}" style="display:inline-flex;align-items:center;gap:6px;background:#fee2e2;color:#991b1b;border:1px solid #fecaca;padding:3px 8px;border-radius:6px;font-size:12px;font-weight:600;">
                <span>${c.name}</span>
                <button type="button" onclick="removeHiddenCustomer(${c.id}, this)" style="background:transparent;border:none;cursor:pointer;color:#dc2626;font-size:14px;line-height:1;padding:0;">&times;</button>
            </span>`;
        });
        html += '</div>';
        custContainer.innerHTML = html;
    }

    // 3. Sales Bills
    const saleInput = document.getElementById('sale-search-input');
    const saleBtn = document.getElementById('btn-add-sale');
    const saleResults = document.getElementById('sale-search-results');
    const saleContainer = document.getElementById('hidden-sales-container');

    setupSearch(saleInput, saleResults, '{{ route('superadmin.hide-records.search-sales') }}', function(item) {
        saleInput.value = item.id;
        saleBtn.click();
    });

    saleBtn?.addEventListener('click', function () {
        const val = saleInput.value.trim();
        if (!val) return;
        fetch('{{ route('superadmin.hide-records.hidden-sale.add') }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ sale: val })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                saleInput.value = '';
                renderSales(data.hidden);
            } else if (data.message) {
                alert(data.message);
            }
        });
    });

    window.removeHiddenSale = function(id, btn) {
        fetch(`{{ url('/superadmin/hide-records/hidden-sale') }}/${id}`, {
            method: 'DELETE',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken }
        })
        .then(r => r.json())
        .then(data => { if (data.success) renderSales(data.hidden); });
    };

    function renderSales(list) {
        if (!list || list.length === 0) {
            saleContainer.innerHTML = '<span id="no-sales-placeholder" style="font-size:12px;color:var(--muted);font-style:italic;">No items added yet.</span>';
            return;
        }
        let html = '<div id="hidden-sales-chips" style="display:flex;flex-wrap:wrap;gap:6px;">';
        list.forEach(s => {
            html += `<span class="hidden-item-chip" data-id="${s.id}" style="display:inline-flex;align-items:center;gap:6px;background:#fee2e2;color:#991b1b;border:1px solid #fecaca;padding:3px 8px;border-radius:6px;font-size:12px;font-weight:600;">
                <span>${s.invoice}</span>
                <button type="button" onclick="removeHiddenSale(${s.id}, this)" style="background:transparent;border:none;cursor:pointer;color:#dc2626;font-size:14px;line-height:1;padding:0;">&times;</button>
            </span>`;
        });
        html += '</div>';
        saleContainer.innerHTML = html;
    }
});
</script>
@endsection
