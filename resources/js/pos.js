import { draftStore } from './pos-draft.js';
import { sellingPriceOptions, priceOptionForItem, stockChoiceKey } from './pos-price-options.js';
const pos = document.getElementById('pos');
const $ = (id) => document.getElementById(id),
  money = (v) => {
    const decimals =
      Math.round(Number(v) * 100) % 100 !== 0
        ? Math.max(2, Number(pos.dataset.decimals))
        : Number(pos.dataset.decimals);
    return `${pos.dataset.currency} ${Number(v).toLocaleString(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals })}`;
  };
const editSeed = $('sale-edit-seed') ? JSON.parse($('sale-edit-seed').textContent) : null;
const editMode = !!editSeed;
let pendingCompletion = null;
let products = [],
  cart = [],
  quote = null,
  searchTimer,
  requestNumber = 0,
  quoteNumber = 0,
  token = crypto.randomUUID(),
  pending = false,
  orderLoading = false,
  checkoutAttempted = false,
  restoreNumber = 0,
  billDiscount = { type: 'AMOUNT', value: '0' };
const dialog = $('payment-dialog'),
  message = $('pos-message'),
  error = $('payment-error');
const escape = (v) =>
  String(v).replace(
    /[&<>"']/g,
    (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c],
  );
function scaled(value, places) {
  let text = String(value || '0'),
    sign = 1n;
  if (!/^-?\d*(?:\.\d*)?$/.test(text)) return 0n;
  if (text.startsWith('-')) {
    sign = -1n;
    text = text.slice(1);
  }
  const [whole, fraction = ''] = text.split('.');
  return (
    sign *
    (BigInt(whole || '0') * 10n ** BigInt(places) +
      BigInt(fraction.padEnd(places, '0').slice(0, places) || '0'))
  );
}
const grossLineCents = (p) =>
  (scaled(p.unit_price ?? p.price, 2) * scaled(p.quantity, 3) + 500n) / 1000n;
const lineDiscountCents = (p) =>
  p.discount_type === 'PERCENT'
    ? (grossLineCents(p) * scaled(p.discount_value, 2) + 5000n) / 10000n
    : scaled(p.discount_value, 2);
const lineCents = (p) => grossLineCents(p) - lineDiscountCents(p);
const draftKey = `twinsofte-pos-draft:${new URL(pos.dataset.productsUrl).pathname}:${pos.dataset.userId}`;
let store;
try {
  if (!editMode) store = draftStore(localStorage, draftKey);
} catch {
  /* Storage may be disabled. */
}
const defaultCustomer = $('customer').value;
function saveDraft() {
  if (editMode) return;
  try {
    if (!store) throw new Error('Storage unavailable');
    if (!cart.length) store.clear();
    else
      store.write({
        token,
        attempted: checkoutAttempted,
        customer: $('customer').value,
        billDiscount,
        items: cart.map((p) => ({
          id: p.id,
          line_key: p.line_key,
          stock_price: p.stock_price,
          stock_layer_id: p.stock_layer_id ?? null,
          stock_reference: p.stock_reference ?? '',
          name: p.name,
          unit_id: p.unit_id,
          unit: p.unit,
          decimal: p.decimal,
          price: p.price,
          quantity: String(p.quantity),
          unit_price: p.unit_price ?? null,
          discount_type: p.discount_type || 'AMOUNT',
          discount_value: String(p.discount_value || '0'),
        })),
      });
  } catch {
    message.textContent =
      'This browser cannot save the cart. Keep this page open until the order is complete.';
  }
}
function resetOrder() {
  cart = [];
  quote = null;
  pendingCompletion = null;
  checkoutAttempted = false;
  token = crypto.randomUUID();
  billDiscount = { type: 'AMOUNT', value: '0' };
  $('discount').value = '0';
  $('customer').value = defaultCustomer;
  updateCustomerDue();
  render();
}
async function completedDraft() {
  const params = new URLSearchParams({ checkout_token: token });
  const response = await fetch(`${pos.dataset.statusUrl}?${params}`, {
    headers: { Accept: 'application/json' },
  });
  if (!response.ok) throw new Error('Unable to check the previous payment. Reload to try again.');
  return response.json();
}
async function restoreDraft() {
  if (pending) return;
  if (editMode) {
    if (checkoutAttempted) location.reload();
    else loadInvoice();
    return;
  }
  const current = ++restoreNumber;
  let saved;
  try {
    saved = store?.read();
  } catch {
    message.textContent = 'The saved cart could not be read. Clear this order to start again.';
    orderLoading = true;
    render(false);
    return;
  }
  if (!saved) {
    orderLoading = false;
    cart = [];
    billDiscount = { type: 'AMOUNT', value: '0' };
    checkoutAttempted = false;
    token = crypto.randomUUID();
    $('customer').value = defaultCustomer;
    updateCustomerDue();
    render(false);
    return;
  }
  token = saved.token;
  checkoutAttempted = !!saved.attempted;
  cart = saved.items.map((item) => ({ ...item, line_key: item.line_key || crypto.randomUUID() }));
  billDiscount = saved.billDiscount || { type: 'AMOUNT', value: '0' };
  $('customer').value = [...$('customer').options].some((o) => o.value === saved.customer)
    ? saved.customer
    : defaultCustomer;
  updateCustomerDue();
  orderLoading = true;
  render(false);
  try {
    if (checkoutAttempted) {
      const result = await completedDraft();
      if (current !== restoreNumber) return;
      if (result.completed) {
        orderLoading = false;
        resetOrder();
        message.textContent = `Sale ${result.invoice} was completed. Your cart has been cleared.`;
        return;
      }
      checkoutAttempted = false;
    }
    const params = new URLSearchParams();
    cart.forEach((p, i) => params.set(`ids[${i}]`, p.id));
    const response = await fetch(`${pos.dataset.productsUrl}?${params}`, {
      headers: { Accept: 'application/json' },
    });
    if (!response.ok) throw new Error('Unable to refresh saved products.');
    const available = await response.json();
    if (current !== restoreNumber) return;
    cart = saved.items.map((item) => {
      const fresh = available.find((p) => p.id === item.id);
      const group = priceOptionForItem(fresh, item);
      const unit = group?.units.find((u) => u.id === item.unit_id);
      return {
        ...item,
        line_key: item.line_key || crypto.randomUUID(),
        stock_price: group?.stock_price ?? item.stock_price,
        stock_reference: item.stock_layer_id != null ? (group?.reference ?? item.stock_reference ?? '') : '',
        units: group?.units || [],
        unavailable: !unit,
        ...(unit
          ? { name: fresh.name, unit: unit.short_name, decimal: unit.decimal, price: unit.price }
          : {}),
      };
    });
    orderLoading = false;
    message.textContent = cart.some((p) => p.unavailable)
      ? 'Some saved items or units are unavailable. Edit their unit or remove them before payment.'
      : 'Saved order restored.';
    render();
  } catch (error) {
    if (current !== restoreNumber) return;
    message.textContent = `${error.message} Your cart is still saved; reload to retry.`;
    render(false);
  }
}
window.addEventListener('pageshow', (event) => {
  if (event.persisted) {
    if (editMode) location.reload();
    else restoreDraft();
  }
});
window.addEventListener('storage', (event) => {
  if (editMode || event.key !== draftKey || pending) return;
  $('line-dialog')?.close();
  $('bill-discount-dialog')?.close();
  dialog.close();
  restoreDraft();
});
const subtotalCents = () => cart.reduce((total, p) => total + lineCents(p), 0n);
const subtotal = () => Number(subtotalCents()) / 100;
const billDiscountCents = (discount = billDiscount) =>
  discount.type === 'PERCENT'
    ? (subtotalCents() * scaled(discount.value, 2) + 5000n) / 10000n
    : scaled(discount.value, 2);
const cartTotal = () => Math.max(0, Number(subtotalCents() - billDiscountCents()) / 100);
const invalidBillDiscount = () => billDiscountCents() > subtotalCents();
function renderSummary() {
  $('cart-subtotal').textContent = money(subtotal());
  $('cart-total').textContent = money(cartTotal());
  const amount = billDiscountCents();
  $('discount').value = (Number(amount) / 100).toFixed(2);
  if ($('edit-bill-discount')) {
    $('edit-bill-discount').disabled = pos.dataset.canDiscount !== '1' || !cart.length || orderLoading;
    $('bill-discount-label').textContent =
      amount > 0n
        ? billDiscount.type === 'PERCENT'
          ? `${Number(billDiscount.value)}% · Edit`
          : 'Edit'
        : 'Add';
    $('bill-discount-amount').hidden = amount === 0n;
    $('bill-discount-amount').textContent = `−${money(Number(amount) / 100)}`;
    $('bill-discount-warning').hidden = !cart.length || !invalidBillDiscount();
  }
  $('payment-bill-discount').hidden = amount === 0n;
  $('payment-bill-discount-amount').textContent = `−${money(Number(amount) / 100)}`;
}
const moneyHTML = (value) => escape(money(value));
const payload = () => ({
  ...(editMode ? { version: editSeed.version, notes: $('edit-notes').value } : {}),
  items: cart.map((p) => ({
    product_id: p.id,
    stock_price: p.stock_price,
    stock_layer_id: p.stock_layer_id ?? null,
    unit_id: p.unit_id,
    quantity: String(p.quantity),
    unit_price: p.unit_price ?? null,
    discount_type: p.discount_type || 'AMOUNT',
    discount_value: p.discount_value || '0',
  })),
  bill_discount_type: billDiscount.type,
  bill_discount_value: billDiscount.value,
  customer_id: $('customer').value || null,
  payments: payments.map((p) => ({
    payment_method_id: p.id,
    amount: p.amount,
    reference: p.reference || null,
  })),
});
function showError(text) {
  error.textContent = text;
  error.hidden = false;
}
async function api(url, data, failureMessage = 'Unable to save this order. Please try again.') {
  const response = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
    },
    body: JSON.stringify(data),
  });
  let result;
  try {
    result = await response.json();
  } catch {
    throw new Error('Unable to reach the server. Check your connection.');
  }
  if (!response.ok) {
    const failure = new Error(
      result.errors ? Object.values(result.errors).flat().join(' ') : failureMessage,
    );
    failure.validationErrors = result.errors;
    throw failure;
  }
  return result;
}
async function search(scanner = false) {
  const current = ++requestNumber;
  $('product-count').textContent = 'Loading products…';
  try {
    const params = new URLSearchParams({
      q: $('product-search').value,
      category_id: $('product-category').value,
      scan: scanner === true ? '1' : '0',
    });
    const response = await fetch(`${pos.dataset.productsUrl}?${params}`, {
      headers: { Accept: 'application/json' },
    });
    if (!response.ok) throw new Error('Unable to load products. Please sign in again or retry.');
    const data = await response.json();
    if (current !== requestNumber) return;
    products = data;
    $('product-count').textContent =
      `${products.length}${products.length === 60 ? '+' : ''} products`;
    $('product-results').innerHTML = products.length
      ? products
          .map(
            (p) =>
              `<button class="product-tile" data-product="${p.id}" type="button">${p.image ? `<img src="${escape(p.image)}" alt="${escape(p.name)}">` : `<span class="product-art"><i data-lucide="package"></i></span>`}<strong>${escape(p.name)}</strong><small>${escape(p.category)} · ${escape(p.sku)}</small><span class="tile-bottom"><b>${priceRange(p)}</b><span>${Number(p.stock)} ${escape(p.unit)}</span></span></button>`,
          )
          .join('')
      : '<div class="empty-state col-span-full"><i data-lucide="search-x"></i><h3>No products found</h3><p>Add products or change your search.</p></div>';
    window.refreshIcons?.();
  } catch (e) {
    $('product-results').textContent = e.message;
    $('product-count').textContent = 'Products unavailable';
  }
}
function priceRange(p) {
  const prices = sellingPriceOptions(p).map((group) => Number(group.stock_price));
  if (!prices.length) return moneyHTML(p.price);
  const low = Math.min(...prices), high = Math.max(...prices);
  return low === high ? moneyHTML(low) : `${moneyHTML(low)} – ${moneyHTML(high)}`;
}
const priceDialog = $('price-choice-dialog');
let choosingProduct = null;
let choosingPrices = [];
function add(p) {
  if (!p || orderLoading || pending) return;
  if (checkoutAttempted) { dialog.close(); restoreDraft(); return; }
  if (!syncQuantities()) return;
  const groups = sellingPriceOptions(p);
  if (!groups.length) { message.textContent = `${p.name} is out of stock.`; return; }
  if (groups.length === 1) { addPrice(p, groups[0]); return; }
  choosingProduct = p;
  choosingPrices = groups;
  $('price-choice-product').textContent = p.name;
  $('price-choice-options').innerHTML = groups.map((group, index) => `<button type="button" class="price-choice" data-price-choice="${index}"><span class="price-choice-rate"><small>Selling price / ${escape(p.unit)}</small><strong>${moneyHTML(group.stock_price)}</strong>${group.reference?`<small class="price-choice-source">${escape(group.reference)}</small>`:''}${group.received?`<small class="price-choice-received">${escape(group.received)}</small>`:''}</span><span class="price-choice-stock"><strong>${Number(group.quantity).toLocaleString()} ${escape(p.unit)}</strong><small>Available stock</small><span class="price-choice-add">Add to cart <kbd>${index + 1}</kbd></span></span></button>`).join('');
  priceDialog.showModal();
  $('price-choice-options').querySelector('button').focus();
}
function addPrice(p, group) {
  const unit = group.units.find((option) => option.id === p.unit_id);
  const key = stockChoiceKey({id:p.id, unit_id:p.unit_id, stock_price:group.stock_price, stock_layer_id:group.stock_layer_id});
  const existing = cart.find((item) => stockChoiceKey(item) === key);
  const maxQty = Number(group.quantity);
  
  if (existing) {
    if (Number(existing.quantity) + 1 > maxQty) {
      message.textContent = `Only ${maxQty} available.`;
      return;
    }
    existing.quantity = Number((Number(existing.quantity) + 1).toFixed(3));
  }
  else {
    if (1 > maxQty) {
      message.textContent = `Only ${maxQty} available.`;
      return;
    }
    cart.push({ ...p, line_key: crypto.randomUUID(), stock_price: group.stock_price, stock_layer_id: group.stock_layer_id ?? null, stock_reference: group.reference ?? '', max_quantity: maxQty, units: group.units, price: unit.price, quantity: 1, unit_price: null, discount_type: 'AMOUNT', discount_value: '0' });
  }
  quote = null;
  message.textContent = '';
  render();
}
function selectPrice(index) {
  const group = choosingPrices[index];
  if (!group) return;
  addPrice(choosingProduct, group);
  priceDialog.close();
  $('product-search').focus({ preventScroll: true });
}
$('price-choice-options').addEventListener('click', (event) => {
  const button = event.target.closest('[data-price-choice]');
  if (button) selectPrice(Number(button.dataset.priceChoice));
});
$('close-price-choice').addEventListener('click', () => priceDialog.close());
priceDialog.addEventListener('close', () => {
  choosingProduct = null;
  choosingPrices = [];
  $('product-search').focus({ preventScroll: true });
});
priceDialog.addEventListener('keydown', (event) => {
  if (/^[1-9]$/.test(event.key)) { event.preventDefault(); selectPrice(Number(event.key) - 1); }
  if (['ArrowDown', 'ArrowUp'].includes(event.key)) {
    event.preventDefault();
    const buttons = [...$('price-choice-options').querySelectorAll('button')];
    const index = buttons.indexOf(document.activeElement);
    buttons[(index + (event.key === 'ArrowDown' ? 1 : buttons.length - 1)) % buttons.length].focus();
  }
});
function render(persist = true) {
  if (!cart.length) billDiscount = { type: 'AMOUNT', value: '0' };
  $('cart-count').textContent = cart.length;
  $('cart-items').innerHTML = cart.length
    ? cart
        .map(
          (p) =>
            `<div class="cart-line compact-cart-line" data-cart-id="${p.line_key}"><button class="cart-item-name ${p.unavailable ? 'unavailable' : ''}" data-action="edit" type="button" title="${escape(p.name)} · ${escape(p.unit)} · Edit price and line discount" aria-label="Edit ${escape(p.name)}" ${orderLoading ? 'disabled' : ''}><span>${escape(p.name)}</span><small>${escape(p.unit)} · ${moneyHTML(p.unit_price ?? p.price)}${p.stock_reference?` · ${escape(p.stock_reference)}`:''}</small></button><div class="cart-qty-controls"><button class="icon-button" data-action="minus" type="button" aria-label="Decrease quantity for ${escape(p.name)}" ${orderLoading ? 'disabled' : ''}>−</button><input class="cart-quantity" type="number" required max="999999" min="${p.decimal ? String(10 ** -Number(pos.dataset.quantityPrecision)) : '1'}" step="${p.decimal ? String(10 ** -Number(pos.dataset.quantityPrecision)) : '1'}" value="${p.quantity}" aria-label="Quantity for ${escape(p.name)}" ${orderLoading ? 'disabled' : ''}><button class="icon-button" data-action="plus" type="button" aria-label="Increase quantity for ${escape(p.name)}" ${orderLoading ? 'disabled' : ''}>+</button></div><strong class="cart-line-amount" title="${moneyHTML(Number(lineCents(p)) / 100)}">${moneyHTML(Number(lineCents(p)) / 100)}</strong><button class="icon-button cart-remove" ${orderLoading ? 'disabled' : ''} data-action="remove" title="Remove item" aria-label="Remove ${escape(p.name)}" type="button"><i data-lucide="x" style="width:14px"></i></button></div>`,
        )
        .join('')
    : '<div class="cart-empty"><i data-lucide="shopping-basket" style="width:35px;height:35px"></i><strong>Your order is empty</strong><small>Scan a barcode or choose a product.</small></div>';
  renderSummary();
  $('open-payment').disabled = pos.dataset.canCheckout !== '1' ||
    invalidBillDiscount() ||
    !cart.length ||
    orderLoading ||
    cart.some((p) => p.unavailable || lineCents(p) < 0n) ||
    pos.dataset.register !== '1';
  window.refreshIcons?.();
  if (persist) saveDraft();
}
$('product-results').addEventListener('click', (e) => {
  const button = e.target.closest('[data-product]');
  if (button) add(products.find((p) => p.id === Number(button.dataset.product)));
});
$('product-search').addEventListener('input', () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(search, 220);
});
document.querySelectorAll('[data-category]').forEach((button) =>
  button.addEventListener('click', () => {
    $('product-category').value = button.dataset.category;
    document.querySelectorAll('[data-category]').forEach((b) => {
      b.classList.toggle('selected', b === button);
      b.setAttribute('aria-pressed', String(b === button));
    });
    search();
  }),
);
$('product-search').addEventListener('keydown', async (e) => {
  if (e.key === 'Enter' && pos.dataset.barcode === '1') {
    e.preventDefault();
    clearTimeout(searchTimer);
    const barcode = $('product-search').value;
    await search(true);
    const p = products.find((p) => p.barcode === barcode || p.sku === barcode);
    if (p) {
      add(p);
      $('product-search').value = '';
      search();
    } else
      message.textContent = 'No exact barcode or SKU match. Select a product from the results.';
  }
});
$('cart-items').addEventListener('click', (e) => {
  const button = e.target.closest('[data-action]');
  if (!button) return;
  const line = button.closest('[data-cart-id]'),
    p = cart.find((p) => p.line_key === line.dataset.cartId);
  if (orderLoading || pending) return;
  if (checkoutAttempted) {
    dialog.close();
    restoreDraft();
    return;
  }
  if (button.dataset.action === 'edit') {
    openLine(p);
    return;
  }
  if (!syncQuantities() && button.dataset.action !== 'remove') return;
  if (button.dataset.action === 'remove') cart = cart.filter((i) => i.line_key !== p.line_key);
  else {
    let newQty = Number(
      (Number(p.quantity) + (button.dataset.action === 'plus' ? 1 : -1)).toFixed(3),
    );
    if (button.dataset.action === 'plus' && 'max_quantity' in p && newQty > p.max_quantity) {
      message.textContent = `Only ${p.max_quantity} available.`;
      return;
    }
    p.quantity = newQty;
    if (p.quantity <= 0) cart = cart.filter((i) => i.line_key !== p.line_key);
    if (Number(p.quantity) > 999999) p.quantity = '999999';
  }
  quote = null;
  render();
});
function validQuantity(input) {
  input.setCustomValidity('');
  if (!/^\d+(?:\.\d{1,3})?$/.test(input.value))
    input.setCustomValidity('Enter a whole number or decimal quantity.');
  return input.checkValidity();
}
function syncQuantities() {
  for (const input of document.querySelectorAll('.cart-quantity')) {
    if (!validQuantity(input)) {
      input.reportValidity();
      message.textContent = 'Enter a valid quantity for this unit.';
      return false;
    }
    const p = cart.find((p) => p.line_key === input.closest('[data-cart-id]').dataset.cartId);
    let newQty = Number(input.value);
    if ('max_quantity' in p && newQty > p.max_quantity) {
      message.textContent = `Only ${p.max_quantity} available.`;
      input.value = p.max_quantity;
      newQty = p.max_quantity;
    }
    p.quantity = newQty;
  }
  return true;
}
$('cart-items').addEventListener('input', (e) => {
  if (e.target.matches('.cart-quantity')) {
    if (checkoutAttempted) {
      dialog.close();
      restoreDraft();
      return;
    }
    const input = e.target;
    if (!validQuantity(input)) return;
    const p = cart.find((p) => p.line_key === input.closest('[data-cart-id]').dataset.cartId);
    let newQty = Number(input.value);
    if ('max_quantity' in p && newQty > p.max_quantity) {
      message.textContent = `Only ${p.max_quantity} available.`;
      input.value = p.max_quantity;
      newQty = p.max_quantity;
    }
    p.quantity = newQty;
    quote = null;
    input.closest('.cart-line').querySelector('.cart-line-amount').textContent = money(
      Number(lineCents(p)) / 100,
    );
    renderSummary();
    $('open-payment').disabled = pos.dataset.canCheckout !== '1' ||
      invalidBillDiscount() ||
      lineCents(p) < 0n ||
      cart.some((item) => item.unavailable || lineCents(item) < 0n);
    saveDraft();
  }
});
const billDialog = $('bill-discount-dialog');
if (billDialog) {
  function previewBillDiscount() {
    const proposed = {
      type: $('bill-discount-type').value,
      value: $('bill-discount-value').value || '0',
    };
    $('bill-preview-subtotal').textContent = money(subtotal());
    $('bill-preview-discount').textContent = `−${money(Number(billDiscountCents(proposed)) / 100)}`;
    $('bill-preview-total').textContent = money(
      Math.max(0, Number(subtotalCents() - billDiscountCents(proposed)) / 100),
    );
  }
  $('edit-bill-discount').addEventListener('click', () => {
    if (pending || orderLoading || !cart.length || document.querySelector('dialog[open]')) return;
    if (checkoutAttempted) {
      restoreDraft();
      return;
    }
    if (!syncQuantities()) return;
    $('bill-discount-type').value = billDiscount.type;
    $('bill-discount-value').value = billDiscount.value;
    $('bill-discount-value').max = billDiscount.type === 'PERCENT' ? '100' : '999999999';
    $('bill-discount-value').setCustomValidity('');
    previewBillDiscount();
    billDialog.showModal();
    $('bill-discount-value').focus();
    $('bill-discount-value').select();
  });
  for (const id of ['close-bill-discount', 'cancel-bill-discount'])
    $(id).addEventListener('click', () => billDialog.close());
  for (const id of ['bill-discount-type', 'bill-discount-value'])
    $(id).addEventListener('input', () => {
      $('bill-discount-value').setCustomValidity('');
      $('bill-discount-value').max =
        $('bill-discount-type').value === 'PERCENT' ? '100' : '999999999';
      previewBillDiscount();
    });
  $('remove-bill-discount').addEventListener('click', () => {
    billDiscount = { type: 'AMOUNT', value: '0' };
    quote = null;
    billDialog.close();
    render();
  });
  $('bill-discount-form').addEventListener('submit', (event) => {
    event.preventDefault();
    const input = $('bill-discount-value');
    input.setCustomValidity('');
    const proposed = { type: $('bill-discount-type').value, value: input.value };
    if (!/^\d+(?:\.\d{1,2})?$/.test(input.value))
      input.setCustomValidity('Enter a discount with up to two decimal places.');
    else if (proposed.type === 'PERCENT' && scaled(input.value, 2) > 10000n)
      input.setCustomValidity('Percentage cannot exceed 100%.');
    else if (billDiscountCents(proposed) > subtotalCents())
      input.setCustomValidity('Discount cannot exceed the subtotal after line discounts.');
    if (!$('bill-discount-form').reportValidity()) return;
    billDiscount = proposed;
    quote = null;
    billDialog.close();
    render();
  });
}
const lineDialog = $('line-dialog');
let editingLine = null;
function openLine(item) {
  if (orderLoading || document.querySelector('dialog[open]')) return;
  editingLine = item;
  $('line-editor-title').textContent = item.name;
  $('line-editor-product').textContent = 'Edit this item. The cart displays only its final amount.';
  const select = $('line-unit');
  select.replaceChildren();
  (item.units || []).forEach((unit) => {
    const option = document.createElement('option');
    option.value = unit.id;
    option.textContent = `${unit.name} (${unit.short_name})`;
    select.append(option);
  });
  select.value = item.unit_id;
  $('line-quantity').value = item.quantity;
  $('line-price').value = item.unit_price ?? item.price;
  $('line-discount-type').value = item.discount_type || 'AMOUNT';
  $('line-discount-value').value = item.discount_value || '0';
  $('line-editor-error').hidden = true;
  updateLineEditor();
  lineDialog.showModal();
  $('line-price').focus({ preventScroll: true });
  lineDialog.scrollTop = 0;
}
function currentLineEdit() {
  const unit = editingLine?.units?.find((u) => u.id === Number($('line-unit').value));
  const price = $('line-price').value;
  return {
    ...editingLine,
    unit_id: unit?.id,
    unit: unit?.short_name,
    decimal: unit?.decimal,
    price: unit?.price || '0',
    unit_price: unit && scaled(price, 2) === scaled(unit.price, 2) ? null : price,
    quantity: $('line-quantity').value,
    discount_type: $('line-discount-type').value,
    discount_value: $('line-discount-value').value || '0',
    unavailable: !unit,
  };
}
function updateLineEditor() {
  if (!editingLine) return;
  const item = currentLineEdit();
  const step = item.decimal ? String(10 ** -Number(pos.dataset.quantityPrecision)) : '1';
  $('line-quantity').step = step;
  $('line-quantity').min = step;
  $('line-discount-value').max = item.discount_type === 'PERCENT' ? '100' : '999999999';
  $('line-catalog-price').textContent =
    `Catalogue price: ${money(item.price)} / ${item.unit || 'unit'}`;
  $('line-preview-total').textContent =
    lineCents(item) < 0n ? 'Review discount' : money(Number(lineCents(item)) / 100);
}
$('line-unit').addEventListener('change', () => {
  const unit = editingLine.units.find((u) => u.id === Number($('line-unit').value));
  $('line-price').value = unit?.price || '0';
  $('line-quantity').value = '1';
  $('line-discount-value').value = '0';
  updateLineEditor();
});
$('line-form').addEventListener('input', updateLineEditor);
['close-line', 'cancel-line'].forEach((id) =>
  $(id).addEventListener('click', () => lineDialog.close()),
);
$('reset-line-price').addEventListener('click', () => {
  const unit = editingLine.units.find((u) => u.id === Number($('line-unit').value));
  $('line-price').value = unit?.price || '0';
  $('line-discount-value').value = '0';
  $('line-discount-type').value = 'AMOUNT';
  updateLineEditor();
});
$('line-form').addEventListener('submit', (event) => {
  event.preventDefault();
  const item = currentLineEdit();
  const error = $('line-editor-error');
  if (
    item.unavailable ||
    lineCents(item) < 0n ||
    (pos.dataset.allowDiscount === '0' &&
      scaled(item.unit_price ?? item.price, 2) < scaled(item.price, 2))
  ) {
    error.textContent = item.unavailable
      ? 'Choose an available unit or remove this item from the cart.'
      : lineCents(item) < 0n
        ? 'Line discount cannot exceed the line amount.'
        : 'Price reductions are disabled in POS settings.';
    error.hidden = false;
    return;
  }
  Object.assign(editingLine, item);
  quote = null;
  lineDialog.close();
  message.textContent = '';
  render();
});
function updateCustomerDue() {
  const due = $('customer').selectedOptions[0]?.dataset.due || '0';
  $('customer-due').hidden = scaled(due, 2) <= 0n;
  $('customer-due-amount').textContent = money(due);
}
$('customer').addEventListener('change', () => {
  quote = null;
  updateCustomerDue();
  saveDraft();
});
updateCustomerDue();
const customerDialog = $('customer-dialog');
if (customerDialog) {
  const form = $('customer-form');
  const name = $('customer-name');
  const customerError = $('customer-error');
  const saveButton = $('save-customer');
  let savingCustomer = false;
  const closeCustomer = () => {
    if (!savingCustomer) {
      customerDialog.close();
      if (pendingCompletion) checkoutCustomerDialog.showModal();
    }
  };
  $('add-customer').addEventListener('click', () => {
    if (document.querySelector('dialog[open]')) return;
    form.reset();
    saveButton.textContent = pendingCompletion
      ? 'Save customer & complete sale'
      : 'Save & select customer';
    name.setCustomValidity('');
    customerError.hidden = true;
    customerDialog.showModal();
    name.focus();
  });
  $('close-customer').addEventListener('click', closeCustomer);
  $('cancel-customer').addEventListener('click', closeCustomer);
  customerDialog.addEventListener('cancel', (event) => {
    if (savingCustomer) event.preventDefault();
    else if (pendingCompletion) {
      event.preventDefault();
      closeCustomer();
    }
  });
  name.addEventListener('input', () => name.setCustomValidity(''));
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (savingCustomer) return;
    name.setCustomValidity(name.value.trim() ? '' : 'Enter a customer name.');
    if (!form.reportValidity()) return;
    const data = Object.fromEntries(new FormData(form));
    savingCustomer = true;
    customerError.hidden = true;
    customerDialog
      .querySelectorAll('button,input,textarea')
      .forEach((element) => (element.disabled = true));
    saveButton.textContent = 'Saving customer…';
    try {
      const result = await api(
        customerDialog.dataset.storeUrl,
        data,
        'Unable to save the customer. Please try again.',
      );
      const select = $('customer');
      const option = new Option(result.customer.name, String(result.customer.id));
      option.dataset.due = result.customer.due_balance || '0';
      select.add(option);
      [...select.options]
        .slice(1)
        .sort((a, b) => a.text.localeCompare(b.text))
        .forEach((option) => select.append(option));
      select.value = String(result.customer.id);
      select.dispatchEvent(new Event('change', { bubbles: true }));
      customerDialog.close();
      message.textContent = '';
      $('customer-added-status').textContent = 'New customer selected for this order.';
      if (pendingCompletion) await resumeCustomerCheckout();
    } catch (error) {
      customerError.textContent = error.message;
      customerError.hidden = false;
    } finally {
      savingCustomer = false;
      customerDialog
        .querySelectorAll('button,input,textarea')
        .forEach((element) => (element.disabled = false));
      saveButton.textContent = 'Save & select customer';
    }
  });
}
const cancelOrderDialog = $('cancel-order-dialog');
function clearCurrentOrder() {
  if (editMode) {
    loadInvoice();
    message.textContent = 'Saved invoice restored.';
    return;
  }
  restoreNumber++;
  orderLoading = false;
  resetOrder();
  message.textContent = '';
}
$('clear-cart').addEventListener('click', () => {
  if (document.querySelector('dialog[open]')) return;
  if (!cart.length) clearCurrentOrder();
  else cancelOrderDialog.showModal();
});
$('keep-order').addEventListener('click', () => cancelOrderDialog.close());
$('cancel-order-confirm').addEventListener('click', () => {
  cancelOrderDialog.close();
  clearCurrentOrder();
});
const methods = new Map(
  Array.from(document.querySelectorAll('[data-method-id]')).map((button) => [
    Number(button.dataset.methodId),
    {
      id: Number(button.dataset.methodId),
      name: button.dataset.methodName,
      type: button.dataset.methodType,
      button,
    },
  ]),
);
let split = false,
  payments = [],
  activeInput = null,
  quoteTimer,
  replaceOnKey = true,
  nextPaymentKey = 1;
const asAmount = (cents) => `${cents / 100n}.${String(cents % 100n).padStart(2, '0')}`;
const allocationTotal = () => payments.reduce((total, p) => total + scaled(p.amount, 2), 0n);
const paidFor = (p) => quote?.payments[payments.indexOf(p)];
function loadInvoice() {
  restoreNumber++;
  quoteNumber++;
  clearTimeout(quoteTimer);
  cart = structuredClone(editSeed.items);
  billDiscount = structuredClone(editSeed.billDiscount);
  $('customer').value = editSeed.customer;
  $('edit-notes').value = editSeed.notes || '';
  token = crypto.randomUUID();
  checkoutAttempted = false;
  quote = null;
  payments = [];
  split = false;
  activeInput = null;
  orderLoading = false;
  updateCustomerDue();
  render(false);
}
function seedEditPayments() {
  const original = editSeed.payments.filter((p) => methods.has(p.id));
  if (!original.length) return false;
  const total = scaled(cartTotal().toFixed(2), 2);
  const oldTotal = original.reduce((sum, p) => sum + scaled(p.amount, 2), 0n);
  let allocated = 0n,
    cumulative = 0n;
  payments = original
    .map((p, index) => {
      cumulative += scaled(p.amount, 2);
      const target =
        index === original.length - 1 || oldTotal === 0n
          ? total
          : (total * cumulative + oldTotal / 2n) / oldTotal;
      const share = target - allocated;
      allocated = target;
      return {
        key: nextPaymentKey++,
        id: p.id,
        amount: asAmount(share),
        paid: methods.get(p.id).type === 'CASH' ? asAmount(share) : '',
        autoPaid: true,
        reference: p.reference || '',
      };
    })
    .filter((p, index) => scaled(p.amount, 2) > 0n || (total === 0n && index === 0));
  split = payments.length > 1;
  renderPayments();
  focusPaymentAmount();
  getQuote();
  return true;
}
function renderEditAdjustments() {
  if (!editMode || !quote) return;
  const holder = $('edit-payment-adjustments');
  if (scaled(quote.remaining_amount, 2) > 0n) {
    holder.textContent = 'Allocate the full revised bill to see the payment adjustment.';
    return;
  }
  const totals = new Map();
  editSeed.payments.forEach((p) => {
    const current = totals.get(p.id) || { name: p.name, cents: 0n };
    current.cents -= scaled(p.collected, 2);
    totals.set(p.id, current);
  });
  quote.payments.forEach((p) => {
    const current = totals.get(p.payment_method_id) || { name: p.method_name, cents: 0n };
    current.cents += scaled(p.customer_payable, 2);
    totals.set(p.payment_method_id, current);
  });
  holder.innerHTML = [...totals.values()]
    .map(
      (p) =>
        `<div><span>${escape(p.name)}</span><strong>${p.cents === 0n ? 'No payment change' : `${p.cents > 0n ? 'Collect' : 'Refund'} ${moneyHTML(Number(p.cents < 0n ? -p.cents : p.cents) / 100)}`}</strong></div>`,
    )
    .join('');
}
function chooseInput(input, select = true) {
  if (!input || input.readOnly || input.disabled) return;
  activeInput = input;
  $('keypad-controls').hidden = false;
  replaceOnKey = select;
  document
    .querySelectorAll('.payment-money-input')
    .forEach((i) => i.classList.toggle('amount-active', i === input));
  $('keypad-label').textContent = input.getAttribute('aria-label');
  updateQuickCash();
}
function focusPaymentAmount() {
  if (!activeInput || !dialog.open || activeInput.disabled || activeInput.readOnly) return;
  activeInput.focus({ preventScroll: true });
  activeInput.select();
}
function renderPayments() {
  $('single-payment').classList.toggle('selected', !split);
  $('split-payment').classList.toggle('selected', split);
  $('single-payment').setAttribute('aria-pressed', String(!split));
  $('split-payment').setAttribute('aria-pressed', String(split));
  $('payment-help').textContent = split
    ? 'Enter a share, then tap a method to add another payment. You can use the same method again.'
    : 'Choose how the customer wants to pay.';
  methods.forEach((m) => {
    const selected = payments.some((p) => p.id === m.id);
    m.button.classList.toggle('selected', selected);
    m.button.setAttribute('aria-pressed', String(selected));
  });
  $('payment-lines').innerHTML = payments
    .map((p, index) => {
      const m = methods.get(p.id);
      const label = split ? `${m.name} · Payment ${index + 1}` : m.name;
      const tenderLabel =
        (editMode ? 'Revised ' : '') +
        (m.type === 'CASH'
          ? editMode
            ? 'cash received'
            : 'Cash received'
          : editMode
            ? 'amount collected'
            : 'Amount collected');
      return `<section class="payment-part" data-payment-key="${p.key}"><div class="payment-part-heading"><h3>${escape(label)}</h3>${split ? `<button class="icon-button" type="button" data-remove-payment="${p.key}" aria-label="Remove ${escape(label)}"><i data-lucide="x"></i></button>` : '<span class="badge green">Selected</span>'}</div><div class="payment-part-fields"><label class="field compact" ${split ? '' : 'hidden'}>Sale amount<input class="payment-money-input" type="text" inputmode="decimal" maxlength="15" data-field="amount" value="${escape(p.amount)}" aria-label="${escape(label)} sale amount" autocomplete="off"></label><label class="field compact">${tenderLabel}<input class="payment-money-input" type="text" inputmode="decimal" maxlength="15" data-field="paid" value="${escape(p.paid)}" aria-label="${escape(split ? `${label} ${tenderLabel}` : tenderLabel)}" autocomplete="off" ${m.type === 'CASH' ? '' : 'readonly'}></label></div><div class="part-collection"><span>${editMode ? 'Revised payable' : 'Collect'} <strong data-part-payable>—</strong></span><span class="part-fee" data-part-fee></span></div><details class="part-reference" ${p.reference ? 'open' : ''}><summary>Add reference (optional)</summary><label class="field compact"><span class="sr-only">${escape(label)} reference</span><input type="text" maxlength="255" data-field="reference" value="${escape(p.reference)}" aria-label="${escape(label)} reference"></label></details></section>`;
    })
    .join('');
  window.refreshIcons?.();
  activeInput = null;
  $('keypad-controls').hidden = true;
  chooseInput(
    document.querySelector('.payment-money-input[data-field="paid"]:not([readonly])') ||
      (split ? document.querySelector('.payment-money-input[data-field="amount"]') : null),
  );
}
function updateQuickCash() {
  const p = activeInput
    ? payments.find(
        (part) => part.key === Number(activeInput.closest('[data-payment-key]').dataset.paymentKey),
      )
    : null;
  const q = p ? paidFor(p) : null;
  if (!q || methods.get(p.id).type !== 'CASH' || activeInput.dataset.field !== 'paid') {
    $('quick-cash').innerHTML = '';
    return;
  }
  const amount = Number(q.customer_payable);
  const options = [500, 1000, 2000, 5000, 10000, 20000, 50000, 100000]
    .filter((n) => n > amount)
    .slice(0, 3);
  $('quick-cash').innerHTML = options
    .map((n) => `<button type="button" data-cash="${n}">${n.toLocaleString()}</button>`)
    .join('');
}
function updateBalance() {
  if (!quote) {
    $('confirm-payment').disabled = true;
    if ($('confirm-due')) $('confirm-due').disabled = true;
    return;
  }
  let received = 0n,
    due = scaled(quote.remaining_amount, 2),
    change = 0n,
    ready = due === 0n,
    creditReady = true;
  payments.forEach((p) => {
    const q = paidFor(p);
    if (!q) {
      ready = creditReady = false;
      return;
    }
    const paid = scaled(p.paid, 2),
      required = scaled(q.customer_payable, 2);
    received += paid;
    if (paid < required) due += required - paid;
    if (q.method_type === 'CASH' && paid > required) change += paid - required;
    if (
      !/^\d+(?:\.\d{1,2})?$/.test(p.paid) ||
      paid < required ||
      (q.method_type !== 'CASH' && paid !== required)
    )
      ready = false;
    if (
      !/^\d+(?:\.\d{1,2})?$/.test(p.paid) ||
      (q.method_type !== 'CASH' && paid !== required) ||
      (scaled(p.amount, 2) === 0n && paid !== 0n)
    )
      creditReady = false;
    if (scaled(p.amount, 2) === 0n && (payments.length > 1 || scaled(quote.sale_amount, 2) > 0n))
      ready = false;
  });
  $('payment-received').textContent = money(Number(received) / 100);
  $('payment-balance').textContent = money(Number(due) / 100);
  $('cash-change').textContent = money(Number(change) / 100);
  $('payment-unallocated').textContent = money(quote.remaining_amount);
  $('payment-balance').classList.toggle('settled', due === 0n);
  $('confirm-payment').disabled = pending || !ready || (editMode && checkoutAttempted);
  if ($('confirm-due')) {
    $('confirm-due').hidden = pos.dataset.canDue !== '1' || due === 0n;
    $('credit-checkout-note').hidden = pos.dataset.canDue !== '1' || due === 0n;
    $('confirm-due').disabled = pos.dataset.canDue !== '1' || pending || !creditReady || due <= 0n;
    $('confirm-due').textContent = `Complete with due · ${money(Number(due) / 100)}`;
  }
}
async function getQuote() {
  clearTimeout(quoteTimer);
  const current = ++quoteNumber;
  quote = null;
  error.hidden = true;
  $('confirm-payment').disabled = true;
  if ($('confirm-due')) $('confirm-due').disabled = true;
  $('payment-payable').textContent = 'Calculating…';
  if (editMode) $('edit-payment-adjustments').textContent = 'Calculating payment adjustment…';
  if (!payments.length) {
    $('payment-payable').textContent = 'Choose a method';
    return;
  }
  if (payments.some((p) => !/^\d+(?:\.\d{1,2})?$/.test(p.amount))) {
    $('payment-payable').textContent = 'Check amounts';
    showError('Enter payment amounts with up to two decimal places.');
    return;
  }
  try {
    const result = await api(pos.dataset.quoteUrl, payload());
    if (current !== quoteNumber || !dialog.open) return;
    quote = result;
    $('payment-base').textContent = money(quote.sale_amount);
    $('payment-payable').textContent = money(quote.customer_payable);
    $('payment-fees').textContent = money(quote.customer_processing_charge);
    payments.forEach((p) => {
      const q = paidFor(p),
        line = document.querySelector(`[data-payment-key="${p.key}"]`);
      line.querySelector('[data-part-payable]').textContent = money(q.customer_payable);
      const fee = line.querySelector('[data-part-fee]');
      fee.textContent =
        Number(q.processing_charge) > 0
          ? `${q.charge_type === 'PERCENTAGE' ? `${Number(q.charge_value)}%` : 'Fixed'} fee ${money(q.processing_charge)} · ${q.charge_bearer === 'BUSINESS' ? 'Paid by business' : 'Paid by customer'}`
          : 'No processing fee';
      if (q.method_type !== 'CASH' || p.autoPaid) {
        p.paid = q.customer_payable;
        const input = line.querySelector('[data-field="paid"]');
        input.value = p.paid;
        if (document.activeElement === input && !input.readOnly) input.select();
      }
    });
    updateQuickCash();
    updateBalance();
    renderEditAdjustments();
  } catch (e) {
    if (current !== quoteNumber) return;
    showError(e.message);
    $('payment-payable').textContent = 'Check amounts';
  }
}
function scheduleQuote() {
  quoteNumber++;
  quote = null;
  $('confirm-payment').disabled = true;
  if ($('confirm-due')) $('confirm-due').disabled = true;
  $('payment-payable').textContent = 'Calculating…';
  clearTimeout(quoteTimer);
  quoteTimer = setTimeout(getQuote, 180);
}
function amountInput(input) {
  const p = payments.find(
    (part) => part.key === Number(input.closest('[data-payment-key]').dataset.paymentKey),
  );
  p[input.dataset.field] = input.value;
  if (input.dataset.field === 'paid') p.autoPaid = false;
  if (activeInput === input) replaceOnKey = false;
  if (input.dataset.field === 'amount') scheduleQuote();
  else if (input.dataset.field === 'paid') updateBalance();
}
$('payment-lines').addEventListener('input', (e) => {
  const input = e.target.closest('[data-field]');
  if (input) amountInput(input);
});
$('payment-lines').addEventListener('focusin', (e) => {
  if (e.target.matches('.payment-money-input')) {
    chooseInput(e.target);
    if (!e.target.readOnly) e.target.select();
  }
});
$('payment-lines').addEventListener('keydown', (e) => {
  if (e.key === 'Enter' && e.target.matches('.payment-money-input')) {
    e.preventDefault();
    if (!pending && !$('confirm-payment').disabled) $('confirm-payment').click();
  }
});
$('payment-lines').addEventListener('click', (e) => {
  const button = e.target.closest('[data-remove-payment]');
  if (!button || pending) return;
  payments = payments.filter((p) => p.key !== Number(button.dataset.removePayment));
  renderPayments();
  getQuote();
});
methods.forEach((m) =>
  m.button.addEventListener('click', () => {
    if (pending) return;
    if (!split)
      payments = [
        {
          key: nextPaymentKey++,
          id: m.id,
          amount: asAmount(scaled(cartTotal().toFixed(2), 2)),
          paid: m.type === 'CASH' ? asAmount(scaled(cartTotal().toFixed(2), 2)) : '',
          autoPaid: true,
          reference: '',
        },
      ];
    else {
      if (payments.length >= 10) {
        showError('Use up to 10 payment entries per sale.');
        return;
      }
      const remaining = scaled(cartTotal().toFixed(2), 2) - allocationTotal();
      payments.push({
        key: nextPaymentKey++,
        id: m.id,
        amount: asAmount(remaining > 0n ? remaining : 0n),
        paid: m.type === 'CASH' ? asAmount(remaining > 0n ? remaining : 0n) : '',
        autoPaid: true,
        reference: '',
      });
    }
    renderPayments();
    if (split)
      chooseInput(
        document.querySelector(`[data-payment-key="${payments.at(-1).key}"] [data-field="amount"]`),
      );
    focusPaymentAmount();
    getQuote();
  }),
);
$('single-payment').addEventListener('click', () => {
  if (pending) return;
  split = false;
  const id = payments[0]?.id || Number(pos.dataset.defaultMethod) || methods.keys().next().value;
  if (methods.has(id)) methods.get(id).button.click();
  else renderPayments();
});
$('split-payment').addEventListener('click', () => {
  if (pending) return;
  split = true;
  renderPayments();
  chooseInput(document.querySelector('.payment-money-input[data-field="amount"]'));
  focusPaymentAmount();
  getQuote();
});
$('open-payment').addEventListener('click', () => {
  if (!cart.length || !syncQuantities()) return;
  $('discount').setCustomValidity('');
  if ($('discount').value && !/^\d+(?:\.\d{1,2})?$/.test($('discount').value))
    $('discount').setCustomValidity('Enter a discount with up to two decimal places.');
  if (!$('discount').checkValidity()) {
    $('discount').reportValidity();
    return;
  }
  split = false;
  payments = [];
  $('payment-base').textContent = money(cartTotal());
  dialog.showModal();
  if (editMode && seedEditPayments()) return;
  const id = methods.has(Number(pos.dataset.defaultMethod))
    ? Number(pos.dataset.defaultMethod)
    : methods.keys().next().value;
  if (methods.has(id)) methods.get(id).button.click();
});
$('close-payment').addEventListener('click', () => {
  if (!pending) {
    if (editMode && checkoutAttempted) {
      location.reload();
      return;
    }
    quoteNumber++;
    clearTimeout(quoteTimer);
    dialog.close();
  }
});
dialog.addEventListener('cancel', (e) => {
  if (pending) e.preventDefault();
  else if (editMode && checkoutAttempted) {
    e.preventDefault();
    location.reload();
  } else {
    quoteNumber++;
    clearTimeout(quoteTimer);
  }
});
function setActiveAmount(value) {
  if (!activeInput || pending) return;
  activeInput.value = value;
  replaceOnKey = false;
  amountInput(activeInput);
}
$('exact-payment').addEventListener('click', () => {
  if (!activeInput || !quote) return;
  const p = payments.find(
    (part) => part.key === Number(activeInput.closest('[data-payment-key]').dataset.paymentKey),
  );
  if (activeInput.dataset.field === 'paid') setActiveAmount(paidFor(p).customer_payable);
  else {
    const others = allocationTotal() - scaled(p.amount, 2);
    const remaining = scaled(quote.sale_amount, 2) - others;
    setActiveAmount(asAmount(remaining > 0n ? remaining : 0n));
  }
});
$('use-balance').addEventListener('click', () => $('exact-payment').click());
$('clear-amount').addEventListener('click', () => setActiveAmount('0'));

document.querySelector('.touch-keypad').addEventListener('pointerdown', (e) => e.preventDefault());
document.querySelector('.touch-keypad').addEventListener('click', (e) => {
  const button = e.target.closest('[data-key]');
  if (!button || !activeInput || pending) return;
  const key = button.dataset.key;
  let value = activeInput.value;
  if (key === 'backspace') value = replaceOnKey ? '0' : value.slice(0, -1) || '0';
  else {
    if (replaceOnKey) value = '';
    if (key === '.' && value.includes('.')) return;
    value = key === '.' ? (value || '0') + '.' : value === '0' ? key : value + key;
    if (!/^\d*(?:\.\d{0,2})?$/.test(value) || value.length > 15) return;
  }
  setActiveAmount(value);
});
$('quick-cash').addEventListener('click', (e) => {
  const button = e.target.closest('[data-cash]');
  if (button) setActiveAmount(button.dataset.cash);
});
$('review-payment').addEventListener('click', getQuote);
document.addEventListener('keydown', (e) => {
  if (e.key === 'F1') {
    e.preventDefault();
    if (!document.querySelector('dialog[open]')) $('product-search').focus();
  }
  if (e.key === 'F4') {
    e.preventDefault();
    if (!document.querySelector('dialog[open]')) $('open-payment').click();
  }
});
const checkoutCustomerDialog = $('checkout-customer-dialog');
function requestCompletion(mode) {
  const button = mode === 'due' ? $('confirm-due') : $('confirm-payment');
  if (!quote || pending || button?.disabled) return;
  if (!editMode && mode === 'due' && !$('customer').value) {
    pendingCompletion = mode;
    const select = $('checkout-customer-select');
    select.replaceChildren(new Option('Select a customer', ''));
    [...$('customer').options]
      .filter((option) => option.value)
      .forEach((option) => select.add(new Option(option.text, option.value)));
    select.setCustomValidity('');
    $('checkout-walk-in').hidden = mode === 'due';
    $('checkout-customer-help').textContent =
      mode === 'due'
        ? 'A named customer is required to track this unpaid balance. Select one or add a new customer.'
        : 'Select or add a customer for this sale, or continue as a walk-in customer.';
    document.querySelector('.checkout-customer-balance').hidden = mode !== 'due';
    $('checkout-customer-due').textContent = $('payment-balance').textContent;
    dialog.close();
    checkoutCustomerDialog.showModal();
    select.focus();
    return;
  }
  completeCheckout(mode);
}
async function resumeCustomerCheckout() {
  const mode = pendingCompletion;
  pendingCompletion = null;
  dialog.showModal();
  await getQuote();
  focusPaymentAmount();
  if (mode) requestCompletion(mode);
}
if (checkoutCustomerDialog) {
  const backToPayment = () => {
    pendingCompletion = null;
    checkoutCustomerDialog.close();
    dialog.showModal();
    focusPaymentAmount();
  };
  ['close-checkout-customer', 'cancel-checkout-customer'].forEach((id) =>
    $(id).addEventListener('click', backToPayment),
  );
  checkoutCustomerDialog.addEventListener('cancel', (event) => {
    event.preventDefault();
    backToPayment();
  });
  $('checkout-customer-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const select = $('checkout-customer-select');
    select.setCustomValidity(select.value ? '' : 'Select a customer or add a new one.');
    if (!event.target.reportValidity()) return;
    $('customer').value = select.value;
    $('customer').dispatchEvent(new Event('change', { bubbles: true }));
    checkoutCustomerDialog.close();
    await resumeCustomerCheckout();
  });
  $('checkout-customer-select').addEventListener('change', (event) =>
    event.target.setCustomValidity(''),
  );
  $('checkout-add-customer').addEventListener('click', () => {
    checkoutCustomerDialog.close();
    $('add-customer').click();
  });
  $('checkout-walk-in').addEventListener('click', () => {
    if (pendingCompletion !== 'paid') return;
    pendingCompletion = null;
    checkoutCustomerDialog.close();
    dialog.showModal();
    completeCheckout('paid');
  });
  $('confirm-due').addEventListener('click', () => requestCompletion('due'));
  $('leave-unpaid').addEventListener('click', () => {
    if (pending) return;
    const method =
      [...methods.values()].find((method) => method.type === 'CASH') ||
      methods.values().next().value;
    if (!method) return;
    split = false;
    payments = [
      {
        key: nextPaymentKey++,
        id: method.id,
        amount: '0.00',
        paid: '0.00',
        autoPaid: true,
        reference: '',
      },
    ];
    renderPayments();
    getQuote();
  });
}
$('confirm-payment').addEventListener('click', () => requestCompletion('paid'));
async function completeCheckout(mode) {
  const button = mode === 'due' ? $('confirm-due') : $('confirm-payment');
  if (!quote || pending || button?.disabled) return;
  pending = true;
  checkoutAttempted = true;
  saveDraft();
  $('confirm-payment').disabled = true;
  $('confirm-payment').textContent = editMode ? 'Saving changes…' : 'Saving sale…';
  dialog.querySelectorAll('button,input').forEach((b) => (b.disabled = true));
  error.hidden = true;
  try {
    const data = payload();
    data.payments.forEach((part, i) => (part.amount_paid = payments[i].paid));
    if (!editMode) data.allow_due = mode === 'due';
    data.quote_hash = quote.quote_hash;
    data.checkout_token = token;
    const result = await api(pos.dataset.completeUrl, data);
    if (editMode) {
      location.assign(result.sale_url);
      return;
    }
    dialog.close();
    payments = [];
    if (result.customer_id) {
      const option = [...$('customer').options].find(
        (option) => option.value === String(result.customer_id),
      );
      if (option) option.dataset.due = result.customer_balance || '0';
    }
    resetOrder();
    message.replaceChildren();
    const label = document.createElement('span');
    label.textContent = `Sale ${result.invoice} completed.${scaled(result.due_balance, 2) > 0n ? ` Balance due ${money(result.due_balance)}.` : ''} `;
    message.append(label);
    if (result.receipt_url) {
      const link = document.createElement('a');
      link.href = result.receipt_url;
      link.target = '_blank';
      link.className = 'text-link';
      link.textContent = 'Open receipt';
      message.append(link);
    }
    search();
    if (result.show_receipt || result.auto_print) showReceipt(result);
  } catch (e) {
    if (e.validationErrors) {
      checkoutAttempted = false;
      saveDraft();
    }
    showError(
      e.message +
        (editMode && !e.validationErrors
          ? ' Reload this invoice to confirm its saved version before making further changes.'
          : ''),
    );
    if (
      e.validationErrors &&
      Object.keys(e.validationErrors).some(
        (key) => !key.endsWith('amount_paid') && !key.endsWith('reference'),
      )
    ) {
      quote = null;
      $('payment-payable').textContent = 'Review amounts';
    }
  } finally {
    pending = false;
    dialog.querySelectorAll('button,input').forEach((b) => (b.disabled = false));
    $('confirm-payment').textContent = editMode ? 'Save changes' : 'Complete sale';
    updateBalance();
    if (!quote) {
      $('confirm-payment').disabled = true;
      if ($('confirm-due')) $('confirm-due').disabled = true;
    }
  }
}

function showReceipt(result) {
  if (!result.receipt_url) return;
  let frame;
  if (result.show_receipt) {
    $('receipt-invoice').textContent = result.invoice;
    $('receipt-external').href = result.receipt_url;
    $('print-receipt').disabled = true;
    frame = $('receipt-frame');
    $('receipt-dialog').showModal();
  } else {
    frame = document.createElement('iframe');
    frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:360px;height:900px';
    frame.title = 'Receipt printing';
    setTimeout(() => frame.remove(), 60000);
    document.body.append(frame);
  }
  frame.onload = () => {
    if (result.show_receipt) $('print-receipt').disabled = false;
    if (result.auto_print) {
      frame.contentWindow.addEventListener(
        'afterprint',
        () => {
          if (!result.show_receipt) frame.remove();
        },
        { once: true },
      );
      frame.contentWindow.focus();
      frame.contentWindow.print();
    }
  };
  frame.src = result.receipt_url + '?embed=1';
}
$('print-receipt').addEventListener('click', () => {
  $('receipt-frame').contentWindow.focus();
  $('receipt-frame').contentWindow.print();
});
$('close-receipt').addEventListener('click', () => $('receipt-dialog').close());
$('next-sale').addEventListener('click', () => {
  $('receipt-dialog').close();
  $('product-search').focus();
});
if (editMode) loadInvoice();
else {
  render(false);
  restoreDraft();
}
search();
