const form = document.querySelector('[data-product-popup]');
if (form) {
  const notify = (type, values = {}) => window.parent.postMessage({ type, ...values }, window.location.origin);
  const error = document.querySelector('[data-product-popup-error]');
  let saving = false;
  const initialize = () => {
    const params = new URLSearchParams(window.location.search);
    const name = form.querySelector('[data-product-name-input]');
    if (params.has('prefill_name') && !name.value) {
      name.value = params.get('prefill_name');
      name.dispatchEvent(new Event('input', { bubbles: true }));
    }
    const supplier = form.querySelector('[name=supplier_id]');
    const supplierId = params.get('prefill_supplier');
    if (supplierId && [...supplier.options].some((option) => option.value === supplierId)) {
      if (supplier.tomselect) supplier.tomselect.setValue(supplierId);
      else supplier.value = supplierId;
    }
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
  else initialize();

  form.querySelector('[data-product-popup-cancel]')?.addEventListener('click', () => {
    if (!saving) notify('purchase-product-cancel');
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !saving && !document.querySelector('dialog[open], .ts-wrapper.dropdown-active')) {
      notify('purchase-product-cancel');
    }
  });
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (saving || !form.reportValidity()) return;
    saving = true;
    notify('purchase-product-saving', { busy: true });
    const button = form.querySelector('button[type=submit]');
    const label = button.textContent;
    button.disabled = true;
    button.textContent = 'Creating product…';
    error.hidden = true;
    try {
      const response = await fetch(form.action, {
        method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' },
      });
      const product = await response.json().catch(() => null);
      if (!response.ok || !product?.id || !Array.isArray(product.units)) {
        throw new Error(Object.values(product?.errors || {}).flat().join(' ') || product?.message || 'Unable to create product. Please try again.');
      }
      notify('purchase-product-created', { product });
    } catch (failure) {
      error.textContent = failure.message;
      error.hidden = false;
      error.focus();
      error.scrollIntoView({ block: 'start', behavior: 'smooth' });
    } finally {
      saving = false;
      button.disabled = false;
      button.textContent = label;
      notify('purchase-product-saving', { busy: false });
    }
  });
}
