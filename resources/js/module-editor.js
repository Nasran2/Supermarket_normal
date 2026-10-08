const editor = document.querySelector('[data-module-editor]');
if (editor) {
  const inputs = [...editor.querySelectorAll('input[name="permissions[]"]')];
  const groups = [...editor.querySelectorAll('[data-permission-group]')];
  function update() {
    const count = editor.querySelector('[data-permission-count]');
    if (count) count.textContent = inputs.filter((input) => input.checked).length;
    groups.forEach((group) => {
      const children = [...group.querySelectorAll('input[name="permissions[]"]')];
      const selected = children.filter((input) => input.checked).length;
      const toggle = group.querySelector('[data-group-toggle]');
      toggle.checked = selected === children.length;
      toggle.indeterminate = selected > 0 && selected < children.length;
    });
  }
  editor.addEventListener('change', (event) => {
    if (event.target.matches('[data-group-toggle]')) {
      event.target.closest('[data-permission-group]').querySelectorAll('input[name="permissions[]"]').forEach((input) => input.checked = event.target.checked);
    }
    update();
  });
  editor.querySelectorAll('[data-permissions-all]').forEach((button) => button.addEventListener('click', () => {
    inputs.forEach((input) => input.checked = button.dataset.permissionsAll === '1');
    update();
  }));
  editor.querySelector('[data-permission-search]')?.addEventListener('input', (event) => {
    const query = event.target.value.trim().toLowerCase();
    groups.forEach((group) => {
      const moduleMatches = group.querySelector('legend').textContent.toLowerCase().includes(query);
      const labels = [...group.querySelectorAll('[data-permission-label]')];
      labels.forEach((label) => label.hidden = !moduleMatches && !label.textContent.toLowerCase().includes(query));
      group.hidden = !labels.some((label) => !label.hidden);
    });
  });
  update();
}
const pricing = document.querySelector('[data-opening-prices]');
if (pricing) {
  let index = pricing.querySelectorAll('[data-opening-row]').length;
  pricing.querySelector('[data-add-opening]').addEventListener('click', () => {
    const fragment = document.querySelector('#opening-price-template').content.cloneNode(true);
    fragment.querySelectorAll('[name]').forEach((input) => input.name = input.name.replace('__INDEX__', index));
    index++;
    pricing.querySelector('[data-opening-body]').append(fragment);
    window.refreshIcons?.();
  });
  pricing.addEventListener('click', (event) => {
    const button = event.target.closest('[data-remove-opening]');
    if (button) button.closest('[data-opening-row]').remove();
  });
  pricing.addEventListener('input', () => {
    pricing.querySelectorAll('[data-opening-row]').forEach((row) => {
      const cost = row.querySelector('[name$="[cost]"]').value;
      const price = row.querySelector('[name$="[selling_price]"]').value;
      row.querySelector('[data-margin-warning]').hidden = Number(price) >= Number(cost);
    });
  });
}
