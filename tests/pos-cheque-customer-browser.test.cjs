// Uses only the disposable cheque-browser fixture database and server on port 8140.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const base = 'http://127.0.0.1:8140';
  const errors = [];
  const artifacts = __dirname + '/.browser-artifacts/cheques';
  fs.mkdirSync(artifacts, { recursive: true });
  async function openWalkInPayment() {
    const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
    const page = await context.newPage();
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto(base + '/login');
    await page.locator('[name=login]').fill('admin');
    await page.locator('[name=password]').fill('password');
    await page.locator('button[type=submit]').click();
    await page.waitForURL(base + '/');
    await page.goto(base + '/pos');
    await page.locator('#customer').selectOption('');
    await page.locator('#product-search').fill('Cheque Browser Grocery');
    await page
      .locator('.product-tile')
      .filter({ hasText: 'Cheque Browser Grocery' })
      .first()
      .click();
    await page.locator('#open-payment').click();
    return { page, context };
  }
  try {
    const { page, context } = await openWalkInPayment();
    await page.locator('.payment-option[data-method-type=CHEQUE]').click();
    await page.locator('#checkout-customer-dialog').waitFor({ state: 'visible' });
    assert.match(
      await page.locator('#checkout-customer-title').innerText(),
      /required for cheques/,
    );
    assert.equal(await page.locator('#checkout-walk-in').isVisible(), false);
    await page.screenshot({ path: artifacts + '/customer-required-popup.png', fullPage: true });
    await page.locator('#cancel-checkout-customer').click();
    await page.locator('#payment-dialog').waitFor({ state: 'visible' });
    assert.equal(await page.locator('#confirm-payment').isDisabled(), true);
    await page.locator('[data-cheque-pos-field=amount]').fill('25000');
    await page.locator('#checkout-customer-dialog').waitFor({ state: 'visible' });
    const existing = await page
      .locator('#checkout-customer-select option')
      .filter({ hasText: 'Browser Cheque Customer' })
      .first()
      .getAttribute('value');
    await page.locator('#checkout-customer-select').selectOption(existing);
    await page.locator('#use-checkout-customer').click();
    await page.locator('#payment-dialog').waitFor({ state: 'visible' });
    assert.equal(await page.locator('#customer').inputValue(), existing);
    assert.equal(await page.locator('[data-cheque-pos-field=amount]').inputValue(), '25000');
    assert.equal(await page.locator('#receipt-dialog').isVisible(), false);
    for (const [key, value] of Object.entries({
      cheque_date: '2026-10-18',
      cheque_number: 'POPUP-' + Date.now(),
      bank_name: 'Browser Bank',
    }))
      await page.locator(`[data-cheque-pos-field=${key}]`).fill(value);
    await page.waitForFunction(() => !document.querySelector('#confirm-payment').disabled);
    assert.equal(await page.locator('#payment-error').isVisible(), false);
    await context.close();

    const added = await openWalkInPayment();
    await added.page.locator('.payment-option[data-method-type=CHEQUE]').click();
    await added.page.locator('#checkout-customer-dialog').waitFor({ state: 'visible' });
    await added.page.locator('#checkout-add-customer').click();
    await added.page.locator('#customer-dialog').waitFor({ state: 'visible' });
    await added.page.locator('#cancel-customer').click();
    await added.page.locator('#checkout-customer-dialog').waitFor({ state: 'visible' });
    await added.page.locator('#checkout-add-customer').click();
    await added.page.locator('#customer-name').fill('Popup Customer ' + Date.now());
    await added.page.locator('#save-customer').click();
    await added.page.locator('#payment-dialog').waitFor({ state: 'visible' });
    assert.ok(await added.page.locator('#customer').inputValue());
    assert.equal(await added.page.locator('.cheque-entry').count(), 1);
    assert.equal(await added.page.locator('#receipt-dialog').isVisible(), false);
    assert.equal(await added.page.locator('#confirm-payment').isDisabled(), true);
    await added.context.close();
    assert.deepEqual(errors, []);
    console.log(
      'Cheque customer popup passed: existing selection, add new, cancel, preserved details, no automatic completion, no browser errors.',
    );
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
