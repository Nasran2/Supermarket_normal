const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const base = process.env.CHEQUE_BROWSER_URL || 'http://127.0.0.1:8150';
const artifacts = __dirname + '/.browser-artifacts/cheque-recovery';
fs.mkdirSync(artifacts, { recursive: true });

(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const page = await browser.newPage({ viewport: { width: 1366, height: 900 } });
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  const stamp = Date.now();
  async function entry(row, amount, number) {
    for (const [key, value] of Object.entries({
      amount,
      cheque_number: number,
      cheque_date: '2026-10-15',
      bank_name: 'Recovery Browser Bank',
    })) {
      await row.locator(`[data-cheque-field=${key}]`).fill(value);
    }
  }
  async function create(number) {
    await page.goto(base + '/cheques/create');
    await page
      .locator('[name=customer_id]')
      .selectOption(
        await page
          .locator('[name=customer_id] option')
          .filter({ hasText: 'Browser Cheque Customer' })
          .getAttribute('value'),
      );
    await entry(page.locator('.cheque-entry').first(), '1000', number);
    await page.getByRole('button', { name: 'Record cheques', exact: true }).click();
    await page.waitForLoadState('networkidle');
    await page.goto(base + '/cheques?q=' + number);
    await page.getByRole('link', { name: '#' + number, exact: true }).click();
    return page.url();
  }
  async function save(dialog, label) {
    await dialog.getByRole('button', { name: label, exact: true }).click();
    await page.waitForLoadState('networkidle');
    assert.equal(
      await page.locator('dialog[open]').count(),
      0,
      await page.locator('body').innerText(),
    );
    assert.ok(!(await page.locator('body').innerText()).includes('Whoops'));
  }
  try {
    await page.goto(base + '/login');
    await page.locator('[name=login]').fill('admin');
    await page.locator('[name=password]').fill('password');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await page.waitForURL(base + '/');
    await create('DUE-' + stamp);
    await page.getByRole('link', { name: 'Edit', exact: true }).click();
    await page.locator('[name=bank_name]').fill('Corrected Browser Bank');
    await page.locator('[name=reference]').fill('Corrected reference');
    await page.getByRole('button', { name: 'Save changes', exact: true }).click();
    await page.waitForLoadState('networkidle');
    assert.ok((await page.locator('body').innerText()).includes('Corrected Browser Bank'));
    await page.getByRole('button', { name: 'Mark Returned', exact: true }).click();
    let dialog = page.locator('dialog[open]');
    assert.equal(await dialog.locator('[name=reason]').getAttribute('required'), null);
    assert.equal(await dialog.locator('[name=resolution]:checked').inputValue(), 'DUE');
    await page.screenshot({ path: artifacts + '/return-options.png' });
    await save(dialog, 'Confirm return');
    assert.ok((await page.locator('body').innerText()).includes('Balance left due'));

    const originalUrl = await create('SPLIT-' + stamp);
    await page.getByRole('button', { name: 'Mark Returned', exact: true }).click();
    dialog = page.locator('dialog[open]');
    await dialog.locator('[name=reason]').fill('Bank returned cheque');
    await dialog.locator('[name=resolution][value=REPLACE]').check();
    await entry(dialog.locator('.cheque-entry').first(), '300', 'REPLACE-A-' + stamp);
    await dialog.locator('[data-add-cheque]').click();
    await entry(dialog.locator('.cheque-entry').nth(1), '400', 'REPLACE-B-' + stamp);
    assert.equal(await dialog.locator('[name=amount]').inputValue(), '700.00');
    assert.ok((await dialog.locator('[data-recovery-balance]').innerText()).includes('300.00'));
    await dialog.locator('.cheque-entry').nth(1).locator('[data-cheque-field=amount]').fill('800');
    assert.equal(await dialog.locator('[data-recovery-submit]').isDisabled(), true);
    await dialog.locator('.cheque-entry').nth(1).locator('[data-cheque-field=amount]').fill('400');
    await dialog
      .locator('.cheque-entry')
      .first()
      .locator('[data-cheque-field=cheque_number]')
      .fill('SPLIT-' + stamp);
    await dialog.getByRole('button', { name: 'Confirm return', exact: true }).click();
    await page.waitForLoadState('networkidle');
    dialog = page.locator('dialog[open]');
    await dialog.waitFor({ state: 'visible' });
    assert.ok(
      (await dialog.locator('.notice.error').innerText()).includes('already exists'),
    );
    assert.equal(await dialog.locator('.cheque-entry').count(), 2);
    assert.equal(await dialog.locator('[name=reason]').inputValue(), 'Bank returned cheque');
    await dialog
      .locator('.cheque-entry')
      .first()
      .locator('[data-cheque-field=cheque_number]')
      .fill('REPLACE-A-' + stamp);
    await page.setViewportSize({ width: 390, height: 844 });
    const bounds = await dialog.boundingBox();
    assert.ok(bounds.x >= 0 && bounds.x + bounds.width <= 391);
    await page.screenshot({ path: artifacts + '/multiple-replacements-mobile.png' });
    await save(dialog, 'Confirm return');
    await page.setViewportSize({ width: 1366, height: 900 });
    assert.ok((await page.locator('body').innerText()).includes('Bank returned cheque'));
    assert.ok((await page.locator('body').innerText()).includes('REPLACE-A-' + stamp));
    assert.ok((await page.locator('body').innerText()).includes('REPLACE-B-' + stamp));
    await page.getByRole('button', { name: 'Resolve return', exact: true }).click();
    dialog = page.locator('dialog[open]');
    await dialog.locator('[name=resolution][value=PAYMENT]').check();
    await dialog.locator('[name=payment_method_id]').selectOption({ label: 'Cash' });
    assert.equal(await dialog.locator('[name=amount]').inputValue(), '300.00');
    await save(dialog, 'Save resolution');
    assert.equal(
      await page.getByRole('button', { name: 'Resolve return', exact: true }).count(),
      0,
    );
    await page.screenshot({ path: artifacts + '/resolution-history.png', fullPage: true });
    await page.getByRole('link', { name: '#REPLACE-A-' + stamp, exact: true }).click();
    assert.ok((await page.locator('body').innerText()).includes('Replaces'));
    await page.goto(originalUrl);
    assert.equal(await page.getByRole('link', { name: 'Edit', exact: true }).count(), 0);

    // Own supplier cheques support partial cash and optional register cash-out.
    await page.goto(base + '/cheques/create');
    await page.locator('[data-cheque-kind]').selectOption('OWN_CHEQUE');
    await page
      .locator('[name=purchase_id]')
      .selectOption(
        await page
          .locator('[name=purchase_id] option')
          .filter({ hasText: 'PUR-CHEQUE-BROWSER' })
          .getAttribute('value'),
      );
    await entry(page.locator('.cheque-entry').first(), '1000', 'OWN-' + stamp);
    await page.getByRole('button', { name: 'Record cheques', exact: true }).click();
    await page.waitForLoadState('networkidle');
    await page.goto(base + '/cheques?q=OWN-' + stamp);
    await page.getByRole('link', { name: '#OWN-' + stamp, exact: true }).click();
    await page.getByRole('button', { name: 'Mark Returned', exact: true }).click();
    dialog = page.locator('dialog[open]');
    assert.equal(await dialog.locator('[name=recovery_side]').inputValue(), 'SUPPLIER');
    await dialog.locator('[name=resolution][value=REPLACE]').check();
    assert.equal(await dialog.locator('[data-supplier-source]').first().isVisible(), true);
    await dialog.locator('[name=resolution][value=PAYMENT]').check();
    await dialog.locator('[name=payment_method_id]').selectOption({ label: 'Cash' });
    assert.equal(await dialog.locator('[data-recovery-register]').isVisible(), true);
    await dialog.locator('[name=take_from_register]').check();
    await dialog.locator('[name=amount]').fill('600');
    await save(dialog, 'Confirm return');
    assert.ok((await page.locator('body').innerText()).includes('400.00'));
    assert.ok((await page.locator('body').innerText()).includes('Payment recorded'));
    assert.deepEqual(errors, []);
    console.log(
      'Cheque edit, optional reason, due, split replacements, partial cash recovery, history, limits and mobile layout passed.',
    );
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
