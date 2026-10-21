function insertToken(input, token) {
  if (!input) return;
  const start = input.selectionStart ?? input.value.length;
  const end = input.selectionEnd ?? start;
  input.setRangeText(token, start, end, 'end');
  input.focus();
  input.dispatchEvent(new Event('input', { bubbles: true }));
}
document.querySelectorAll('[data-sms-insert]').forEach((button) => {
  button.addEventListener('click', () =>
    insertToken(document.getElementById(button.dataset.smsTarget), button.dataset.smsInsert),
  );
});
const settings = document.querySelector('[data-sms-settings]');
if (settings) {
  const sample = {
    name: 'Sample Customer',
    business: settings.dataset.smsBusiness,
    currency: settings.dataset.smsCurrency,
    invoice: 'INV-00001',
    total: '2,500.00',
    paid: '1,500.00',
    due: '1,000.00',
    amount: '1,500.00',
    receipt_url: 'https://your-store.example/r/receipt',
    method: 'Cash',
    payment_status: 'Payment confirmed',
    date: '25/10/2026',
    cheque_number: 'CHQ-001234',
    cheque_type: 'Customer',
    bank: 'Sample Bank',
    overdue: '',
    parties: 'Sample Customer',
  };
  settings.querySelectorAll('[data-sms-template-input]').forEach((input) => {
    const card = input.closest('[data-sms-template-card]');
    const update = () => {
      card.querySelector('[data-sms-template-count]').textContent =
        `${input.value.length} / 1,000 characters`;
      card.querySelector('[data-sms-template-preview]').textContent = input.value.replace(
        /\{([^{}]+)\}/g,
        (match, token) => sample[token] ?? match,
      );
    };
    input.addEventListener('input', update);
    update();
  });
  settings.querySelectorAll('[data-sms-reset]').forEach((button) => {
    button.addEventListener('click', () => {
      const input = document.getElementById(button.dataset.smsReset);
      input.value = button.dataset.smsDefault;
      input.dispatchEvent(new Event('input', { bubbles: true }));
    });
  });
  // Browser validation must be able to focus a template inside a collapsed editor.
  settings.querySelectorAll('textarea').forEach((input) =>
    input.addEventListener('invalid', () => {
      const details = input.closest('details');
      if (details) details.open = true;
    }),
  );
}
const composer = document.querySelector('[data-sms-composer]');
if (composer) {
  const data = JSON.parse(document.getElementById('sms-composer-data').textContent);
  const kind = composer.querySelector('[name=kind]');
  const message = composer.querySelector('[name=message]');
  const count = document.getElementById('sms-compose-count');
  const tokens = document.getElementById('sms-compose-tokens');
  const audience = composer.querySelector('[name=audience]');
  const selected = document.getElementById('sms-selected-count');
  let previousTemplate = data[kind.value].template;
  const updateCount = () => {
    count.textContent = `${message.value.length} / 1,000 characters`;
  };
  function updateTokens() {
    tokens.replaceChildren(
      ...data[kind.value].tokens.map((name) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = `{${name}}`;
        button.addEventListener('click', () => insertToken(message, `{${name}}`));
        return button;
      }),
    );
  }
  kind.addEventListener('change', () => {
    if (!message.value || message.value === previousTemplate)
      message.value = data[kind.value].template;
    previousTemplate = data[kind.value].template;
    updateTokens();
    updateCount();
  });
  document.getElementById('sms-use-template').addEventListener('click', () => {
    message.value = data[kind.value].template;
    updateCount();
  });
  function updateSelection() {
    const total = composer.querySelectorAll('[name="customers[]"]:checked:not(:disabled)').length;
    selected.textContent = audience.value === 'all' ? 'All active customers' : `${total} selected`;
    const boxes = [...composer.querySelectorAll('[name="customers[]"]:not(:disabled)')];
    const all = document.getElementById('sms-select-page');
    all.checked = boxes.length > 0 && boxes.every((box) => box.checked);
    all.indeterminate = total > 0 && total < boxes.length;
  }
  composer.addEventListener('change', updateSelection);
  message.addEventListener('input', updateCount);
  updateSelection();
  updateTokens();
  updateCount();
}
const testForm = document.getElementById('sms-test-form');
if (testForm) {
  const send = document.getElementById('sms-test-send');
  const another = document.getElementById('sms-test-another');
  const result = document.getElementById('sms-test-result');
  const originalLabel = send.innerHTML;
  let busy = false;
  function newTest() {
    if (busy || testForm.dataset.paused === '1') return;
    testForm.elements.token.value = crypto.randomUUID();
    send.disabled = false;
    another.hidden = true;
  }
  another.addEventListener('click', newTest);
  ['sms-test-phone', 'sms-test-message'].forEach((id) =>
    document.getElementById(id).addEventListener('input', newTest),
  );
  testForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (busy || testForm.dataset.paused === '1') return;
    busy = true;
    send.disabled = true;
    send.textContent = 'Sending…';
    another.hidden = true;
    result.hidden = false;
    result.className = 'notice info';
    result.textContent = 'Sending test SMS…';
    try {
      const response = await fetch(testForm.action, {
        method: 'POST',
        headers: { Accept: 'application/json' },
        body: new FormData(testForm),
      });
      const body = await response.json();
      if (!response.ok)
        throw new Error(
          Object.values(body.errors || {}).flat()[0] || body.message || 'Unable to send test SMS.',
        );
      result.textContent = body.message;
      result.className = body.status === 'SENT' ? 'notice success' : 'notice error';
      another.hidden = false;
    } catch (error) {
      result.className = 'notice error';
      result.textContent = error.message;
      // Keep the same token when retrying a request whose response may have been lost.
      send.disabled = false;
    } finally {
      busy = false;
      send.innerHTML = originalLabel;
      window.refreshIcons?.();
    }
  });
}
