export function setupTaxBills({ showReceipt, onCustomerSaved = () => {} }) {
  const $ = (id) => document.getElementById(id);
  const chooser = $('bill-type-dialog');
  if (!chooser) return { open() {} };
  const details = $('tax-customer-dialog');
  const form = $('tax-customer-form');
  const select = $('tax-customer-select');
  const printDialog = $('tax-receipt-dialog');
  const printFrame = $('tax-receipt-frame');
  let printVersion = 0,
    downloadUrl;
  const fields = ['name', 'tax_number', 'phone', 'address'];
  const supplierKeys = ['business_name', 'tax_number', 'phone', 'address'];
  const supplierSection = $('tax-supplier-section');
  let sale,
    context,
    supplyEdited = false,
    busy = false,
    loading = 0;
  const field = (name) => form.elements.namedItem(name);
  const notice = (id, message) => {
    $(id).textContent = message;
    $(id).hidden = !message;
  };
  function lock(value) {
    busy = value;
    chooser.querySelectorAll('button').forEach((button) => (button.disabled = value));
    form
      .querySelectorAll('button,input,textarea,select')
      .forEach((input) => (input.disabled = value));
    $('close-tax-customer').disabled = value;
    $('save-tax-customer').textContent = value ? 'Preparing invoice…' : 'Save & print';
    if (!value) {
      supplierKeys.forEach(
        (key) =>
          (field(`supplier_${key}`).disabled =
            supplierSection.hidden || !context?.can_configure_supplier),
      );
    }
  }
  async function json(url, body) {
    const response = await fetch(url, {
      method: body ? 'POST' : 'GET',
      headers: {
        Accept: 'application/json',
        ...(body
          ? {
              'Content-Type': 'application/json',
              'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
            }
          : {}),
      },
      ...(body ? { body: JSON.stringify(body) } : {}),
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) {
      const error = new Error(
        result.errors
          ? Object.values(result.errors).flat().join(' ')
          : result.message || 'Unable to prepare the tax invoice. Please try again.',
      );
      error.validationErrors = result.errors;
      throw error;
    }
    return result;
  }
  async function download(url) {
    const filename = `tax-invoice-${sale.invoice.replace(/[^a-z0-9_-]/gi, '-')}.pdf`;
    const response = await fetch(url, { headers: { Accept: 'application/pdf' } });
    if (!response.ok || !response.headers.get('content-type')?.includes('application/pdf'))
      throw new Error('Unable to download the PDF. Please try the Download PDF button again.');
    const blob = URL.createObjectURL(await response.blob());
    const link = document.createElement('a');
    link.href = blob;
    link.download = filename;
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(blob), 60000);
  }
  function syncPlaceOfSupply() {
    if (!supplyEdited) field('place_of_supply').value = field('address').value.trim();
  }
  field('address').addEventListener('input', syncPlaceOfSupply);
  field('place_of_supply').addEventListener('input', () => {
    supplyEdited = !!field('place_of_supply').value.trim();
  });
  fields.forEach((name) =>
    field(name).addEventListener('input', () => {
      field(name).closest('.field').dataset.missing = String(!field(name).value.trim());
    }),
  );
  function populateSupplier(data) {
    supplierSection.hidden = !data.missing_supplier?.length;
    $('tax-supplier-permission').hidden = !!data.can_configure_supplier;
    $('tax-supplier-fields').hidden = !data.can_configure_supplier;
    supplierKeys.forEach((key) => {
      const input = field(`supplier_${key}`);
      input.value = data.supplier?.[key] || '';
      input.required = !supplierSection.hidden && !!data.can_configure_supplier;
      input.disabled = supplierSection.hidden || !data.can_configure_supplier;
    });
  }
  function focusMissingDetail() {
    const missing = fields.map(field).find((input) => !input.value.trim());
    const supplierMissing = supplierKeys
      .map((key) => field(`supplier_${key}`))
      .find((input) => !input.disabled && !input.value.trim());
    (missing || supplierMissing || select).focus();
  }
  function fillCustomer(customer) {
    fields.forEach((name) => {
      field(name).value = customer?.[name] || '';
      field(name).readOnly = !!customer?.[name]?.trim() && !context.can_edit;
      field(name).closest('.field').dataset.missing = String(!customer?.[name]?.trim());
    });
    syncPlaceOfSupply();
    const labels = {
      name: 'customer name',
      tax_number: 'tax number',
      phone: 'telephone',
      address: 'address',
    };
    const missing = fields.filter((name) => !customer?.[name]?.trim()).map((name) => labels[name]);
    const needsShop = !missing.length && !!context.missing_supplier?.length;
    $('tax-customer-title').textContent = needsShop
      ? 'Complete your shop details'
      : customer
        ? 'Complete customer details'
        : 'Add or select a customer';
    $('tax-customer-help').textContent = needsShop
      ? 'The customer details are ready. Complete your shop details below once to use them on future tax invoices.'
      : customer
        ? missing.length
          ? `Please add ${missing.join(', ')} for ${customer.name}. Saved details are already filled in.`
          : 'Customer details are ready. Check them and print the tax invoice.'
        : 'Choose an existing customer or enter a new customer below. Name, tax number, telephone and address are required for the tax invoice.';
    $('save-tax-customer').disabled = customer
      ? !context.can_edit && !context.can_create && fields.some((name) => !customer[name]?.trim())
      : !context.can_create;
  }
  function populate(data) {
    context = data;
    supplyEdited = false;
    select.replaceChildren();
    if (data.can_create) select.add(new Option('Add a new customer', ''));
    else select.add(new Option('Select a customer', ''));
    data.customers.forEach((customer) =>
      select.add(new Option(customer.name, String(customer.id))),
    );
    if (data.fixed_customer && data.customer)
      select.add(new Option(data.customer.name, String(data.customer.id)));
    select.value = data.customer ? String(data.customer.id) : '';
    $('tax-customer-picker').hidden = data.fixed_customer;
    field('delivery_date').value = data.delivery_date;
    field('place_of_supply').value = data.place_of_supply;
    fillCustomer(data.customer);
    populateSupplier(data);
  }
  function showTaxPrint(result) {
    const version = ++printVersion;
    downloadUrl = result.download_url;
    details.close();
    chooser.close();
    notice('tax-receipt-error', '');
    $('tax-receipt-title').textContent = sale.invoice;
    $('next-sale-tax-label').textContent = $('product-search') ? 'Next sale' : 'Done';
    $('tax-receipt-external').href = result.preview_url;
    $('print-tax-receipt').disabled = true;
    printFrame.onload = () => {
      if (version !== printVersion || !printDialog.open) return;
      if (!printFrame.contentDocument?.querySelector('[data-tax-invoice]')) {
        notice(
          'tax-receipt-error',
          'The invoice preview could not load. Use Open invoice to try again.',
        );
        return;
      }
      printFrame.contentDocument.addEventListener('keydown', handlePrintShortcut);
      printFrame.contentWindow.addEventListener('afterprint', () => {
        if (printDialog.open) $('print-tax-receipt').focus();
      });
      $('print-tax-receipt').disabled = false;
      $('print-tax-receipt').focus();
      printFrame.contentWindow.focus();
      printFrame.contentWindow.print();
    };
    printDialog.showModal();
    printFrame.src = result.preview_url + '?embed=1';
  }
  function closeTaxPrint() {
    ++printVersion;
    printFrame.onload = null;
    printDialog.close();
    $('product-search')?.focus();
  }
  function handlePrintShortcut(event) {
    if (
      !printDialog.open ||
      event.defaultPrevented ||
      event.repeat ||
      event.altKey ||
      event.ctrlKey ||
      event.metaKey
    )
      return;
    if (event.key === 'Escape') {
      event.preventDefault();
      event.stopPropagation();
      closeTaxPrint();
      return;
    }
    if (event.target.closest?.('input, textarea, select, [contenteditable="true"]')) return;
    const action = {
      1: 'tax-receipt-external',
      2: 'download-tax-receipt',
      3: 'print-tax-receipt',
      4: 'next-sale-tax',
    }[event.key];
    if (!action) return;
    event.preventDefault();
    event.stopPropagation();
    const button = $(action);
    if (!button.disabled) button.click();
  }
  printDialog.addEventListener('keydown', handlePrintShortcut);
  ['close-tax-receipt', 'next-sale-tax'].forEach((id) =>
    $(id).addEventListener('click', closeTaxPrint),
  );
  printDialog.addEventListener('cancel', (event) => {
    event.preventDefault();
    closeTaxPrint();
  });
  $('print-tax-receipt').addEventListener('click', () => {
    printFrame.contentWindow.focus();
    printFrame.contentWindow.print();
  });
  $('download-tax-receipt').addEventListener('click', async () => {
    const button = $('download-tax-receipt');
    if (button.disabled) return;
    button.disabled = true;
    notice('tax-receipt-error', '');
    try {
      await download(downloadUrl);
    } catch (error) {
      notice('tax-receipt-error', error.message);
    } finally {
      button.disabled = false;
    }
  });
  async function issue(data) {
    const result = await json(sale.tax_store_url, data);
    if (result.customer) onCustomerSaved(result.customer);
    showTaxPrint(result);
  }
  function back() {
    if (busy) return;
    loading++;
    details.close();
    chooser.showModal();
    $('bill-tax').focus();
  }
  function close() {
    if (busy) return;
    loading++;
    chooser.close();
    $('product-search')?.focus();
  }
  ['close-bill-type', 'cancel-bill-type'].forEach((id) => $(id).addEventListener('click', close));
  chooser.addEventListener('cancel', (event) => {
    event.preventDefault();
    close();
  });
  chooser.addEventListener('keydown', (event) => {
    if (event.altKey || event.ctrlKey || event.metaKey || event.repeat || busy) return;
    if (event.key === '1' || event.key === '2') {
      event.preventDefault();
      $(event.key === '1' ? 'bill-normal' : 'bill-tax').click();
    }
  });
  $('bill-normal').addEventListener('click', () => {
    if (busy) return;
    chooser.close();
    showReceipt({ ...sale, show_receipt: true, auto_print: true });
  });
  $('bill-tax').addEventListener('click', async () => {
    if (busy) return;
    lock(true);
    notice('bill-type-error', '');
    form.reset();
    notice('tax-customer-error', '');
    try {
      const data = await json(sale.tax_context_url);
      if (data.issued) {
        showTaxPrint(data);
        return;
      }
      populate(data);
      if (
        data.customer &&
        fields.every((name) => data.customer[name]?.trim()) &&
        !data.missing_supplier.length
      ) {
        await issue({
          ...data.customer,
          customer_id: data.customer.id,
          delivery_date: data.delivery_date,
          place_of_supply: field('place_of_supply').value,
        });
      } else {
        chooser.close();
        details.showModal();
      }
    } catch (error) {
      notice('bill-type-error', error.message);
    } finally {
      lock(false);
      if (details.open) {
        fillCustomer(context.customer);
        focusMissingDetail();
      }
    }
  });
  select.addEventListener('change', async () => {
    const current = ++loading;
    notice('tax-customer-error', '');
    $('save-tax-customer').disabled = true;
    if (!select.value) {
      fillCustomer(null);
      return;
    }
    fields.forEach((name) => (field(name).readOnly = true));
    try {
      const url = new URL(sale.tax_context_url, location.href);
      url.searchParams.set('customer_id', select.value);
      const data = await json(url);
      if (current !== loading || !details.open) return;
      context = data;
      fillCustomer(data.customer);
    } catch (error) {
      if (current === loading) notice('tax-customer-error', error.message);
    }
  });
  ['close-tax-customer', 'cancel-tax-customer'].forEach((id) =>
    $(id).addEventListener('click', back),
  );
  details.addEventListener('cancel', (event) => {
    event.preventDefault();
    back();
  });
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (busy) return;
    fields.forEach((name) => (field(name).value = field(name).value.trim()));
    if (!field('place_of_supply').value.trim())
      field('place_of_supply').value = field('address').value;
    const extra = form.querySelector('details');
    if (!field('delivery_date').value || !field('place_of_supply').value.trim()) extra.open = true;
    if (!form.reportValidity()) return;
    const data = Object.fromEntries(new FormData(form));
    if (!supplierSection.hidden && context.can_configure_supplier) {
      data.supplier = Object.fromEntries(
        supplierKeys.map((key) => [key, field(`supplier_${key}`).value.trim()]),
      );
    }
    supplierKeys.forEach((key) => delete data[`supplier_${key}`]);
    lock(true);
    notice('tax-customer-error', '');
    try {
      await issue(data);
    } catch (error) {
      notice('tax-customer-error', error.message);
      if (error.validationErrors?.supplier) {
        try {
          const fresh = await json(sale.tax_context_url);
          context.can_configure_supplier = fresh.can_configure_supplier;
          populateSupplier(fresh);
        } catch {
          /* Keep the entered customer details available for retry. */
        }
      }
    } finally {
      lock(false);
    }
  });
  return {
    open(result) {
      if (busy || !result.tax_context_url) return;
      sale = result;
      notice('bill-type-error', '');
      $('bill-type-invoice').textContent = sale.invoice;
      chooser.showModal();
      $('bill-normal').focus();
    },
  };
}
