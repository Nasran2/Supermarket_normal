const pos = document.getElementById('pos');
const $ = (id) => document.getElementById(id),
  money = (v) => {
    const decimals =
      Math.round(Number(v) * 100) % 100 !== 0
        ? Math.max(2, Number(pos.dataset.decimals))
        : Number(pos.dataset.decimals);
    return `${pos.dataset.currency} ${Number(v).toLocaleString(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals })}`;
  };
let products = [],
  cart = [],
  method = null,
  quote = null,
  searchTimer,
  requestNumber = 0,
  quoteNumber = 0,
  token = crypto.randomUUID(),
  pending = false;
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
const lineCents = (p) => (scaled(p.price, 2) * scaled(p.quantity, 3) + 500n) / 1000n;
const subtotalCents = () => cart.reduce((total, p) => total + lineCents(p), 0n);
const subtotal = () => Number(subtotalCents()) / 100;
const cartTotal = () => Math.max(0, Number(subtotalCents() - scaled($('discount').value, 2)) / 100);
const moneyHTML = (value) => escape(money(value));
const payload = () => ({
  items: cart.map((p) => ({ product_id: p.id, quantity: String(p.quantity) })),
  discount: $('discount').value || '0',
  customer_id: $('customer').value || null,
  payment_method_id: method?.dataset.methodId,
  reference: $('payment-reference').value || null,
});
function showError(text) {
  error.textContent = text;
  error.hidden = false;
}
async function api(url, data) {
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
      result.errors
        ? Object.values(result.errors).flat().join(' ')
        : 'Unable to save this order. Please try again.',
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
              `<button class="product-tile" data-product="${p.id}" type="button">${p.image ? `<img src="${escape(p.image)}" alt="${escape(p.name)}">` : `<span class="product-art"><i data-lucide="package"></i></span>`}<strong>${escape(p.name)}</strong><small>${escape(p.category)} · ${escape(p.sku)}</small><span class="tile-bottom"><b>${moneyHTML(p.price)}</b><span>${Number(p.stock)} ${escape(p.unit)}</span></span></button>`,
          )
          .join('')
      : '<div class="empty-state col-span-full"><i data-lucide="search-x"></i><h3>No products found</h3><p>Add products or change your search.</p></div>';
    window.refreshIcons?.();
  } catch (e) {
    $('product-results').textContent = e.message;
    $('product-count').textContent = 'Products unavailable';
  }
}
function add(p) {
  if (!syncQuantities()) return;
  const existing = cart.find((i) => i.id === p.id);
  if (existing) existing.quantity = Number((Number(existing.quantity) + 1).toFixed(3));
  else cart.push({ ...p, quantity: 1 });
  quote = null;
  message.textContent = '';
  render();
}
function render() {
  $('cart-count').textContent = cart.length;
  $('cart-items').innerHTML = cart.length
    ? cart
        .map(
          (p) =>
            `<div class="cart-line" data-cart-id="${p.id}"><div class="cart-line-top"><strong>${escape(p.name)}</strong><span>${moneyHTML(Number(lineCents(p)) / 100)}</span><button class="icon-button" data-action="remove" title="Remove item" type="button"><i data-lucide="x" style="width:14px"></i></button></div><div class="cart-line-controls"><button class="icon-button" data-action="minus" type="button" aria-label="Decrease quantity">−</button><input class="cart-quantity" type="number" required max="999999" min="${p.decimal ? String(10 ** -Number(pos.dataset.quantityPrecision)) : '1'}" step="${p.decimal ? String(10 ** -Number(pos.dataset.quantityPrecision)) : '1'}" value="${p.quantity}" aria-label="Quantity for ${escape(p.name)}"><button class="icon-button" data-action="plus" type="button" aria-label="Increase quantity">+</button><small>${escape(p.unit)} × ${moneyHTML(p.price)}</small></div></div>`,
        )
        .join('')
    : '<div class="cart-empty"><i data-lucide="shopping-basket" style="width:35px;height:35px"></i><strong>Your order is empty</strong><small>Scan a barcode or choose a product.</small></div>';
  $('cart-subtotal').textContent = money(subtotal());
  $('cart-total').textContent = money(cartTotal());
  $('open-payment').disabled = !cart.length || pos.dataset.register !== '1';
  window.refreshIcons?.();
}
$('product-results').addEventListener('click', (e) => {
  const button = e.target.closest('[data-product]');
  if (button) add(products.find((p) => p.id === Number(button.dataset.product)));
});
$('product-search').addEventListener('input', () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(search, 220);
});
$('product-category').addEventListener('change', search);
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
    p = cart.find((p) => p.id === Number(line.dataset.cartId));
  if (button.dataset.action === 'remove') cart = cart.filter((i) => i.id !== p.id);
  else {
    p.quantity = Number(
      (Number(p.quantity) + (button.dataset.action === 'plus' ? 1 : -1)).toFixed(3),
    );
    if (p.quantity <= 0) cart = cart.filter((i) => i.id !== p.id);
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
    const p = cart.find((p) => p.id === Number(input.closest('[data-cart-id]').dataset.cartId));
    p.quantity = input.value;
  }
  return true;
}
$('cart-items').addEventListener('input', (e) => {
  if (e.target.matches('.cart-quantity')) {
    const input = e.target;
    if (!validQuantity(input)) return;
    const p = cart.find((p) => p.id === Number(input.closest('[data-cart-id]').dataset.cartId));
    p.quantity = input.value;
    quote = null;
    input.closest('.cart-line').querySelector('.cart-line-top>span').textContent = money(
      Number(lineCents(p)) / 100,
    );
    $('cart-subtotal').textContent = money(subtotal());
    $('cart-total').textContent = money(cartTotal());
  }
});
$('discount').addEventListener('input', () => {
  $('discount').setCustomValidity('');
  quote = null;
  render();
});
$('customer').addEventListener('change', () => {
  quote = null;
});
$('clear-cart').addEventListener('click', () => {
  if (cart.length && confirm('Clear the current order?')) {
    cart = [];
    $('discount').value = 0;
    token = crypto.randomUUID();
    render();
  }
});
function cashChange() {
  if (quote)
    $('cash-change').textContent = money(
      Math.max(0, Number($('cash-received').value || 0) - Number(quote.customer_payable)),
    );
}
$('cash-received').addEventListener('input', cashChange);
async function getQuote() {
  const current = ++quoteNumber;
  quote = null;
  error.hidden = true;
  $('confirm-payment').disabled = true;
  $('payment-payable').textContent = 'Calculating…';
  $('charge-detail').hidden = true;
  $('cash-fields').hidden = method?.dataset.methodType !== 'CASH';
  try {
    const result = await api(pos.dataset.quoteUrl, payload());
    if (current !== quoteNumber) return;
    quote = result;
    $('payment-base').textContent = money(quote.sale_amount);
    $('payment-payable').textContent = money(quote.customer_payable);
    const fee = Number(quote.processing_charge);
    if (fee > 0) {
      const rate =
        quote.charge_type === 'PERCENTAGE' ? `${Number(quote.charge_value)}%` : 'fixed fee';
      $('charge-detail').hidden = false;
      $('charge-detail').innerHTML =
        `<span>${escape(quote.method_name)} processing fee (${rate})</span><strong>${moneyHTML(fee)}</strong><small>${quote.charge_bearer === 'BUSINESS' ? 'Paid by business. Customer total stays the same.' : 'Paid by customer. Included in the amount to pay.'}</small>`;
    }
    $('payable-label').textContent =
      quote.charge_bearer === 'BUSINESS' ? 'Customer pays' : 'Amount to pay';
    if (quote.method_type === 'CASH') {
      $('cash-received').value = quote.customer_payable;
      const payable = Number(quote.customer_payable);
      const amounts = [500, 1000, 2000, 5000, 10000, 20000, 50000, 100000]
        .filter((a) => a > payable)
        .slice(0, 4);
      if (!amounts.length) amounts.push(Math.ceil(payable / 10000) * 10000);
      $('quick-cash').innerHTML =
        `<button type="button" data-cash="${quote.customer_payable}">Exact</button>` +
        amounts
          .map((a) => `<button type="button" data-cash="${a}">${a.toLocaleString()}</button>`)
          .join('');
      cashChange();
    }
    $('confirm-payment').disabled = false;
  } catch (e) {
    if (current !== quoteNumber) return;
    showError(e.message);
    $('payment-payable').textContent = '—';
  }
}
$('quick-cash').addEventListener('click', (e) => {
  const button = e.target.closest('[data-cash]');
  if (button) {
    $('cash-received').value = button.dataset.cash;
    cashChange();
  }
});
document.querySelectorAll('[data-method-id]').forEach((button) =>
  button.addEventListener('click', () => {
    if (pending) return;
    method = button;
    document
      .querySelectorAll('[data-method-id]')
      .forEach((b) => b.classList.toggle('selected', b === button));
    getQuote();
  }),
);
$('open-payment').addEventListener('click', () => {
  if (!cart.length || !syncQuantities()) return;
  $('discount').setCustomValidity('');
  if ($('discount').value && !/^\d+(?:\.\d{1,2})?$/.test($('discount').value))
    $('discount').setCustomValidity('Enter a discount with up to two decimal places.');
  if (!$('discount').checkValidity()) {
    $('discount').reportValidity();
    return;
  }
  $('payment-base').textContent = money(cartTotal());
  dialog.showModal();
  const selected =
    method ||
    document.querySelector(`[data-method-id="${pos.dataset.defaultMethod}"]`) ||
    document.querySelector('[data-method-id]');
  selected?.click();
});
$('close-payment').addEventListener('click', () => {
  if (!pending) {
    quoteNumber++;
    dialog.close();
  }
});
dialog.addEventListener('cancel', (e) => {
  if (pending) e.preventDefault();
  else quoteNumber++;
});
$('confirm-payment').addEventListener('click', async () => {
  if (!quote || pending) return;
  pending = true;
  $('confirm-payment').disabled = true;
  $('confirm-payment').textContent = 'Saving sale…';
  document
    .querySelectorAll(
      '[data-method-id],#cash-received,#payment-reference,#quick-cash button,#close-payment',
    )
    .forEach((b) => (b.disabled = true));
  error.hidden = true;
  try {
    const data = payload();
    data.quote_hash = quote.quote_hash;
    data.checkout_token = token;
    data.amount_paid =
      quote.method_type === 'CASH' ? $('cash-received').value : quote.customer_payable;
    const result = await api(pos.dataset.completeUrl, data);
    dialog.close();
    cart = [];
    quote = null;
    token = crypto.randomUUID();
    $('discount').value = 0;
    $('payment-reference').value = '';
    render();
    message.replaceChildren();
    const label = document.createElement('span');
    label.textContent = `Sale ${result.invoice} completed. `;
    const link = document.createElement('a');
    link.href = result.receipt_url;
    link.target = '_blank';
    link.className = 'text-link';
    link.textContent = 'Open receipt';
    message.append(label, link);
    search();
    if (result.show_receipt || result.auto_print) showReceipt(result);
  } catch (e) {
    showError(e.message);
    if (
      e.validationErrors &&
      Object.keys(e.validationErrors).some((key) => !['amount_paid', 'reference'].includes(key))
    ) {
      quote = null;
      $('payment-payable').textContent = 'Review payment again';
    }
  } finally {
    pending = false;
    document
      .querySelectorAll(
        '[data-method-id],#cash-received,#payment-reference,#quick-cash button,#close-payment',
      )
      .forEach((b) => (b.disabled = false));
    $('confirm-payment').disabled = !quote;
    $('confirm-payment').textContent = 'Complete sale';
  }
});
function showReceipt(result) {
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
render();
search();
