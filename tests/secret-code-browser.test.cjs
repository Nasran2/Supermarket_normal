// Use tests/Support/secret-code-browser-bootstrap.php against isolated SQLite.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const base = process.env.SCODE_BROWSER_URL || 'http://127.0.0.1:8160';
const off = process.env.SCODE_DISABLED_URL || 'http://127.0.0.1:8161';
const artifacts = __dirname + '/.browser-artifacts/secret-code';
fs.mkdirSync(artifacts, { recursive: true });
(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const context = await browser.newContext({ viewport: { width: 1366, height: 1000 } });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  async function preview(code) {
    await page.waitForFunction(
      (expected) =>
        document
          .querySelector('#barcode-preview .barcode-label-secret_code')
          ?.textContent.trim() === expected &&
        !document.querySelector('#barcode-error').textContent,
      code,
    );
  }
  try {
    await page.goto(base + '/login');
    await page.locator('[name=login]').fill('admin');
    await page.locator('[name=password]').fill('password');
    await page.locator('button[type=submit]').click();
    await page.waitForURL(base + '/');
    await page.goto(base + '/settings/barcode');
    for (const [digit, letter] of Object.entries({
      0: 'K',
      1: 'A',
      2: 'B',
      3: 'C',
      4: 'D',
      5: 'T',
      6: 'F',
      7: 'H',
      8: 'G',
      9: 'I',
    }))
      await page.locator(`[name=scode_digit_${digit}]`).fill(letter);
    await page.locator('[name=scode_fillers]').fill('X');
    await page.locator('[name=scode_min_length]').fill('1');
    await page.locator('[name=scode_padding]').selectOption('PRICE');
    await page.locator('[name=scode_padding_side]').selectOption('SUFFIX');
    await page.locator('[name=scode_position]').selectOption('bottom');
    await page.locator('[name=scode_alignment]').selectOption('right');
    await page.locator('[name=scode_font_size]').fill('9');
    await page.getByRole('button', { name: 'Save Settings', exact: true }).click();
    await page.waitForLoadState('networkidle');
    assert.equal(await page.locator('[name=scode_digit_8]').inputValue(), 'G');
    await page.locator('[data-secret-settings]').screenshot({ path: artifacts + '/settings.png' });

    await page.goto(base + '/barcode');
    const response = await page.request.get(base + '/barcode/products?q=Milk%20Powder');
    const product = (await response.json())[0];
    assert.ok(!JSON.stringify(product).includes('"cost"'));
    await page.goto(base + '/barcode?product=' + product.id);
    await preview('GTKX');
    assert.equal(await page.locator('#barcode-warnings').innerText(), '');
    await page.locator('summary.barcode-advanced-heading').click();
    assert.equal(await page.locator('[data-config=secret_code_font_size]').isDisabled(), true);
    await page.locator('[data-config=secret_code_use_defaults]').uncheck();
    await page.locator('[data-config=secret_code_position]').selectOption('top');
    await page.locator('[data-config=secret_code_font_size]').fill('8');
    await page.locator('[data-config=secret_code_alignment]').selectOption('left');
    await page.getByRole('button', { name: 'Preview Labels', exact: true }).click();
    await preview('GTKX');
    await page.waitForFunction(() =>
      document
        .querySelector('#barcode-preview .barcode-label-content')
        ?.firstElementChild.classList.contains('barcode-label-secret_code'),
    );
    await page
      .locator('#barcode-preview')
      .screenshot({ path: artifacts + '/code-top-preview.png' });
    await page.locator('#barcode-preset-name').fill('Secret code layout ' + Date.now());
    await page.locator('#barcode-save-preset').click();
    await page.waitForFunction(() =>
      document
        .querySelector('#barcode-preset')
        .selectedOptions[0].textContent.startsWith('Secret code layout'),
    );
    await page.locator('#barcode-print').click();
    await page.locator('#barcode-confirm').waitFor({ state: 'visible' });
    const popup = context.waitForEvent('page');
    await page.locator('#barcode-confirm-print').click();
    const printed = await popup;
    await printed.waitForURL(/\/barcode\/jobs\/\d+/);
    await printed.locator('.barcode-label-secret_code').waitFor();
    assert.equal(
      (await printed.locator('.barcode-label-secret_code').first().innerText()).trim(),
      'GTKX',
    );
    assert.equal(await printed.locator('#barcode-browser-print').isDisabled(), false);
    await printed.screenshot({ path: artifacts + '/printed-label.png', fullPage: true });
    const jobPath = new URL(printed.url()).pathname;
    await page.goto(base + '/settings/barcode');
    await page.locator('[name=scode_padding_side]').selectOption('PREFIX');
    await page.getByRole('button', { name: 'Save Settings', exact: true }).click();
    await page.waitForLoadState('networkidle');
    await page.goto(base + '/barcode?product=' + product.id);
    await preview('XGTK');
    await printed.reload();
    assert.equal(
      (await printed.locator('.barcode-label-secret_code').first().innerText()).trim(),
      'GTKX',
    );

    await page.goto(off + '/settings/barcode');
    assert.equal(await page.locator('[name=scode_digit_0]').count(), 0);
    await page.goto(off + '/barcode?product=' + product.id);
    assert.equal(await page.locator('[data-secret-layout]').count(), 0);
    await page.waitForSelector('#barcode-preview .barcode-label');
    assert.equal(await page.locator('.barcode-label-secret_code').count(), 0);
    await printed.goto(off + jobPath);
    assert.equal(await printed.locator('.barcode-label-secret_code').count(), 0);
    await page.goto(base + '/settings/barcode');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.waitForFunction(() => document.documentElement.scrollWidth <= innerWidth + 1);
    await page.locator('[data-secret-settings]').scrollIntoViewIfNeeded();
    await page.screenshot({ path: artifacts + '/settings-mobile.png' });
    assert.deepEqual(errors, []);
    console.log(
      'Secret code settings, GTKX/XGTK padding, layout overrides, saved presets, print snapshots, mobile and SCODE=false checks passed.',
    );
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
