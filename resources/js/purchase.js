const container = document.getElementById('purchase-items'),
  template = document.getElementById('purchase-item-template');
let index = 0;
const allProducts = JSON.parse(document.getElementById('all-products-data')?.textContent || '[]');

function total() {
  let amount = 0n;
  container.querySelectorAll('.purchase-line').forEach((line) => {
    const qty = line.querySelector('[data-field=quantity]').value;
    const cost = line.querySelector('[data-field=cost]').value;
    const selling = line.querySelector('[data-field=selling_price]').value;
    line.querySelector('[data-field-display=price_warning]').hidden = moneyCents(selling) >= moneyCents(cost);
    const lineTotal = lineCostCents(qty, cost);
    line.querySelector('[data-field-display=line_total]').textContent = displayCents(lineTotal);
    amount += lineTotal;
  });
  const currency = document.getElementById('purchase-form').dataset.currency;
  const subtotal = amount;
  let charges = 0n;
  document.querySelectorAll('[data-charge=amount]').forEach(input => { charges += moneyCents(input.value); });
  amount += charges;
  document.getElementById('purchase-subtotal').textContent = `${currency} ${displayCents(subtotal)}`;
  document.getElementById('purchase-charges-total').textContent = `${currency} ${displayCents(charges)}`;
  document.getElementById('purchase-total').textContent = `${currency} ${displayCents(amount)}`;
  const count = container.querySelectorAll('.purchase-line').length;
  document.getElementById('purchase-line-count').textContent = `${count} ${count === 1 ? 'item' : 'items'}`;
  document.getElementById('purchase-empty').hidden = count > 0;
  document.getElementById('save-purchase').disabled = !count;
  const paidInput = document.getElementById('purchase-amount-paid');
  const given = paidInput ? moneyCents(paidInput.value) : moneyCents(document.getElementById('purchase-summary-paid').dataset.recordedPaid);
  const {paid,due,change,status} = paymentBalance(amount,given);
  if (paidInput) {
    const method = document.getElementById('purchase-payment-method');
    method.required = given > 0n;
    const badge = document.getElementById('purchase-auto-status');
    badge.textContent = count === 0 ? 'Unpaid' : status;
    badge.className = `badge ${status === 'Paid' ? 'green' : 'amber'}`;
    document.querySelector('#purchase-payment-note span').textContent = given === 0n ? 'No payment now. The full supplier bill remains due.' : !method.value ? 'Choose the method used to pay this supplier.' : method.selectedOptions[0]?.dataset.type === 'CASH' ? 'Your register records the payment after any change is returned.' : 'Record the amount given and any change returned. Your cash register is unchanged.';
  }
  const tracked = document.getElementById('purchase-form').dataset.paymentTracked !== '0';
  document.getElementById('purchase-summary-paid').textContent = tracked ? `${currency} ${displayCents(paidInput ? paid : given)}` : 'Unrecorded';
  document.getElementById('purchase-summary-due').textContent = tracked ? `${currency} ${displayCents(due)}` : 'Unrecorded';
  document.getElementById('purchase-change-row').hidden = !paidInput || change === 0n;
  document.getElementById('purchase-summary-change').textContent = `${currency} ${displayCents(change)}`;
  const invalid = !paidInput && given > amount;
  const error = document.getElementById('purchase-payment-validation');
  error.textContent = 'The new bill total is below the recorded payments. Record a supplier refund before saving.';
  error.hidden = !invalid;
  const preview = document.getElementById('purchase-allocation-preview');
  const allocate = document.querySelector('input[name=charge_treatment]:checked')?.value === 'COST';
  preview.hidden = !allocate || charges === 0n || count === 0;
  preview.replaceChildren();
  if (!preview.hidden) {
    const rows = [...container.querySelectorAll('.purchase-line')];
    const parts = allocateCharges(rows.map(row => lineCostCents(row.querySelector('[data-field=quantity]').value,row.querySelector('[data-field=cost]').value)),charges);
    rows.forEach((row,i)=>{ const entry=document.createElement('p'); const name=document.createElement('span'); name.textContent=row.querySelector('[data-field-display=name]').textContent; const value=document.createElement('strong'); value.textContent=`+ ${currency} ${displayCents(parts[i])}`; entry.append(name,value); preview.append(entry); });
  }
}

function add(initial = {}, prepend = false) {
  const productId = initial.product_id;
  if (!productId) return;
  const product = allProducts.find(p => String(p.id) === String(productId));
  if (!product) return;

  const line = template.content.firstElementChild.cloneNode(true);
  
  // Setup inputs
  line.querySelectorAll('[data-field]').forEach((input) => {
    input.name = `items[${index}][${input.dataset.field}]`;
    if (initial[input.dataset.field] !== undefined) input.value = initial[input.dataset.field];
  });
  
  // Set display text
  line.querySelector('[data-field-display=name]').textContent = product.name;
  line.querySelector('[data-field-display=sku]').textContent = product.sku;
  
  index++;
  
  const productInput = line.querySelector('[data-field=product_id]'),
    qty = line.querySelector('[data-field=quantity]'),
    cost = line.querySelector('[data-field=cost]'),
    selling = line.querySelector('[data-field=selling_price]'),
    unitSelect = line.querySelector('[data-field=unit_id]');
    
  productInput.value = product.id;

  function unit(keepInitial = false) {
    const options = product.units || [];
    const selectedId = keepInitial ? initial.unit_id : null;
    unitSelect.replaceChildren();
    options.forEach((u) => {
      const option = document.createElement('option');
      option.value = u.id;
      option.textContent = u.name + ' (' + u.short_name + ')';
      option.dataset.decimal = u.decimal ? '1' : '0';
      option.dataset.cost = u.cost;
      option.dataset.price = u.price;
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

  unitSelect.addEventListener('change', () => {
    cost.value = unitSelect.selectedOptions[0]?.dataset.cost ?? 0;
    selling.value = unitSelect.selectedOptions[0]?.dataset.price ?? 0;
    qty.value = '1';
    updatePrecision();
    total();
  });
  
  line.addEventListener('input', total);
  line.querySelector('.remove-line').addEventListener('click', () => {
    line.remove();
    total();
  });
  
  if (prepend) {
      container.prepend(line);
  } else {
      container.append(line);
  }
  
  if (initial.cost == null) {
      cost.value = product.cost || 0;
  }
  
  unit(true);
  if (initial.selling_price == null) selling.value = unitSelect.selectedOptions[0]?.dataset.price ?? product.price ?? 0;
  line.querySelector('[data-field-display=prices]').textContent = (product.prices || []).map((group) => `${group.stock_price} · ${Number(group.quantity)} ${product.unit}`).join(' / ');
  window.refreshIcons?.();
  total();
}

let chargeIndex = 0;
function addCharge(initial={}) {
  const row = document.getElementById('purchase-charge-template').content.firstElementChild.cloneNode(true);
  row.querySelectorAll('[data-charge]').forEach(input=>{input.name=`charges[${chargeIndex}][${input.dataset.charge}]`;input.value=initial[input.dataset.charge]??'';});
  chargeIndex++;
  row.addEventListener('input',total);
  row.querySelector('button').addEventListener('click',()=>{row.remove();document.getElementById('purchase-charges-empty').hidden=!!document.getElementById('purchase-charges').children.length;total();});
  document.getElementById('purchase-charges').append(row);
  document.getElementById('purchase-charges-empty').hidden=true;
  window.refreshIcons?.();total();
}
document.getElementById('add-purchase-charge').addEventListener('click',()=>addCharge());
document.querySelectorAll('input[name=charge_treatment]').forEach(input=>input.addEventListener('change',total));
const initialChargesRaw = JSON.parse(document.getElementById('purchase-charges-initial')?.textContent||'[]');
(Array.isArray(initialChargesRaw) ? initialChargesRaw : Object.values(initialChargesRaw)).forEach(row=>addCharge(row));
const initialDataRaw = JSON.parse(document.getElementById('purchase-initial')?.textContent || '[]');
const initialData = Array.isArray(initialDataRaw) ? initialDataRaw : Object.values(initialDataRaw);
if (initialData.length) initialData.forEach(i => add(i, false));
document.getElementById('purchase-payment-inputs')?.addEventListener('input', total);
document.getElementById('purchase-payment-method')?.addEventListener('change', total);
total();

// --- Supplier Dropdown ---
const supSearch = document.getElementById('supplier-search');
const supDrop = document.getElementById('supplier-dropdown');
if (supSearch) {
    supSearch.addEventListener('focus', () => supDrop.classList.add('show'));
    document.addEventListener('click', (e) => {
        if(!e.target.closest('#supplier-search-container')) supDrop.classList.remove('show');
    });
    supSearch.addEventListener('input', (e) => {
        document.getElementById('supplier_id').value = '';
        const val = e.target.value.toLowerCase();
        supDrop.querySelectorAll('.supplier-option').forEach(opt => {
            opt.style.display = opt.dataset.name.toLowerCase().includes(val) ? 'block' : 'none';
        });
    });
    document.querySelectorAll('.supplier-option').forEach(opt => {
        opt.addEventListener('click', () => {
            document.getElementById('supplier_id').value = opt.dataset.id;
            supSearch.value = opt.dataset.name;
            supDrop.classList.remove('show');
        });
    });
}

// --- Product Dropdown ---
const prodSearch = document.getElementById('product-search');
const prodDrop = document.getElementById('product-dropdown');
if (prodSearch) {
    prodSearch.addEventListener('focus', () => prodDrop.classList.add('show'));
    document.addEventListener('click', (e) => {
        if(!e.target.closest('#product-search-container')) prodDrop.classList.remove('show');
    });
    prodSearch.addEventListener('input', (e) => {
        const val = e.target.value.toLowerCase();
        prodDrop.querySelectorAll('.product-option').forEach(opt => {
            opt.style.display = `${opt.dataset.name} ${opt.dataset.sku} ${opt.dataset.barcode}`.toLowerCase().includes(val) ? 'block' : 'none';
        });
    });
    document.querySelectorAll('.product-option').forEach(opt => {
        opt.addEventListener('click', () => {
            add({ product_id: opt.dataset.id }, true); // prepend true
            prodSearch.value = '';
            prodDrop.classList.remove('show');
            prodDrop.querySelectorAll('.product-option').forEach(o => o.style.display = 'block'); // reset filter
        });
    });
}
import {moneyCents, lineCostCents, displayCents, paymentBalance, allocateCharges} from './purchase-totals.js';
