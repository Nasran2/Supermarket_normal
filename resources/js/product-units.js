const editor = document.getElementById('unit-editor');
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
