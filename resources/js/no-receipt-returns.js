import { sellingPriceOptions } from './pos-price-options.js';
const config = document.getElementById('no-receipt-config');
if (config) {
  const c = JSON.parse(config.textContent),
    $ = (id) => document.getElementById(id),
    money = (v) =>
      `${c.currency} ${Number(v).toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  const sourceLabel = (s) =>
    ({
      CURRENT_LOWEST: 'Current lowest active selling price',
      CURRENT_DEFAULT: 'Current default selling price',
      CURRENT_DEFAULT_FALLBACK: 'Current default price / cost (no active source)',
      LATEST_PRICE: 'Latest selling price',
      MANUAL: 'Manual manager confirmation',
      LATEST_PURCHASE: 'Latest known purchase cost',
      ORIGINAL_SALE: 'Original net sale value',
    })[s] || s;
  const products = new Map();
  let rows = [],
    replacements = [],
    customer = null,
    step = 1,
    busy = false,
    submitted = false,
    lastQuote = null,
    sequence = 0,
    resolutionSnapshot = null;
  const error = (message) => {
    $('nr-error').textContent = message;
    $('nr-error').hidden = !message;
  };
  const mode = () => document.querySelector('[name="nr-resolution"]:checked').value;
  const el = (tag, text = '', className = '') => {
    const e = document.createElement(tag);
    e.textContent = text;
    e.className = className;
    return e;
  };
  const button = (text, action, className = 'btn secondary') => {
    const e = el('button', text, className);
    e.type = 'button';
    e.addEventListener('click', action);
    return e;
  };
  async function get(url, params = {}) {
    const response = await fetch(`${url}?${new URLSearchParams(params)}`, {
      headers: { Accept: 'application/json' },
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.message || 'Unable to load data.');
    return data;
  }
  async function post(url, data) {
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
      },
      body: JSON.stringify(data),
    });
    const body = await response.json();
    if (!response.ok)
      throw new Error(
        Object.values(body.errors || {})
          .flat()
          .join(' ') ||
          body.message ||
          'Unable to complete return.',
      );
    return body;
  }
  function show(next) {
    step = next;
    document
      .querySelectorAll('[data-nr-step]')
      .forEach((e) => (e.hidden = Number(e.dataset.nrStep) !== next));
    document.querySelectorAll('[data-nr-indicator]').forEach((e) => {
      e.classList.toggle('active', Number(e.dataset.nrIndicator) === next);
      e.classList.toggle('complete', Number(e.dataset.nrIndicator) < next);
    });
  }
  function clearQuote() {
    lastQuote = null;
    $('nr-complete').disabled = true;
  }
  function payload() {
    return {
      return_type: 'NO_RECEIPT',
      token: c.token,
      customer_id: customer?.id || null,
      reason: $('nr-reason').value,
      notes: $('nr-notes').value,
      resolution: mode(),
      items: rows.map((r) => ({
        product_id: r.product.id,
        unit_id: r.unit_id,
        quantity: r.quantity,
        sale_item_id: r.match?.sale_item_id || null,
        credit_price: r.price === '' ? null : r.price,
        stock_action: r.action,
        supplier_id: r.supplier_id || null,
        ...(r.reason ? { reason: r.reason } : {}),
        notes: r.notes || null,
        price_override_reason: r.override_reason || null,
        stock_selling_price: r.stock_price,
        cost_basis: r.cost_basis === '' ? null : r.cost_basis,
        purchased_on: r.purchased_on || null,
      })),
      replacements:
        mode() === 'MONEY'
          ? []
          : replacements.map((r) => ({
              product_id: r.product.id,
              unit_id: r.unit_id,
              quantity: r.quantity,
              stock_price: r.stock_price,
              ...(r.line_index != null ? { return_line_index: r.line_index } : {}),
            })),
      payment_method_id: $('nr-payment-method').value || null,
      apply_due: $('nr-apply-due')?.value || '0',
      add_to_due: $('nr-add-due')?.checked || false,
    };
  }
  function customerDisplay() {
    const summary = $('nr-customer-summary');
    summary.replaceChildren();
    if (customer) {
      summary.append(
        el('strong', `${customer.name} · ${customer.phone || ''} · Customer #${customer.id}`),
        el('p', `Outstanding Due: ${money(customer.due)}`),
      );
      if (customer.history_url) {
        const a = el('a', 'View recent purchases', 'text-link');
        a.href = customer.history_url;
        summary.append(a);
      }
    } else
      summary.textContent =
        'Walk-in Customer · Select a customer to use account credit or add an exchange difference to due.';
    if ($('nr-apply-due')) {
      $('nr-apply-due').disabled = !customer;
      if (!customer) $('nr-apply-due').value = '0';
      $('nr-due-help').textContent = customer
        ? `Account credit will be allocated oldest due first for ${customer.name}.`
        : 'Select a customer in Products to apply account credit.';
    }
    if ($('nr-add-due')) {
      $('nr-add-due').disabled = !customer;
      if (!customer) $('nr-add-due').checked = false;
    }
  }
  async function chooseCustomer(value) {
    customer = value;
    rows.forEach((r) => {
      r.match = null;
      r.matches = [];
      r.warning = null;
      resetSuggestion(r);
    });
    clearQuote();
    customerDisplay();
    renderRows();
    renderAdded();
    $('nr-customer-results').replaceChildren();
    if (c.autoHistory) await Promise.all(rows.map((r) => findMatches(r)));
  }
  function resetSuggestion(r) {
    const unit = r.product.units.find((u) => u.id === r.unit_id) || r.product.units[0];
    r.unit_id = unit.id;
    r.price = unit.suggestion.suggested_credit_price ?? '';
    r.source = unit.suggestion.credit_price_source;
    r.stock_price = unit.suggestion.stock_selling_price;
    r.cost_basis = c.costMethod === 'MANUAL' ? '' : undefined;
    r.override_reason = '';
  }
  function renderAdded() {
    const container = $('nr-added-products');
    container.replaceChildren();
    if (!rows.length) container.append(el('p', 'Search or scan a product to start.', 'muted'));
    rows.forEach((r) => {
      const div = el('div', '', 'nr-added-product');
      div.append(
        el('strong', `${r.product.name} × ${r.quantity}`),
        el(
          'span',
          r.match ? `Matched to ${r.match.invoice}` : 'No original sale identified',
          'badge ' + (r.match ? 'green' : 'amber'),
        ),
        button('Remove', () => removeRow(r)),
      );
      container.append(div);
    });
  }
  function removeRow(r) {
    rows = rows.filter((row) => row !== r);
    clearQuote();
    renderRows();
    renderAdded();
  }
  async function addProduct(p) {
    products.set(p.id, p);
    let r = rows.find((r) => r.product.id === p.id && r.unit_id === p.unit_id);
    if (r) r.quantity = String(Number(r.quantity) + 1);
    else {
      r = {
        product: p,
        unit_id: p.unit_id,
        quantity: '1',
        action:
          (c.defaultAction === 'WRITEOFF' && !c.writeoff) ||
          (c.defaultAction === 'SUPPLIER' && !c.supplierReturn)
            ? 'RESTOCK'
            : c.defaultAction,
        supplier_id: null,
        reason: '',
        notes: '',
        matches: [],
        warning: null,
      };
      resetSuggestion(r);
      rows.push(r);
    }
    clearQuote();
    renderRows();
    renderAdded();
    $('nr-product-search').value = '';
    $('nr-product-results').replaceChildren();
    if (customer && c.autoHistory) await findMatches(r);
  }
  async function findMatches(r) {
    if (!customer) {
      error('Select a customer to find matching purchases.');
      return;
    }
    const customerId = customer.id;
    try {
      const data = await get(c.matchesUrl, { customer_id: customerId, product_id: r.product.id });
      if (customer?.id !== customerId || !rows.includes(r)) return;
      r.matches = data.matches;
      r.warning = data.warning;
      r.searched = true;
      renderRows();
    } catch (e) {
      error(e.message);
    }
  }
  function field(card, label, input) {
    const wrapper = el('label', label, 'field');
    wrapper.append(input);
    card.append(wrapper);
    return wrapper;
  }
  function input(value, type, onChange) {
    const i = document.createElement('input');
    i.type = type;
    i.value = value ?? '';
    i.addEventListener('input', () => {
      onChange(i.value);
      clearQuote();
      renderAdded();
    });
    return i;
  }
  function select(options, value, onChange) {
    const s = document.createElement('select');
    options.forEach(([id, label]) => {
      const o = el('option', label);
      o.value = id;
      o.selected = String(id) === String(value);
      s.append(o);
    });
    s.addEventListener('change', () => {
      onChange(s.value);
      clearQuote();
    });
    return s;
  }
  function renderRows() {
    const container = $('nr-lines');
    container.replaceChildren();
    rows.forEach((r) => {
      const card = el('article', '', 'nr-return-card');
      card.dataset.productId = r.product.id;
      const header = el('header');
      const name = el('div');
      name.append(el('h3', r.product.name), el('small', `SKU: ${r.product.sku}`, 'muted'));
      header.append(
        name,
        button('Remove', () => removeRow(r)),
      );
      card.append(header);
      const badge = el(
        'p',
        r.match
          ? `Verified · Matched to ${r.match.invoice} (${r.match.date})`
          : 'No original sale identified',
        'badge ' + (r.match ? 'green' : 'amber'),
      );
      card.append(badge);
      const grid = el('div', '', 'form-grid');
      card.append(grid);
      const lineCredit = el(
        'p',
        `Line return credit: ${money(Number(r.price || 0) * Number(r.quantity || 0))}`,
        'nr-line-credit',
      );
      card.append(lineCredit);
      if (r.match)
        card.append(
          el(
            'p',
            `Original price: ${money(r.match.original_price || r.price)} · Allocated original discount: ${money(r.match.allocated_discount || 0)} · Original net price: ${money(r.price)}`,
            'muted',
          ),
        );
      const updateCredit = () =>
        (lineCredit.textContent = `Line return credit: ${money(Number(r.price || 0) * Number(r.quantity || 0))}`);
      const unit = r.product.units.find((u) => u.id === r.unit_id) || {
        decimal: true,
        short_name: r.match?.unit,
      };
      const qty = input(r.quantity, 'number', (v) => {
        r.quantity = v;
        updateCredit();
      });
      qty.min = unit.decimal ? '0.001' : '1';
      qty.step = unit.decimal ? '0.001' : '1';
      qty.setAttribute('aria-label', `Return quantity for ${r.product.name}`);
      field(grid, 'Qty', qty);
      const units = r.product.units.map((u) => [u.id, u.short_name]);
      if (r.match && !units.some(([id]) => id === r.unit_id)) units.push([r.unit_id, r.match.unit]);
      const unitSelect = select(units, r.unit_id, (v) => {
        r.unit_id = Number(v);
        r.match = null;
        resetSuggestion(r);
        renderRows();
      });
      unitSelect.disabled = !!r.match;
      field(grid, 'Unit', unitSelect);
      const price = input(r.price, 'number', (v) => {
        r.price = v;
        updateCredit();
      });
      price.min = '0';
      price.step = '0.01';
      price.disabled =
        !!r.match || !(c.allowPriceChange || (c.priceRule === 'MANUAL' && c.manualPrice));
      price.setAttribute('aria-label', `Return credit price for ${r.product.name}`);
      field(grid, 'Return Credit Price', price);
      card.append(
        el(
          'p',
          `Suggested from: ${sourceLabel(r.source)}${r.match ? '' : '. Original bill not available. Confirm the amount to credit for this item.'}`,
          'muted',
        ),
      );
      if (!r.match && (c.allowPriceChange || c.priceRule === 'MANUAL'))
        field(
          grid,
          'Price confirmation / override reason',
          input(r.override_reason, 'text', (v) => (r.override_reason = v)),
        );
      const actions = [['RESTOCK', 'Restock']];
      if (c.writeoff) actions.push(['WRITEOFF', 'Write Off / Cost as Expense']);
      if (c.supplierReturn) actions.push(['SUPPLIER', 'Return to Supplier']);
      field(
        grid,
        'Stock Action',
        select(actions, r.action, (v) => {
          r.action = v;
          renderRows();
        }),
      );
      const prices = [
        ...new Set([...r.product.price_options.map((o) => o.stock_price), r.stock_price]),
      ];
      field(
        grid,
        'Returned stock selling price',
        select(
          prices.map((p) => [p, money(p)]),
          r.stock_price,
          (v) => (r.stock_price = v),
        ),
      );
      field(
        grid,
        'Item reason (optional)',
        select(
          [
            ['', 'Use main reason'],
            ...[
              'Damaged',
              'Defective',
              'Wrong item',
              'Expired',
              'Customer changed mind',
              'Product quality issue',
              'Other',
            ].map((s) => [s, s]),
          ],
          r.reason,
          (v) => (r.reason = v),
        ),
      );
      field(
        grid,
        'Item notes',
        input(r.notes, 'text', (v) => (r.notes = v)),
      );
      if (Number(c.daysLimit) > 0 && !r.match) {
        const date = input(r.purchased_on, 'date', (v) => (r.purchased_on = v));
        field(grid, 'Claimed purchase date', date);
        card.append(
          el(
            'small',
            `Confirm the date supplied by the customer. Return limit: ${c.daysLimit} days.`,
            'muted',
          ),
        );
      }
      if (!r.match) {
        card.append(
          el(
            'p',
            `Cost basis: Estimated · ${sourceLabel(unit.suggestion?.cost_basis_source || c.costMethod)}${c.viewCost && unit.suggestion?.cost_basis != null ? ' · ' + money(unit.suggestion.cost_basis) + ' per primary unit' : ''}`,
            'muted',
          ),
        );
        if (c.costMethod === 'MANUAL' && c.viewCost) {
          const cost = input(r.cost_basis, 'number', (v) => (r.cost_basis = v));
          cost.min = '0';
          cost.step = '0.01';
          field(grid, 'Estimated cost per primary unit (manager)', cost);
        }
      }
      if (r.action === 'SUPPLIER') {
        field(
          grid,
          'Confirm supplier',
          select(
            [['', 'Choose supplier'], ...c.suppliers.map((s) => [s.id, s.name])],
            r.supplier_id,
            (v) => (r.supplier_id = Number(v) || null),
          ),
        );
        if (!r.match) {
          card.append(
            el(
              'small',
              'Original supplier is unknown. Confirm the supplier; suggestions are not a purchase provenance link.',
              'muted',
            ),
          );
          card.append(
            el(
              'p',
              r.product.recent_suppliers
                .map((s) => `${s.name} · last purchase ${s.date}`)
                .join(' | '),
              'muted',
            ),
          );
        }
      }
      if (r.warning) card.append(el('p', r.warning, 'nr-warning'));
      if (r.match)
        card.append(
          button('Continue without original sale', () => {
            r.match = null;
            resetSuggestion(r);
            renderRows();
            renderAdded();
            clearQuote();
          }),
        );
      else {
        card.append(button('Find Matching Purchase', () => findMatches(r)));
        if (r.searched) {
          const list = el('div', '', 'nr-matches');
          list.append(
            el(
              'strong',
              r.matches.length
                ? 'Possible Original Purchases'
                : 'No eligible matching purchases found.',
            ),
          );
          r.matches.forEach((m) => {
            const entry = el('div', '', 'nr-match');
            const info = el('div');
            info.append(
              el('strong', `${m.invoice} · ${m.date}`),
              el(
                'small',
                `${m.quantity} ${m.unit} bought · ${m.remaining} available to return · original net ${money(m.net_price)} each`,
                'cell-note',
              ),
            );
            entry.append(
              info,
              button('Link This Sale', () => {
                r.match = m;
                r.unit_id = m.unit_id;
                r.price = m.net_price;
                r.source = 'ORIGINAL_SALE';
                renderRows();
                renderAdded();
                clearQuote();
              }),
            );
            list.append(entry);
          });
          list.append(
            el(
              'small',
              'You can continue without an original sale. No invoice will be fabricated.',
              'muted',
            ),
          );
          card.append(list);
        }
      }
      container.append(card);
    });
  }
  function searchBind(id, resultId, url, action, autoBarcode = false, category = false) {
    let timer,
      searchId = 0;
    const query = $(id);
    async function search(force = false) {
      const version = ++searchId;
      try {
        const found = await get(url, {
          q: query.value,
          ...(category ? { category_id: $('nr-category').value } : {}),
        });
        if (version !== searchId) return;
        const exact = found.find(
          (p) => p.barcode === query.value || (force && p.sku === query.value),
        );
        if (exact && autoBarcode) {
          await action(exact);
          query.value = '';
          $(resultId).replaceChildren();
          return;
        }
        $(resultId).replaceChildren(...found.map((p) => button(p.name, () => action(p))));
      } catch (e) {
        error(e.message);
      }
    }
    query.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(() => search(), 200);
    });
    query.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        clearTimeout(timer);
        search(true);
      }
    });
    return () => search();
  }
  const searchProducts = searchBind(
    'nr-product-search',
    'nr-product-results',
    c.productsUrl,
    addProduct,
    true,
    true,
  );
  $('nr-category').addEventListener('change', searchProducts);
  searchBind('nr-customer-search', 'nr-customer-results', c.customersUrl, chooseCustomer);
  $('nr-walk-in').addEventListener('click', () => chooseCustomer(null));
  function replacement(p, option, line_index = null) {
    return {
      product: p,
      unit_id: line_index != null ? rows[line_index].unit_id : p.unit_id,
      quantity: line_index != null ? rows[line_index].quantity : '1',
      stock_price: option?.stock_price,
      line_index,
    };
  }
  async function addReplacement(p) {
    products.set(p.id, p);
    const options = sellingPriceOptions(p);
    if (!options.length) {
      error('This replacement product is out of stock.');
      return;
    }
    const add = (o) => {
      replacements.push(replacement(p, o));
      renderReplacements();
      $('nr-replacement-query').value = '';
      $('nr-replacement-results').replaceChildren();
      $('nr-price-dialog').close();
      clearQuote();
    };
    if (options.length === 1) add(options[0]);
    else {
      $('nr-price-options').replaceChildren(
        ...options.map((o) =>
          button(
            `${money(o.stock_price)} · ${o.quantity} available`,
            () => add(o),
            'btn secondary w-full',
          ),
        ),
      );
      $('nr-price-dialog').showModal();
    }
  }
  searchBind(
    'nr-replacement-query',
    'nr-replacement-results',
    c.replacementProductsUrl,
    addReplacement,
    true,
  );
  $('nr-price-close').addEventListener('click', () => $('nr-price-dialog').close());
  function renderReplacements() {
    const container = $('nr-replacements');
    container.replaceChildren();
    replacements.forEach((r, i) => {
      const card = el('article', '', 'return-replacement-card');
      card.append(el('strong', r.product.name));
      const qty = input(r.quantity, 'number', (v) => (r.quantity = v));
      const selectedUnit = r.product.units.find((u) => u.id === r.unit_id);
      qty.min = selectedUnit?.decimal ? '0.001' : '1';
      qty.step = selectedUnit?.decimal ? '0.001' : '1';
      qty.disabled = mode() === 'SAME';
      field(card, 'Quantity', qty);
      const unit = select(
        r.product.units.map((u) => [u.id, u.short_name]),
        r.unit_id,
        (v) => {
          r.unit_id = Number(v);
          renderReplacements();
        },
      );
      unit.disabled = mode() === 'SAME';
      field(card, 'Unit', unit);
      field(
        card,
        'Available selling price',
        select(
          sellingPriceOptions(r.product).map((o) => [
            o.stock_price,
            `${money(o.stock_price)} · ${o.quantity} available`,
          ]),
          r.stock_price,
          (v) => (r.stock_price = v),
        ),
      );
      if (mode() === 'OTHER')
        card.append(
          button('Remove', () => {
            replacements.splice(i, 1);
            renderReplacements();
            clearQuote();
          }),
        );
      container.append(card);
    });
  }
  async function prepareResolution() {
    clearQuote();
    $('nr-replacement-search').hidden = mode() !== 'OTHER';
    const snapshot = JSON.stringify([
      mode(),
      rows.map((r) => [r.product.id, r.unit_id, r.quantity, r.action, r.match?.sale_item_id]),
    ]);
    if (snapshot === resolutionSnapshot) {
      renderReplacements();
      return;
    }
    resolutionSnapshot = snapshot;
    if (mode() === 'MONEY') {
      replacements = [];
      renderReplacements();
      return;
    }
    if (mode() === 'SAME') {
      const ids = [...new Set(rows.map((r) => r.product.id))];
      const params = {};
      ids.forEach((id, i) => (params[`ids[${i}]`] = id));
      const found = await get(c.replacementProductsUrl, params);
      const anticipated = new Map();
      for (const r of rows) {
        if (!r.match || r.action !== 'RESTOCK') continue;
        let left =
          (Number(r.quantity) * Number(r.match.base_quantity || r.match.quantity)) /
          Number(r.match.quantity);
        for (const group of r.match.restock_prices || []) {
          const take = Math.min(left, Number(group.quantity));
          if (take <= 0) continue;
          const options = anticipated.get(r.product.id) || [];
          options.push({ ...group, quantity: take.toFixed(3) });
          anticipated.set(r.product.id, options);
          left -= take;
        }
      }
      replacements = rows.map((r, index) => {
        const current = found.find((p) => p.id === r.product.id);
        const p = current
          ? {
              ...current,
              price_options: [...current.price_options, ...(anticipated.get(r.product.id) || [])],
            }
          : null;
        if (!p) throw new Error(`${r.product.name} is no longer available.`);
        const options = sellingPriceOptions(p);
        if (!options.length)
          throw new Error(
            `${r.product.name} has no current replacement stock. Choose another product.`,
          );
        return replacement(p, options[0], index);
      });
    } else replacements = [];
    renderReplacements();
  }
  document
    .querySelectorAll('[name="nr-resolution"]')
    .forEach((r) =>
      r.addEventListener('change', () => prepareResolution().catch((e) => error(e.message))),
    );
  function avoidDisallowedCash() {
    const method = $('nr-payment-method');
    if (!c.cashRefundAllowed && method.selectedOptions[0]?.dataset.type === 'CASH') {
      method.value =
        [...method.options].find((o) => o.value && o.dataset.type !== 'CASH')?.value || '';
    }
  }
  async function review() {
    const version = ++sequence;
    clearQuote();
    error('');
    try {
      let q;
      try {
        q = await post(c.quoteUrl, payload());
      } catch (e) {
        if (e.message.includes('Cash refunds without a receipt are disabled')) {
          avoidDisallowedCash();
          q = await post(c.quoteUrl, payload());
        } else throw e;
      }
      if (version !== sequence || submitted) return;
      lastQuote = q;
      $('nr-verification').textContent = {
        VERIFIED: 'Verified Return',
        PARTIALLY_VERIFIED: 'Partially Verified',
        UNVERIFIED: 'No Receipt',
      }[q.verification_status];
      $('nr-review-items').replaceChildren(
        ...q.lines.map((l) =>
          el(
            'p',
            `${l.name} × ${l.quantity} ${l.unit} · ${money(l.amount)} · ${l.original_invoice || 'No original sale identified'} · ${l.stock_action}`,
          ),
        ),
      );
      const totals = $('nr-review-totals');
      totals.replaceChildren();
      [
        ['Return credit', q.amount],
        ['Refundable original fees', q.fee_refund],
        ['Linked original dues reduced', q.due_reduction],
        ['Available return credit', q.available_credit],
        ['Replacement products', q.replacement_value],
        ['Customer due before', q.customer_due_before],
        ['Applied to other customer due', q.apply_due],
        ['Customer due after credit', q.customer_due_after],
        ['Refund', q.refund_amount],
        ['Payment charge', q.payment_charge || 0],
      ].forEach(([name, value]) => {
        const div = el('div');
        div.append(el('span', name), el('strong', money(value)));
        totals.append(div);
      });
      $('nr-allocation-preview').replaceChildren(
        ...[...q.original_due_allocations, ...q.due_allocations]
          .filter((a) => Number(a.amount) > 0)
          .map((a) => el('p', `${a.invoice}: reduce ${money(a.amount)}`)),
      );
      const paying = Number(q.must_pay) > 0;
      $('nr-difference').textContent = paying
        ? `CUSTOMER MUST PAY ${money(Number(q.must_pay) + Number(q.payment_charge || 0))}`
        : Number(q.owed) > 0
          ? `WE OWE CUSTOMER ${money(q.owed)}`
          : 'DIFFERENCE ' + money(0);
      $('nr-credit-options').hidden = paying;
      $('nr-pay-options').hidden = !paying;
      $('nr-method-label').hidden =
        (!paying && Number(q.refund_amount) === 0) || (paying && $('nr-add-due')?.checked);
      [...$('nr-payment-method').options].forEach(
        (o) =>
          (o.hidden = o.disabled = !paying && o.dataset.type === 'CASH' && !c.cashRefundAllowed),
      );
      $('nr-settlement-message').textContent =
        paying && $('nr-add-due')?.checked
          ? `Add ${money(q.must_pay)} to ${customer?.name}'s due.`
          : Number(q.refund_amount) > 0
            ? `Refund ${money(q.refund_amount)} using the permitted selected method.`
            : 'No money refund is required.';
      $('nr-complete').disabled = false;
    } catch (e) {
      if (version === sequence) {
        error(e.message);
        lastQuote = null;
        $('nr-complete').disabled = true;
      }
    }
  }
  let reviewTimer;
  ['nr-apply-due', 'nr-add-due', 'nr-payment-method'].forEach((id) =>
    $(id)?.addEventListener('input', () => {
      clearQuote();
      if (step === 4 && !submitted) {
        clearTimeout(reviewTimer);
        reviewTimer = setTimeout(review, 200);
      }
    }),
  );
  document.querySelectorAll('[data-nr-next]').forEach((b) =>
    b.addEventListener('click', async () => {
      if (busy) return;
      const next = Number(b.dataset.nrNext);
      busy = true;
      b.disabled = true;
      error('');
      try {
        if (next >= 2 && !rows.length) throw new Error('Add at least one returned product.');
        if (next >= 2 && c.requireCustomer && !customer)
          throw new Error('Select a customer for this return.');
        if (next === 3 && step === 2) {
          if (c.requireReason && !$('nr-reason').value) throw new Error('Choose a return reason.');
          await prepareResolution();
        }
        show(next);
        if (next === 4) await review();
      } catch (e) {
        error(e.message);
      } finally {
        busy = false;
        b.disabled = false;
      }
    }),
  );
  $('nr-complete').addEventListener('click', async () => {
    if (busy || submitted || !lastQuote) return;
    busy = true;
    submitted = true;
    $('nr-complete').disabled = true;
    error('');
    try {
      const saved = await post(c.storeUrl, { ...payload(), quote_hash: lastQuote.quote_hash });
      location.assign(saved.auto_print ? saved.receipt_url : saved.url);
    } catch (e) {
      busy = false;
      submitted = false;
      clearQuote();
      await review();
      error(e.message);
    }
  });
  $('nr-save-draft').addEventListener('click', async () => {
    if (busy || submitted) return;
    busy = true;
    $('nr-save-draft').disabled = true;
    try {
      const saved = await post(c.draftUrl, payload());
      location.assign(saved.url);
    } catch (e) {
      error(e.message);
      busy = false;
      $('nr-save-draft').disabled = false;
    }
  });
  customerDisplay();
  avoidDisallowedCash();
  if (c.draft) {
    (async () => {
      const d = c.draft;
      if (d.customer_id) {
        const found = await get(c.customersUrl, { id: d.customer_id });
        customer = found[0] || null;
      }
      const params = {};
      [...new Set(d.items.map((i) => i.product_id))].forEach((id, i) => (params[`ids[${i}]`] = id));
      const found = await get(c.productsUrl, params);
      found.forEach((p) => products.set(p.id, p));
      rows = d.items.map((saved) => {
        const product = products.get(saved.product_id);
        if (!product) throw new Error('A saved returned product is unavailable.');
        const r = {
          product,
          unit_id: saved.unit_id || product.unit_id,
          quantity: saved.quantity,
          action: saved.stock_action,
          supplier_id: saved.supplier_id,
          reason: saved.reason,
          notes: saved.notes,
          matches: [],
          purchased_on: saved.purchased_on,
          price: saved.credit_price,
          stock_price: saved.stock_selling_price,
          cost_basis: saved.cost_basis,
          override_reason: saved.price_override_reason,
        };
        r.source = product.units.find((u) => u.id === r.unit_id)?.suggestion.credit_price_source;
        if (saved.sale_item_id)
          r.match = {
            sale_item_id: saved.sale_item_id,
            invoice: 'Saved original sale',
            unit_id: r.unit_id,
            unit: product.units.find((u) => u.id === r.unit_id)?.short_name,
          };
        return r;
      });
      $('nr-reason').value = d.reason || '';
      $('nr-notes').value = d.notes || '';
      document.querySelector(`[name="nr-resolution"][value="${d.resolution}"]`).checked = true;
      if (d.payment_method_id) $('nr-payment-method').value = d.payment_method_id;
      if ($('nr-apply-due')) $('nr-apply-due').value = d.apply_due || '0';
      if ($('nr-add-due')) $('nr-add-due').checked = !!d.add_to_due;
      if (customer)
        await Promise.all(
          rows
            .filter((r) => r.match)
            .map(async (r) => {
              const data = await get(c.matchesUrl, {
                customer_id: customer.id,
                product_id: r.product.id,
              });
              r.match =
                data.matches.find((m) => m.sale_item_id === r.match.sale_item_id) || r.match;
            }),
        );
      customerDisplay();
      renderRows();
      renderAdded();
      show(2);
      if (d.replacements?.length) {
        const replacementParams = {};
        [...new Set(d.replacements.map((r) => r.product_id))].forEach(
          (id, i) => (replacementParams[`ids[${i}]`] = id),
        );
        const replacementProducts = await get(c.replacementProductsUrl, replacementParams);
        replacements = d.replacements.map((r) => ({
          product: replacementProducts.find((p) => p.id === r.product_id),
          unit_id: r.unit_id,
          quantity: r.quantity,
          stock_price: r.stock_price,
          line_index: r.return_line_index,
        }));
        $('nr-replacement-search').hidden = d.resolution !== 'OTHER';
        resolutionSnapshot = JSON.stringify([
          mode(),
          rows.map((r) => [r.product.id, r.unit_id, r.quantity, r.action, r.match?.sale_item_id]),
        ]);
        renderReplacements();
      }
    })().catch((e) => error(e.message));
  }
}
