const $ = (id) => document.getElementById(id);
const reverse = $('adjustment-reverse-dialog');
if (reverse) {
  $('open-adjustment-reverse').addEventListener('click', () => reverse.showModal());
  for (const id of ['close-adjustment-reverse', 'cancel-adjustment-reverse'])
    $(id).addEventListener('click', () => reverse.close());
}
const form = $('adjustment-form');
if (form) {
  const escape = (v) =>
    String(v).replace(
      /[&<>"']/g,
      (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c],
    );
  const rows = new Map(
    JSON.parse($('adjustment-initial-rows').textContent).map((p) => {
      p.product_id = Number(p.product_id);
      p.unit_id = Number(p.unit_id);
      return [p.product_id, p];
    }),
  );
  const selected = new Map();
  let results = [],
    request = 0,
    timer;
  function message(value) {
    $('adjustment-message').textContent = value;
    $('adjustment-message').hidden = !value;
  }
  function preview(p) {
    const current = Number(p.expected_stock),
      qty = Number(p.quantity || 0);
    return p.mode === 'SET' ? qty : current + (p.mode === 'REMOVE' ? -qty : qty);
  }
  function renderRows() {
    $('adjustment-row-count').textContent = `${rows.size} products`;
    $('adjustment-empty').hidden = rows.size > 0;
    $('adjustment-save').disabled = rows.size === 0;
    $('adjustment-items').innerHTML = [...rows.values()]
      .map((p) => {
        const prefix = `items[${p.product_id}]`;
        const hidden = [
          'product_id',
          'unit_id',
          'expected_stock',
          'expected_price',
          'expected_cost',
        ]
          .map(
            (field) =>
              `<input type="hidden" name="${prefix}[${field}]" value="${escape(p[field])}">`,
          )
          .join('');
        return `<tr data-row="${p.product_id}"><td>${hidden}<strong>${escape(p.name)}</strong><small>${escape(p.sku)} · ${escape(p.unit)}</small></td><td>${escape(p.expected_stock)} ${escape(p.unit)}</td><td><select name="${prefix}[mode]" data-field="mode" aria-label="Adjustment for ${escape(p.name)}">${[
          ['ADD', 'Add stock'],
          ['REMOVE', 'Remove stock'],
          ['SET', 'Set counted stock'],
        ]
          .map(
            ([key, label]) =>
              `<option value="${key}" ${key === p.mode ? 'selected' : ''}>${label}</option>`,
          )
          .join(
            '',
          )}</select></td><td><input type="number" data-field="quantity" name="${prefix}[quantity]" value="${escape(p.quantity || '0')}" min="0" max="999999999999" step="${p.decimal ? 10 ** -Number(form.dataset.precision) : 1}" inputmode="decimal" required aria-label="Quantity for ${escape(p.name)}"></td><td><strong data-stock-preview class="${preview(p) < 0 ? 'text-danger' : ''}">${preview(p).toFixed(3)} ${escape(p.unit)}</strong></td><td><input type="number" data-field="price" name="${prefix}[price]" value="${escape(p.price ?? '')}" placeholder="${escape(p.expected_price)}" min="0" max="999999999" step="0.01" inputmode="decimal" aria-label="Selling price for ${escape(p.name)}"><small>Current ${escape(form.dataset.currency)} ${escape(p.expected_price)}</small></td><td><input type="number" data-field="cost" name="${prefix}[cost]" value="${escape(p.cost ?? '')}" placeholder="${escape(p.expected_cost)}" min="0" max="999999999" step="0.01" inputmode="decimal" aria-label="Cost for ${escape(p.name)}"><small>Current ${escape(form.dataset.currency)} ${escape(p.expected_cost)}</small></td><td><button class="icon-button text-danger" type="button" data-remove="${p.product_id}" ${p.locked ? 'disabled' : ''} aria-label="Remove ${escape(p.name)}" title="${p.locked ? 'Original products remain in this batch' : 'Remove product'}"><i data-lucide="x"></i></button></td></tr>`;
      })
      .join('');
    window.refreshIcons?.();
  }
  function selectionState() {
    $('adjustment-add-selected').disabled = !selected.size;
    $('adjustment-add-selected').textContent = selected.size
      ? `Add ${selected.size} selected`
      : 'Add selected';
    const available = results.filter((p) => !rows.has(p.product_id));
    $('adjustment-select-all').checked =
      available.length > 0 && available.every((p) => selected.has(p.product_id));
    $('adjustment-select-all').disabled = !available.length;
  }
  function renderResults() {
    $('adjustment-search-count').textContent =
      `${results.length}${results.length === 60 ? '+' : ''} results`;
    $('adjustment-search-results').innerHTML = results.length
      ? results
          .map(
            (p) =>
              `<label class="adjustment-product-choice ${rows.has(p.product_id) ? 'added' : ''}"><input type="checkbox" data-select-product="${p.product_id}" ${rows.has(p.product_id) ? 'disabled checked' : selected.has(p.product_id) ? 'checked' : ''}><span><strong>${escape(p.name)}</strong><small>${escape(p.sku)} · ${escape(p.expected_stock)} ${escape(p.unit)} in stock</small></span><span class="badge slate">${rows.has(p.product_id) ? 'Added' : escape(p.unit)}</span></label>`,
          )
          .join('')
      : '<p class="muted">No matching active products.</p>';
    selectionState();
  }
  async function search() {
    const current = ++request;
    try {
      const response = await fetch(
        `${form.dataset.productsUrl}?${new URLSearchParams({ q: $('adjustment-search').value })}`,
        { headers: { Accept: 'application/json' } },
      );
      if (!response.ok) throw new Error('Unable to load products. Reload or try again.');
      const data = await response.json();
      if (current !== request) return;
      results = data;
      renderResults();
    } catch (error) {
      if (current === request) message(error.message);
    }
  }
  $('adjustment-search').addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(search, 180);
  });
  $('adjustment-search').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      clearTimeout(timer);
      search();
    }
  });
  $('adjustment-search-results').addEventListener('change', (e) => {
    const input = e.target.closest('[data-select-product]');
    if (!input) return;
    const p = results.find((p) => p.product_id === Number(input.dataset.selectProduct));
    if (input.checked) selected.set(p.product_id, p);
    else selected.delete(p.product_id);
    selectionState();
  });
  $('adjustment-select-all').addEventListener('change', (e) => {
    results
      .filter((p) => !rows.has(p.product_id))
      .forEach((p) =>
        e.target.checked ? selected.set(p.product_id, p) : selected.delete(p.product_id),
      );
    renderResults();
  });
  $('adjustment-add-selected').addEventListener('click', () => {
    if (rows.size + selected.size > 100) {
      message('Use up to 100 products per adjustment.');
      return;
    }
    selected.forEach((p) => rows.set(p.product_id, { ...p, mode: 'ADD', quantity: '0' }));
    selected.clear();
    message('');
    renderRows();
    renderResults();
  });
  $('adjustment-items').addEventListener('input', (e) => {
    const input = e.target.closest('[data-field]');
    if (!input) return;
    const row = input.closest('[data-row]'),
      p = rows.get(Number(row.dataset.row));
    p[input.dataset.field] = input.value;
    row.querySelector('[data-stock-preview]').textContent = `${preview(p).toFixed(3)} ${p.unit}`;
    row.querySelector('[data-stock-preview]').classList.toggle('text-danger', preview(p) < 0);
  });
  $('adjustment-items').addEventListener('click', (e) => {
    const button = e.target.closest('[data-remove]');
    if (!button) return;
    const p = rows.get(Number(button.dataset.remove));
    if (p.locked) return;
    rows.delete(p.product_id);
    renderRows();
    renderResults();
  });
  form.addEventListener('submit', (e) => {
    if (!rows.size || [...rows.values()].some((p) => preview(p) < 0)) {
      e.preventDefault();
      message('Select products and ensure their new stock is not negative.');
    }
  });
  renderRows();
  search();
}
