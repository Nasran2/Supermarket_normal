// Read-only invoice checks against the isolated tax-browser fixture on port 8152.
// Set TAX_SHORTCUT_SALE_ID to an issued tax invoice in that fixture (defaults to 41).
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    const page = await browser.newPage({
      viewport: { width: 1366, height: 950 },
      acceptDownloads: true,
    });
    await page.addInitScript(() => {
      window.print = () => {
        window.__printCount = (window.__printCount || 0) + 1;
      };
    });
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('http://127.0.0.1:8152/login');
    await page.locator('[name=login]').fill('tax-browser');
    await page.locator('[name=password]').fill('password');
    await page.locator('button[type=submit]').click();
    await page.waitForURL('http://127.0.0.1:8152/');
    await page.goto(`http://127.0.0.1:8152/sales/${process.env.TAX_SHORTCUT_SALE_ID || '41'}`);
    const frame = page.frameLocator('#tax-receipt-frame');
    async function open() {
      await page.locator('#sale-tax-bill').click();
      await page.keyboard.press('2');
      await page.locator('#tax-receipt-dialog').waitFor({ state: 'visible' });
      await page.waitForFunction(() => !document.querySelector('#print-tax-receipt').disabled);
    }
    async function frameKey(key) {
      await frame.locator('body').click({ position: { x: 8, y: 8 } });
      await page.keyboard.press(key);
    }
    await open();
    assert.equal(await page.locator('#tax-receipt-external kbd').innerText(), '1');
    assert.equal(await page.locator('#download-tax-receipt kbd').innerText(), '2');
    assert.equal(await page.locator('#print-tax-receipt kbd').innerText(), '3');
    assert.match(await page.locator('#next-sale-tax').innerText(), /4.*Esc/s);
    const prints = await frame.locator('body').evaluate(() => window.__printCount);
    await frameKey('3');
    assert.equal(await frame.locator('body').evaluate(() => window.__printCount), prints + 1);
    const download = page.waitForEvent('download');
    await frameKey('2');
    assert.match((await download).suggestedFilename(), /^tax-invoice-.*\.pdf$/);
    const popupPromise = page.waitForEvent('popup');
    await frameKey('1');
    const popup = await popupPromise;
    await popup.waitForLoadState();
    assert.match(popup.url(), /tax-invoice\/print$/);
    await popup.close();
    await page
      .locator('#tax-receipt-dialog')
      .screenshot({ path: 'tests/.browser-artifacts/customer-tax/tax-print-shortcuts.png' });
    await frameKey('4');
    await page.locator('#tax-receipt-dialog').waitFor({ state: 'hidden' });
    await open();
    await frameKey('Escape');
    await page.locator('#tax-receipt-dialog').waitFor({ state: 'hidden' });
    await open();
    await page.locator('#print-tax-receipt').focus();
    await page.keyboard.press('Escape');
    await page.locator('#tax-receipt-dialog').waitFor({ state: 'hidden' });
    assert.deepEqual(errors, []);
    console.log(
      'Tax print shortcuts passed: 1/open, 2/download, 3/print, 4/close and Esc from both invoice and footer focus.',
    );
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
