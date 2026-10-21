const smsDialog = document.getElementById('sms-receipt-dialog');
if (smsDialog) {
  const element = (id) => document.getElementById(id);
  const form = element('sms-receipt-form');
  const status = element('sms-receipt-status');
  const fields = element('sms-customer-fields');
  const modes = element('sms-recipient-modes');
  const existingFields = element('sms-existing-fields');
  const newFields = element('sms-new-fields');
  const phoneFields = element('sms-phone-fields');
  const recipient = element('sms-receipt-recipient');
  const select = element('sms-customer-select');
  const name = element('sms-customer-name');
  const phone = element('sms-customer-phone');
  const search = element('sms-customer-search');
  const searchStatus = element('sms-customer-search-status');
  const submit = element('sms-receipt-submit');
  const submitLabel = element('sms-receipt-submit-label');
  const cancel = element('sms-receipt-cancel');
  let target = '',
    token = '',
    busy = false,
    searchTimer,
    lookupVersion = 0,
    contextVersion = 0,
    lockedCustomer = null,
    mode = 'existing',
    loading = false,
    finished = false,
    sourceButton = null;
  const headers = {
    Accept: 'application/json',
    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
  };
  const showStatus = (text, tone = 'info') => {
    status.hidden = !text;
    status.textContent = text;
    status.dataset.tone = tone;
  };
  const validPhone = (value) => {
    let number = value.replace(/[\s()+.\-]/g, '').replace(/^00/, '');
    if (/^0[1-9][0-9]{8}$/.test(number)) number = '94' + number.slice(1);
    else if (/^7[0-9]{8}$/.test(number)) number = '94' + number;
    return /^[1-9][0-9]{7,14}$/.test(number) && (!number.startsWith('94') || number.length === 11);
  };
  const selectedCustomer = () => {
    if (lockedCustomer) return lockedCustomer;
    const option = select.selectedOptions[0];
    return mode === 'existing' && select.value
      ? { id: select.value, name: option.dataset.name, phone: option.dataset.phone }
      : null;
  };
  function updateRecipient() {
    const customer = selectedCustomer();
    const isNew = mode === 'new' && !lockedCustomer;
    const needsPhone = isNew || (customer && !validPhone(customer.phone || ''));
    modes.hidden = !!lockedCustomer;
    existingFields.hidden = mode !== 'existing' || !!lockedCustomer;
    newFields.hidden = !isNew;
    select.disabled = mode !== 'existing' || !!lockedCustomer || busy;
    search.disabled = busy;
    name.disabled = !isNew || busy;
    name.required = isNew;
    phoneFields.hidden = !needsPhone;
    phone.disabled = !needsPhone || busy;
    phone.required = !!needsPhone;
    recipient.hidden = !customer;
    if (customer) {
      element('sms-recipient-name').textContent = customer.name;
      element('sms-recipient-phone').textContent = needsPhone
        ? 'Add a valid mobile number below'
        : customer.phone;
    }
    element('sms-phone-label').textContent = isNew
      ? 'Mobile number'
      : 'Add or correct mobile number';
    element('sms-phone-help').textContent = isNew
      ? 'We’ll save these details as a customer and link them to this bill.'
      : 'This number will be saved to this customer’s record.';
    submitLabel.textContent = busy ? 'Sending…' : isNew ? 'Save customer & send' : 'Send receipt';
    submit.disabled =
      busy ||
      loading ||
      finished ||
      (isNew
        ? !name.value.trim() || !validPhone(phone.value)
        : !customer || (needsPhone && !validPhone(phone.value)));
    modes.querySelectorAll('button').forEach((button) => {
      button.setAttribute('aria-pressed', String(button.dataset.smsMode === mode));
      button.disabled = busy;
    });
  }
  async function json(url, options = {}) {
    const response = await fetch(url, { ...options, headers: { ...headers, ...options.headers } });
    const body = await response.json();
    if (!response.ok)
      throw new Error(
        Object.values(body.errors || {}).flat()[0] || body.message || 'Unable to send SMS.',
      );
    return body;
  }
  async function findCustomers() {
    const version = ++lookupVersion;
    loading = true;
    searchStatus.hidden = false;
    searchStatus.textContent = 'Finding customers…';
    updateRecipient();
    try {
      const customers = await json(
        `${smsDialog.dataset.customersUrl}?${new URLSearchParams({ q: search.value })}`,
      );
      if (version !== lookupVersion || lockedCustomer || mode !== 'existing' || !smsDialog.open)
        return;
      select.replaceChildren(new Option('Select a customer…', ''));
      customers.forEach((customer) => {
        const option = new Option(
          `${customer.name} · ${customer.phone || 'No number'}`,
          customer.id,
        );
        option.dataset.name = customer.name;
        option.dataset.phone = customer.phone || '';
        select.append(option);
      });
      searchStatus.hidden = customers.length > 0;
      searchStatus.textContent = 'No customers found. Try another search or choose New customer.';
    } catch (error) {
      if (version === lookupVersion && smsDialog.open) {
        searchStatus.hidden = false;
        searchStatus.textContent = error.message;
      }
    } finally {
      if (version === lookupVersion) {
        loading = false;
        updateRecipient();
      }
    }
  }
  async function send(payload) {
    if (busy || finished) return;
    busy = true;
    updateRecipient();
    smsDialog.querySelectorAll('[data-sms-close]').forEach((button) => {
      button.disabled = true;
    });
    showStatus('Sending the receipt. Please wait…');
    try {
      const result = await json(target, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...payload, token }),
      });
      finished = true;
      if (selectedCustomer() && payload.phone) {
        element('sms-recipient-phone').textContent = payload.phone;
      }
      showStatus(
        result.message,
        result.status === 'SENT'
          ? 'success'
          : result.status === 'FAILED' || result.status === 'UNKNOWN'
            ? 'error'
            : 'info',
      );
      fields.hidden = true;
      phoneFields.hidden = true;
      submit.hidden = true;
      cancel.textContent = 'Done';
      if (result.status === 'SENT' && sourceButton?.closest('#receipt-dialog')?.open) {
        smsDialog.close();
        sourceButton.dispatchEvent(
          new CustomEvent('sms:receipt-sent', {
            bubbles: true,
            detail: { message: result.message },
          }),
        );
      }
      // Keep the token after every outcome so a lost response or repeated click cannot send twice.
    } catch (error) {
      showStatus(error.message, 'error');
    } finally {
      busy = false;
      if (!finished) updateRecipient();
      smsDialog.querySelectorAll('[data-sms-close]').forEach((button) => {
        button.disabled = false;
      });
    }
  }
  document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-sms-receipt-url]');
    if (!button || !button.dataset.smsReceiptUrl || busy || smsDialog.open) return;
    sourceButton = button;
    const version = ++contextVersion;
    const nextTarget = button.dataset.smsReceiptUrl;
    if (target !== nextTarget) {
      target = nextTarget;
      token = crypto.randomUUID();
    }
    form.reset();
    lockedCustomer = null;
    mode = 'existing';
    loading = true;
    finished = false;
    fields.hidden = true;
    recipient.hidden = true;
    phoneFields.hidden = true;
    submit.hidden = false;
    submit.disabled = true;
    submitLabel.textContent = 'Send receipt';
    cancel.textContent = 'Cancel';
    select.replaceChildren(new Option('Select a customer…', ''));
    showStatus('Checking the bill’s customer…');
    smsDialog.showModal();
    try {
      const context = await json(target);
      if (version !== contextVersion || !smsDialog.open) return;
      lockedCustomer = context.customer;
      loading = false;
      showStatus('');
      updateRecipient();
      if (context.can_send) await send({});
      else {
        fields.hidden = false;
        if (!lockedCustomer) await findCustomers();
      }
    } catch (error) {
      if (version === contextVersion && smsDialog.open) showStatus(error.message, 'error');
    }
  });
  modes.querySelectorAll('[data-sms-mode]').forEach((button) =>
    button.addEventListener('click', () => {
      if (busy || mode === button.dataset.smsMode) return;
      mode = button.dataset.smsMode;
      lookupVersion++;
      clearTimeout(searchTimer);
      loading = false;
      phone.value = '';
      showStatus('');
      updateRecipient();
      if (mode === 'existing') {
        select.value = '';
        findCustomers();
        search.focus();
      } else name.focus();
    }),
  );
  search.addEventListener('input', () => {
    clearTimeout(searchTimer);
    lookupVersion++;
    select.replaceChildren(new Option('Select a customer…', ''));
    phone.value = '';
    loading = true;
    searchStatus.hidden = false;
    searchStatus.textContent = 'Finding customers…';
    updateRecipient();
    searchTimer = setTimeout(findCustomers, 250);
  });
  select.addEventListener('change', () => {
    phone.value = '';
    showStatus('');
    updateRecipient();
  });
  name.addEventListener('input', updateRecipient);
  phone.addEventListener('input', updateRecipient);
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    if (!submit.disabled) send(Object.fromEntries(new FormData(form)));
  });
  smsDialog.querySelectorAll('[data-sms-close]').forEach((button) =>
    button.addEventListener('click', () => {
      if (!busy) smsDialog.close();
    }),
  );
  smsDialog.addEventListener('close', () => {
    contextVersion++;
    lookupVersion++;
    clearTimeout(searchTimer);
  });
  smsDialog.addEventListener('cancel', (event) => {
    if (busy) event.preventDefault();
  });
}
const smsAll = document.getElementById('sms-select-page');
if (smsAll)
  smsAll.addEventListener('change', () => {
    const checked = smsAll.checked;
    document.querySelectorAll('[name="customers[]"]:not(:disabled)').forEach((checkbox) => {
      checkbox.checked = checked;
    });
  });
