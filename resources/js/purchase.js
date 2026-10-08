const container = document.getElementById('purchase-items'),
  template = document.getElementById('purchase-item-template');
let index = 0;
const allProducts = JSON.parse(document.getElementById('all-products-data')?.textContent || '[]');

function total() {
  let amount = 0;
  container.querySelectorAll('.purchase-line').forEach((line) => {
    const qty = Number(line.querySelector('[data-field=quantity]').value) || 0;
    const cost = Number(line.querySelector('[data-field=cost]').value) || 0;
    const lineTotal = qty * cost;
    line.querySelector('[data-field-display=line_total]').textContent = lineTotal.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    amount += lineTotal;
  });
  document.getElementById('purchase-total').textContent = amount.toLocaleString(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
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
  
  if (!initial.cost) {
      cost.value = product.cost || 0;
  }
  
  unit(true);
  window.refreshIcons?.();
  total();
}

const initialData = JSON.parse(document.getElementById('purchase-initial')?.textContent || '[]');
if (initialData.length) initialData.forEach(i => add(i, false));

// --- Supplier Dropdown ---
const supSearch = document.getElementById('supplier-search');
const supDrop = document.getElementById('supplier-dropdown');
if (supSearch) {
    supSearch.addEventListener('focus', () => supDrop.classList.add('show'));
    document.addEventListener('click', (e) => {
        if(!e.target.closest('#supplier-search-container')) supDrop.classList.remove('show');
    });
    supSearch.addEventListener('input', (e) => {
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
            opt.style.display = opt.dataset.name.toLowerCase().includes(val) ? 'block' : 'none';
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
