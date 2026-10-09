import {exactBarcode, needsStockRow, singleStockRow} from './stock-adjustment-picker.js';
const $ = (id) => document.getElementById(id);
const reverse = $('adjustment-reverse-dialog');
if (reverse) {
  $('open-adjustment-reverse').addEventListener('click', () => reverse.showModal());
  for (const id of ['close-adjustment-reverse', 'cancel-adjustment-reverse']) $(id).addEventListener('click', () => reverse.close());
}
const form = $('adjustment-form');
if (form) {
  const escape = (v) => String(v).replace(/[&<>"']/g,(c)=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
  const rows = new Map(JSON.parse($('adjustment-initial-rows').textContent).map(p=>{p.product_id=Number(p.product_id);p.unit_id=Number(p.unit_id);return [p.product_id,p];}));
  const input = $('adjustment-search'), dropdown = $('adjustment-search-results');
  let results=[], resultQuery='', request=0, timer, active=0;
  function message(value) { $('adjustment-message').textContent=value;$('adjustment-message').hidden=!value; }
  function preview(p) { const qty=Number(p.quantity||0); return p.mode==='SET'?qty:Number(p.expected_stock)+(p.mode==='REMOVE'?-qty:qty); }
  function closeResults() { dropdown.hidden=true;input.setAttribute('aria-expanded','false');input.removeAttribute('aria-activedescendant'); }
  function focusQuantity(id) { const control=$('adjustment-items').querySelector(`[data-row="${id}"] [data-field=quantity]`);control?.focus();control?.select(); }
  function syncRow(p, row) {
    const reducing=needsStockRow(p);
    if (!reducing) p.stock_layer_id='';
    else if (!p.stock_layer_id) p.stock_layer_id=singleStockRow(p);
    const picker=row.querySelector('[data-stock-picker]'), select=picker.querySelector('select');
    select.value=p.stock_layer_id||'';
    picker.hidden=!reducing || (p.layers||[]).length===1;
    select.required=reducing;
    const layer=(p.layers||[]).find(l=>String(l.id)===String(p.stock_layer_id));
    row.querySelector('[data-selected-stock]').hidden=!reducing || !layer;
    row.querySelector('[data-selected-stock]').textContent=layer?`Stock row: ${form.dataset.currency} ${layer.selling_price} · ${Number(layer.quantity)} ${p.unit}`:'';
    for (const field of ['price','cost']) {
      const control=row.querySelector(`[data-field=${field}]`);
      if(control) {
        control.disabled=reducing;
        control.readOnly=form.dataset.canPrices!=='1';
        control.value=reducing ? (layer?.[field==='price'?'selling_price':'cost_price']??'') : (p[field]??'');
        control.title=reducing ? 'Removed stock keeps the selected stock row’s prices.' : 'Leave blank to use the product default.';
      }
    }
    row.querySelector('[data-stock-preview]').textContent=`${preview(p).toFixed(3)} ${p.unit}`;
    row.querySelector('[data-stock-preview]').classList.toggle('text-danger',preview(p)<0);
  }
  function renderRows() {
    $('adjustment-row-count').textContent=`${rows.size} ${rows.size===1?'product':'products'}`;
    $('adjustment-empty').hidden=rows.size>0;$('adjustment-save').disabled=!rows.size;
    $('adjustment-items').innerHTML=[...rows.values()].map(p=>{
      const prefix=`items[${p.product_id}]`;
      const hidden=['product_id','unit_id','expected_stock','expected_price','expected_cost'].map(field=>`<input type="hidden" name="${prefix}[${field}]" value="${escape(p[field]??'')}">`).join('');
      return `<tr data-row="${p.product_id}"><td>${hidden}<strong>${escape(p.name)}</strong><small>${escape(p.sku)} · ${escape(p.unit)}</small><small data-selected-stock hidden></small><label class="field adjustment-stock-picker" data-stock-picker hidden><span>Choose stock row</span><select name="${prefix}[stock_layer_id]" data-field="stock_layer_id" aria-label="Stock price row for ${escape(p.name)}"><option value="">Select the stock to remove</option>${(p.layers||[]).map(l=>`<option value="${l.id}">${escape(form.dataset.currency)} ${escape(l.selling_price)} · ${Number(l.quantity)} ${escape(p.unit)}</option>`).join('')}</select></label></td><td>${escape(p.expected_stock)} ${escape(p.unit)}</td><td><select name="${prefix}[mode]" data-field="mode" aria-label="Adjustment for ${escape(p.name)}">${[['ADD','Add stock'],['REMOVE','Remove stock'],['SET','Set counted stock']].map(([key,label])=>`<option value="${key}" ${key===p.mode?'selected':''}>${label}</option>`).join('')}</select></td><td><input type="number" data-field="quantity" name="${prefix}[quantity]" value="${escape(p.quantity??'0')}" min="0" max="999999999999" step="${p.decimal?10**-Number(form.dataset.precision):1}" inputmode="decimal" required aria-label="Quantity for ${escape(p.name)}"></td><td><strong data-stock-preview></strong></td><td class="adjustment-price-cell"><input type="number" data-field="price" name="${prefix}[price]" value="${escape(p.price??'')}" placeholder="${escape(p.expected_price)}" min="0" max="999999999" step="0.01" inputmode="decimal" aria-label="Selling price for ${escape(p.name)}"></td>${form.dataset.canCost==='1'?`<td class="adjustment-price-cell"><input type="number" data-field="cost" name="${prefix}[cost]" value="${escape(p.cost??'')}" placeholder="${escape(p.expected_cost)}" min="0" max="999999999" step="0.01" inputmode="decimal" aria-label="Cost for ${escape(p.name)}"></td>`:''}<td><button class="icon-button text-danger" type="button" data-remove="${p.product_id}" ${p.locked?'disabled':''} aria-label="Remove ${escape(p.name)}" title="${p.locked?'Original products remain in this adjustment':'Remove product'}"><i data-lucide="x"></i></button></td></tr>`;
    }).join('');
    for (const p of rows.values()) syncRow(p,$('adjustment-items').querySelector(`[data-row="${p.product_id}"]`));
    window.refreshIcons?.();
  }
  function addProduct(p) {
    if(!p)return;
    const id=Number(p.product_id);
    const existing=rows.has(id);
    if(!existing) {
      if(rows.size>=100) {message('Use up to 100 products per adjustment.');return;}
      rows.set(id,{...p,product_id:id,mode:$('adjustment-default-mode').value,quantity:'0'});renderRows();
    }
    message('');request++;clearTimeout(timer);input.value='';results=[];resultQuery='';closeResults();
    $('adjustment-search-count').textContent=`${p.name} ${existing?'is already in this adjustment':'added'}. Enter quantity, then press Enter for the next product.`;
    focusQuantity(id);
  }
  function highlight() {
    [...dropdown.querySelectorAll('[data-add-product]')].forEach((button,index)=>{button.setAttribute('aria-selected',String(index===active));button.classList.toggle('active',index===active);});
    const button=dropdown.querySelector(`[data-add-product="${results[active]?.product_id}"]`);
    if(button){input.setAttribute('aria-activedescendant',button.id);button.scrollIntoView({block:'nearest'});}
  }
  function renderResults() {
    $('adjustment-search-count').textContent=results.length?`${results.length}${results.length===60?'+':''} matches · click one to add`:'No matching products. Try a name, SKU or barcode.';
    dropdown.innerHTML=results.length?results.map(p=>`<button type="button" role="option" id="adjustment-option-${p.product_id}" data-add-product="${p.product_id}"><span><strong>${escape(p.name)}</strong><small>${escape(p.sku)} · ${escape(p.expected_stock)} ${escape(p.unit)} in stock</small></span><span class="badge ${rows.has(Number(p.product_id))?'green':'slate'}">${rows.has(Number(p.product_id))?'Added':'Add'}</span></button>`).join(''):'<p class="muted">No matching products.</p>';
    dropdown.hidden=false;input.setAttribute('aria-expanded','true');active=0;highlight();
  }
  async function search(select=false) {
    const query=input.value.trim(), current=++request;
    if(!query){results=[];closeResults();return;}
    $('adjustment-search-count').textContent='Searching…';
    try {
      const response=await fetch(`${form.dataset.productsUrl}?${new URLSearchParams({q:query})}`,{headers:{Accept:'application/json'}});
      if(!response.ok)throw new Error('Unable to load products. Try searching again.');
      const data=await response.json();
      if(current!==request || query!==input.value.trim())return;
      results=data;resultQuery=query;message('');
      const barcode=exactBarcode(results,query);
      if(barcode){addProduct(barcode);return;}
      if(select && results.length){addProduct(results[0]);return;}
      renderResults();
    } catch(error){if(current===request){closeResults();message(error.message);$('adjustment-search-count').textContent='Search unavailable. Try again.';}}
  }
  input.addEventListener('input',()=>{request++;clearTimeout(timer);closeResults();if(!input.value.trim()){$('adjustment-search-count').textContent='Search or scan the next product.';return;}timer=setTimeout(()=>search(),160);});
  input.addEventListener('focus',()=>{if(input.value.trim()){if(resultQuery===input.value.trim()&&results.length)renderResults();else search();}});
  input.addEventListener('keydown',e=>{
    if(e.isComposing)return;
    if(e.key==='Escape'){e.preventDefault();request++;clearTimeout(timer);closeResults();}
    if(e.key==='ArrowDown'||e.key==='ArrowUp'){e.preventDefault();if(results.length&&!dropdown.hidden){active=(active+(e.key==='ArrowDown'?1:-1)+results.length)%results.length;highlight();}}
    if(e.key==='Enter'){e.preventDefault();clearTimeout(timer);if(resultQuery===input.value.trim()&&results.length&&!dropdown.hidden)addProduct(results[active]);else search(true);}
  });
  dropdown.addEventListener('click',e=>{const button=e.target.closest('[data-add-product]');if(button)addProduct(results.find(p=>Number(p.product_id)===Number(button.dataset.addProduct)));});
  document.addEventListener('pointerdown',e=>{if(!e.target.closest('.adjustment-search-control'))closeResults();});
  $('adjustment-items').addEventListener('input',e=>{const control=e.target.closest('[data-field]');if(!control)return;const row=control.closest('[data-row]'),p=rows.get(Number(row.dataset.row));p[control.dataset.field]=control.value;syncRow(p,row);});
  $('adjustment-items').addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.isComposing&&e.target.matches('[data-field=quantity]')){e.preventDefault();input.focus();input.select();}});
  $('adjustment-items').addEventListener('click',e=>{const button=e.target.closest('[data-remove]');if(!button)return;const p=rows.get(Number(button.dataset.remove));if(p.locked)return;rows.delete(p.product_id);renderRows();closeResults();input.focus();});
  form.addEventListener('submit',e=>{if(!rows.size||[...rows.values()].some(p=>preview(p)<0)){e.preventDefault();message('Add a product and ensure the new stock is not negative.');}});
  renderRows();
  if(rows.size)$('adjustment-search-count').textContent='Search or scan to add another product.';
}
