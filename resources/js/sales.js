const workspace = document.getElementById('sale-workspace');
if (workspace) {
  const $ = (id) => document.getElementById(id);
  const cents = (value, scale = 2) => {
    const match = /^(\d+)(?:\.(\d*))?$/.exec(String(value));
    if (!match || (match[2]?.length || 0) > scale) return null;
    return (
      BigInt(match[1]) * 10n ** BigInt(scale) + BigInt((match[2] || '').padEnd(scale, '0') || '0')
    );
  };
  const display = (amount) =>
    `${workspace.dataset.currency} ${(Number(amount) / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  const due = cents(workspace.dataset.due);
  const dialogs = {
    delete: $('sale-delete-dialog'),
    return: $('sale-return-dialog'),
    pay: $('sale-pay-dialog'),
  };
  document
    .querySelectorAll('[data-close-sale-dialog]')
    .forEach((button) => button.addEventListener('click', () => button.closest('dialog').close()));
  // Action links remain usable without JavaScript and are enhanced into popups here.
  document.querySelectorAll('a[href]').forEach((link) => {
    const url = new URL(link.href);
    const action = url.searchParams.get('action');
    if (url.pathname !== location.pathname || !dialogs[action]) return;
    link.addEventListener('click', (event) => {
      event.preventDefault();
      open(action);
    });
  });
  function open(action) {
    const dialog = dialogs[action];
    if (!dialog || dialog.open || document.querySelector('dialog[open]')) return;
    dialog.showModal();
    const input =
      action === 'pay'
        ? $('due-payment-tender')
        : dialog.querySelector('input:not([type="hidden"]),select,textarea');
    input?.focus({ preventScroll: true });
    if (input?.select && !input.readOnly) input.select();
  }
  const returnRows = [...document.querySelectorAll('[data-return-line]')];
  function updateReturn() {
    if (!$('sale-return-form')) return;
    let amount = 0n,
      selected = false,
      valid = true;
    for (const row of returnRows) {
      const qty = cents(row.querySelector('[data-return-quantity]').value, 3);
      const whole = cents(row.dataset.whole, 3),
        returned = cents(row.dataset.returned, 3),
        remaining = cents(row.dataset.remaining, 3);
      if (qty === null || qty > remaining) {
        valid = false;
        continue;
      }
      if (qty > 0n) selected = true;
      const net = cents(row.dataset.net),
        refunded = cents(row.dataset.refunded);
      const value = (net * (returned + qty) + whole / 2n) / whole - refunded;
      row.querySelector('[data-return-value]').textContent = display(value);
      amount += value;
    }
    const reduction = amount > due ? due : amount,
      refund = amount - reduction;
    $('return-total').textContent = display(amount);
    $('return-due-reduction').textContent = display(reduction);
    $('return-refund').textContent = display(refund);
    $('return-payment-method').required = refund > 0n;
    $('save-sale-return').disabled = !valid || !selected;
    $('return-error').hidden = valid;
    $('return-error').textContent =
      'Check the return quantities. They cannot exceed the available quantity.';
  }
  returnRows.forEach((row) =>
    row.querySelector('[data-return-quantity]').addEventListener('input', updateReturn),
  );
  $('return-all')?.addEventListener('click', () => {
    returnRows.forEach((row) => {
      row.querySelector('[data-return-quantity]').value = row.dataset.remaining;
    });
    updateReturn();
  });
  updateReturn();
  let automaticTender = $('due-payment-tender')?.value === $('due-payment-amount')?.value;
  function updateCollection() {
    if (!$('sale-collection-form')) return;
    const method = $('due-payment-method').selectedOptions[0];
    const cash = method?.dataset.type === 'CASH';
    const amount = cents($('due-payment-amount').value),
      tender = $('due-payment-tender');
    tender.readOnly = !cash;
    $('due-tender-label').textContent = cash ? 'Cash received' : 'Amount collected';
    if (!cash || automaticTender) tender.value = $('due-payment-amount').value;
    const received = cents(tender.value);
    const valid =
      amount !== null &&
      amount > 0n &&
      amount <= due &&
      received !== null &&
      received >= amount &&
      (cash || received === amount);
    $('due-after').textContent = amount !== null ? display(amount > due ? 0n : due - amount) : '—';
    $('due-change').textContent =
      received !== null && amount !== null
        ? display(received > amount ? received - amount : 0n)
        : '—';
    $('save-due-payment').disabled = !valid;
    $('due-payment-error').hidden = valid;
    $('due-payment-error').textContent =
      'Enter a payment within the balance due and enough cash to cover it.';
  }
  $('due-payment-amount')?.addEventListener('input', updateCollection);
  $('due-payment-tender')?.addEventListener('input', () => {
    automaticTender = false;
    updateCollection();
  });
  $('due-payment-method')?.addEventListener('change', () => {
    automaticTender = true;
    updateCollection();
  });
  $('sale-collection-form')?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && event.target.matches('#due-payment-tender,#due-payment-amount')) {
      event.preventDefault();
      if (!$('save-due-payment').disabled) $('save-due-payment').click();
    }
  });
  updateCollection();
  open(workspace.dataset.openAction);
}
