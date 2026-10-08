const container = document.getElementById('purchase-items'),
  template = document.getElementById('purchase-item-template');
let index = 0;
function total() {
  let amount = 0;
  container.querySelectorAll('.purchase-line').forEach((line) => {
    amount +=
      Number(line.querySelector('[data-field=quantity]').value) *
      Number(line.querySelector('[data-field=cost]').value);
  });
  document.getElementById('purchase-total').textContent = amount.toLocaleString(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}
function add(initial = {}) {
  const line = template.content.firstElementChild.cloneNode(true);
  line.querySelectorAll('[data-field]').forEach((input) => {
    input.name = `items[${index}][${input.dataset.field}]`;
    if (initial[input.dataset.field] !== undefined) input.value = initial[input.dataset.field];
  });
  index++;
  const product = line.querySelector('[data-field=product_id]'),
    qty = line.querySelector('[data-field=quantity]'),
    cost = line.querySelector('[data-field=cost]');
  const unitSelect = line.querySelector('[data-field=unit_id]');
  function unit(keepInitial = false) {
    const options = JSON.parse(product.selectedOptions[0]?.dataset.units || '[]');
    const selectedId = keepInitial ? initial.unit_id : null;
    unitSelect.replaceChildren();
    options.forEach((u) => {
      const option = document.createElement('option');
      option.value = u.id;
      option.textContent = u.name + ' (' + u.short_name + ')';
      option.dataset.decimal = u.decimal ? '1' : '0';
      option.dataset.cost = u.cost;
      unitSelect.append(option);
    });
    if (selectedId) {
      if (options.some((u) => String(u.id) === String(selectedId))) unitSelect.value = selectedId;
      else {
        const missing = document.createElement('option');
        missing.value = '';
        missing.textContent = 'Previous unit unavailable — select a unit';
        unitSelect.prepend(missing);
        unitSelect.value = '';
      }
    }
    if (!options.length) {
      const option = document.createElement('option');
      option.value = '';
      option.textContent = 'Select unit';
      unitSelect.append(option);
    }
    updatePrecision();
  }
  function updatePrecision() {
    const opt = unitSelect.selectedOptions[0];
    qty.step =
      opt?.dataset.decimal === '1'
        ? String(10 ** -Number(document.getElementById('purchase-form').dataset.quantityPrecision))
        : '1';
    qty.min = qty.step;
  }
  product.addEventListener('change', () => {
    qty.value = '1';
    unit();
    cost.value = unitSelect.selectedOptions[0]?.dataset.cost ?? 0;
    total();
  });
  unitSelect.addEventListener('change', () => {
    cost.value = unitSelect.selectedOptions[0]?.dataset.cost ?? 0;
    qty.value = '1';
    updatePrecision();
    total();
  });
  line.addEventListener('input', total);
  line.querySelector('.remove-line').addEventListener('click', () => {
    line.remove();
    total();
  });
  container.append(line);
  unit(true);
  window.refreshIcons?.();
  total();
}
document.getElementById('add-purchase-item').addEventListener('click', () => add());
const initial = JSON.parse(document.getElementById('purchase-initial').textContent);
if (initial.length) initial.forEach(add);
else add();
