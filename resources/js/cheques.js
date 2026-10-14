import { moneyCents, displayCents } from './purchase-totals.js';
const editors = document.querySelectorAll('[data-cheque-editor]');
for (const editor of editors) {
  const form = editor.closest('form');
  if (!form) continue;
  const method = form.querySelector('[name=payment_method_id]');
  const kind = form.querySelector('[data-cheque-kind]');
  const rows = editor.querySelector('[data-cheque-rows]');
  let pool = [],
    poolLoaded = false;
  const field = (row, key) => row.querySelector(`[data-cheque-field="${key}"]`);
  function sum() {
    let total = 0n;
    for (const row of rows.children) {
      const value =
        field(row, 'source').value === 'THIRD_PARTY'
          ? pool.find((c) => String(c.id) === field(row, 'cheque_id').value)?.amount || '0'
          : field(row, 'amount').value;
      const match = /^(\d+)(?:\.(\d{0,2}))?$/.exec(value || '0');
      if (match) total += BigInt(match[1]) * 100n + BigInt((match[2] || '').padEnd(2, '0') || '0');
    }
    const text = `${total / 100n}.${String(total % 100n).padStart(2, '0')}`;
    editor.querySelector('[data-cheque-total]').textContent = `Total cheques: ${text}`;
    if (editor.hidden) return;
    for (const name of ['amount', 'amount_paid']) {
      const input = form.querySelector(`[name="${name}"]`);
      if (input) {
        input.value = text;
        input.dispatchEvent(new Event('input', { bubbles: true }));
      }
    }
  }
  async function loadPool() {
    if (poolLoaded || !editor.dataset.availableUrl) return;
    try {
      const response = await fetch(editor.dataset.availableUrl, {
        headers: { Accept: 'application/json' },
      });
      if (!response.ok) throw new Error('Could not load available customer cheques.');
      pool = await response.json();
      poolLoaded = true;
      for (const row of rows.children) poolOptions(row);
    } catch (e) {
      editor.querySelector('[data-cheque-total]').textContent = e.message;
    }
  }
  function poolOptions(row) {
    const select = field(row, 'cheque_id');
    const selected = select.value;
    select.replaceChildren(new Option('Choose a customer cheque', ''));
    for (const c of pool)
      select.add(
        new Option(`${c.customer} · #${c.number} · ${c.bank} · ${c.date} · ${c.amount}`, c.id),
      );
    select.value = selected;
  }
  function configure(row, index) {
    const supplier = editor.dataset.side === 'supplier';
    const source = field(row, 'source');
    source.querySelector('[value=OWN]').disabled = supplier && editor.dataset.own !== '1';
    source.querySelector('[value=THIRD_PARTY]').disabled =
      !supplier || editor.dataset.transfer !== '1';
    if (!supplier) source.value = 'OWN';
    else if (source.selectedOptions[0]?.disabled)
      source.value = editor.dataset.own === '1' ? 'OWN' : 'THIRD_PARTY';
    const transferred = supplier && source.value === 'THIRD_PARTY';
    for (const container of row.querySelectorAll('[data-own-field]'))
      container.hidden = transferred;
    for (const container of row.querySelectorAll('[data-supplier-source]'))
      container.hidden = !supplier || (container.hasAttribute('data-own-field') && transferred);
    for (const container of row.querySelectorAll('[data-transfer-field]'))
      container.hidden = !transferred;
    for (const input of row.querySelectorAll('[data-cheque-field]')) {
      input.name = `cheques[${index}][${input.dataset.chequeField}]`;
      input.disabled =
        editor.hidden ||
        input.closest('label').hidden ||
        (input.dataset.chequeField === 'source' && !supplier);
    }
    field(row, 'cheque_id').required = transferred;
    row.querySelector('legend').textContent = `Cheque ${index + 1}`;
    row.querySelector('[data-remove-cheque]').hidden = rows.children.length <= 1;
    if (transferred) loadPool();
  }
  function renderState() {
    const selected = method?.selectedOptions[0];
    if (editor.dataset.resolutionEditor === '1') {
      editor.dataset.side =
        form.querySelector('[name=recovery_side]').value === 'SUPPLIER' ? 'supplier' : 'receive';
      editor.hidden = form.querySelector('[name=resolution]:checked')?.value !== 'REPLACE';
    } else editor.hidden = !editor.dataset.always && selected?.dataset.type !== 'CHEQUE';
    if (kind) {
      const supplier = kind.value === 'OWN_CHEQUE';
      editor.dataset.side = supplier ? 'supplier' : 'receive';
      for (const label of form.querySelectorAll('[data-receive-party]')) {
        label.hidden = supplier;
        label.querySelector('select').disabled = supplier;
      }
      for (const label of form.querySelectorAll('[data-supplier-party]')) {
        label.hidden = !supplier;
        label.querySelector('select').disabled = !supplier;
      }
    }
    Array.from(rows.children).forEach(configure);
    editor.querySelector('[data-add-cheque]').hidden = editor.dataset.multiple !== '1';
    sum();
  }
  function add() {
    rows.append(editor.querySelector('template').content.cloneNode(true));
    poolOptions(rows.lastElementChild);
    renderState();
  }
  editor.addEventListener('click', (e) => {
    if (e.target.closest('[data-add-cheque]')) {
      if (rows.children.length < 30) add();
    }
    if (e.target.closest('[data-remove-cheque]') && rows.children.length > 1) {
      e.target.closest('.cheque-entry').remove();
      renderState();
    }
  });
  editor.addEventListener('change', (e) => {
    const row = e.target.closest('.cheque-entry');
    if (e.target.dataset.chequeField === 'source') renderState();
    if (e.target.dataset.chequeField === 'bank_account_id') {
      const option = e.target.selectedOptions[0];
      for (const [key, data] of [
        ['bank_name', 'bank'],
        ['account_number', 'number'],
        ['drawer_name', 'holder'],
        ['branch', 'branch'],
      ])
        if (option.dataset[data]) field(row, key).value = option.dataset[data];
    }
    sum();
  });
  editor.addEventListener('input', (e) => {
    if (e.target.dataset.chequeField === 'amount') sum();
  });
  method?.addEventListener('change', renderState);
  kind?.addEventListener('change', renderState);
  form
    .querySelectorAll('[name=resolution], [name=recovery_side]')
    .forEach((input) => input.addEventListener('change', renderState));
  const initial = JSON.parse(editor.querySelector('[data-cheque-initial]').textContent);
  if (initial.length) {
    for (const entry of initial) {
      add();
      for (const [key, value] of Object.entries(entry)) {
        const input = field(rows.lastElementChild, key);
        if (input) {
          if (key === 'cheque_id') input.add(new Option(`Cheque #${value}`, value));
          input.value = value;
        }
      }
    }
    renderState();
  } else add();
}

for (const form of document.querySelectorAll('[data-cheque-recovery]')) {
  const amount = form.querySelector('[name=amount]');
  const method = form.querySelector('[name=payment_method_id]');
  const editor = form.querySelector('[data-cheque-editor]');
  const render = () => {
    const side = form.querySelector('[name=recovery_side]').value;
    const limit = moneyCents(
      side === 'SUPPLIER' ? form.dataset.supplierLimit : form.dataset.customerLimit,
    );
    const accountAllowed =
      (side === 'SUPPLIER' ? form.dataset.supplierAllowed : form.dataset.customerAllowed) === '1';
    const sourceAllowed =
      side === 'SUPPLIER'
        ? editor.dataset.own === '1' || editor.dataset.transfer === '1'
        : form.dataset.customerReplace === '1';
    for (const radio of form.querySelectorAll('[name=resolution]')) {
      radio.disabled =
        radio.value !== 'DUE' &&
        (!accountAllowed || limit <= 0n || (radio.value === 'REPLACE' && !sourceAllowed));
      radio.closest('label').hidden = radio.disabled;
    }
    let selected = form.querySelector('[name=resolution]:checked');
    if (!selected || selected.disabled) {
      selected = form.querySelector('[name=resolution]:not(:disabled)');
      if (selected) {
        selected.checked = true;
        selected.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }
    const choice = selected?.value || 'DUE';
    const payment = form.querySelector('[data-recovery-payment]');
    payment.hidden = choice !== 'PAYMENT';
    payment.querySelectorAll('input, select').forEach((input) => {
      input.disabled = choice !== 'PAYMENT';
    });
    method.required = choice === 'PAYMENT';
    const register = form.querySelector('[data-recovery-register]');
    register.hidden =
      choice !== 'PAYMENT' ||
      side !== 'SUPPLIER' ||
      method.selectedOptions[0]?.dataset.type !== 'CASH';
    register.querySelector('input').disabled = register.hidden;
    amount.closest('[data-recovery-amount]').hidden = choice === 'DUE';
    amount.disabled = choice === 'DUE';
    amount.readOnly = choice === 'REPLACE';
    amount.required = choice !== 'DUE';
    amount.max = displayCents(limit).replaceAll(',', '');
    if (choice === 'PAYMENT' && moneyCents(amount.value) === 0n) amount.value = amount.max;
    form.querySelector('[data-recovery-amount-label]').textContent =
      choice === 'REPLACE'
        ? 'Total replacement cheques'
        : side === 'CUSTOMER'
          ? 'Amount received'
          : 'Amount paid';
    const current = choice === 'DUE' ? 0n : moneyCents(amount.value);
    const remaining = limit - current;
    amount.setCustomValidity(
      current > limit ? 'The total exceeds the amount remaining against this returned cheque.' : '',
    );
    form.querySelector('[data-recovery-balance]').textContent =
      remaining < 0n
        ? 'The total is above the remaining return amount.'
        : `${form.dataset.currency} ${displayCents(remaining)} remains to be handled against this return.`;
    form.querySelector('[data-recovery-submit]').disabled =
      !selected || (choice !== 'DUE' && (current <= 0n || current > limit));
  };
  form.addEventListener('input', render);
  form.addEventListener('change', render);
  form.addEventListener('submit', () => {
    const button = form.querySelector('[data-recovery-submit]');
    button.disabled = true;
    button.textContent = 'Saving…';
  });
  render();
}
document.addEventListener('click', (event) => {
  event.target.closest('[data-close-cheque-dialog]')?.closest('dialog')?.close();
});
document.querySelectorAll('[data-reopen-cheque]').forEach((dialog) => dialog.showModal());
for (const form of document.querySelectorAll('[data-cheque-edit]')) {
  form.querySelector('[data-edit-bank-account]')?.addEventListener('change', (event) => {
    const option = event.target.selectedOptions[0];
    for (const [field, property] of [
      ['bank_name', 'bank'],
      ['account_number', 'number'],
      ['drawer_name', 'holder'],
      ['branch', 'branch'],
    ]) {
      if (option.dataset[property] !== undefined)
        form.querySelector(`[name=${field}]`).value = option.dataset[property];
    }
  });
}
