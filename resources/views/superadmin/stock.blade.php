@extends('superadmin.layout')

@section('title', 'Product & Stock Visibility')

@section('content')
<div class="page-heading">
    <div>
        <span class="eyebrow">DEVELOPER OPTION</span>
        <h1>Secret Dashboard</h1>
        <p>Control product prices, cost prices, stock quantities, and exact products normal users should not see.</p>
    </div>
    <div style="display:flex;gap:0.75rem;align-items:center;">
        <a href="{{ route('superadmin.controls') }}" class="btn secondary">
            <x-icon name="sliders-horizontal"/>
            Dashboard Controls
        </a>
        <a href="{{ route('superadmin.products') }}" class="btn secondary">
            <x-icon name="package"/>
            Real Products
        </a>
    </div>
</div>

<form method="POST" action="{{ route('superadmin.stock.save') }}" id="stock-form">
    @csrf

    <div class="card" style="margin-bottom:24px;">
        <div class="card-heading" style="margin-bottom:16px;">
            <div>
                <h2 style="font-size:18px;font-weight:700;">Product &amp; Stock Visibility</h2>
                <p style="color:var(--muted);font-size:13px;margin-top:2px;">
                    Control product prices, cost prices, stock quantities, and exact products normal users should not see.
                </p>
            </div>
            <span class="secret-badge">
                <x-icon name="shield-alert" :size="15"/>
                Super Admin Active
            </span>
        </div>

        <div style="padding: 0 24px 24px;">

        {{-- 3-Card Percentage Grid --}}
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));gap:16px;margin-bottom:24px;">

            {{-- Visible stock/quantity percentage --}}
            <div style="background:var(--bg, #f8fafc);border:1px solid var(--border,#e2e8f0);border-radius:12px;padding:16px;">
                <label for="visible_stock_percentage" style="display:block;font-size:14px;font-weight:600;margin-bottom:6px;color:var(--ink);">
                    Visible stock/quantity percentage
                </label>
                <div style="display:flex;align-items:center;gap:10px;">
                    <input type="number"
                           id="visible_stock_percentage"
                           name="visible_stock_percentage"
                           min="0"
                           max="100"
                           step="1"
                           value="{{ old('visible_stock_percentage', $stockPercentage) }}"
                           style="font-size:18px;font-weight:700;padding:8px 14px;border-radius:8px;border:1.5px solid var(--border);width:100%;color:#4f46e5;background:#fff;"
                           required>
                    <span style="font-size:16px;font-weight:700;color:var(--muted);">%</span>
                </div>
                <p style="font-size:12px;color:var(--muted);margin-top:6px;margin-bottom:0;">
                    100 means show the full quantity. 50 means show half.
                </p>
            </div>

            {{-- Visible cost price percentage --}}
            <div style="background:var(--bg, #f8fafc);border:1px solid var(--border,#e2e8f0);border-radius:12px;padding:16px;">
                <label for="visible_cost_percentage" style="display:block;font-size:14px;font-weight:600;margin-bottom:6px;color:var(--ink);">
                    Visible cost price percentage
                </label>
                <div style="display:flex;align-items:center;gap:10px;">
                    <input type="number"
                           id="visible_cost_percentage"
                           name="visible_cost_percentage"
                           min="0"
                           max="100"
                           step="1"
                           value="{{ old('visible_cost_percentage', $costPercentage) }}"
                           style="font-size:18px;font-weight:700;padding:8px 14px;border-radius:8px;border:1.5px solid var(--border);width:100%;color:#4f46e5;background:#fff;"
                           required>
                    <span style="font-size:16px;font-weight:700;color:var(--muted);">%</span>
                </div>
                <p style="font-size:12px;color:var(--muted);margin-top:6px;margin-bottom:0;">
                    100 means show full cost. 50 means show 50% of cost prices and COGS.
                </p>
            </div>

            {{-- Visible selling price percentage --}}
            <div style="background:var(--bg, #f8fafc);border:1px solid var(--border,#e2e8f0);border-radius:12px;padding:16px;">
                <label for="visible_selling_price_percentage" style="display:block;font-size:14px;font-weight:600;margin-bottom:6px;color:var(--ink);">
                    Visible selling price percentage
                </label>
                <div style="display:flex;align-items:center;gap:10px;">
                    <input type="number"
                           id="visible_selling_price_percentage"
                           name="visible_selling_price_percentage"
                           min="0"
                           max="100"
                           step="1"
                           value="{{ old('visible_selling_price_percentage', $sellingPricePercentage ?? $pricePercentage) }}"
                           style="font-size:18px;font-weight:700;padding:8px 14px;border-radius:8px;border:1.5px solid var(--border);width:100%;color:#4f46e5;background:#fff;"
                           required>
                    <span style="font-size:16px;font-weight:700;color:var(--muted);">%</span>
                </div>
                <p style="font-size:12px;color:var(--muted);margin-top:6px;margin-bottom:0;">
                    100 means show full price. 50 means show 50% of selling prices.
                </p>
            </div>

        </div>

        {{-- 2-Column Grid matching Screenshot --}}
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;" class="stock-control-grid">

            {{-- LEFT COLUMN --}}
            <div style="display:flex;flex-direction:column;gap:18px;">

                {{-- Hide exact stock quantity --}}
                <div class="switch-wrap">
                    <div class="switch-info">
                        <strong>Hide exact stock quantity</strong>
                        <small>Shows stock as hidden instead of exact quantities.</small>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="hide_exact_stock_quantity" value="1" @checked($switches['hide_exact_stock_quantity'])>
                        <span class="toggle-slider"></span>
                    </label>
                </div>

                {{-- Hide exact purchase cost --}}
                <div class="switch-wrap">
                    <div class="switch-info">
                        <strong>Hide exact purchase cost</strong>
                        <small>Hides cost/purchase prices from normal users.</small>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="hide_exact_purchase_cost" value="1" @checked($switches['hide_exact_purchase_cost'])>
                        <span class="toggle-slider"></span>
                    </label>
                </div>

                {{-- Hide all quantity data --}}
                <div class="switch-wrap">
                    <div class="switch-info">
                        <strong>Hide all quantity data</strong>
                        <small>Hides all quantity-based fields controlled by this dashboard.</small>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="hide_all_quantity_data" value="1" @checked($switches['hide_all_quantity_data'])>
                        <span class="toggle-slider"></span>
                    </label>
                </div>

                {{-- Hide products by search --}}
                <div style="background:var(--bg, #f8fafc);border:1px solid var(--border,#e2e8f0);border-radius:12px;padding:16px;">
                    <div style="margin-bottom:10px;">
                        <strong style="display:block;font-size:14px;font-weight:600;color:var(--ink);">Hide products by search</strong>
                        <small style="display:block;font-size:12px;color:var(--muted);margin-top:3px;line-height:1.4;">
                            Search product name, SKU, or barcode. Hidden products disappear from product lists, POS, sales, purchase screens, and reports for normal users.
                        </small>
                    </div>

                    {{-- Add product form / search --}}
                    <div style="display:flex;gap:8px;position:relative;">
                        <input type="text"
                               id="product-search-input"
                               placeholder="Type product name / SKU / barcode"
                               style="flex:1;padding:9px 14px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:#fff;"
                               autocomplete="off">
                        <button type="button"
                                id="btn-add-product"
                                class="btn primary"
                                style="background:#2563eb;color:#fff;border-radius:8px;padding:9px 18px;font-weight:600;font-size:13px;">
                            Add
                        </button>

                        {{-- Autocomplete dropdown --}}
                        <div id="product-search-results"
                             style="display:none;position:absolute;top:100%;left:0;right:60px;background:#fff;border:1px solid var(--border);border-radius:8px;box-shadow:0 10px 25px rgba(0,0,0,0.1);z-index:50;max-height:220px;overflow-y:auto;margin-top:4px;">
                        </div>
                    </div>

                    {{-- Hidden products tag list --}}
                    <div id="hidden-products-container" style="margin-top:14px;">
                        @if(empty($hiddenProducts))
                            <span id="no-items-placeholder" style="font-size:13px;color:var(--muted);font-style:italic;">No items added yet.</span>
                        @else
                            <div id="hidden-chips-list" style="display:flex;flex-wrap:wrap;gap:8px;">
                                @foreach($hiddenProducts as $hp)
                                    <span class="hidden-product-chip" data-id="{{ $hp['id'] }}"
                                          style="display:inline-flex;align-items:center;gap:6px;background:#fee2e2;color:#991b1b;border:1px solid #fecaca;padding:4px 10px;border-radius:6px;font-size:12px;font-weight:600;">
                                        <span>{{ $hp['name'] }} ({{ $hp['sku'] }})</span>
                                        <button type="button"
                                                onclick="removeHiddenItem({{ $hp['id'] }}, this)"
                                                style="background:transparent;border:none;cursor:pointer;color:#dc2626;font-size:14px;line-height:1;padding:0;">
                                            &times;
                                        </button>
                                    </span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>

            </div>

            {{-- RIGHT COLUMN --}}
            <div style="display:flex;flex-direction:column;gap:18px;">

                {{-- Enable POS price percentage --}}
                <div class="switch-wrap">
                    <div class="switch-info">
                        <strong>Enable POS price percentage</strong>
                        <small>Apply this percentage to POS product prices and cart prices.</small>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="enable_pos_price_percentage" value="1" @checked($switches['enable_pos_price_percentage'])>
                        <span class="toggle-slider"></span>
                    </label>
                </div>

                {{-- Hide exact selling price --}}
                <div class="switch-wrap">
                    <div class="switch-info">
                        <strong>Hide exact selling price</strong>
                        <small>Hides product selling prices where this rule applies.</small>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="hide_exact_selling_price" value="1" @checked($switches['hide_exact_selling_price'])>
                        <span class="toggle-slider"></span>
                    </label>
                </div>

                {{-- Hide all price data --}}
                <div class="switch-wrap">
                    <div class="switch-info">
                        <strong>Hide all price data</strong>
                        <small>Hides all price-based fields controlled by this dashboard.</small>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="hide_all_price_data" value="1" @checked($switches['hide_all_price_data'])>
                        <span class="toggle-slider"></span>
                    </label>
                </div>

                {{-- Hide product-level data --}}
                <div class="switch-wrap">
                    <div class="switch-info">
                        <strong>Hide product-level data</strong>
                        <small>Replaces product names/details where supported.</small>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" name="hide_product_level_data" value="1" @checked($switches['hide_product_level_data'])>
                        <span class="toggle-slider"></span>
                    </label>
                </div>

                {{-- Hide product cost price ranges --}}
                <div style="background:var(--bg, #f8fafc);border:1px solid var(--border,#e2e8f0);border-radius:12px;padding:16px;">
                    <label for="hide_cost_price_ranges" style="display:block;font-size:14px;font-weight:600;margin-bottom:6px;color:var(--ink);">
                        Hide product cost price ranges
                    </label>
                    <input type="text"
                           id="hide_cost_price_ranges"
                           name="hide_cost_price_ranges"
                           value="{{ old('hide_cost_price_ranges', $hideCostPriceRanges) }}"
                           placeholder="Example: 0-50,120-200"
                           style="width:100%;padding:9px 14px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:#fff;">
                </div>

                {{-- Hide product selling price ranges --}}
                <div style="background:var(--bg, #f8fafc);border:1px solid var(--border,#e2e8f0);border-radius:12px;padding:16px;">
                    <label for="hide_selling_price_ranges" style="display:block;font-size:14px;font-weight:600;margin-bottom:6px;color:var(--ink);">
                        Hide product selling price ranges
                    </label>
                    <input type="text"
                           id="hide_selling_price_ranges"
                           name="hide_selling_price_ranges"
                           value="{{ old('hide_selling_price_ranges', $hideSellingPriceRanges) }}"
                           placeholder="Example: 0-100,250-400"
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
    .stock-control-grid {
        grid-template-columns: 1fr !important;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('product-search-input');
    const btnAdd = document.getElementById('btn-add-product');
    const resultsBox = document.getElementById('product-search-results');
    const container = document.getElementById('hidden-products-container');

    let debounceTimer;

    searchInput.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        const query = this.value.trim();
        if (query.length < 1) {
            resultsBox.style.display = 'none';
            return;
        }

        debounceTimer = setTimeout(() => {
            fetch('{{ route('superadmin.stock.search-products') }}?q=' + encodeURIComponent(query))
                .then(res => res.json())
                .then(data => {
                    if (!data || data.length === 0) {
                        resultsBox.innerHTML = '<div style="padding:10px;font-size:12px;color:#94a3b8;">No matching products found.</div>';
                        resultsBox.style.display = 'block';
                        return;
                    }

                    let html = '';
                    data.forEach(p => {
                        html += `
                            <div class="search-item" onclick="selectProduct(${p.id}, '${escapeHtml(p.name)}', '${escapeHtml(p.sku)}')"
                                 style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:13px;display:flex;justify-content:space-between;align-items:center;">
                                <span><strong>${escapeHtml(p.name)}</strong> <small style="color:#64748b;">(${escapeHtml(p.sku)})</small></span>
                                <span style="font-size:11px;color:#4f46e5;font-weight:600;">+ Hide</span>
                            </div>
                        `;
                    });
                    resultsBox.innerHTML = html;
                    resultsBox.style.display = 'block';
                })
                .catch(() => {
                    resultsBox.style.display = 'none';
                });
        }, 250);
    });

    document.addEventListener('click', function (e) {
        if (!searchInput.contains(e.target) && !resultsBox.contains(e.target)) {
            resultsBox.style.display = 'none';
        }
    });

    btnAdd.addEventListener('click', function () {
        const query = searchInput.value.trim();
        if (!query) return;

        addHiddenItem(query);
    });

    window.selectProduct = function (id, name, sku) {
        resultsBox.style.display = 'none';
        searchInput.value = '';
        addHiddenItem(id);
    };

    function addHiddenItem(input) {
        fetch('{{ route('superadmin.stock.hidden-product.add') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept': 'application/json'
            },
            body: JSON.stringify({ product: input })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                renderChips(data.hidden);
                searchInput.value = '';
            } else {
                alert(data.message || 'Product could not be added.');
            }
        })
        .catch(err => {
            console.error(err);
        });
    }

    window.removeHiddenItem = function (id, btn) {
        fetch('{{ url('/superadmin/stock/hidden-product') }}/' + id, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept': 'application/json'
            }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                renderChips(data.hidden);
            }
        });
    };

    function renderChips(list) {
        if (!list || list.length === 0) {
            container.innerHTML = '<span id="no-items-placeholder" style="font-size:13px;color:var(--muted);font-style:italic;">No items added yet.</span>';
            return;
        }

        let html = '<div id="hidden-chips-list" style="display:flex;flex-wrap:wrap;gap:8px;">';
        list.forEach(item => {
            html += `
                <span class="hidden-product-chip" data-id="${item.id}"
                      style="display:inline-flex;align-items:center;gap:6px;background:#fee2e2;color:#991b1b;border:1px solid #fecaca;padding:4px 10px;border-radius:6px;font-size:12px;font-weight:600;">
                    <span>${escapeHtml(item.name)} (${escapeHtml(item.sku || '')})</span>
                    <button type="button" onclick="removeHiddenItem(${item.id}, this)"
                            style="background:transparent;border:none;cursor:pointer;color:#dc2626;font-size:14px;line-height:1;padding:0;">
                        &times;
                    </button>
                </span>
            `;
        });
        html += '</div>';
        container.innerHTML = html;
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
});
</script>
@endsection

