@push('scripts')
<script>
document.querySelector('[data-hr-row-search]')?.addEventListener('input',function(){const q=this.value.toLowerCase();document.querySelectorAll('[data-hr-search-row]').forEach(row=>row.hidden=!row.dataset.search.includes(q));});
document.querySelector('[data-hr-mark-present]')?.addEventListener('click',()=>{document.querySelectorAll('[data-hr-search-row]:not([hidden]) select').forEach(select=>{if(!select.value&&!select.disabled) select.value='PRESENT';});});
document.querySelectorAll('.hr-attendance-table select').forEach(select=>select.addEventListener('change',()=>{if(!['PRESENT','LATE','HALF_DAY'].includes(select.value)){const row=select.closest('tr');row.querySelectorAll('input[type=time]').forEach(input=>input.value='');row.querySelector('input[type=number]').value='0';row.querySelector('input[type=checkbox]').checked=false;}}));
const hrLines=document.querySelector('[data-hr-lines]');let hrLineIndex=hrLines?.children.length||0;
document.querySelector('[data-hr-add-line]')?.addEventListener('click',()=>{if(hrLines.children.length>=30)return;const i=hrLineIndex++;const row=document.createElement('div');row.className='hr-payroll-line';row.innerHTML=`<select name="lines[${i}][kind]"><option value="EARNING">Allowance / earning</option><option value="DEDUCTION">Deduction</option></select><input name="lines[${i}][name]" placeholder="Description"><input name="lines[${i}][amount]" type="number" min="0" step="0.01" value="0" aria-label="Amount"><button type="button" class="icon-button text-danger" data-hr-remove-line aria-label="Remove salary line">×</button>`;hrLines.append(row);row.querySelector('input').focus();});
hrLines?.addEventListener('click',event=>{event.target.closest('[data-hr-remove-line]')?.closest('.hr-payroll-line').remove();});
</script>
@endpush
