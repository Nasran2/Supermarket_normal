// Use the isolated tax fixture with TAX_BROWSER_MISSING_SUPPLIER=true on port 8152.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    const page = await browser.newPage({
      viewport: { width: 390, height: 700 },
      acceptDownloads: true,
    });
    await page.addInitScript(() => {
      window.print = () => {
        window.__taxPrinted = true;
      };
    });
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('http://127.0.0.1:8152/login');
    await page.locator('[name=login]').fill('tax-browser');
    await page.locator('[name=password]').fill('password');
    await page.locator('button[type=submit]').click();
    await page.waitForURL('http://127.0.0.1:8152/');
    await page.goto('http://127.0.0.1:8152/pos');
    await page.locator('#customer').selectOption({ label: 'Complete Tax Customer' });
    await page.locator('#product-search').fill('Tax Browser Grocery');
    await page.locator('.product-tile').filter({ hasText: 'Tax Browser Grocery' }).first().click();
    await page.locator('#open-payment').click();
    await page.waitForFunction(() => !document.querySelector('#confirm-payment').disabled);
    await page.locator('#confirm-payment').click();
    await page.locator('#bill-type-dialog').waitFor({ state: 'visible' });
    await page.keyboard.press('2');
    await page.locator('#tax-customer-dialog').waitFor({ state: 'visible' });
    assert.equal(
      await page.locator('#tax-customer-title').innerText(),
      'Complete your shop details',
    );
    assert.equal(
      await page.locator('#tax-customer-form [name=name]').inputValue(),
      'Complete Tax Customer',
    );
    for (const [key, value] of Object.entries({
      business_name: 'Tax Browser Store',
      tax_number: '123456789',
      address: '10 Store Street',
      phone: '0112345678',
    })) {
      await page.locator(`[name=supplier_${key}]`).fill(value);
    }
    const save = await page.locator('#save-tax-customer').boundingBox();
    assert.ok(save.y + save.height <= 700, 'Save remains visible while the fields scroll');
    await page.screenshot({
      path: 'tests/.browser-artifacts/customer-tax/shop-details-mobile.png',
    });
    await page.locator('#save-tax-customer').click();
    await page.locator('#tax-receipt-dialog').waitFor({ state: 'visible' });
    await page.waitForFunction(() => !document.querySelector('#print-tax-receipt').disabled);
    assert.equal(
      await page
        .frameLocator('#tax-receipt-frame')
        .locator('body')
        .evaluate(() => window.__taxPrinted),
      true,
    );
    const download = page.waitForEvent('download');
    await page.locator('#download-tax-receipt').click();
    await download;
    await page.locator('#tax-customer-dialog').waitFor({ state: 'hidden' });
    assert.deepEqual(errors, []);
    console.log(
      'Complete customer + missing shop passed on mobile: shop saved, PDF downloaded, no browser errors.',
    );
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
