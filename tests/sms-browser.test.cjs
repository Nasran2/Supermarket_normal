// Uses only tests/Support/sms-browser-router.php on the isolated SMS fixture server.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const base = 'http://127.0.0.1:8146';
  const artifacts = __dirname + '/.browser-artifacts/sms';
  fs.mkdirSync(artifacts, { recursive: true });
  const context = await browser.newContext({ viewport: { width: 1366, height: 1000 } });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  try {
    await page.goto(base + '/login');
    await page.locator('[name=login]').fill('admin');
    await page.locator('[name=password]').fill('password');
    await page.locator('button[type=submit]').click();
    await page.waitForURL(base + '/');
    await page.goto(base + '/settings');
    await page.locator('.settings-card').filter({ hasText: 'SMS settings' }).click();
    await page.waitForURL(base + '/settings/sms');
    await page.locator('[name=username]').fill('94770000002');
    await page.locator('[name=password]').fill('settings-browser-only');
    await page.locator('[name=admin_numbers]').fill('0771234567');
    await page.locator('[name=cheque_reminder_time]').fill('09:15');
    await page
      .locator('[data-sms-template-card]')
      .filter({ has: page.locator('[name=receipt_enabled]') })
      .locator('summary')
      .click();
    await page
      .locator('[name=receipt_template]')
      .fill(
        'Hi {name}. Thank you from {business}. Invoice: {invoice}. Total {currency} {total}. Due {due}. View bill: {receipt_url}',
      );
    await page
      .locator('[data-sms-template-card]')
      .filter({ has: page.locator('[name=payment_enabled]') })
      .locator('summary')
      .click();
    await page
      .locator('[name=payment_template]')
      .fill(
        'Thanks {name}, paid {currency} {amount}. Balance: {currency} {due}. {payment_status}.',
      );
    assert.match(
      await page
        .locator('[data-sms-template-card]')
        .filter({ has: page.locator('[name=payment_enabled]') })
        .locator('[data-sms-template-preview]')
        .innerText(),
      /Thanks Sample Customer, paid Rs. 1,500.00/,
    );
    await page.getByRole('button', { name: 'Save SMS settings' }).click();
    await page.getByText('SMS settings saved.').waitFor();
    assert.equal(await page.locator('[name=password]').inputValue(), '');
    assert.equal(await page.locator('[name=username]').inputValue(), '94770000002');
    assert.equal(await page.locator('[name=admin_numbers]').inputValue(), '94771234567');
    assert.equal(await page.locator('[name=cheque_reminder_time]').inputValue(), '09:15');
    assert.equal((await page.content()).includes('settings-browser-only'), false);
    await page.locator('.sms-connection-test > summary').click();
    await page.locator('#sms-test-phone').fill('0771234567');
    await page.locator('#sms-test-message').fill('Browser connection test');
    await page.getByRole('button', { name: 'Send test SMS', exact: true }).click();
    await page
      .locator('#sms-test-result')
      .filter({ hasText: 'Test SMS accepted by Textit for 94771234567.' })
      .waitFor();
    assert.equal(await page.locator('#sms-test-send').isDisabled(), true);
    assert.equal(await page.locator('#sms-test-another').isVisible(), true);
    assert.equal(await page.locator('[name=username]').inputValue(), '94770000002');
    assert.equal(page.url(), base + '/settings/sms');
    await page.screenshot({ path: artifacts + '/sms-settings.png', fullPage: true });
    await page.goto(base + '/sms');
    await page.screenshot({ path: artifacts + '/sms-workspace-desktop.png', fullPage: true });
    const searchButton = await page.locator('.sms-search-form .btn').first().boundingBox();
    assert.ok(searchButton.height < 55, 'Search button should align with the input');
    await page.locator('[name=kind]').selectOption('CUSTOM');
    await page.locator('[name="customers[]"]').first().check();
    await page.locator('[name=message]').fill('Hi {name}, offers from {business}.');
    await page.getByRole('button', { name: 'Preview messages' }).click();
    await page.getByRole('heading', { name: '1 SMS message' }).waitFor();
    assert.match(
      await page.locator('.sms-preview-bubble').innerText(),
      /Hi Existing SMS Buyer, offers from SMS Test Market/,
    );
    await page.screenshot({ path: artifacts + '/bulk-preview.png', fullPage: true });
    await page.getByRole('button', { name: 'Send 1 message' }).click();
    await page.waitForURL(base + '/sms');
    assert.match(await page.locator('main').innerText(), /PENDING/);
    await page.goto(base + '/sales/1');
    await page.getByRole('button', { name: 'Send receipt SMS', exact: true }).click();
    await page.locator('#sms-customer-fields').waitFor({ state: 'visible' });
    assert.equal(await page.locator('#sms-customer-name').isVisible(), false);
    assert.equal(await page.locator('#sms-customer-phone').isVisible(), false);
    assert.equal(await page.locator('#sms-receipt-submit').isDisabled(), true);
    await page.locator('#sms-customer-search').fill('Nobody matches this');
    await page
      .getByText('No customers found. Try another search or choose New customer.')
      .waitFor();
    assert.equal(await page.locator('#sms-receipt-submit').isDisabled(), true);
    await page.locator('#sms-customer-search').fill('Existing');
    await page
      .locator('#sms-customer-select option')
      .filter({ hasText: 'Existing SMS Buyer' })
      .waitFor({ state: 'attached' });
    await page
      .locator('#sms-customer-select')
      .selectOption({ label: 'Existing SMS Buyer · 0771234567' });
    assert.match(
      await page.locator('#sms-receipt-recipient').innerText(),
      /Sending to\s+Existing SMS Buyer\s+0771234567/,
    );
    assert.equal(await page.locator('#sms-customer-name').isVisible(), false);
    assert.equal(await page.locator('#sms-customer-phone').isVisible(), false);
    assert.equal(await page.locator('#sms-receipt-submit').isDisabled(), false);
    await page.screenshot({ path: artifacts + '/select-customer.png', fullPage: true });
    await page.locator('#sms-receipt-submit').click();
    await page.getByText('Receipt accepted by Textit for 94771234567.').waitFor();
    assert.equal(await page.locator('#sms-customer-fields').isVisible(), false);
    assert.equal(await page.locator('#sms-receipt-submit').isVisible(), false);
    await page.locator('[data-sms-close]').last().click();
    // Closing while the customer check is in flight must cancel the automatic send.
    let releaseContext;
    const contextGate = new Promise((resolve) => {
      releaseContext = resolve;
    });
    let cancelledPosts = 0;
    const onRequest = (request) => {
      if (request.url() === base + '/sms/receipts/1' && request.method() === 'POST')
        cancelledPosts++;
    };
    page.on('request', onRequest);
    await page.route(base + '/sms/receipts/1', async (route) => {
      if (route.request().method() === 'GET') await contextGate;
      await route.continue();
    });
    await page.getByRole('button', { name: 'Send receipt SMS', exact: true }).click();
    await page.locator('#sms-receipt-dialog[open]').waitFor();
    await page.locator('[data-sms-close]').last().click();
    const contextResponse = page.waitForResponse(
      (response) =>
        response.url() === base + '/sms/receipts/1' && response.request().method() === 'GET',
    );
    releaseContext();
    await contextResponse;
    await page.evaluate(() => new Promise(requestAnimationFrame));
    assert.equal(cancelledPosts, 0);
    page.off('request', onRequest);
    await page.unroute(base + '/sms/receipts/1');
    // A bill already linked to a customer only asks for their missing number.
    await page.goto(base + '/sales/2');
    await page.getByRole('button', { name: 'Send receipt SMS', exact: true }).click();
    await page.locator('#sms-customer-phone').waitFor({ state: 'visible' });
    assert.equal(await page.locator('#sms-recipient-modes').isVisible(), false);
    assert.equal(await page.locator('#sms-existing-fields').isVisible(), false);
    assert.equal(await page.locator('#sms-customer-name').isVisible(), false);
    assert.match(await page.locator('#sms-receipt-recipient').innerText(), /Missing Phone Buyer/);
    assert.equal(await page.locator('#sms-receipt-submit').isDisabled(), true);
    await page.locator('#sms-customer-phone').fill('0772222222');
    await page.locator('#sms-receipt-submit').click();
    await page.getByText('Receipt accepted by Textit for 94772222222.').waitFor();
    assert.match(await page.locator('#sms-receipt-recipient').innerText(), /0772222222/);
    assert.equal(await page.locator('#sms-phone-fields').isVisible(), false);
    await page.locator('[data-sms-close]').last().click();
    // Saved customers with valid numbers still send automatically.
    await page.getByRole('button', { name: 'Send receipt SMS', exact: true }).click();
    await page.getByText('Receipt accepted by Textit for 94772222222.').waitFor();
    await page.locator('[data-sms-close]').last().click();
    // POS completion shows Print and Send SMS together. A walk-in receipt asks for name and phone.
    await page.goto(base + '/pos');
    await page.locator('.product-tile').filter({ hasText: 'SMS Test Product' }).click();
    await page.locator('#open-payment').click();
    await page.locator('.payment-option[data-method-type=CASH]').click();
    await page.waitForFunction(() => !document.getElementById('confirm-payment').disabled);
    await page.locator('#confirm-payment').click();
    await page.locator('#receipt-dialog').waitFor({ state: 'visible' });
    await page.locator('#print-receipt:not([disabled])').waitFor();
    await page.screenshot({ path: artifacts + '/receipt-with-sms.png', fullPage: true });
    assert.equal(await page.locator('#sms-receipt').isVisible(), true);
    await page.locator('#sms-receipt').click();
    await page.locator('#sms-customer-fields').waitFor({ state: 'visible' });
    await page.locator('[data-sms-mode=new]').click();
    assert.equal(await page.locator('#sms-existing-fields').isVisible(), false);
    assert.equal(await page.locator('#sms-customer-select').isDisabled(), true);
    assert.equal(await page.locator('#sms-receipt-submit').isDisabled(), true);
    await page.locator('#sms-customer-name').fill('New Browser Buyer');
    await page.locator('#sms-customer-phone').fill('123');
    assert.equal(await page.locator('#sms-receipt-submit').isDisabled(), true);
    await page.locator('#sms-customer-phone').fill('0779876543');
    assert.equal(await page.locator('#sms-receipt-submit').isDisabled(), false);
    await page.screenshot({
      path: artifacts + '/new-receipt-customer-desktop.png',
      fullPage: true,
    });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: artifacts + '/new-receipt-customer-mobile.png', fullPage: true });
    const modalBox = await page.locator('#sms-receipt-dialog').boundingBox();
    assert.ok(
      modalBox.x >= 0 && modalBox.x + modalBox.width <= 390,
      'SMS dialog should fit mobile width',
    );
    assert.ok(
      modalBox.y >= 0 && modalBox.y + modalBox.height <= 844,
      'SMS dialog should fit mobile height',
    );
    // A provider failure stays visible in the SMS dialog instead of returning as a success.
    const posSmsUrl = await page.locator('#sms-receipt').getAttribute('data-sms-receipt-url');
    const failSms = (route) =>
      route.request().method() === 'POST'
        ? route.fulfill({ json: { status: 'FAILED', message: 'Test gateway rejected the SMS.' } })
        : route.continue();
    await page.route(posSmsUrl, failSms);
    await page.locator('#sms-receipt-submit').click();
    await page
      .locator('#sms-receipt-status')
      .filter({ hasText: 'Test gateway rejected the SMS.' })
      .waitFor();
    assert.equal(await page.locator('#sms-receipt-dialog').isVisible(), true);
    assert.equal(await page.locator('#receipt-sms-status').isVisible(), false);
    await page.locator('[data-sms-close]').last().click();
    await page.unroute(posSmsUrl, failSms);
    await page.locator('#sms-receipt').click();
    await page.locator('#sms-customer-fields').waitFor({ state: 'visible' });
    await page.locator('[data-sms-mode=new]').click();
    await page.locator('#sms-customer-name').fill('New Browser Buyer');
    await page.locator('#sms-customer-phone').fill('0779876543');
    const sendRequest = page.waitForRequest(
      (request) => request.url().includes('/sms/receipts/') && request.method() === 'POST',
    );
    await page.locator('#sms-receipt-submit').click();
    const newCustomerPayload = (await sendRequest).postDataJSON();
    assert.equal(newCustomerPayload.customer_id, undefined);
    assert.equal(newCustomerPayload.name, 'New Browser Buyer');
    await page.setViewportSize({ width: 1366, height: 1000 });
    await page.locator('#sms-receipt-dialog').waitFor({ state: 'hidden' });
    assert.equal(await page.locator('#receipt-dialog').isVisible(), true);
    await page
      .locator('#receipt-sms-status')
      .filter({ hasText: 'Receipt accepted by Textit for 94779876543.' })
      .waitFor();
    assert.equal(await page.locator('#sms-receipt').isDisabled(), true);
    assert.equal(await page.locator('#print-receipt').isEnabled(), true);
    assert.equal(await page.locator('#next-sale').isEnabled(), true);
    assert.equal(
      await page.locator('#next-sale').evaluate((button) => document.activeElement === button),
      true,
    );
    await page.screenshot({ path: artifacts + '/sale-complete-after-sms.png', fullPage: true });
    await page.setViewportSize({ width: 390, height: 640 });
    const actionsBox = await page.locator('#receipt-dialog .receipt-actions').boundingBox();
    assert.ok(
      actionsBox.y >= 0 && actionsBox.y + actionsBox.height <= 640,
      'Receipt actions remain visible after SMS on a small screen',
    );
    await page.screenshot({
      path: artifacts + '/sale-complete-after-sms-mobile.png',
      fullPage: true,
    });
    await page.setViewportSize({ width: 1366, height: 1000 });
    // A new sale resets the SMS confirmation; a saved number returns automatically too.
    await page.locator('#next-sale').click();
    await page.locator('#customer').selectOption({ label: 'Existing SMS Buyer' });
    await page.locator('.product-tile').filter({ hasText: 'SMS Test Product' }).click();
    await page.locator('#open-payment').click();
    await page.locator('.payment-option[data-method-type=CASH]').click();
    await page.waitForFunction(() => !document.getElementById('confirm-payment').disabled);
    await page.locator('#confirm-payment').click();
    await page.locator('#receipt-dialog').waitFor({ state: 'visible' });
    await page.locator('#print-receipt:not([disabled])').waitFor();
    assert.equal(await page.locator('#receipt-sms-status').isVisible(), false);
    assert.equal(await page.locator('#sms-receipt').isEnabled(), true);
    await page.locator('#sms-receipt').click();
    await page
      .locator('#receipt-sms-status')
      .filter({ hasText: 'Receipt accepted by Textit for 94771234567.' })
      .waitFor();
    assert.equal(await page.locator('#sms-receipt-dialog').isVisible(), false);
    assert.equal(await page.locator('#receipt-dialog').isVisible(), true);
    // Account due payments queue one personalized customer confirmation.
    await page.goto(base + '/manage/customers/1');
    await page.getByRole('button', { name: 'Collect Payment', exact: true }).click();
    await page.locator('#payment-modal [name=amount]').fill('100');
    await page.locator('#payment-modal [name=payment_method_id]').selectOption({ label: 'Cash' });
    await page.getByRole('button', { name: 'Record Payment', exact: true }).click();
    await page.getByText('Payment recorded successfully.').waitFor();
    await page.goto(base + '/sms');
    assert.match(
      await page.locator('.sms-history-panel').innerText(),
      /Thanks Existing SMS Buyer, paid Rs. 100.00/,
    );
    assert.equal(
      await page
        .locator('.sms-history-panel tbody tr')
        .filter({ hasText: 'Due-payment confirmation' })
        .count(),
      1,
    );
    // Pause all sends without losing the workspace or history, and independently turn off payment confirmations.
    await page.goto(base + '/settings/sms');
    await page.locator('[name=sending_enabled][type=checkbox]').uncheck();
    await page.locator('[name=payment_enabled][type=checkbox]').uncheck();
    await page.getByRole('button', { name: 'Save SMS settings' }).click();
    await page.getByText('SMS settings saved.').waitFor();
    await page.goto(base + '/sms');
    await page.getByText(/SMS sending is paused/).waitFor();
    assert.equal(await page.getByRole('button', { name: 'Preview messages' }).isDisabled(), true);
    await page.goto(base + '/pos');
    assert.equal(await page.locator('#sms-receipt').count(), 0);
    await page.goto(base + '/settings/sms');
    await page.locator('[name=sending_enabled][type=checkbox]').check();
    await page.getByRole('button', { name: 'Save SMS settings' }).click();
    await page.getByText('SMS settings saved.').waitFor();
    await page.goto(base + '/manage/customers/1');
    await page.getByRole('button', { name: 'Collect Payment', exact: true }).click();
    await page.locator('#payment-modal [name=amount]').fill('25');
    await page.locator('#payment-modal [name=payment_method_id]').selectOption({ label: 'Cash' });
    await page.getByRole('button', { name: 'Record Payment', exact: true }).click();
    await page.getByText('Payment recorded successfully.').waitFor();
    await page.goto(base + '/sms');
    assert.equal(
      await page
        .locator('.sms-history-panel tbody tr')
        .filter({ hasText: 'Due-payment confirmation' })
        .count(),
      1,
    );
    await page.screenshot({ path: artifacts + '/sms-history-desktop.png', fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.waitForFunction(
      () => getComputedStyle(document.querySelector('.app-shell')).marginLeft === '0px',
    );
    assert.ok(
      await page
        .locator('.main-content')
        .evaluate((main) => main.getBoundingClientRect().width > 350),
      'Mobile content should use the screen width',
    );
    await page.screenshot({ path: artifacts + '/sms-workspace-mobile.png', fullPage: true });
    assert.ok(
      await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1),
      'SMS workspace should not overflow horizontally',
    );
    await page.setViewportSize({ width: 1366, height: 1000 });
    // Customer receipt links open without a login session and retain printing.
    await page.goto(base + '/sms');
    const text = await page.locator('main').innerText();
    const match = text.match(/View bill: (https?:\/\/[^\s]+\/r\/[A-Za-z0-9]{64})/);
    assert.ok(match, 'SMS history should include public receipt URL');
    const publicPath = new URL(match[1]).pathname;
    const guest = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const receipt = await guest.newPage();
    const publicResponse = await receipt.goto(base + publicPath);
    assert.equal(
      publicResponse.status(),
      200,
      `Public receipt returned ${publicResponse.status()} at ${publicPath}`,
    );
    await receipt.screenshot({ path: artifacts + '/public-receipt-mobile.png', fullPage: true });
    await receipt.getByRole('button', { name: 'Print receipt' }).waitFor();
    assert.equal(await receipt.getByText('Back to POS').count(), 0);
    assert.equal(await receipt.locator('input[type=password]').count(), 0);
    await receipt.screenshot({ path: artifacts + '/public-receipt-mobile.png', fullPage: true });
    await guest.close();
    // The same saved settings must be unavailable through a server with SMS=false.
    const offBase = 'http://127.0.0.1:8147';
    const offContext = await browser.newContext();
    const offPage = await offContext.newPage();
    await offPage.goto(offBase + '/login');
    await offPage.locator('[name=login]').fill('admin');
    await offPage.locator('[name=password]').fill('password');
    await offPage.locator('button[type=submit]').click();
    await offPage.waitForURL(offBase + '/');
    await offPage.goto(offBase + '/settings');
    assert.equal(await offPage.locator('a[href="' + offBase + '/settings/sms"]').count(), 0);
    assert.equal((await offPage.goto(offBase + '/settings/sms')).status(), 404);
    assert.equal((await offPage.goto(offBase + '/sms')).status(), 404);
    assert.equal((await offPage.goto(offBase + publicPath)).status(), 404);
    await offPage.goto(offBase + '/pos');
    assert.equal(await offPage.locator('#sms-receipt').count(), 0);
    await offContext.close();
    assert.deepEqual(errors, []);
    console.log(
      'SMS browser checks passed: editable templates, notification switches, payment confirmations, desktop/mobile layout, SMS=false visibility, preview, queue, customer selection, POS popup, public receipt. No live SMS sent.',
    );
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
