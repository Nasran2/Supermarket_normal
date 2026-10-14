import { unitsEnabled, unitLabel } from './units.js';
import { fitAndCheckLabels, configureOrientation } from './barcode-layout.js';
const app = document.getElementById('barcode-app');
if (app) {
  configureOrientation(app);
  const $ = (id) => document.getElementById(id);
  const csrf = document.querySelector('meta[name=csrf-token]').content;
  const escape = (value) =>
    String(value ?? '').replace(
      /[&<>"']/g,
      (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c],
    );
  const initial = JSON.parse($('barcode-initial').textContent);
  let presets = JSON.parse($('barcode-presets').textContent);
  let configuration = JSON.parse($('barcode-configuration').textContent);
  const products = new Map();
  let rows = [],
    visible = [],
    next = 0,
    previewVersion = 0,
    timer,
    searchVersion = 0,
    busy = false;
  const canBulk = app.dataset.canBulk === '1';
  const money = (n) =>
    `${app.dataset.currency} ${Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  const error = (message) => {
    $('barcode-error').textContent = message;
    $('barcode-error').hidden = !message;
  };
  async function request(url, body, method = 'POST') {
    const response = await fetch(url, {
      method,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrf,
      },
      body: body ? JSON.stringify(body) : undefined,
    });
    const data = await response.json();
    if (!response.ok)
      throw new Error(
        Object.values(data.errors || {})
          .flat()
          .join(' ') ||
          data.message ||
          'The request failed.',
      );
    return data;
  }
  function add(p, selected = false, quantity = app.dataset.defaultQuantity, option = '') {
    products.set(p.id, p);
    if (selected && !canBulk && rows.some((r) => r.selected && r.product_id !== p.id))
      rows.forEach((r) => (r.selected = false));
    rows.push({
      id: ++next,
      product_id: p.id,
      selected,
      quantity: Number(quantity),
      option: option || (p.options.length === 1 ? p.options[0].key : ''),
    });
  }
  function readConfig() {
    const c = { ...configuration };
    for (const input of app.querySelectorAll('[data-config]')) {
      c[input.dataset.config] =
        input.type === 'checkbox'
          ? input.checked
          : input.hasAttribute('data-mm')
            ? input.value === ''
              ? null
              : (Number(input.value) * 10).toFixed(2)
            : input.type === 'number'
              ? Number(input.value)
              : input.value;
    }
    return c;
  }
  function writeConfig(c) {
    configuration = { ...c };
    for (const input of app.querySelectorAll('[data-config]')) {
      const value = c[input.dataset.config];
      if (input.type === 'checkbox') input.checked = !!value;
      else
        input.value = input.hasAttribute('data-mm')
          ? value == null
            ? ''
            : Number(value) / 10
          : (value ?? '');
    }
  }
  function body(confirmed = false) {
    return {
      configuration: readConfig(),
      items: rows
        .filter((r) => r.selected)
        .map((r) => ({ product_id: r.product_id, option: r.option, quantity: r.quantity })),
      preset_name: $('barcode-preset').selectedOptions[0]?.textContent || 'Custom layout',
      confirmed,
    };
  }
  function totals() {
    const selected = rows.filter((r) => r.selected);
    const count = selected.reduce((n, r) => n + Math.max(0, Number(r.quantity) || 0), 0);
    const n = new Set(selected.map((r) => r.product_id)).size;
    const c = readConfig();
    $('barcode-summary').textContent =
      `Selected products: ${n} · Total labels: ${count} · Rows: ${Math.ceil((count + Number(c.skip_labels || 0)) / Number(c.labels_per_row || 1))}`;
    return { count, n, c };
  }
  function render() {
    // Selected products persist when filters change; unselected matches follow the current results.
    const display = rows.filter((r) => r.selected || visible.includes(r.product_id));
    $('barcode-product-table').hidden = display.length === 0;
    $('barcode-products-empty').hidden = display.length > 0;
    $('barcode-products-empty').textContent = $('barcode-search').value.trim()
      ? $('barcode-search-status').textContent === 'Searching…'
        ? 'Searching for products…'
        : 'No matching products. Try another search.'
      : 'Search products by name, SKU or barcode to choose labels.';
    $('barcode-select-visible').disabled = visible.length === 0;
    $('barcode-products').innerHTML = display
      .map((r) => {
        const p = products.get(r.product_id);
        return `<tr data-row="${r.id}" class="${r.selected ? 'barcode-row-selected' : ''}"><td><input data-select type="checkbox" ${r.selected ? 'checked' : ''} aria-label="Select ${escape(p.name)}"></td><td><strong>${escape(p.name)}</strong><small>${escape(p.sku)}</small><small class="${p.barcode ? '' : 'barcode-no-code'}">${escape(p.barcode || 'No barcode assigned.')}</small></td><td><select data-option aria-label="Select selling price for ${escape(p.name)}"><option value="">Choose selling price${unitsEnabled() ? ' / unit' : ''}${app.dataset.expiry === '1' ? ' / lot' : ''}</option>${p.options.map((o) => `<option value="${escape(o.key)}" ${r.option === o.key ? 'selected' : ''}>${escape(money(o.price))}${unitsEnabled() ? ' / ' + escape(unitLabel(o.unit)) : ''} · ${Number(o.stock)} available${app.dataset.expiry === '1' && o.expires_at ? ` · Exp: ${escape(o.expires_at)}` : ''}${app.dataset.expiry === '1' && o.batch_number ? ` · ${escape(o.batch_number)}` : ''}</option>`).join('')}</select></td><td><input data-qty type="number" min="1" max="10000" step="1" value="${r.quantity}" aria-label="Quantity to print for ${escape(p.name)}"></td><td><div class="barcode-product-actions"><button class="btn secondary small" data-stock type="button">Use Current Stock Qty</button><button class="btn secondary small" data-another type="button">Another Price / Lot</button><button class="btn secondary small" data-remove type="button">Remove</button>${!p.barcode && app.dataset.canGenerate === '1' ? '<button class="btn secondary small" data-generate type="button">Generate Barcode</button>' : ''}${!p.barcode && app.dataset.canEdit === '1' ? '<button class="btn secondary small" data-enter type="button">Enter Barcode</button>' : ''}</div></td></tr>`;
      })
      .join('');
    totals();
  }
  async function search() {
    const version = ++searchVersion;
    const query = $('barcode-search').value.trim();
    visible = [];
    rows = rows.filter((r) => r.selected);
    $('barcode-search-status').textContent = query ? 'Searching…' : '';
    render();
    if (!query) return;
    try {
      const params = new URLSearchParams({
        q: query,
        category: $('barcode-category').value,
        active: $('barcode-active').value,
        has_barcode: $('barcode-has').value,
        low_stock: $('barcode-low').checked ? '1' : '0',
      });
      const found = await request(`${app.dataset.productsUrl}?${params}`, undefined, 'GET');
      if (version !== searchVersion) return;
      visible = found.map((p) => p.id);
      rows = rows.filter((r) => r.selected || visible.includes(r.product_id));
      for (const p of found) {
        products.set(p.id, p);
        if (!rows.some((r) => r.product_id === p.id)) add(p);
      }
      $('barcode-search-status').textContent =
        `${found.length}${found.length === 60 ? '+' : ''} matches`;
      render();
    } catch (e) {
      if (version === searchVersion) {
        $('barcode-search-status').textContent = 'Search failed';
        $('barcode-products-empty').textContent = 'Could not load products. Try searching again.';
        error(e.message);
      }
    }
  }
  function schedule() {
    previewVersion++;
    clearTimeout(timer);
    totals();
    timer = setTimeout(preview, 300);
  }
  async function preview() {
    const version = ++previewVersion;
    if (!rows.some((r) => r.selected)) {
      $('barcode-preview').textContent = 'Select at least one product to see your labels.';
      $('barcode-warnings').textContent = '';
      error('');
      return false;
    }
    try {
      const result = await request(app.dataset.previewUrl, body());
      if (version !== previewVersion) return false;
      $('barcode-preview').innerHTML = result.html;
      const overflow = fitAndCheckLabels($('barcode-preview'));
      $('barcode-warnings').textContent = [
        ...result.warnings,
        overflow
          ? 'Some content may not fit within the current label size. Increase dimensions, reduce fonts or hide optional content.'
          : '',
      ]
        .filter(Boolean)
        .join(' ');
      error('');
      return !overflow;
    } catch (e) {
      if (version === previewVersion) {
        error(e.message);
        $('barcode-preview').textContent = 'Correct the selection or label settings to preview.';
      }
      return false;
    }
  }
  $('barcode-products').addEventListener('change', (e) => {
    const row = rows.find((r) => r.id === Number(e.target.closest('[data-row]')?.dataset.row));
    if (!row) return;
    if (e.target.hasAttribute('data-select')) {
      if (e.target.checked && !canBulk)
        rows.forEach((r) => {
          if (r.product_id !== row.product_id) r.selected = false;
        });
      row.selected = e.target.checked;
      render();
    }
    if (e.target.hasAttribute('data-option')) row.option = e.target.value;
    if (e.target.hasAttribute('data-qty')) row.quantity = Number(e.target.value);
    schedule();
  });
  $('barcode-products').addEventListener('click', async (e) => {
    const button = e.target.closest('button');
    if (!button) return;
    const row = rows.find((r) => r.id === Number(button.closest('[data-row]').dataset.row));
    const p = products.get(row.product_id);
    if (button.hasAttribute('data-stock')) {
      const o = p.options.find((o) => o.key === row.option);
      if (!o) {
        error(unitsEnabled() ? 'Choose a selling price / unit first.' : 'Choose a selling price first.');
        return;
      }
      row.quantity = Math.max(1, Math.ceil(Number(o.stock)));
    }
    if (button.hasAttribute('data-another')) add(p, row.selected, app.dataset.defaultQuantity, '');
    if (button.hasAttribute('data-remove')) rows = rows.filter((r) => r.id !== row.id);
    if (button.hasAttribute('data-generate') || button.hasAttribute('data-enter')) {
      let code = null;
      if (button.hasAttribute('data-enter')) {
        code = window.prompt(
          'Enter the barcode printed on this product (default format from Barcode Settings):',
        );
        if (!code) return;
      } else if (!window.confirm(`Generate and assign a barcode to ${p.name}?`)) return;
      button.disabled = true;
      try {
        const updated = await request(
          `${app.dataset.assignUrl}/${p.id}`,
          code ? { barcode: code } : {},
        );
        products.set(p.id, updated);
        error('');
      } catch (e) {
        error(e.message);
      } finally {
        button.disabled = false;
      }
    }
    render();
    schedule();
  });
  $('barcode-select-visible').addEventListener('click', () => {
    if (!canBulk) {
      error('Your role can print one product at a time.');
      return;
    }
    rows.filter((r) => visible.includes(r.product_id)).forEach((r) => (r.selected = true));
    render();
    schedule();
  });
  $('barcode-generate-missing')?.addEventListener('click', async () => {
    const missing = [
      ...new Set(
        rows
          .filter((r) => r.selected && !products.get(r.product_id).barcode)
          .map((r) => r.product_id),
      ),
    ];
    if (!missing.length) {
      error('No selected products need a barcode.');
      return;
    }
    if (
      !window.confirm(
        `${missing.length} selected products do not have barcodes. Generate and assign barcodes now?`,
      )
    )
      return;
    const button = $('barcode-generate-missing');
    button.disabled = true;
    try {
      for (const id of missing)
        products.set(id, await request(`${app.dataset.assignUrl}/${id}`, {}));
      error('');
    } catch (e) {
      error(e.message);
    } finally {
      button.disabled = false;
      render();
      schedule();
    }
  });
  $('barcode-clear').addEventListener('click', () => {
    rows.forEach((r) => (r.selected = false));
    render();
    schedule();
  });
  function quantity(n) {
    rows.filter((r) => r.selected).forEach((r) => (r.quantity = n));
    render();
    schedule();
  }
  $('barcode-apply-qty').addEventListener('click', () =>
    quantity(Number($('barcode-set-qty').value)),
  );
  app
    .querySelectorAll('[data-quick-qty]')
    .forEach((b) => b.addEventListener('click', () => quantity(Number(b.dataset.quickQty))));
  let searchTimer;
  $('barcode-search').addEventListener('input', () => {
    clearTimeout(searchTimer);
    ++searchVersion;
    if (!$('barcode-search').value.trim()) {
      search();
      return;
    }
    visible = [];
    rows = rows.filter((r) => r.selected);
    $('barcode-search-status').textContent = 'Searching…';
    render();
    searchTimer = setTimeout(search, 200);
  });
  ['barcode-category', 'barcode-active', 'barcode-has', 'barcode-low'].forEach((id) =>
    $(id).addEventListener('change', search),
  );
  app.querySelectorAll('[data-config]').forEach((i) => i.addEventListener('input', schedule));
  $('barcode-preset').addEventListener('change', () => {
    const preset = presets.find((p) => String(p.id) === $('barcode-preset').value);
    if (preset) {
      writeConfig(preset.configuration);
      if ($('barcode-preset-name')) $('barcode-preset-name').value = preset.name;
    }
    schedule();
  });
  async function savePreset(update) {
    const id = $('barcode-preset').value;
    if (update && !id) {
      error('Choose a saved preset to update.');
      return;
    }
    try {
      const saved = await request(
        update ? `${app.dataset.presetsUrl}/${id}` : app.dataset.presetsUrl,
        {
          name: $('barcode-preset-name').value,
          is_default: $('barcode-save-default').checked,
          configuration: readConfig(),
        },
        update ? 'PUT' : 'POST',
      );
      presets = presets.filter((p) => p.id !== saved.id);
      if (saved.is_default) presets.forEach((p) => (p.is_default = false));
      presets.push(saved);
      $('barcode-preset').innerHTML =
        '<option value="">Custom layout</option>' +
        presets
          .map(
            (p) =>
              `<option value="${p.id}">${escape(p.name)}${p.is_default ? ' · Default' : ''}</option>`,
          )
          .join('');
      $('barcode-preset').value = saved.id;
      error('');
    } catch (e) {
      error(e.message);
    }
  }
  $('barcode-save-preset')?.addEventListener('click', () => savePreset(false));
  $('barcode-update-preset')?.addEventListener('click', () => savePreset(true));
  $('barcode-preview-button').addEventListener('click', preview);
  $('barcode-print')?.addEventListener('click', async () => {
    if (busy) return;
    if (!(await preview())) return;
    const { count, n, c } = totals();
    $('barcode-confirm-text').textContent =
      `Products: ${n}. Labels: ${count}. Preset: ${$('barcode-preset').selectedOptions[0]?.textContent}. Paper: ${Number(c.paper_width) / 10} cm. Labels Per Row: ${c.labels_per_row}.${count > Number(app.dataset.largeJob) ? ` You are about to generate ${count} labels.` : ''}`;
    $('barcode-confirm-print').textContent = `Generate ${count} Labels`;
    $('barcode-confirm').showModal();
  });
  $('barcode-confirm-cancel').addEventListener('click', () => $('barcode-confirm').close());
  $('barcode-confirm-print').addEventListener('click', async () => {
    if (busy) return;
    busy = true;
    $('barcode-confirm-print').disabled = true;
    // Open during the click so popup blockers do not hide the finished print job.
    const tab = window.open('about:blank', '_blank');
    try {
      const result = await request(app.dataset.printUrl, body(true));
      $('barcode-confirm').close();
      if (tab) tab.location = result.url;
      else window.location.assign(result.url);
    } catch (e) {
      tab?.close();
      error(e.message);
      $('barcode-confirm').close();
    } finally {
      busy = false;
      $('barcode-confirm-print').disabled = false;
    }
  });
  initial.forEach((row) => add(row.product, true, row.quantity, row.option));
  render();
  search();
  if (initial.length) schedule();
}
