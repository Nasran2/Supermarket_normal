// Run against the disposable customer-tax-browser fixture on port 8152.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const artifacts = __dirname + '/.browser-artifacts/customer-tax';
  fs.mkdirSync(artifacts, { recursive: true });
  const page = await browser.newPage({
    viewport: { width: 1366, height: 950 },
    acceptDownloads: true,
  });
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  const base = 'http://127.0.0.1:8152';
  let printCalls = 0,
    downloads = 0;
  page.on('download', () => downloads++);
  await page.addInitScript(() => {
    window.print = () => {
      window.top.postMessage('tax-test-print', '*');
    };
  });
  await page.exposeFunction('taxTestPrint', () => printCalls++);
  await page.addInitScript(() =>
    window.addEventListener('message', (event) => {
      if (event.data === 'tax-test-print') window.taxTestPrint();
    }),
  );
  async function sell(customer = '') {
    await page.goto(base + '/pos');
    await page.locator('#customer').selectOption(customer);
    await page.locator('#product-search').fill('Tax Browser Grocery');
    await page.locator('.product-tile').filter({ hasText: 'Tax Browser Grocery' }).first().click();
    await page.locator('#open-payment').click();
    await page.waitForFunction(() => !document.querySelector('#confirm-payment').disabled);
    await page.locator('#confirm-payment').click();
    await page.locator('#bill-type-dialog').waitFor({ state: 'visible' });
  }
  async function option(name) {
    return await page
      .locator('#customer option')
      .filter({ hasText: name })
      .first()
      .getAttribute('value');
  }
  try {
    await page.goto(base + '/login');
    await page.locator('[name=login]').fill('tax-browser');
    await page.locator('[name=password]').fill('password');
    await page.locator('button[type=submit]').click();
    await page.waitForURL(base + '/');
    await sell();
    assert.equal(printCalls, 0, 'auto print must wait for bill choice');
    const normalBox = await page.locator('#bill-normal').boundingBox();
    const taxBox = await page.locator('#bill-tax').boundingBox();
    assert.equal(normalBox.y, taxBox.y, 'bill options should be side by side');
    assert.ok(normalBox.x + normalBox.width < taxBox.x);
    assert.equal(await page.locator('.bill-preview-stage svg').count(), 2);
    assert.equal(
      (await page.locator('.bill-preview-stage').allTextContents())
        .join(' ')
        .includes('Tax Browser'),
      false,
    );
    await page.screenshot({ path: artifacts + '/bill-chooser.png' });
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('#bill-type-dialog').isVisible(), false);
    await page.locator('#pos-message button').click();
    await page.keyboard.press('1');
    await page.locator('#receipt-dialog').waitFor({ state: 'visible' });
    await page.waitForFunction(() => !document.querySelector('#print-receipt').disabled);
    await page.waitForTimeout(300);
    assert.equal(printCalls, 1);
    await page.locator('#next-sale').click();

    await sell();
    await page.route(
      '**/sales/*/tax-invoice/context*',
      async (route) => {
        const response = await route.fetch();
        const data = await response.json();
        await route.fulfill({
          response,
          json: { ...data, missing_supplier: ['TIN / VAT number', 'address'] },
        });
      },
      { times: 1 },
    );
    await page.keyboard.press('2');
    await page.locator('#tax-customer-dialog').waitFor({ state: 'visible' });
    assert.equal(await page.locator('#tax-customer-form [name=name]').inputValue(), '');
    assert.equal(await page.locator('#tax-customer-title').innerText(), 'Add or select a customer');
    assert.equal(await page.locator('#tax-customer-picker').isVisible(), true);
    assert.equal(
      await page.locator('#tax-customer-form [name=tax_number]').getAttribute('required'),
      '',
    );
    const newCustomerName = 'Browser New Tax Customer ' + Date.now();
    await page.locator('#tax-customer-form [name=name]').fill(newCustomerName);
    await page.locator('#tax-customer-form [name=tax_number]').fill('555666777');
    await page.locator('#tax-customer-form [name=phone]').fill('0779999999');
    await page.locator('#tax-customer-form [name=address]').fill('100 New Customer Road');
    const supply = page.locator('#tax-customer-form [name=place_of_supply]');
    assert.equal(await supply.inputValue(), '100 New Customer Road');
    await page.locator('.tax-delivery-details summary').click();
    await supply.fill('Custom delivery location');
    await page.locator('#tax-customer-form [name=address]').fill('101 New Customer Road');
    assert.equal(
      await supply.inputValue(),
      'Custom delivery location',
      'keep manually entered delivery location',
    );
    await supply.fill('');
    await page.locator('#tax-customer-form [name=address]').fill('100 New Customer Road');
    assert.equal(await supply.inputValue(), '100 New Customer Road');
    await page.screenshot({ path: artifacts + '/tax-customer-details.png' });
    if (await page.locator('#tax-supplier-section').isVisible()) {
      await page.locator('[name=supplier_business_name]').fill('Tax Browser Store');
      await page.locator('[name=supplier_tax_number]').fill('123456789');
      await page.locator('[name=supplier_address]').fill('10 Store Street');
      await page.locator('[name=supplier_phone]').fill('0112345678');
      assert.equal(
        await page.locator('#tax-customer-form [name=address]').inputValue(),
        '100 New Customer Road',
      );
      const actions = await page
        .locator('#tax-customer-dialog .customer-dialog-actions')
        .boundingBox();
      assert.ok(actions.y + actions.height <= 950, 'Save action remains within the viewport');
      await page.screenshot({ path: artifacts + '/shop-details-inline.png' });
    }
    await page.locator('#save-tax-customer').click();
    await page.locator('#tax-receipt-dialog').waitFor({ state: 'visible' });
    await page.waitForFunction(() => !document.querySelector('#print-tax-receipt').disabled);
    assert.equal(downloads, 0, 'saving should print, not automatically download');
    assert.match(
      await page.frameLocator('#tax-receipt-frame').locator('body').innerText(),
      /Tax Invoice/,
    );
    await page.screenshot({ path: artifacts + '/tax-print-preview.png' });
    const download = page.waitForEvent('download');
    await page.locator('#download-tax-receipt').click();
    await (await download).saveAs(artifacts + '/tax-invoice.pdf');
    await page.locator('#next-sale-tax').click();
    await page.locator('#tax-customer-dialog').waitFor({ state: 'hidden' });
    assert.equal(await page.locator('#bill-type-dialog').isVisible(), false);
    assert.equal(await page.locator('#cart-count').innerText(), '0');
    assert.equal(
      await page.locator('#customer option').filter({ hasText: newCustomerName }).count(),
      1,
    );

    await page.goto(base + '/pos');
    const incomplete = await option('Incomplete Tax Customer');
    await sell(incomplete);
    await page.route(
      '**/sales/*/tax-invoice/context*',
      async (route) => {
        const response = await route.fetch();
        const data = await response.json();
        await route.fulfill({
          response,
          json: { ...data, missing_supplier: ['TIN / VAT number', 'address'] },
        });
      },
      { times: 1 },
    );
    await page.keyboard.press('2');
    await page.locator('#tax-customer-dialog').waitFor({ state: 'visible' });
    assert.equal(
      await page.locator('#tax-customer-form [name=name]').inputValue(),
      'Incomplete Tax Customer',
    );
    assert.equal(await page.locator('#tax-customer-form [name=phone]').inputValue(), '0771234567');
    assert.match(await page.locator('#tax-customer-help').innerText(), /tax number, address/);
    assert.equal(
      await page
        .locator('#tax-customer-form [name=tax_number]')
        .evaluate((el) => el.closest('.field').dataset.missing),
      'true',
    );
    await page.locator('#tax-customer-form [name=tax_number]').fill('111222333');
    await page.locator('#tax-customer-form [name=address]').fill('200 Existing Customer Road');
    assert.equal(
      await page.locator('#tax-customer-form [name=place_of_supply]').inputValue(),
      '200 Existing Customer Road',
    );
    await page.locator('#save-tax-customer').click();
    await page.locator('#tax-receipt-dialog').waitFor({ state: 'visible' });
    await page.waitForFunction(() => !document.querySelector('#print-tax-receipt').disabled);
    await page.locator('#next-sale-tax').click();
    await page.locator('#tax-customer-dialog').waitFor({ state: 'hidden' });
    await sell(incomplete);
    await page.keyboard.press('2');
    await page.locator('#tax-receipt-dialog').waitFor({ state: 'visible' });
    await page.waitForFunction(() => !document.querySelector('#print-tax-receipt').disabled);
    await page.locator('#next-sale-tax').click();
    assert.ok(printCalls >= 4, 'normal and tax invoices should trigger print');
    await page.locator('#bill-type-dialog').waitFor({ state: 'hidden' });
    assert.equal(await page.locator('#tax-customer-dialog').isVisible(), false);

    assert.equal(
      await page
        .getByRole('link', { name: 'Tax settings', exact: true, includeHidden: true })
        .count(),
      1,
    );
    await page.goto(base + '/settings/customer_tax');
    await page.locator('[name=business_name]').fill('Tax Settings Updated Store');
    await page.locator('[name=tax_invoice_note]').fill('Invoice note from tax settings');
    await page.locator('[name=vat_rate]').fill('10');
    await page.locator('[name=vat_price_mode]').selectOption('EXCLUSIVE');
    await page.locator('form.form-card .form-footer button').click();
    await page.goto(base + '/pos');
    await page.locator('#product-search').fill('Tax Browser Grocery');
    await page.locator('.product-tile').filter({ hasText: 'Tax Browser Grocery' }).first().click();
    assert.match(await page.locator('#cart-vat').innerText(), /11.80/);
    assert.match(await page.locator('#cart-total').innerText(), /129.80/);
    await page.locator('#open-payment').click();
    await page.waitForFunction(() => !document.querySelector('#confirm-payment').disabled);
    assert.match(await page.locator('#payment-payable').innerText(), /129.80/);
    await page.locator('#confirm-payment').click();
    await page.locator('#bill-type-dialog').waitFor({ state: 'visible' });
    await page.keyboard.press('Escape');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.locator('#pos-message button').click();
    await page.screenshot({ path: artifacts + '/bill-chooser-mobile.png' });
    const mobileNormal = await page.locator('#bill-normal').boundingBox();
    const mobileTax = await page.locator('#bill-tax').boundingBox();
    assert.equal(mobileNormal.y, mobileTax.y);
    assert.ok(mobileNormal.x + mobileNormal.width < mobileTax.x);
    assert.equal(
      await page.evaluate(
        () =>
          document.querySelector('#bill-type-dialog').getBoundingClientRect().right <=
          window.innerWidth,
      ),
      true,
    );
    await page.locator('#cancel-bill-type').click();
    await page.goto(base + '/pos');
    const completeCustomer = await option('Complete Tax Customer');
    await sell(completeCustomer);
    await page.keyboard.press('2');
    await page.locator('#tax-receipt-dialog').waitFor({ state: 'visible' });
    await page.waitForFunction(() => !document.querySelector('#print-tax-receipt').disabled);
    const previewText = await page.frameLocator('#tax-receipt-frame').locator('body').innerText();
    assert.match(previewText, /Tax Settings Updated Store/);
    assert.match(previewText, /Invoice note from tax settings/);
    await page.screenshot({ path: artifacts + '/tax-print-mobile.png' });
    assert.deepEqual(errors, []);
    console.log(
      'Tax checkout passed: Esc, shortcuts 1/2, print, walk-in details, existing missing details, repeat PDF, VAT settings, mobile, no browser errors.',
    );
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
