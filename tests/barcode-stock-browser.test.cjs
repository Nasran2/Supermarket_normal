const { chromium } = require('playwright');
const assert = require('node:assert/strict');
(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  try {
    const base = 'http://127.0.0.1:8128';
    await page.goto(base + '/login');
    await page.locator('[name=login]').fill('admin');
    await page.locator('[name=password]').fill('password');
    await page.locator('button[type=submit]').click();
    await page.waitForURL(base + '/');
    const products = await (await page.request.get(base + '/barcode/products?q=BROWSER')).json();
    const dated = products.find((p) => p.sku === 'BROWSER-EXPIRY');
    const milk = products.find((p) => p.sku === 'BROWSER-MILK');
    const soap = products.find((p) => p.name.startsWith('Browser Bath Soap'));
    assert.ok(dated);
    await page.goto(`${base}/barcode?product=${dated.id}`);
    const row = page
      .locator('#barcode-products tr')
      .filter({ hasText: 'Browser Dated Soap' })
      .first();
    await row.waitFor();
    assert.equal(await row.locator('[data-option]').inputValue(), '');
    const key = dated.options.find((o) => o.expires_at === '2027-02-15').key;
    await row.locator('[data-option]').selectOption(key);
    await row.locator('[data-qty]').fill('3');
    await row.locator('[data-qty]').dispatchEvent('change');
    await page.locator('.barcode-advanced-heading').click();
    await page.locator('[data-config=show_expiry]').check();
    await page.locator('[data-config=show_batch]').check();
    await page.waitForFunction(
      () => document.querySelectorAll('#barcode-preview .barcode-label-expiry').length === 3,
    );
    assert.equal(
      await page.locator('#barcode-preview .barcode-label-expiry').first().textContent(),
      'Exp: 15/02/2027',
    );
    assert.equal(
      await page.locator('#barcode-preview .barcode-label-batch').first().textContent(),
      'Batch: BROWSER-FEB',
    );
    assert.ok(!(await page.locator('#barcode-preview').textContent()).includes('20/12/2026'));
    await page.screenshot({
      path: __dirname + '/.browser-artifacts/barcode/expiry-lot.png',
      fullPage: true,
    });
    await page.goto(base + '/purchases');
    await page.getByRole('link', { name: 'PUR-BROWSER-LABELS', exact: true }).click();
    await page.getByRole('link', { name: 'Print Labels for Purchased Items', exact: true }).click();
    await page.locator('#barcode-products tr').first().waitFor();
    const purchaseRows = page
      .locator('#barcode-products tr')
      .filter({ has: page.locator('[data-select]:checked') });
    assert.equal(await purchaseRows.count(), 2);
    const milkRow = purchaseRows.filter({ hasText: 'Milk Powder' });
    const biscuitsRow = purchaseRows.filter({ hasText: 'Biscuits' });
    assert.equal(await milkRow.locator('[data-qty]').inputValue(), '24');
    assert.equal(await biscuitsRow.locator('[data-qty]').inputValue(), '12');
    assert.ok(await milkRow.locator('[data-option]').inputValue());
    assert.ok(await biscuitsRow.locator('[data-option]').inputValue());
    await page.goto(base + '/pos');
    await page.locator('#product-search').fill(milk.barcode);
    await page.locator('#product-search').press('Enter');
    await page.waitForFunction(() => document.querySelectorAll('.cart-line').length === 1);
    assert.match(await page.locator('.cart-item-name').first().textContent(), /Milk Powder/);
    await page.locator('#product-search').fill(soap.barcode);
    await page.locator('#product-search').press('Enter');
    await page.locator('#price-choice-dialog').waitFor({ state: 'visible' });
    assert.ok((await page.locator('#price-choice-dialog').textContent()).includes('130'));
    assert.ok((await page.locator('#price-choice-dialog').textContent()).includes('140'));
    assert.deepEqual(errors, []);
    console.log(
      'Stock browser checks passed: exact expiry/batch labels, purchase 24/12 suggestions, single-option scan immediate, multiple-price scan opens existing stock selector.',
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
