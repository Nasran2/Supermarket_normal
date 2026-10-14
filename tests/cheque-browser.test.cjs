const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const artifacts = __dirname + '/.browser-artifacts/cheques';
fs.mkdirSync(artifacts, { recursive: true });
(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  const base = 'http://127.0.0.1:8140';
  const stamp = Date.now();
  async function login(p, url) {
    await p.goto(url + '/login');
    await p.locator('[name=login]').fill('admin');
    await p.locator('[name=password]').fill('password');
    await p.locator('button[type=submit]').click();
    await p.waitForURL(url + '/');
  }
  async function fillEntry(row, amount, date, number) {
    for (const [field, value] of Object.entries({
      amount,
      cheque_date: date,
      cheque_number: number,
      bank_name: 'Browser Bank',
    }))
      await row.locator(`[data-cheque-field=${field}]`).fill(value);
  }
  async function action(number, kind) {
    await page.goto(base + '/cheques?q=' + encodeURIComponent(number));
    await page
      .locator('tbody')
      .getByRole('link', { name: '#' + number, exact: true })
      .click();
    await page
      .getByRole('button', { name: kind === 'pass' ? 'Mark Passed' : 'Mark Returned', exact: true })
      .click();
    const dialog = page.locator('dialog[open]');
    if (kind === 'return') await dialog.locator('[name=reason]').fill('Insufficient funds');
    await dialog
      .getByRole('button', {
        name: kind === 'pass' ? 'Confirm Passed' : 'Confirm return',
        exact: true,
      })
      .click();
    await page.waitForLoadState('networkidle');
    assert.ok(!(await page.locator('body').innerText()).includes('Whoops'));
  }
  try {
    await login(page, base);
    await page.goto(base + '/manage/roles/create');
    assert.ok((await page.locator('body').innerText()).includes('Mark cheques passed'));
    await page.goto(base + '/manage/customers');
    await page.getByRole('link', { name: 'View Browser Cheque Customer', exact: true }).click();
    const customerUrl = page.url();
    await page.getByRole('button', { name: 'Collect Payment', exact: true }).click();
    const form = page.locator('#payment-form');
    await form.locator('[name=payment_method_id]').selectOption({ label: 'Cheque' });
    await form.locator('[data-cheque-editor]').waitFor({ state: 'visible' });
    const numbers = [`REC-A-${stamp}`, `REC-B-${stamp}`, `REC-C-${stamp}`];
    for (let i = 0; i < 3; i++) {
      if (i) await form.locator('[data-add-cheque]').click();
      await fillEntry(
        form.locator('.cheque-entry').nth(i),
        i === 2 ? '5000' : '10000',
        ['2026-10-18', '2026-11-02', '2026-11-25'][i],
        numbers[i],
      );
    }
    assert.equal(await form.locator('[name=amount]').inputValue(), '25000.00');
    await form.getByRole('button', { name: 'Record Payment', exact: true }).click();
    await page.waitForLoadState('networkidle');
    assert.ok((await page.locator('body').innerText()).includes('Payment recorded successfully.'));
    await page.screenshot({ path: artifacts + '/customer-hold.png', fullPage: true });
    await action(numbers[0], 'pass');
    await action(numbers[1], 'return');
    await page.goto(base + '/cheques?q=' + encodeURIComponent(numbers[2]));
    await page
      .locator('tbody')
      .getByRole('link', { name: '#' + numbers[2], exact: true })
      .click();
    await page.getByText('Transfer customer cheque to a supplier', { exact: true }).click();
    await page
      .locator('select[name=purchase_id]')
      .selectOption(
        await page
          .locator('select[name=purchase_id] option')
          .filter({ hasText: 'PUR-CHEQUE-BROWSER' })
          .getAttribute('value'),
      );
    await page.getByRole('button', { name: 'Confirm transfer', exact: true }).click();
    await page.waitForLoadState('networkidle');
    assert.ok(
      (await page.locator('body').innerText()).includes(
        'Browser Cheque Customer → Browser Cheque Supplier',
      ),
    );
    await action(numbers[2], 'return');
    // Purchase dialog supports multiple own cheques and keeps cash drawer controls hidden.
    await page.goto(base + '/purchases');
    await page.getByRole('link', { name: 'PUR-CHEQUE-BROWSER', exact: true }).first().click();
    await page.locator('[data-purchase-dialog="purchase-pay-dialog"]').click();
    const pay = page.locator('#purchase-pay-dialog');
    await page.evaluate(
      () => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))),
    );
    await pay.locator('[name=payment_method_id]').selectOption({ label: 'Cheque' });
    await fillEntry(pay.locator('.cheque-entry').first(), '10000', '2026-10-18', `OWN-A-${stamp}`);
    await pay.locator('[data-add-cheque]').click();
    await fillEntry(pay.locator('.cheque-entry').nth(1), '15000', '2026-11-02', `OWN-B-${stamp}`);
    assert.equal(await pay.locator('[name=amount]').inputValue(), '25000.00');
    await pay.getByRole('button', { name: 'Record payment', exact: true }).click();
    await page.waitForLoadState('networkidle');
    assert.ok((await page.locator('body').innerText()).includes('Cheque Hold'));
    // Complete a POS bill with three independent cheque entries.
    await page.goto(base + '/pos');
    await page.locator('#product-search').fill('Cheque Browser Grocery');
    await page
      .locator('.product-tile')
      .filter({ hasText: 'Cheque Browser Grocery' })
      .first()
      .click();
    await page
      .locator('#customer')
      .selectOption(
        await page
          .locator('#customer option')
          .filter({ hasText: 'Browser Cheque Customer' })
          .first()
          .getAttribute('value'),
      );
    await page.locator('#open-payment').click();
    await page.locator('.payment-option[data-method-type=CHEQUE]').click();
    await page.waitForFunction(() => document.querySelector('#confirm-payment').disabled);
    for (let i = 0; i < 3; i++) {
      if (i) await page.locator('[data-add-pos-cheque]').click();
      const row = page.locator('.cheque-pos-entries .cheque-entry').nth(i);
      for (const [key, value] of Object.entries({
        amount: i === 2 ? '5000' : '10000',
        cheque_date: ['2026-10-18', '2026-11-02', '2026-11-25'][i],
        cheque_number: `POS-${i}-${stamp}`,
        bank_name: 'Browser POS Bank',
      }))
        await row.locator(`[data-cheque-pos-field=${key}]`).fill(value);
      if (i < 2) assert.equal(await page.locator('#confirm-payment').isDisabled(), true);
    }
    await page.waitForFunction(() => !document.querySelector('#confirm-payment').disabled);
    assert.ok((await page.locator('[data-pos-cheque-total]').innerText()).includes('Remaining: 0.00'));
    await page.locator('#confirm-payment').click();
    await page.locator('#receipt-dialog').waitFor({ state: 'visible' });
    await page.screenshot({ path: artifacts + '/pos-cheques.png', fullPage: true });
    assert.ok(
      (
        await page.locator('#receipt-dialog iframe').contentFrame().locator('body').innerText()
      ).includes('3 cheques'),
    );
    const receipt = page.locator('#receipt-dialog iframe').contentFrame();
    assert.equal(await receipt.locator('small').filter({ hasText: 'Cheque date:' }).count(), 3);
    assert.equal(await receipt.locator('small').filter({ hasText: 'Cheque no:' }).count(), 3);
    assert.ok((await receipt.locator('body').innerText()).includes(`POS-0-${stamp}`));
    // The standalone entry screen and bank/settings screens render without errors.
    await page.goto(base + '/cheques/create');
    await page.locator('[data-cheque-kind]').selectOption('OWN_CHEQUE');
    assert.equal(await page.locator('[data-supplier-party]').isVisible(), true);
    assert.equal(await page.locator('[data-receive-party]').isVisible(), false);
    await page.goto(base + '/settings/cheques');
    await page.locator('[name=cheque_alert_days]').fill('3');
    await page.getByRole('button', { name: 'Save settings', exact: true }).click();
    await page.waitForLoadState('networkidle');
    assert.ok((await page.locator('body').innerText()).includes('Cheque settings saved.'));
    await page.goto(base + '/reports/cheques');
    await page.screenshot({ path: artifacts + '/report.png', fullPage: true });
    for (const width of [1366, 1024, 768]) {
      await page.setViewportSize({ width, height: 900 });
      assert.equal(
        await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
        true,
      );
    }
    const disabledContext = await browser.newContext();
    const off = await disabledContext.newPage();
    await login(off, 'http://127.0.0.1:8141');
    for (const path of [
      '/pos',
      '/purchases/create',
      '/manage/roles/create',
      '/settings',
      '/reports',
      new URL(customerUrl).pathname,
    ]) {
      await off.goto('http://127.0.0.1:8141' + path);
      assert.ok(!(await off.locator('body').innerText()).includes('Cheque Hold'));
      assert.equal(await off.locator('[data-cheque-editor]').count(), 0);
      assert.ok(!(await off.locator('body').innerText()).includes('Mark cheques passed'));
      assert.equal(await off.locator('.sidebar').getByText('Cheques', { exact: true }).count(), 0);
    }
    assert.equal((await off.request.get('http://127.0.0.1:8141/cheques')).status(), 404);
    await disabledContext.close();
    assert.deepEqual(errors, []);
    console.log(
      'Cheque browser checks passed: multiple customer cheques, pass/return, transfer and linked return, multiple own cheques, settings, reports, responsive widths and disabled feature UI.',
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
