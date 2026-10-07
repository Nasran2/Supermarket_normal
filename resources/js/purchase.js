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
  function unit() {
    const opt = product.selectedOptions[0];
    qty.step =
      opt?.dataset.decimal === '1'
        ? String(10 ** -Number(document.getElementById('purchase-form').dataset.quantityPrecision))
        : '1';
    qty.min = qty.step;
  }
  product.addEventListener('change', () => {
    cost.value = product.selectedOptions[0]?.dataset.cost ?? 0;
    unit();
    total();
  });
  line.addEventListener('input', total);
  line.querySelector('.remove-line').addEventListener('click', () => {
    line.remove();
    total();
  });
  container.append(line);
  unit();
  window.refreshIcons?.();
  total();
}
document.getElementById('add-purchase-item').addEventListener('click', () => add());
const initial = JSON.parse(document.getElementById('purchase-initial').textContent);
if (initial.length) initial.forEach(add);
else add();
