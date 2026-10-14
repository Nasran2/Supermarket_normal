import { quantityText } from './units.js';
const dialog = document.getElementById('expiry-writeoff-confirm');
if (dialog) {
  let selectedForm = null;
  let confirmed = false;
  document.querySelectorAll('form[data-expiry-writeoff]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (confirmed && form === selectedForm) return;
      event.preventDefault();
      selectedForm = form;
      const quantity = Number(form.elements.quantity.value);
      document.getElementById('expiry-writeoff-review').textContent =
        `Remove ${quantityText(quantity, form.dataset.unit)} of ${form.dataset.product} (expiry ${form.dataset.expiry})?`;
      const cost = document.getElementById('expiry-writeoff-cost');
      cost.textContent = form.dataset.cost
        ? `Expense at inventory cost: ${form.dataset.currency} ${(quantity * Number(form.dataset.cost) / Number(form.dataset.stock)).toFixed(2)}`
        : 'An expense will be recorded using this batch’s inventory cost.';
      dialog.showModal();
    });
  });
  document.getElementById('expiry-writeoff-cancel').addEventListener('click', () => dialog.close());
  document.getElementById('expiry-writeoff-save').addEventListener('click', () => {
    if (!selectedForm) return;
    confirmed = true;
    dialog.close();
    selectedForm.requestSubmit();
  });
  dialog.addEventListener('close', () => { if (!confirmed) selectedForm = null; });
}
