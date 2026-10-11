const editor = document.getElementById('unit-editor');
document.querySelectorAll('[data-product-reference-dialog]').forEach((dialog) => {
  const form = dialog.querySelector('form');
  const select = document.getElementById(dialog.dataset.select);
  const error = form.querySelector('[data-reference-error]');
  const submit = form.querySelector('[type="submit"]');
  const closeButtons = dialog.querySelectorAll('[data-close-product-reference]');
  let saving = false;
  document
    .querySelector(`[data-open-product-reference="${dialog.id}"]`)
    .addEventListener('click', () => {
      error.hidden = true;
      select.tomselect?.close();
      dialog.showModal();
      form.elements.name.focus();
    });
  closeButtons.forEach((button) => button.addEventListener('click', () => dialog.close()));
  dialog.addEventListener('cancel', (event) => {
    if (saving) event.preventDefault();
  });
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (saving) return;
    form.elements.name.value = form.elements.name.value.trim();
    if (!form.reportValidity()) return;
    saving = true;
    submit.disabled = true;
    closeButtons.forEach((button) => {
      button.disabled = true;
    });
    error.hidden = true;
    try {
      const response = await fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: { Accept: 'application/json' },
      });
      const result = await response.json().catch(() => null);
      if (!response.ok || !result?.id || typeof result.name !== 'string') {
        throw new Error(
          Object.values(result?.errors || {})
            .flat()
            .join(' ') ||
            result?.message ||
            'Unable to save. Please try again.',
        );
      }
      const id = String(result.id);
      if (select.tomselect) {
        select.tomselect.addOption({ value: id, text: result.name });
        if (select.multiple) select.tomselect.addItem(id);
        else select.tomselect.setValue(id);
        select.tomselect.refreshOptions(false);
      } else {
        select.add(new Option(result.name, id, true, true));
        if (!select.multiple) select.value = id;
        select.dispatchEvent(new Event('change', { bubbles: true }));
      }
      const status = editor.querySelector('[data-reference-status]');
      status.textContent = `${result.name} was added and selected.`;
      status.hidden = false;
      form.reset();
      dialog.close();
      if (select.tomselect) select.tomselect.focus();
      else select.focus();
    } catch (failure) {
      error.textContent = failure.message;
      error.hidden = false;
    } finally {
      saving = false;
      submit.disabled = false;
      closeButtons.forEach((button) => {
        button.disabled = false;
      });
    }
  });
});
const rows = document.getElementById('conversion-rows');
const primary = document.getElementById('primary-unit');
const price = document.getElementById('primary-price');
const template = document.getElementById('conversion-template');
const notice = document.getElementById('unit-editor-message');
let nextIndex = 0;
function refresh() {
  const unit = primary.selectedOptions[0]?.dataset.short || 'Primary';
  const used = [...rows.querySelectorAll('[data-field=unit_id]')].map((select) => select.value);
  rows.querySelectorAll('.conversion-row').forEach((row) => {
    row.querySelector('[data-base-label]').textContent = unit;
    const selected = row.querySelector('[data-field=unit_id]');
    for (const option of selected.options) {
      option.disabled =
        option.value !== '' &&
        (option.value === primary.value ||
          (option.value !== selected.value && used.includes(option.value)));
    }
    const calculated = row.querySelector('[data-calculated-price]');
    if (calculated) {
      const base = Number(row.querySelector('[data-field=base_quantity]').value);
      const converted = Number(row.querySelector('[data-field=converted_quantity]').value);
      const amount = (Number(price.value) * base) / converted;
      calculated.textContent =
        Number.isFinite(amount) && converted > 0
          ? `Calculated: ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} / ${selected.selectedOptions[0]?.textContent || 'unit'}`
          : 'Enter both conversion quantities';
    }
  });
  document.getElementById('conversion-empty').hidden = rows.children.length > 0;
  document.getElementById('add-conversion').disabled = !primary.value || rows.children.length >= 20;
  const preset = document.getElementById('unit-preset');
  if (preset) {
    preset.disabled = !primary.value;
    for (const option of preset.options) {
      option.hidden = !!option.value && option.dataset.base !== primary.value;
      option.disabled = !!option.value && option.dataset.base !== primary.value;
    }
  }
}
function add(data = {}) {
  const row = template.content.firstElementChild.cloneNode(true);
  row.querySelectorAll('[data-field]').forEach((input) => {
    input.name = `conversions[${nextIndex}][${input.dataset.field}]`;
    if (data[input.dataset.field] !== undefined && data[input.dataset.field] !== null)
      input.value = ['base_quantity', 'converted_quantity'].includes(input.dataset.field)
        ? String(Number(data[input.dataset.field]))
        : data[input.dataset.field];
  });
  nextIndex++;
  row.querySelector('.remove-conversion').addEventListener('click', () => {
    row.remove();
    refresh();
  });
  rows.append(row);
  refresh();
  window.refreshIcons?.();
}
document.getElementById('add-conversion').addEventListener('click', () => add());
rows.addEventListener('input', refresh);
primary.addEventListener('change', () => {
  if (rows.children.length) {
    rows.replaceChildren();
    notice.hidden = false;
    notice.textContent =
      'Primary unit changed. Add conversions for the new primary unit before saving.';
  }
  const preset = document.getElementById('unit-preset');
  if (preset) preset.value = '';
  refresh();
});
price?.addEventListener('input', refresh);
const presets = document.getElementById('conversion-presets');
document.getElementById('unit-preset')?.addEventListener('change', (event) => {
  const preset = JSON.parse(presets.textContent).find(
    (p) => String(p.id) === event.target.value && String(p.unit_id) === primary.value,
  );
  if (!preset) return;
  rows.replaceChildren();
  preset.conversions.forEach(add);
  notice.hidden = false;
  notice.textContent =
    'Preset loaded. You can adjust these units and prices for this product. Later preset edits do not change this product.';
});
JSON.parse(document.getElementById('conversion-initial').textContent).forEach(add);
refresh();
let previewUrl;
document.getElementById('product-image-input')?.addEventListener('change', (event) => {
  const file = event.target.files[0];
  if (!file || !['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) return;
  if (previewUrl) URL.revokeObjectURL(previewUrl);
  previewUrl = URL.createObjectURL(file);
  const image = document.createElement('img');
  image.src = previewUrl;
  image.alt = 'Selected product image';
  document.getElementById('product-image-preview').replaceChildren(image);
});

const trackStock = document.getElementById('track-stock');
if (trackStock) {
  const refreshTracking = () => {
    const enabled = trackStock.checked;
    document.querySelectorAll('[data-stock-only]').forEach((item) => {
      item.hidden = !enabled;
    });
    document.getElementById('stock-tracking-note').hidden = enabled;
    const low = document.querySelector('[name="low_stock"]');
    if (!enabled && low) low.value = '0';
    const opening = document.querySelector('[data-opening-prices]');
    if (opening) {
      opening.querySelector('h3').textContent = enabled
        ? 'Opening stock & pricing'
        : 'Cost & selling price';
      opening.querySelector('.opening-prices-header p').textContent = enabled
        ? 'Enter one price, or add another row for stock with a different cost or selling price.'
        : 'These prices are used at checkout. No stock quantity is needed.';
      opening.querySelector('[data-add-opening]').hidden = !enabled;
      opening.querySelectorAll('[data-opening-row]').forEach((row, index) => {
        row.hidden = !enabled && index > 0;
        row.querySelectorAll('input').forEach((input) => {
          input.disabled = !enabled && index > 0;
        });
        const quantity = row.querySelector('[name$="[quantity]"]');
        quantity.closest('label').hidden = !enabled;
        if (!enabled) quantity.value = '0';
      });
    }
  };
  trackStock.addEventListener('change', refreshTracking);
  refreshTracking();
}
const discountType = document.getElementById('product-discount-type');
if (discountType) {
  const refreshDiscount = () => {
    const percent = discountType.value === 'PERCENT';
    document.getElementById('product-discount-value-field').hidden = discountType.value === 'NONE';
    document.getElementById('product-discount-value').max = percent ? '100' : '999999999';
    document.getElementById('product-discount-value-label').textContent = percent
      ? 'Discount percentage (%)'
      : 'Fixed discount amount';
    document.getElementById('product-discount-help').textContent = percent
      ? 'The percentage is taken off the entire product line.'
      : 'This amount is taken off once from the entire product line, even when the customer buys several units.';
  };
  discountType.addEventListener('change', refreshDiscount);
  refreshDiscount();
}

const expiryToggle = document.getElementById('track-expiry');
if (expiryToggle) {
  const refreshExpiry = () => {
    const enabled = expiryToggle.checked && trackStock?.checked;
    document.querySelectorAll('[data-expiry-field]').forEach(field => {
      field.hidden = !enabled;
      field.querySelectorAll('input').forEach(input => { input.disabled = !enabled; });
    });
  };
  expiryToggle.addEventListener('change', refreshExpiry);
  trackStock?.addEventListener('change', refreshExpiry);
  new MutationObserver(refreshExpiry).observe(document.querySelector('[data-opening-body]') || document.body, {childList: true, subtree: true});
  refreshExpiry();
}
