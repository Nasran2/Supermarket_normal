const workflow = document.getElementById('register-workflow');
if (workflow) {
  const opening = document.getElementById('open-register-dialog');
  const closing = document.getElementById('close-register-dialog');
  const openForm = document.getElementById('open-register-form');
  const closeForm = document.getElementById('close-register-form');
  const content = document.getElementById('register-summary-content');
  const done = document.getElementById('register-summary-done');
  let saving = false;
  let closed = false;
  let expected = null;
  let requestNumber = 0;
  const cents = (value) => {
    if (!/^\d+(?:\.\d{1,2})?$/.test(value)) return null;
    const [whole, fraction = ''] = value.split('.');
    return BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
  };
  const display = (value) =>
    `${workflow.dataset.currency} ${(Number(value) / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  const showError = (dialog, message) => {
    const error = dialog.querySelector('[role="alert"]');
    error.textContent = message;
    error.hidden = false;
  };
  const request = async (url, data) => {
    const response = await fetch(url, {
      method: data ? 'POST' : 'GET',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
      },
      ...(data ? { body: JSON.stringify(data) } : {}),
    });
    let result;
    try {
      result = await response.json();
    } catch {
      throw new Error('Unable to reach the server. Please try again.');
    }
    if (!response.ok)
      throw new Error(
        result.errors
          ? Object.values(result.errors).flat().join(' ')
          : 'Unable to update the register. Refresh and try again.',
      );
    return result;
  };
  function cashDifference() {
    if (!closeForm) return;
    const actual = cents(closeForm.elements.actual_cash.value);
    document.getElementById('register-live-difference').textContent =
      actual !== null && expected !== null ? display(actual - expected) : '—';
  }
  function showOpening() {
    if (saving || opening.open) return;
    opening.querySelector('[role="alert"]').hidden = true;
    opening.showModal();
    const input = openForm?.elements.opening_cash;
    input?.focus();
    input?.select();
  }
  async function showClosing() {
    if (saving || closing.open) return;
    closed = false;
    expected = null;
    closing.querySelector('[role="alert"]').hidden = true;
    document.getElementById('close-register-title').textContent = 'Close register';
    content.textContent = 'Loading your shift summary…';
    done.hidden = true;
    if (closeForm) {
      closeForm.hidden = false;
      closeForm.reset();
      closeForm.querySelector('[type="submit"]').disabled = true;
      cashDifference();
    }
    closing.showModal();
    const current = ++requestNumber;
    try {
      const result = await request(workflow.dataset.summaryUrl);
      if (current !== requestNumber || !closing.open) return;
      content.innerHTML = result.html;
      const expectedValue = String(result.expected_cash);
      expected = cents(expectedValue.replace('-', ''));
      if (expectedValue.startsWith('-') && expected !== null) expected = -expected;
      if (closeForm) {
        closeForm.elements.register_id.value = result.register_id;
        closeForm.querySelector('[type="submit"]').disabled = false;
      }
    } catch (error) {
      if (current === requestNumber) showError(closing, error.message);
    }
  }
  document
    .querySelectorAll('[data-open-register]')
    .forEach((button) => button.addEventListener('click', showOpening));
  document
    .querySelectorAll('[data-close-register]')
    .forEach((button) => button.addEventListener('click', showClosing));
  document.querySelectorAll('[data-register-toggle]').forEach((button) =>
    button.addEventListener('click', (event) => {
      event.preventDefault();
      if (workflow.dataset.current === '1') showClosing();
      else showOpening();
    }),
  );
  for (const dialog of [opening, closing]) {
    let activeInput = dialog.querySelector('[data-register-amount]');
    let replace = true;
    dialog.addEventListener('focusin', (event) => {
      if (event.target.matches('[data-register-amount]')) {
        activeInput = event.target;
        replace = true;
        activeInput.select();
      }
    });
    dialog.addEventListener('input', (event) => {
      if (event.target.matches('[data-register-amount]')) {
        event.target.setCustomValidity('');
        replace = false;
        cashDifference();
      }
    });
    dialog
      .querySelector('.register-keypad')
      ?.addEventListener('pointerdown', (event) => event.preventDefault());
    dialog.querySelector('.register-keypad')?.addEventListener('click', (event) => {
      const button = event.target.closest('[data-register-key]');
      if (!button || !activeInput || saving) return;
      const key = button.dataset.registerKey;
      let value = activeInput.value;
      if (key === 'clear') value = '0';
      else if (key === 'backspace') value = value.slice(0, -1) || '0';
      else {
        if (replace) value = '';
        if (key === '.' && value.includes('.')) return;
        value = key === '.' ? (value || '0') + '.' : value === '0' ? key : value + key;
        if (!/^\d*(?:\.\d{0,2})?$/.test(value) || value.length > 12) return;
      }
      replace = false;
      activeInput.value = value;
      activeInput.setCustomValidity('');
      cashDifference();
    });
    const dismiss = () => {
      if (saving) return;
      if (closed) {
        window.location.reload();
        return;
      }
      if (dialog === opening && workflow.dataset.required === '1') return;
      requestNumber++;
      dialog.close();
    };
    dialog.querySelector('[data-dismiss-register]').addEventListener('click', dismiss);
    dialog.addEventListener('cancel', (event) => {
      event.preventDefault();
      dismiss();
    });
  }
  async function submit(form, dialog, url) {
    if (saving) return;
    const input = form.querySelector('[data-register-amount]');
    const amount = cents(input.value);
    if (amount === null || amount > 99999999900n) {
      input.setCustomValidity(
        'Enter a cash amount from 0 to 999,999,999 with up to two decimal places.',
      );
      input.reportValidity();
      return;
    }
    saving = true;
    dialog.querySelector('[role="alert"]').hidden = true;
    const button = form.querySelector('[type="submit"]');
    const label = button.textContent;
    const data = Object.fromEntries(new FormData(form));
    form.querySelectorAll('button,input,textarea').forEach((element) => (element.disabled = true));
    button.textContent = 'Saving…';
    try {
      const result = await request(url, data);
      if (dialog === opening) {
        window.location.reload();
        return;
      }
      closed = true;
      document.getElementById('close-register-title').textContent = 'Register closed';
      content.innerHTML = result.html;
      form.hidden = true;
      done.hidden = false;
      done.focus();
    } catch (error) {
      showError(dialog, error.message);
    } finally {
      saving = false;
      form
        .querySelectorAll('button,input,textarea')
        .forEach((element) => (element.disabled = false));
      button.textContent = label;
    }
  }
  openForm?.addEventListener('submit', (event) => {
    event.preventDefault();
    submit(openForm, opening, workflow.dataset.openUrl);
  });
  closeForm?.addEventListener('submit', (event) => {
    event.preventDefault();
    submit(closeForm, closing, workflow.dataset.closeUrl);
  });
  done.addEventListener('click', () => window.location.reload());
  if (workflow.dataset.required === '1') {
    opening.querySelector('[data-dismiss-register]').hidden = true;
    showOpening();
  }
}
