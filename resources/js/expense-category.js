const dialog = document.getElementById('expense-category-dialog');
if (dialog) {
  const form = document.getElementById('expense-category-quick-form');
  const error = form.querySelector('[data-category-error]');
  document.querySelector('[data-open-expense-category]').addEventListener('click', () => {
    error.hidden = true;
    dialog.showModal();
    form.elements.name.focus();
  });
  dialog.querySelectorAll('[data-close-expense-category]').forEach(button => button.addEventListener('click', () => dialog.close()));
  let saving = false;
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (saving) return;
    saving = true;
    const submit = form.querySelector('[type=submit]');
    submit.disabled = true;
    error.hidden = true;
    try {
      const response = await fetch(form.action, {method:'POST', body:new FormData(form), headers:{Accept:'application/json'}});
      const result = await response.json();
      if (!response.ok) throw new Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'Unable to add the category. Try again.');
      const select = document.querySelector('[data-module-editor] select[name=expense_category_id]');
      select.add(new Option(result.name, result.id, true, true));
      select.dispatchEvent(new Event('change', {bubbles:true}));
      form.reset();
      dialog.close();
      select.focus();
    } catch (failure) {
      error.textContent = failure.message;
      error.hidden = false;
    } finally {
      saving = false;
      submit.disabled = false;
    }
  });
}
