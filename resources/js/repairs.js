const node = (tag, text) => {
  const element = document.createElement(tag);
  element.textContent = text;
  return element;
};

const headers = () => ({
  Accept: 'application/json',
  'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
});

async function json(url, options = {}) {
  const response = await fetch(url, { headers: headers(), ...options });
  if (!response.ok) {
    const body = await response.json().catch(() => ({}));
    throw new Error(Object.values(body.errors ?? {}).flat()[0] ?? 'Could not complete the request. Try again.');
  }
  return response.json();
}

function picker(root) {
  const input = root.querySelector('[data-picker-query]');
  const value = root.querySelector('[data-picker-value]');
  const results = root.querySelector('[data-picker-results]');
  let pending, timer;

  const choose = (row) => {
    input.value = row.name;
    value.value = row.id;
    input.setCustomValidity('');
    results.hidden = true;
    root.dispatchEvent(new CustomEvent('repair:pick', { detail: row }));
  };

  function search() {
    pending?.abort();
    clearTimeout(timer);
    const searchText = input.value.trim();
    if (!searchText) {
      results.hidden = true;
      return;
    }
    results.hidden = false;
    results.replaceChildren(node('p', 'Searching…'));
    timer = setTimeout(async () => {
      pending = new AbortController();
      try {
        const rows = await json(root.dataset.repairPicker + '?' + new URLSearchParams({ q: searchText }), { signal: pending.signal });
        const choices = rows.map((row) => {
          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'repair-picker-item';
          const nameSpan = document.createElement('span');
          nameSpan.className = 'picker-item-name';
          nameSpan.textContent = row.name;
          button.append(nameSpan);
          if (row.phone || row.sku) {
            const metaSpan = document.createElement('span');
            metaSpan.className = 'picker-item-phone';
            metaSpan.textContent = row.phone ?? row.sku;
            button.append(metaSpan);
          }
          button.addEventListener('click', () => choose(row));
          return button;
        });

        if (choices.length) {
          results.replaceChildren(...choices);
        } else {
          const emptyDiv = document.createElement('div');
          emptyDiv.className = 'repair-picker-empty';
          const p = document.createElement('p');
          p.textContent = `No matches found for "${searchText}".`;
          emptyDiv.append(p);

          const createBtn = document.createElement('button');
          createBtn.type = 'button';
          createBtn.className = 'btn secondary btn-sm';
          createBtn.dataset.quickCreateCustomer = 'true';
          createBtn.dataset.customerName = searchText;
          createBtn.textContent = `+ New customer "${searchText}"`;
          emptyDiv.append(createBtn);

          results.replaceChildren(emptyDiv);
        }
      } catch (error) {
        if (error.name !== 'AbortError') results.replaceChildren(node('p', error.message));
      }
    }, 250);
  }

  results.addEventListener('click', (event) => {
    const quickBtn = event.target.closest('[data-quick-create-customer]');
    if (quickBtn) {
      const dialog = document.getElementById('repair-customer-dialog');
      if (dialog) {
        const nameInput = dialog.querySelector('input[name="name"]');
        if (nameInput) nameInput.value = quickBtn.dataset.customerName || input.value.trim();
        dialog.showModal();
        nameInput?.focus();
        results.hidden = true;
      }
    }
  });

  input.addEventListener('input', () => {
    value.value = '';
    input.setCustomValidity(input.value ? 'Choose a result from the search or add a new customer.' : '');
    root.dispatchEvent(new CustomEvent('repair:pick', { detail: input.value.trim() ? { id: '', name: input.value.trim(), phone: '' } : null }));
    search();
  });
  input.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') results.hidden = true;
    if (event.key === 'ArrowDown' && !results.hidden) {
      event.preventDefault();
      results.querySelector('button')?.focus();
    }
  });
  document.addEventListener('pointerdown', (event) => {
    if (!root.contains(event.target)) results.hidden = true;
  });
  return choose;
}

function intake(root, chooseCustomer) {
  const search = root.querySelector('[data-history-url]');
  const history = root.querySelector('[data-repair-history]');
  const device = root.querySelector('[data-repair-device]');
  const newDevice = root.querySelector('[data-new-device]');
  const checks = root.querySelector('[data-repair-checks]');
  const catalog = JSON.parse(root.querySelector('[data-device-catalog]').textContent);
  const brand = root.querySelector('[data-catalog-brand]');
  const catalogModel = root.querySelector('[data-catalog-model]');
  const catalogFields = root.querySelector('[data-catalog-fields]');
  const manualFields = root.querySelector('[data-manual-device]');
  const manualToggle = root.querySelector('[data-manual-toggle]');
  const savedDevices = root.querySelector('[data-saved-devices]');
  const form = search.closest('form');
  const customerName = search.querySelector('[data-picker-query]');
  const customerId = search.querySelector('[data-picker-value]');
  const panels = [...root.querySelectorAll('[data-intake-panel]')];
  const steps = [...root.querySelectorAll('[data-intake-step]')];
  const next = root.querySelector('[data-intake-next]');
  const back = root.querySelector('[data-intake-back]');
  const save = root.querySelector('[data-intake-save]');
  const issueError = root.querySelector('[data-intake-issue-error]');
  const extraChecks = root.querySelector('[data-extra-checks]');
  const extraList = root.querySelector('[data-extra-check-list]');
  const customChecks = root.querySelector('[data-custom-checks]');
  const customList = root.querySelector('[data-custom-check-list]');
  let request, selectedDevices = [], current = 0;
  let selectedCustomerPhone = root.dataset.selectedCustomerPhone || '';
  let manual = Boolean(form.elements.brand.value || form.elements.model.value);
  let nextIndex = checks.querySelectorAll('[data-repair-check]').length;

  function updateBillPreview() {
    // 1. Customer
    const custName = customerName.value.trim();
    const custEl = root.querySelector('[data-bill="customer-name"]');
    const custPhoneEl = root.querySelector('[data-bill="customer-phone"]');
    if (custEl) {
      if (custName) {
        custEl.textContent = custName;
        custEl.classList.remove('bill-empty-muted');
      } else {
        custEl.textContent = 'Select customer';
        custEl.classList.add('bill-empty-muted');
      }
    }
    if (custPhoneEl) {
      if (selectedCustomerPhone) {
        custPhoneEl.textContent = 'Tel: ' + selectedCustomerPhone;
        custPhoneEl.hidden = false;
      } else {
        custPhoneEl.hidden = true;
      }
    }

    // 2. Device info
    const existing = selectedDevices.find((row) => String(row.id) === device.value);
    const selectedBrand = catalog.find((row) => String(row.id) === brand.value);
    const model = selectedBrand?.models.find((row) => String(row.id) === catalogModel.value);
    const description = existing ? [existing.brand, existing.model]
      : manual ? [form.elements.brand?.value.trim(), form.elements.model?.value.trim()]
        : model ? [selectedBrand?.name, model?.name] : [];
    const deviceTitle = description.filter(Boolean).join(' ');
    const deviceEl = root.querySelector('[data-bill="device-title"]');
    if (deviceEl) {
      if (deviceTitle) {
        deviceEl.textContent = deviceTitle;
        deviceEl.classList.remove('bill-empty-muted');
      } else {
        deviceEl.textContent = 'Select device';
        deviceEl.classList.add('bill-empty-muted');
      }
    }

    // Serial & Color
    const serialVal = (existing ? existing.serial : form.elements.serial?.value)?.trim();
    const serialWrap = root.querySelector('[data-bill-wrap="serial"]');
    const serialEl = root.querySelector('[data-bill="device-serial"]');
    if (serialWrap && serialEl) {
      if (serialVal) {
        serialEl.textContent = serialVal;
        serialWrap.hidden = false;
      } else {
        serialWrap.hidden = true;
      }
    }
    const colorVal = (existing ? existing.color : form.elements.color?.value)?.trim();
    const colorWrap = root.querySelector('[data-bill-wrap="color"]');
    const colorEl = root.querySelector('[data-bill="device-color"]');
    if (colorWrap && colorEl) {
      if (colorVal) {
        colorEl.textContent = colorVal;
        colorWrap.hidden = false;
      } else {
        colorWrap.hidden = true;
      }
    }
    const deviceMetaWrap = root.querySelector('[data-bill-device-meta]');
    if (deviceMetaWrap) {
      deviceMetaWrap.hidden = !serialVal && !colorVal;
    }

    // 3. Issues
    const issueList = issues();
    const issuesContainer = root.querySelector('[data-bill="issues-list"]');
    if (issuesContainer) {
      if (issueList.length) {
        issuesContainer.replaceChildren(...issueList.map((issue) => {
          const chip = document.createElement('span');
          chip.className = 'bill-issue-tag';
          chip.textContent = issue;
          return chip;
        }));
      } else {
        const placeholder = document.createElement('span');
        placeholder.className = 'bill-empty-muted';
        placeholder.textContent = 'Add a reported issue';
        issuesContainer.replaceChildren(placeholder);
      }
    }

    // 4. Condition
    const condTags = [...form.querySelectorAll('input[name="condition_tags[]"]:checked')].map((i) => i.value);
    const condExtra = form.elements.condition?.value.trim();
    const condList = [...condTags, condExtra].filter(Boolean);
    const condWrap = root.querySelector('[data-bill-wrap="condition"]');
    const condEl = root.querySelector('[data-bill="condition-text"]');
    if (condWrap && condEl) {
      if (condList.length) {
        condEl.textContent = condList.join(' · ');
        condWrap.hidden = false;
      } else {
        condWrap.hidden = true;
      }
    }

    // 5. Items received
    const itemTags = [...form.querySelectorAll('input[name="received_items[]"]:checked')].map((i) => i.value);
    const itemExtra = form.elements.accessories?.value.trim();
    const itemsList = [...itemTags, itemExtra].filter(Boolean);
    const itemsWrap = root.querySelector('[data-bill-wrap="items"]');
    const itemsEl = root.querySelector('[data-bill="items-text"]');
    if (itemsWrap && itemsEl) {
      if (itemsList.length) {
        itemsEl.textContent = itemsList.join(' · ');
        itemsWrap.hidden = false;
      } else {
        itemsWrap.hidden = true;
      }
    }

    // 6. Inspection
    const inspectWrap = root.querySelector('[data-bill-wrap="inspection"]');
    const inspectEl = root.querySelector('[data-bill="inspection-list"]');
    if (inspectWrap && inspectEl) {
      const selectedChecks = [...checks.querySelectorAll('input[type="radio"]:checked')].filter((f) => f.value !== 'Not tested');
      if (selectedChecks.length) {
        inspectEl.replaceChildren(...selectedChecks.map((f) => {
          const checkCard = f.closest('[data-repair-check]');
          const label = checkCard?.querySelector('[data-check-title]')?.textContent.trim() || 'Check';
          const pill = document.createElement('span');
          pill.className = 'bill-inspect-pill ' + (f.value === 'Working' ? 'works' : 'issue');
          pill.textContent = label + ': ' + f.value;
          return pill;
        }));
        inspectWrap.hidden = false;
      } else {
        inspectWrap.hidden = true;
      }
    }

    // 7. Estimate
    const estimateRaw = form.elements.estimate?.value ?? '';
    const estimateVal = parseFloat(estimateRaw);
    const estimateEl = root.querySelector('[data-bill="estimate"]');
    if (estimateEl) {
      const currency = root.dataset.currency || 'Rs.';
      if (!isNaN(estimateVal) && estimateVal >= 0 && estimateRaw.trim() !== '') {
        estimateEl.textContent = currency + ' ' + estimateVal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      } else {
        estimateEl.textContent = currency + ' 0.00';
      }
    }

    // 8. Due date
    const dueVal = form.elements.due_at?.value;
    const dueWrap = root.querySelector('[data-bill-wrap="due-date"]');
    const dueEl = root.querySelector('[data-bill="due-date"]');
    if (dueWrap && dueEl) {
      if (dueVal) {
        const d = new Date(dueVal + 'T00:00:00');
        dueEl.textContent = !isNaN(d) ? d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : dueVal;
        dueWrap.hidden = false;
      } else {
        dueWrap.hidden = true;
      }
    }

    // 9. Received date
    const recVal = form.elements.received_at?.value;
    const recEl = root.querySelector('[data-bill="received-date"]');
    if (recEl && recVal) {
      const d = new Date(recVal + 'T00:00:00');
      recEl.textContent = !isNaN(d) ? d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : recVal;
    }

    // 10. Technician
    const techSelect = form.elements.technician_id;
    const techWrap = root.querySelector('[data-bill-wrap="technician"]');
    const techEl = root.querySelector('[data-bill="technician"]');
    if (techWrap && techEl && techSelect) {
      const techText = techSelect.selectedOptions[0]?.text?.trim();
      if (techSelect.value && techText && techText !== 'Unassigned') {
        techEl.textContent = techText;
        techWrap.hidden = false;
      } else {
        techWrap.hidden = true;
      }
    }

    // 11. Warranty
    const warrantyVal = form.elements.warranty_days?.value?.trim();
    const warrantyWrap = root.querySelector('[data-bill-wrap="warranty"]');
    const warrantyEl = root.querySelector('[data-bill="warranty"]');
    if (warrantyWrap && warrantyEl) {
      if (warrantyVal && Number(warrantyVal) > 0) {
        warrantyEl.textContent = warrantyVal + ' days';
        warrantyWrap.hidden = false;
      } else {
        warrantyWrap.hidden = true;
      }
    }
  }

  function addCheck(templateSelector, container, label) {
    if (checks.querySelectorAll('[data-repair-check]').length >= 40) return;
    const fragment = root.querySelector(templateSelector).content.cloneNode(true);
    const row = fragment.querySelector('[data-repair-check]');
    row.querySelectorAll('[name]').forEach((field) => {
      field.name = field.name.replace('__INDEX__', nextIndex);
    });
    if (label) {
      row.dataset.checkLabel = label;
      row.querySelector('[data-check-title]').textContent = label;
      row.querySelector('[data-check-title-input]').value = label;
      row.querySelector('[role="group"]').setAttribute('aria-label', label + ' result');
    }
    const skipRadio = row.querySelector('input[value="Not tested"]');
    if (skipRadio) skipRadio.checked = true;
    container.append(fragment);
    nextIndex++;
    return row;
  }

  function fillModels(selected = '') {
    const models = catalog.find((row) => String(row.id) === brand.value)?.models ?? [];
    catalogModel.replaceChildren(new Option(brand.value ? (models.length ? 'Choose a model' : 'No models listed') : 'Choose a brand first', ''));
    for (const row of models) {
      catalogModel.add(new Option(row.name, row.id, false, String(row.id) === String(selected)));
    }
  }

  function issues() {
    return [...form.querySelectorAll('input[name="reported_issues[]"]:checked')].map((input) => input.value)
      .concat(form.elements.faults.value.trim()).filter(Boolean);
  }

  function summary() {
    const existing = selectedDevices.find((row) => String(row.id) === device.value);
    const selectedBrand = catalog.find((row) => String(row.id) === brand.value);
    const model = selectedBrand?.models.find((row) => String(row.id) === catalogModel.value);
    const description = existing ? [existing.brand, existing.model]
      : manual ? [form.elements.brand.value.trim(), form.elements.model.value.trim()]
        : model ? [selectedBrand.name, model.name] : [];
    const values = {
      customer: customerId.value ? customerName.value : (customerName.value.trim() || 'Select a customer'),
      device: description.filter(Boolean).join(' ') || 'Choose a device',
      issue: issues().join(' · ') || 'Add a reported issue',
    };
    for (const [key, value] of Object.entries(values)) {
      root.querySelectorAll('[data-review="' + key + '"]').forEach((element) => { element.textContent = value; });
    }
    updateBillPreview();
  }

  function showStep(step, focus = true) {
    current = Math.max(0, Math.min(panels.length - 1, step));
    form.elements.intake_step.value = current;
    panels.forEach((panel, index) => { panel.hidden = index !== current; });
    steps.forEach((button, index) => {
      if (index === current) button.setAttribute('aria-current', 'step');
      else button.removeAttribute('aria-current');
      button.dataset.complete = String(index < current);
    });
    next.hidden = current === panels.length - 1;
    back.hidden = current === 0;
    save.hidden = !next.hidden;
    next.firstChild.textContent = current === 0 ? 'Continue to condition ' : 'Continue to review ';
    summary();
    if (focus) panels[current].querySelector('h2').focus();
  }

  function validStep(step) {
    if (step === 0 && !customerId.value) {
      customerName.setCustomValidity('Select a customer from the search results or add a new customer.');
    }
    const invalid = [...panels[step].querySelectorAll('input, select, textarea')].find((field) => !field.validity.valid);
    if (invalid) {
      showStep(step, false);
      if (invalid.closest('details')) invalid.closest('details').open = true;
      invalid.reportValidity();
      return false;
    }
    if (step === 0 && !issues().length) {
      showStep(0, false);
      issueError.hidden = false;
      form.elements.faults.closest('details').open = true;
      form.elements.faults.focus();
      return false;
    }
    return true;
  }

  function changeStep(step) {
    for (let index = 0; index < step; index++) if (!validStep(index)) return;
    showStep(step);
  }

  function refreshChecks() {
    const selected = checks.querySelectorAll('input[type="radio"]:checked');
    const recorded = [...selected].filter((field) => field.value !== 'Not tested').length;
    const faults = [...selected].filter((field) => field.value === 'Not working').length;
    root.querySelector('[data-inspection-count]').textContent = recorded
      ? recorded + ' checked' + (faults ? ' · ' + faults + (faults === 1 ? ' issue' : ' issues') : '')
      : 'No checks recorded';
    updateBillPreview();
  }

  function updateDevice() {
    const existing = selectedDevices.find((row) => String(row.id) === device.value);
    newDevice.hidden = Boolean(existing);
    newDevice.querySelectorAll('input, select').forEach((field) => { field.disabled = Boolean(existing); });
    catalogFields.hidden = manual;
    manualFields.hidden = !manual;
    for (const field of [brand, catalogModel, form.elements.brand, form.elements.model]) {
      const isCatalog = field === brand || field === catalogModel;
      field.disabled = Boolean(existing) || (isCatalog === manual);
      field.required = !field.disabled;
    }
    manualToggle.textContent = manual ? 'Choose from the device catalog' : 'Can’t find the device? Enter it manually';
    const namedDevice = existing ?? (manual ? { brand: form.elements.brand.value.trim(), model: form.elements.model.value.trim() } : null);
    const catalogBrand = catalog.find((row) => namedDevice ? row.name.toLowerCase() === namedDevice.brand.toLowerCase() : String(row.id) === brand.value);
    const profile = catalogBrand?.models.find((row) => namedDevice ? row.name.toLowerCase() === namedDevice.model.toLowerCase() : String(row.id) === catalogModel.value)?.inspection;
    const suggestions = {
      'Face ID': ['Face ID'], 'Touch ID': ['Touch ID'], Fingerprint: ['Fingerprint sensor'], Foldable: ['Fingerprint sensor', 'Hinge', 'Cover screen'],
    }[profile] ?? [];
    for (const row of extraList.children) {
      const checkedVal = row.querySelector(':checked')?.value;
      const isUntouched = !checkedVal || checkedVal === 'Not tested';
      if (!suggestions.includes(row.dataset.checkLabel) && isUntouched && !row.querySelector('[data-check-note] input').value) row.remove();
    }
    const labels = new Set([...checks.querySelectorAll('input[name$="[label]"]')].map((input) => input.value));
    for (const label of suggestions) {
      if (!labels.has(label)) addCheck('[data-extra-check-template]', extraList, label);
    }
    extraChecks.hidden = !extraList.childElementCount;
    summary();
    refreshChecks();
  }

  brand.value = brand.dataset.selected;
  if (!brand.value && catalogModel.dataset.selected) {
    brand.value = catalog.find((row) => row.models.some((model) => String(model.id) === catalogModel.dataset.selected))?.id ?? '';
  }
  if (!catalog.length) manual = true;
  fillModels(catalogModel.dataset.selected);
  form.noValidate = true;
  next.addEventListener('click', () => changeStep(current + 1));
  back.addEventListener('click', () => showStep(current - 1));
  steps.forEach((button, index) => button.addEventListener('click', () => changeStep(index)));
  form.addEventListener('submit', (event) => {
    if (current < panels.length - 1) {
      event.preventDefault();
      changeStep(current + 1);
      return;
    }
    for (let index = 0; index < panels.length; index++) {
      if (!validStep(index)) {
        event.preventDefault();
        return;
      }
    }
    save.disabled = true;
  });
  form.addEventListener('input', () => {
    if (issues().length) issueError.hidden = true;
    summary();
  });
  form.addEventListener('change', () => {
    summary();
  });

  async function loadHistory(customer, selected = '') {
    request?.abort();
    selectedDevices = [];
    device.replaceChildren(new Option('New device', ''));
    updateDevice();
    history.replaceChildren();
    history.hidden = !customer;
    savedDevices.hidden = true;
    if (!customer) return;
    request = new AbortController();
    history.append(node('p', 'Loading customer history…'));
    try {
      const data = await json(search.dataset.historyUrl.replace('__id__', customer), { signal: request.signal });
      selectedDevices = data.devices;
      savedDevices.hidden = !data.devices.length;
      for (const row of data.devices) {
        const title = [row.brand, row.model, row.serial, row.color].filter(Boolean).join(' · ');
        device.add(new Option(title, row.id, false, String(row.id) === String(selected)));
      }
      updateDevice();
      const totals = node('p', data.open + ' active jobs · Repair balance ' + data.due + ' · Account balance ' + data.account);
      const list = document.createElement('ul');
      list.className = 'repair-history';
      for (const job of data.history) {
        const link = node('a', [job.device, job.status, job.received, job.warranty ? 'Warranty until ' + job.warranty : null].filter(Boolean).join(' · '));
        link.href = job.url;
        link.className = 'text-link';
        const item = document.createElement('li');
        item.append(link);
        list.append(item);
      }
      const paymentList = document.createElement('ul');
      paymentList.className = 'repair-history';
      for (const payment of data.payments) {
        paymentList.append(node('li', [payment.kind, payment.method_name, payment.amount].join(' · ')));
      }
      const detail = document.createElement('details');
      detail.append(node('summary', 'Recent repairs and payments'), list, node('h3', 'Repair payments'), paymentList);
      if (data.previous_payments?.length) {
        const previous = document.createElement('ul');
        previous.className = 'repair-history';
        data.previous_payments.forEach((payment) => previous.append(node('li', [payment.date, payment.type, payment.amount].join(' · '))));
        detail.append(node('h3', 'POS and account payments'), previous);
      }
      if (!list.childElementCount && !paymentList.childElementCount && !data.previous_payments?.length) detail.append(node('p', 'No previous activity.'));
      history.replaceChildren(totals, detail);
    } catch (error) {
      if (error.name !== 'AbortError') history.replaceChildren(node('p', error.message));
    }
  }

  search.addEventListener('repair:pick', (event) => {
    if (event.detail && event.detail.phone !== undefined) {
      selectedCustomerPhone = event.detail.phone || '';
    } else if (!event.detail) {
      selectedCustomerPhone = '';
    }
    loadHistory(event.detail?.id);
    summary();
  });
  if (customerId.value) loadHistory(customerId.value, device.dataset.selected);
  updateDevice();
  showStep(Number(root.dataset.intakeStart) || 0, false);
  device.addEventListener('change', updateDevice);
  manualFields.addEventListener('change', updateDevice);
  brand.addEventListener('change', () => { fillModels(); updateDevice(); });
  catalogModel.addEventListener('change', updateDevice);
  manualToggle.addEventListener('click', () => {
    manual = !manual;
    updateDevice();
    (manual ? form.elements.brand : brand).focus();
  });
  root.querySelector('[data-add-check]').addEventListener('click', () => {
    const row = addCheck('[data-check-template]', customList);
    if (!row) return;
    customChecks.hidden = false;
    row.querySelector('input').focus();
  });
  checks.addEventListener('click', (event) => {
    if (event.target.closest('[data-remove-check]')) {
      event.target.closest('[data-repair-check]').remove();
      customChecks.hidden = !customList.childElementCount;
      refreshChecks();
    }
  });
  checks.addEventListener('change', (event) => {
    if (event.target.type !== 'radio') return;
    const row = event.target.closest('[data-repair-check]');
    const note = row.querySelector('[data-check-note]');
    note.hidden = event.target.value !== 'Not working' && !note.querySelector('input').value;
    refreshChecks();
  });
  root.querySelector('[data-new-repair-customer]')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const add = event.currentTarget;
    const button = add.querySelector('button[type="submit"], button.btn.primary');
    const error = add.querySelector('[data-customer-error]');
    button.disabled = true;
    error.hidden = true;
    try {
      const created = await json(add.action, { method: 'POST', body: new FormData(add) });
      selectedCustomerPhone = created.phone || '';
      chooseCustomer(created);
      summary();
      add.reset();
      add.closest('dialog').close();
    } catch (failure) {
      error.textContent = failure.message;
      error.hidden = false;
    } finally {
      button.disabled = false;
    }
  });

  const type = root.querySelector('[data-unlock-type]');
  const secret = root.querySelector('[data-unlock-secret]');
  const credential = root.querySelector('[data-unlock-value]');
  const pattern = root.querySelector('[data-unlock-pattern]');
  const grid = pattern.querySelector('.repair-pattern');
  const line = grid.querySelector('[data-pattern-line]');
  let dots = [], drawing = false;
  const selectDot = (button) => {
    if (!button || dots.includes(button)) return;
    dots.push(button);
    button.setAttribute('aria-pressed', 'true');
    credential.value = dots.map((dot) => dot.dataset.dot).join('-');
    line.setAttribute('points', dots.map((dot) => {
      const x = (dot.offsetLeft + dot.offsetWidth / 2) * 100 / grid.offsetWidth;
      const y = (dot.offsetTop + dot.offsetHeight / 2) * 100 / grid.offsetHeight;
      return x + ',' + y;
    }).join(' '));
  };
  const reset = () => {
    dots = [];
    line.removeAttribute('points');
    pattern.querySelectorAll('[data-dot]').forEach((button) => button.setAttribute('aria-pressed', 'false'));
    credential.value = '';
  };
  type.addEventListener('change', () => {
    reset();
    secret.value = '';
    secret.disabled = !['PIN', 'PASSWORD'].includes(type.value);
    secret.inputMode = type.value === 'PIN' ? 'numeric' : 'text';
    secret.closest('label').hidden = secret.disabled;
    pattern.hidden = type.value !== 'PATTERN';
  });
  secret.addEventListener('input', () => { credential.value = secret.value; });
  grid.addEventListener('pointerdown', (event) => {
    const dot = event.target.closest('[data-dot]');
    if (!dot) return;
    drawing = true;
    grid.setPointerCapture(event.pointerId);
    selectDot(dot);
  });
  grid.addEventListener('pointermove', (event) => {
    if (drawing) selectDot(document.elementFromPoint(event.clientX, event.clientY)?.closest('[data-dot]'));
  });
  grid.addEventListener('lostpointercapture', () => { drawing = false; });
  grid.addEventListener('click', (event) => selectDot(event.target.closest('[data-dot]')));
  pattern.querySelector('[data-pattern-clear]').addEventListener('click', reset);
}

function liveFilters(form) {
  let timer, pending;
  form.querySelector('[data-repair-keyword]').addEventListener('input', () => {
    clearTimeout(timer);
    pending?.abort();
    timer = setTimeout(() => form.requestSubmit(), 350);
  });
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearTimeout(timer);
    pending?.abort();
    pending = new AbortController();
    const url = new URL(form.action);
    url.search = new URLSearchParams(new FormData(form)).toString();
    const results = document.querySelector('[data-repair-results]');
    results.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch(url, { signal: pending.signal });
      if (!response.ok) throw new Error();
      const page = new DOMParser().parseFromString(await response.text(), 'text/html');
      const updated = page.querySelector('[data-repair-results]');
      if (!updated) throw new Error();
      results.replaceWith(updated);
      for (const selector of ['[data-repair-export]', '.repair-report-nav']) {
        if (document.querySelector(selector) && page.querySelector(selector)) document.querySelector(selector).replaceWith(page.querySelector(selector));
      }
      window.history.replaceState(null, '', url);
    } catch (error) {
      if (error.name !== 'AbortError') window.location.assign(url);
    } finally {
      results.removeAttribute('aria-busy');
    }
  });
}

document.querySelectorAll('[data-repair-picker]').forEach((root) => {
  const choose = picker(root);
  if (root.dataset.historyUrl) intake(root.closest('[data-repair-intake]'), choose);
});
document.querySelectorAll('[data-repair-filters]').forEach(liveFilters);
document.querySelectorAll('[data-repair-open]').forEach((button) => {
  button.addEventListener('click', () => document.getElementById(button.dataset.repairOpen)?.showModal());
});
document.querySelectorAll('[data-repair-close]').forEach((button) => {
  button.addEventListener('click', () => button.closest('dialog').close());
});
document.querySelectorAll('[data-repair-confirm], [data-workflow]').forEach((form) => {
  form.addEventListener('submit', (event) => {
    const status = form.elements.status?.value;
    const message = form.dataset.repairConfirm ?? ({
      COMPLETED: 'Finalize the invoice and lock repair charges?',
      DELIVERED: 'Mark delivered and clear the stored device credential?',
      CANCELLED: 'Cancel this repair?',
      UNREPAIRABLE: 'Close this repair as unrepairable?',
    })[status];
    if (message && !window.confirm(message)) event.preventDefault();
  });
});
document.querySelectorAll('[data-repair-unlock]').forEach((button) => {
  const output = button.parentElement.querySelector('[data-unlock-output]');
  const hide = () => { output.textContent = ''; output.hidden = true; };
  let timer;
  button.addEventListener('click', async () => {
    clearTimeout(timer);
    if (!output.hidden) { hide(); return; }
    button.disabled = true;
    try {
      const credential = await json(button.dataset.repairUnlock, { method: 'POST' });
      output.textContent = credential.value ? credential.type + ' · ' + credential.value : 'No credential stored.';
    } catch (error) {
      output.textContent = error.message;
    } finally {
      output.hidden = false;
      button.disabled = false;
      timer = setTimeout(hide, 15000);
    }
  });
  window.addEventListener('pagehide', hide);
});
document.querySelectorAll('[data-repair-cheque-method]').forEach((form) => {
  const method = form.elements.payment_method_id;
  const fields = form.querySelector('[data-repair-cheque-fields]');
  const update = () => {
    const cheque = method.value === form.dataset.repairChequeMethod;
    fields.hidden = !cheque;
    for (const input of fields.querySelectorAll('input')) {
      input.disabled = !cheque;
      input.required = cheque && input.hasAttribute('data-required');
    }
    if (form.elements.bank_account_id) form.elements.bank_account_id.disabled = cheque;
  };
  method.addEventListener('change', update);
  update();
});
