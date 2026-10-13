import { configureOrientation } from './barcode-layout.js';
const form = document.getElementById('barcode-preset-editor');
if (form) configureOrientation(form);
form?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const submit = form.querySelector('button[type=submit]');
  submit.disabled = true;
  const configuration = JSON.parse(
    document.getElementById('preset-editor-configuration').textContent,
  );
  for (const input of form.querySelectorAll('[data-config]'))
    configuration[input.dataset.config] =
      input.type === 'checkbox'
        ? input.checked
        : input.hasAttribute('data-mm')
          ? input.value === ''
            ? null
            : (Number(input.value) * 10).toFixed(2)
          : input.type === 'number'
            ? Number(input.value)
            : input.value;
  try {
    const response = await fetch(form.dataset.url, {
      method: form.dataset.method,
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
      },
      body: JSON.stringify({
        name: document.getElementById('preset-editor-name').value,
        is_default: document.getElementById('preset-editor-default').checked,
        configuration,
      }),
    });
    const data = await response.json();
    if (!response.ok)
      throw new Error(
        Object.values(data.errors || {})
          .flat()
          .join(' ') || data.message,
      );
    window.location.assign(form.dataset.return);
  } catch (error) {
    const message = document.getElementById('preset-editor-error');
    message.hidden = false;
    message.textContent = error.message;
    submit.disabled = false;
  }
});
