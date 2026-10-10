import { sellingPriceOptions } from './pos-price-options.js';
const configElement = document.getElementById('return-config');
if (configElement) {
  const c = JSON.parse(configElement.textContent), $ = (id) => document.getElementById(id);
  let step = 1, replacements = [], products = new Map(), busy = false, latestQuote = null, quoteSequence = 0, submitted = false;
  const currency = (v) => `Rs. ${Number(v).toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  const error = (message) => { $('return-error').textContent = message; $('return-error').hidden = !message; };
  const resolution = () => document.querySelector('[name="return_resolution"]:checked').value;
  const selectedLines = () => c.lines.filter((line) => Number(document.querySelector(`[data-return-line="${line.id}"] .return-qty`).value) > 0);
  function payload() {
    return { token: c.token, original_id: c.originalId, reason: $('return-reason').value, notes: $('return-notes').value, resolution: resolution(),
      items: c.lines.map((line) => { const row = document.querySelector(`[data-return-line="${line.id}"]`); return { [c.kind === 'sales' ? 'sale_item_id' : 'purchase_item_id']: line.id, quantity: row.querySelector('.return-qty').value || '0', ...(c.kind === 'sales' ? { stock_action: row.querySelector('.return-stock-action').value, supplier_id: row.querySelector('.return-supplier')?.value || null } : {}) }; }),
      replacements: resolution() === 'MONEY' ? [] : replacements.map((r) => ({ product_id: r.product_id, unit_id: r.unit_id, quantity: r.quantity, ...(r.stock_price != null ? { stock_price: r.stock_price } : {}), ...(r.source_id ? { [c.kind === 'sales' ? 'sale_item_id' : 'purchase_item_id']: r.source_id } : {}), ...(c.kind === 'purchase' ? { cost: r.cost, selling_price: r.selling_price } : {}) })),
      apply_due: $('return-apply-due')?.value || '0', keep_credit: $('return-keep-credit')?.value || '0', credit_customer_id: $('return-credit-customer')?.value || null,
      payment_method_id: $('return-payment-method').value || null, add_to_due: $('return-add-due')?.checked || false, use_current_price: $('return-current-price')?.checked || false };
  }
  async function request(url, data) {
    const response = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: JSON.stringify(data) });
    const body = await response.json();
    if (!response.ok) throw new Error(Object.values(body.errors || {}).flat().join(' ') || body.message || 'Unable to save the return.');
    return body;
  }
  function showStep(next) {
    step = next;
    document.querySelectorAll('[data-return-step]').forEach((e) => { e.hidden = Number(e.dataset.returnStep) !== next; });
    document.querySelectorAll('[data-step-indicator]').forEach((e) => { e.classList.toggle('active', Number(e.dataset.stepIndicator) === next); e.classList.toggle('complete', Number(e.dataset.stepIndicator) < next); });
  }
  function textElement(tag, text, className = '') { const e = document.createElement(tag); e.textContent = text; e.className = className; return e; }
  async function loadProducts(ids) {
    const params = new URLSearchParams(); ids.forEach((id, i) => params.append(`ids[${i}]`, id));
    const response = await fetch(`${c.productsUrl}?${params}`, { headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error('Unable to load replacement stock.');
    const found = await response.json(); found.forEach((p) => products.set(p.id, p)); return found;
  }
  function newReplacement(product, option, line = null) {
    const unitId = line?.unit_id || product.unit_id, units = option?.units || product.units;
    const unit = units.find((u) => u.id === unitId) || units[0];
    return { product_id: product.id, name: product.name, source_id: line?.id, unit_id: unitId, quantity: line ? document.querySelector(`[data-return-line="${line.id}"] .return-qty`).value : '1', stock_price: option?.stock_price, units,
      cost: line?.price || product.cost || '0', selling_price: unit?.price || product.price, options: sellingPriceOptions(product) };
  }
  function renderReplacements() {
    const container = $('return-replacement-list'); container.replaceChildren();
    replacements.forEach((r, i) => {
      const card = textElement('article', '', 'return-replacement-card'); card.append(textElement('strong', r.name));
      const qtyLabel = textElement('label', 'Quantity', 'field'), qty = document.createElement('input'); qty.type = 'number'; qty.min = '0.001'; qty.step = '0.001'; qty.value = r.quantity; qty.disabled = resolution() === 'SAME'; qty.addEventListener('input', () => { r.quantity = qty.value; }); qtyLabel.append(qty); card.append(qtyLabel);
      const unitLabel = textElement('label', 'Unit', 'field'), unit = document.createElement('select'); (r.units || []).forEach((u) => { const o = textElement('option', u.short_name); o.value = u.id; o.selected = u.id === r.unit_id; unit.append(o); }); unit.disabled = resolution() === 'SAME'; unit.addEventListener('change', () => { r.unit_id = Number(unit.value); }); unitLabel.append(unit); card.append(unitLabel);
      if (c.kind === 'sales') {
        const label = textElement('label', 'Available selling price', 'field'), select = document.createElement('select');
        r.options.forEach((o) => { const e = textElement('option', `${currency(o.stock_price)} · ${o.quantity} available`); e.value = o.stock_price; e.selected = o.stock_price === r.stock_price; select.append(e); });
        select.addEventListener('change', () => { r.stock_price = select.value; const opt = r.options.find((o) => o.stock_price === r.stock_price); if (opt) r.units = opt.units; }); label.append(select); card.append(label);
        if (resolution() === 'SAME') card.append(textElement('small', 'Original effective selling value is used unless current price is selected.'));
      } else {
        [['Replacement cost', 'cost'], ['Selling price', 'selling_price']].forEach(([name, key]) => { const label = textElement('label', name, 'field'), input = document.createElement('input'); input.type = 'number'; input.min = '0'; input.step = '0.01'; input.value = r[key]; input.addEventListener('input', () => { r[key] = input.value; }); label.append(input); card.append(label); });
      }
      if (resolution() === 'OTHER') { const remove = textElement('button', 'Remove', 'btn secondary'); remove.type = 'button'; remove.addEventListener('click', () => { replacements.splice(i, 1); renderReplacements(); }); card.append(remove); }
      container.append(card);
    });
  }
  async function prepareResolution() {
    const mode = resolution(); const currentPrice = $('return-current-price'); if(currentPrice) { currentPrice.closest('label').hidden = mode !== 'SAME'; if(mode !== 'SAME') currentPrice.checked=false; } $('return-replacements').hidden = mode === 'MONEY'; $('return-product-search').hidden = mode !== 'OTHER';
    if (mode === 'MONEY') { replacements = []; return; }
    if (mode === 'SAME') {
      await loadProducts([...new Set(selectedLines().map((l) => l.product_id))]);
      const anticipated = new Map();
      for (const line of selectedLines()) {
        const row = document.querySelector(`[data-return-line="${line.id}"]`);
        if (c.kind !== 'sales' || row.querySelector('.return-stock-action')?.value !== 'RESTOCK') continue;
        let left = Number(row.querySelector('.return-qty').value) * Number(line.base_quantity) / Number(line.sold);
        for (const source of line.restock_prices || []) {
          const take = Math.min(left, Number(source.remaining)); if (take <= 0) continue;
          const entries = anticipated.get(line.product_id) || []; entries.push({ stock_price: source.stock_price, quantity: take.toFixed(3), units: source.units }); anticipated.set(line.product_id, entries); left -= take;
        }
      }
      replacements = selectedLines().map((line) => { const current = products.get(line.product_id); const p = current ? { ...current, price_options: [...current.price_options, ...(anticipated.get(line.product_id) || [])] } : null; if (!p) throw new Error(`${line.name} is inactive or unavailable.`); return newReplacement(p, sellingPriceOptions(p)[0], line); });
    } else replacements = [];
    renderReplacements();
  }
  async function review() {
    const sequence = ++quoteSequence; $('return-complete').disabled = true; error('');
    try {
      const data = await request(c.quoteUrl, payload());
      if (sequence !== quoteSequence) return;
      latestQuote = data;
      $('return-review-items').replaceChildren(...data.lines.map((l) => textElement('p', `${l.name} × ${l.quantity} ${l.unit}${l.stock_action ? ` · ${({ RESTOCK: 'Restock', WRITEOFF: 'Write off', SUPPLIER: 'Return to supplier' })[l.stock_action]}` : ''} · ${currency(l.amount)}`)));
      const totals = $('return-review-totals'); totals.replaceChildren();
      [['Returned items', data.amount], ['Refundable original fees', data.fee_refund || 0], ['Original bill due reduced', data.due_reduction], ['Available credit', data.available_credit], ['Replacement products', data.replacement_value], ['Other account due available', data.customer_due_available ?? data.supplier_due_available ?? 0], ['Other account due reduced', data.apply_due], ['Refund / money received', data.refund_amount], ['Payment charge', data.payment_charge || 0]].forEach(([name, amount]) => { const row = document.createElement('div'); row.append(textElement('span', name), textElement('strong', currency(amount))); totals.append(row); });
      const paying = Number(data.must_pay) > 0, owed = Number(data.owed) > 0;
      $('return-difference').textContent = paying ? `${c.kind === 'sales' ? 'CUSTOMER MUST PAY' : 'WE OWE SUPPLIER'} ${currency(Number(data.must_pay) + Number(data.payment_charge || 0))}` : owed ? `${c.kind === 'sales' ? 'WE OWE CUSTOMER' : 'SUPPLIER OWES US'} ${currency(data.owed)}` : 'DIFFERENCE Rs. 0.00';
      $('return-credit-options').hidden = !owed; $('return-pay-options').hidden = !paying;
      $('return-method-label').hidden = (!paying && Number(data.refund_amount) === 0) || (paying && $('return-add-due')?.checked);
      $('return-settlement-message').textContent = paying && $('return-add-due')?.checked ? `Add ${currency(data.must_pay)} to the account due.` : Number(data.refund_amount) > 0 ? `${c.kind === 'sales' ? 'Refund customer' : 'Receive from supplier'} ${currency(data.refund_amount)}.` : 'No money refund is required.';
      $('return-complete').disabled = false; return true;
    } catch (e) { if (sequence === quoteSequence) { latestQuote = null; error(e.message); } return false; }
  }
  document.querySelectorAll('[data-return-next]').forEach((button) => button.addEventListener('click', async () => {
    if (busy) return; const next = Number(button.dataset.returnNext); error(''); busy = true; button.disabled = true;
    try {
      if (next === 3 && step === 2) {
        if (!selectedLines().length) throw new Error('Select at least one returned product.');
        if (c.requireReason && !$('return-reason').value) throw new Error('Choose a return reason.');
        if ($('return-reason').value === 'Other' && !$('return-notes').value.trim()) throw new Error('Enter notes for Other.');
        for (const line of selectedLines()) { const qty = Number(document.querySelector(`[data-return-line="${line.id}"] .return-qty`).value); if (qty > Number(line.remaining) || (!line.decimal && !Number.isInteger(qty))) throw new Error(`Check the return quantity for ${line.name}.`); }
        await prepareResolution();
      }
      if (next === 4) { if (!await review()) return; }
      showStep(next);
    } catch (e) { error(e.message); } finally { busy = false; button.disabled = false; }
  }));
  document.querySelectorAll('.return-stock-action').forEach((select) => { const refresh = () => { const choice = select.closest('td').querySelector('.return-supplier-choice'); if (choice) choice.hidden = select.value !== 'SUPPLIER'; }; select.addEventListener('change', refresh); refresh(); });
  document.querySelectorAll('[name="return_resolution"]').forEach((input) => input.addEventListener('change', () => prepareResolution().catch((e) => error(e.message))));
  let searchTimer, searchSequence = 0;
  $('return-product-query').addEventListener('input', () => {
    clearTimeout(searchTimer); const sequence = ++searchSequence;
    searchTimer = setTimeout(async () => {
      try { const response = await fetch(`${c.productsUrl}?q=${encodeURIComponent($('return-product-query').value)}&category_id=${encodeURIComponent($('return-product-category').value)}`, { headers: { Accept: 'application/json' } }); if (!response.ok) throw new Error('Product search failed.'); const found = await response.json(); if (sequence !== searchSequence) return;
        $('return-product-results').replaceChildren(); found.forEach((p) => { products.set(p.id, p); const button = textElement('button', p.name, 'btn secondary'); button.type = 'button'; button.addEventListener('click', () => {
          const options = sellingPriceOptions(p); if (c.kind === 'sales' && !options.length) { error('This product is out of stock.'); return; }
          const add = (option) => { replacements.push(newReplacement(p, option)); renderReplacements(); $('return-product-results').replaceChildren(); $('return-product-query').value = ''; $('return-price-dialog').close(); };
          if (c.kind === 'sales' && options.length > 1) { $('return-price-options').replaceChildren(); options.forEach((option) => { const b = textElement('button', `${currency(option.stock_price)} · ${option.quantity} available`, 'btn secondary w-full'); b.type = 'button'; b.addEventListener('click', () => add(option)); $('return-price-options').append(b); }); $('return-price-dialog').showModal(); } else add(options[0]);
        }); $('return-product-results').append(button); });
      } catch (e) { error(e.message); }
    }, 250);
  });
  $('return-product-category').addEventListener('change',()=>{ $('return-product-query').dispatchEvent(new Event('input')); });
  $('return-product-query').addEventListener('keydown',(e)=>{ if(e.key==='Enter') { e.preventDefault(); const result=[...products.values()].find(p=>p.barcode===$('return-product-query').value||p.sku===$('return-product-query').value); const buttons=[...$('return-product-results').querySelectorAll('button')]; const button=buttons.find(b=>b.textContent===result?.name); button?.click(); } });
  $('return-close-price').addEventListener('click', () => $('return-price-dialog').close());
  const customerSelect=$('return-credit-customer');
  if(customerSelect && $('return-customer-query')) {
    const options=[...customerSelect.options].map(o=>({value:o.value,label:o.textContent}));
    $('return-customer-query').addEventListener('input',()=>{ const query=$('return-customer-query').value.toLowerCase(), current=customerSelect.value; customerSelect.replaceChildren(); options.filter(o=>!o.value||o.value===current||o.label.toLowerCase().includes(query)).forEach(o=>{ const e=textElement('option',o.label);e.value=o.value;e.selected=o.value===current;customerSelect.append(e); }); });
  }
  let reviewTimer;
  ['return-apply-due', 'return-keep-credit', 'return-payment-method', 'return-credit-customer', 'return-add-due'].forEach((id) => $(id)?.addEventListener('change', () => { if (step === 4 && !submitted) { $('return-complete').disabled = true; clearTimeout(reviewTimer); reviewTimer = setTimeout(review, 120); } }));
  $('return-complete').addEventListener('click', async () => {
    if (busy || submitted || !latestQuote) return; busy = true; submitted = true; $('return-complete').disabled = true; error('');
    try { const data = await request(c.storeUrl, { ...payload(), quote_hash: latestQuote.quote_hash }); location.assign(data.auto_print ? data.receipt_url : data.url); }
    catch (e) { error(e.message); busy = false; submitted = false; $('return-complete').disabled = false; }
  });
  $('return-save-draft').addEventListener('click',async()=>{
    if(busy||submitted) return; busy=true;$('return-save-draft').disabled=true;error('');
    try { const saved=await request(c.draftUrl,payload());location.assign(saved.url); }
    catch(e) { error(e.message);busy=false;$('return-save-draft').disabled=false; }
  });
  if(c.draft) {
    const draft=c.draft;
    $('return-reason').value=draft.reason||'';$('return-notes').value=draft.notes||'';
    for(const saved of draft.items||[]) {
      const id=saved[c.kind==='sales'?'sale_item_id':'purchase_item_id'], row=document.querySelector(`[data-return-line="${id}"]`);if(!row) continue;
      row.querySelector('.return-qty').value=saved.quantity;
      if(row.querySelector('.return-stock-action')) {row.querySelector('.return-stock-action').value=saved.stock_action||'RESTOCK';row.querySelector('.return-stock-action').dispatchEvent(new Event('change'));}
      if(row.querySelector('.return-supplier')) row.querySelector('.return-supplier').value=saved.supplier_id||'';
    }
    const choice=document.querySelector(`[name="return_resolution"][value="${draft.resolution}"]`);if(choice) choice.checked=true;
    if($('return-apply-due')) $('return-apply-due').value=draft.apply_due||'0';if($('return-keep-credit')) $('return-keep-credit').value=draft.keep_credit||'0';
    if($('return-credit-customer')) $('return-credit-customer').value=draft.credit_customer_id||'';if(draft.payment_method_id) $('return-payment-method').value=draft.payment_method_id;
    if($('return-add-due')) $('return-add-due').checked=!!draft.add_to_due;if($('return-current-price')) $('return-current-price').checked=!!draft.use_current_price;
    loadProducts([...new Set((draft.replacements||[]).map(r=>r.product_id))]).then(()=>{
      replacements=(draft.replacements||[]).map(saved=>{const product=products.get(saved.product_id);if(!product) throw new Error('A saved replacement product is no longer available.');const option=sellingPriceOptions(product).find(o=>o.stock_price===saved.stock_price);const r=newReplacement(product,option);return {...r,...saved,source_id:saved.sale_item_id||saved.purchase_item_id};});
      $('return-replacements').hidden=draft.resolution==='MONEY';$('return-product-search').hidden=draft.resolution!=='OTHER';renderReplacements();
    }).catch(e=>error(e.message));
    showStep(2);
  }

}
