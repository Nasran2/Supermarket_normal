// NODE_PATH may point at the Codex bundled Playwright package directory.
// Requires tests/Support/barcode-browser-bootstrap.php on an isolated SQLite server.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const base = process.env.BARCODE_TEST_URL || 'http://127.0.0.1:8128';
const artifacts = __dirname + '/.browser-artifacts/barcode';
fs.mkdirSync(artifacts, { recursive: true });
(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
  const soapName = 'Browser Bath Soap ' + Date.now();
  const presetName = 'Browser Custom ' + Date.now();
  const page = await context.newPage();
  const errors = [];
  const badResponses = [];
  context.on('page', (p) => {
    p.on('pageerror', (e) => errors.push(e.message));
    p.on('response', (r) => {
      if (r.status() >= 500) badResponses.push(`${r.status()} ${r.url()}`);
    });
  });
  page.on('pageerror', (e) => errors.push(e.message));
  const waitPreview = async (p) => {
    await p.waitForFunction(
      () =>
        document.querySelectorAll('#barcode-preview .barcode-label').length > 0 &&
        !document.getElementById('barcode-error').textContent,
    );
  };
  const printJob = async (p) => {
    await p.locator('#barcode-print').click();
    await p.locator('#barcode-confirm').waitFor({ state: 'visible' });
    const popup = context.waitForEvent('page');
    await p.locator('#barcode-confirm-print').click();
    const printed = await popup;
    await printed.waitForURL(/barcode\/jobs\/\d+/);
    await printed.locator('.barcode-label').first().waitFor();
    assert.equal(
      await printed.locator('#barcode-browser-print').isDisabled(),
      false,
      await printed.locator('#barcode-print-warning').textContent(),
    );
    return printed;
  };
  try {
    await page.goto(base + '/login');
    await page.locator('[name=login]').fill('admin');
    await page.locator('[name=password]').fill('password');
    await page.locator('button[type=submit]').click();
    await page.waitForURL(base + '/');
    await page.goto(base + '/manage/products/create');
    const form = page.locator('form.product-editor');
    await form.locator('[name=name]').fill(soapName);
    await form.locator('[name=sku]').fill('BROWSER-SOAP-' + Date.now());
    await form.locator('[data-generate-product-barcode]').click();
    await page.waitForFunction(() => document.getElementById('product-barcode').value.length > 0);
    const barcode = await form.locator('[name=barcode]').inputValue();
    await form.locator('[name="opening_layers[0][quantity]"]').fill('20');
    await form.locator('[name="opening_layers[0][cost]"]').fill('100');
    await form.locator('[name="opening_layers[0][selling_price]"]').fill('130');
    await form.locator('[data-add-opening]').click();
    await form.locator('[name="opening_layers[1][quantity]"]').fill('15');
    await form.locator('[name="opening_layers[1][cost]"]').fill('100');
    await form.locator('[name="opening_layers[1][selling_price]"]').fill('140');
    await form.locator('button[type=submit]').click();
    await page.waitForURL(base + '/manage/products');
    await page
      .getByRole('link', { name: new RegExp(soapName) })
      .first()
      .click();
    await page.getByRole('link', { name: 'Print Label', exact: true }).click();
    await page
      .locator('#barcode-preset')
      .selectOption({ label: '49 × 25 mm - 2 Across' })
      .catch(async () => {
        await page
          .locator('#barcode-preset')
          .selectOption(
            await page
              .locator('#barcode-preset option')
              .filter({ hasText: '49 × 25 mm - 2 Across' })
              .getAttribute('value'),
          );
      });
    const soap = page.locator('#barcode-products tr').filter({ hasText: soapName }).first();
    await soap.waitFor();
    assert.equal(await soap.locator('[data-option]').inputValue(), '');
    const options = await soap
      .locator('[data-option] option')
      .evaluateAll((es) =>
        es.map((e) => ({ value: e.value, text: e.textContent })).filter((e) => e.value),
      );
    assert.equal(options.length, 2);
    await soap
      .locator('[data-option]')
      .selectOption(options.find((o) => o.text.includes('130.00')).value);
    await soap.locator('[data-qty]').fill('5');
    await soap.locator('[data-qty]').dispatchEvent('change');
    await waitPreview(page);
    for (const width of [1920, 1366, 1024, 768]) {
      await page.setViewportSize({ width, height: 900 });
      await page.screenshot({ path: `${artifacts}/labels-${width}.png`, fullPage: true });
      assert.equal(
        await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1),
        true,
        `Page overflow at ${width}`,
      );
    }
    const previewSize = await page.locator('#barcode-preview .barcode-label').first().boundingBox();
    assert.ok(Math.abs(previewSize.width - (49 * 96) / 25.4) < 1);
    assert.ok(Math.abs(previewSize.height - (25 * 96) / 25.4) < 1);
    const printed = await printJob(page);
    assert.equal(await printed.locator('.barcode-label').count(), 5);
    assert.equal(await printed.locator('.barcode-sheet-row').count(), 3);
    await printed.pdf({
      path: `${artifacts}/five-labels.pdf`,
      preferCSSPageSize: true,
      printBackground: true,
    });
    await printed
      .locator('.barcode-label-graphic')
      .first()
      .screenshot({ path: `${artifacts}/barcode.png` });
    const printSize = await printed.locator('.barcode-label').first().boundingBox();
    assert.ok(Math.abs(printSize.width - previewSize.width) < 1);
    assert.ok(Math.abs(printSize.height - previewSize.height) < 1);
    await printed.close();
    await soap.locator('[data-qty]').fill('10');
    await soap.locator('[data-qty]').dispatchEvent('change');
    await soap.locator('[data-another]').click();
    const second = page.locator('#barcode-products tr').filter({ hasText: soapName }).nth(1);
    await second
      .locator('[data-option]')
      .selectOption(options.find((o) => o.text.includes('140.00')).value);
    await second.locator('[data-qty]').fill('15');
    await second.locator('[data-qty]').dispatchEvent('change');
    await page.waitForFunction(() =>
      document.getElementById('barcode-summary').textContent.includes('Total labels: 25'),
    );
    const multi = await printJob(page);
    assert.equal(
      await multi.locator('.barcode-label-price').filter({ hasText: '130.00' }).count(),
      10,
    );
    assert.equal(
      await multi.locator('.barcode-label-price').filter({ hasText: '140.00' }).count(),
      15,
    );
    await multi.close();
    await page.goto(base + '/barcode');
    await page.locator('#barcode-product-table').waitFor({ state: 'hidden' });
    assert.equal(await page.locator('#barcode-products tr').count(), 0);
    for (const [name, qty] of [
      [soapName, '10'],
      ['Milk Powder', '20'],
      ['Biscuits', '5'],
    ]) {
      await page.locator('#barcode-search').fill(name);
      const row = page.locator('#barcode-products tr').filter({ hasText: name }).first();
      await row.waitFor();
      await row.locator('[data-select]').check();
      if (name === soapName)
        await row
          .locator('[data-option]')
          .selectOption(options.find((o) => o.text.includes('130.00')).value);
      await row.locator('[data-qty]').fill(qty);
      await row.locator('[data-qty]').dispatchEvent('change');
    }
    await page.locator('#barcode-search').fill('');
    assert.equal(await page.locator('#barcode-products tr').count(), 3);
    assert.deepEqual(
      await page
        .locator('#barcode-products [data-qty]')
        .evaluateAll((inputs) => inputs.map((i) => i.value)),
      ['10', '20', '5'],
    );
    await page.waitForFunction(() =>
      document.getElementById('barcode-summary').textContent.includes('Total labels: 35'),
    );
    const bulk = await printJob(page);
    assert.equal(await bulk.locator('.barcode-label').count(), 35);
    await bulk.close();
    await page.locator('.barcode-advanced-heading').click();
    await page.locator('[data-config=price_font_size]').fill('16');
    await page.locator('[data-config=padding_left]').fill('0.1');
    await page.locator('#barcode-preset-name').fill(presetName);
    await page.locator('#barcode-save-default').check();
    await page.locator('#barcode-save-preset').click();
    await page.waitForFunction(() =>
      document
        .getElementById('barcode-preset')
        .selectedOptions[0].textContent.includes('Browser Custom'),
    );
    await page.goto(base + '/barcode/presets');
    await page
      .locator('tr')
      .filter({ hasText: presetName })
      .getByRole('link', { name: 'Edit', exact: true })
      .click();
    await page.locator('[data-config=price_font_size]').fill('17');
    await page.getByRole('button', { name: 'Save Preset', exact: true }).click();
    await page.waitForURL(base + '/barcode/presets');
    // Invalid width must show validation instead of producing a broken preview.
    await page.goto(base + '/barcode');
    assert.match(
      await page.locator('#barcode-preset option:checked').textContent(),
      new RegExp(presetName),
    );
    await page.locator('#barcode-search').fill('Milk Powder');
    await page.locator('#barcode-products tr').first().waitFor();
    const milk = page.locator('#barcode-products tr').filter({ hasText: 'Milk Powder' }).first();
    await milk.locator('[data-select]').check();
    await waitPreview(page);
    await page.locator('.barcode-advanced-heading').click();
    await page.locator('[data-config=paper_width]').fill('1');
    await page.locator('#barcode-error').waitFor({ state: 'visible' });
    assert.match(await page.locator('#barcode-error').textContent(), /exceed the paper width/);
    await page.goto(base + '/pos');
    assert.equal(await page.locator('#pos').getAttribute('data-barcode'), '1');
    assert.deepEqual(errors, []);
    assert.deepEqual(badResponses, []);
    fs.writeFileSync(
      `${artifacts}/result.json`,
      JSON.stringify(
        {
          barcode,
          viewports: [1920, 1366, 1024, 768],
          single: 5,
          multiplePrices: { 130: 10, 140: 15 },
          bulk: 35,
          previewSize,
          printSize,
          consoleErrors: errors,
        },
        null,
        2,
      ),
    );
    console.log(
      'Browser checks passed: generation, price choice, 5/25/35 labels, preset create/edit, invalid sizing, four viewports, exact mm geometry, no console errors.',
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
