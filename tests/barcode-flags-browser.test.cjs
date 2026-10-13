const { chromium } = require('playwright');
const assert = require('node:assert/strict');
(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    for (const [port, enabled, expiry] of [
      [8129, false, false],
      [8130, true, false],
      [8128, true, true],
    ]) {
      const context = await browser.newContext();
      const p = await context.newPage();
      const base = `http://127.0.0.1:${port}`;
      await p.goto(base + '/login');
      await p.locator('[name=login]').fill('admin');
      await p.locator('[name=password]').fill('password');
      await p.locator('button[type=submit]').click();
      await p.waitForURL(base + '/');
      await p.goto(base + '/manage/products/create');
      assert.equal(await p.locator('[name=barcode]').count(), enabled ? 1 : 0);
      assert.equal(
        await p.locator('.sidebar').getByText('Barcode Labels', { exact: true }).count(),
        enabled ? 1 : 0,
      );
      await p.goto(base + '/manage/roles/create');
      assert.equal(
        await p.locator('legend').filter({ hasText: 'Barcode labels' }).count(),
        enabled ? 1 : 0,
      );
      await p.goto(base + '/settings');
      assert.equal(
        await p.getByRole('heading', { name: 'Barcode', exact: true }).count(),
        enabled ? 1 : 0,
      );
      const products = await p.request.get(base + '/barcode/products?q=BROWSER');
      assert.equal(products.status(), enabled ? 200 : 404);
      if (enabled) {
        await p.goto(base + '/barcode');
        assert.equal(await p.locator('[data-config=show_expiry]').count(), expiry ? 1 : 0);
        assert.equal(await p.locator('[data-config=show_batch]').count(), expiry ? 1 : 0);
        assert.ok((await products.json()).some((x) => x.barcode));
      } else {
        for (const url of [
          '/barcode',
          '/barcodes/print',
          '/settings/barcode',
          '/barcode/presets',
          '/barcode/history',
          '/barcode/jobs/1',
        ])
          assert.equal((await p.request.get(base + url)).status(), 404, url);
        await p.goto(base + '/manage/products');
        assert.ok(
          !(await p.locator('.product-table').innerText()).toLowerCase().includes('barcode'),
        );
      }
      await p.goto(base + '/pos');
      assert.equal(await p.locator('#pos').getAttribute('data-barcode'), enabled ? '1' : '0');
      await context.close();
    }
    console.log(
      'Browser toggle checks passed: disabled product/sidebar/roles/settings/routes/POS; enabled again preserves codes; EXPIRE=false hides expiry and batch controls.',
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
