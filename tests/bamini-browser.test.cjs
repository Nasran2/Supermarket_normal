// Use an isolated seeded test database, with BAMINI=true on :8141 and false on
// :8142. BAMINI_REPAIR_URL optionally points to a test repair job for Quick Add.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');

(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    for (const viewport of [{ width: 1440, height: 1000 }, { width: 390, height: 844 }]) {
      const context = await browser.newContext({ viewport });
      const page = await context.newPage();
      const errors = [];
      page.on('pageerror', (error) => errors.push(error.message));
      const base = process.env.BAMINI_BASE_URL || 'http://127.0.0.1:8141';
      await page.goto(base + '/login');
      await page.locator('[name=login]').fill('admin');
      await page.locator('[name=password]').fill('password');
      await page.locator('button[type=submit]').click();
      await page.waitForURL(base + '/');
      await page.goto(base + '/settings/stock');
      await page.locator('[name=product_name_typing_mode]').selectOption('bamini');
      await page.getByRole('button', { name: 'Save changes' }).click();
      await page.waitForLoadState();
      await page.goto(base + '/manage/products/create');
      const mode = page.locator('[data-product-name-mode]');
      const input = page.locator('[data-product-name-input]');
      const preview = page.locator('[data-product-name-preview]');
      await input.waitFor();
      await page.waitForFunction(() => document.querySelector('[data-product-name-editor]').dataset.initialized === '1');
      assert.equal(await mode.inputValue(), 'bamini');
      await input.pressSequentially('tzf;fk;');
      assert.equal(await preview.textContent(), 'வணக்கம்');
      assert.equal(await input.inputValue(), 'tzf;fk;');
      await input.press('Backspace');
      assert.equal(await preview.textContent(), 'வணக்கம');
      await input.press(';');
      await input.press('Home');
      await input.press('Delete');
      assert.equal(await preview.textContent(), 'ணக்கம்');
      await input.press('t');
      assert.equal(await preview.textContent(), 'வணக்கம்');
      await input.fill('jkpo;'); // replacement/paste input event
      assert.equal(await preview.textContent(), 'தமிழ்');
      await mode.selectOption('english');
      assert.equal(await input.inputValue(), 'தமிழ்');
      await mode.selectOption('bamini');
      assert.equal(await input.inputValue(), 'jkpo;');
      await mode.selectOption('english');
      await input.fill('Tea 200g');
      assert.equal(await page.locator('input[type=hidden][name=name]').inputValue(), 'Tea 200g');
      await mode.selectOption('bamini');
      assert.equal(await preview.textContent(), 'Tea 200g');
      await input.fill('jkpo;');
      const sku = 'BAMINI-BROWSER-' + viewport.width + '-' + Date.now();
      await page.locator('[name=sku]').fill(sku);
      await page.locator('#track-stock').uncheck();
      if (await page.locator('[name=unit_id]').count()) await page.locator('[name=unit_id]').selectOption({ index: 1 });
      await page.locator('[name="opening_layers[0][cost]"]').fill('20');
      await page.locator('[name="opening_layers[0][selling_price]"]').fill('100');
      if (process.env.BAMINI_SCREENSHOT_DIR) {
        await page.locator('.product-details-card').screenshot({ path: process.env.BAMINI_SCREENSHOT_DIR + '/' + viewport.width + '.png' });
      }
      await page.getByRole('button', { name: 'Create product', exact: true }).click();
      await page.waitForURL(base + '/manage/products');
      const productUrl = await page.locator('.product-list-identity').filter({ hasText: sku }).getAttribute('href');
      await page.goto(productUrl);
      assert.equal(await page.locator('h1').textContent(), 'தமிழ்');
      await page.goto(productUrl + '/edit');
      await page.waitForFunction(() => document.querySelector('[data-product-name-editor]').dataset.initialized === '1');
      assert.equal(await input.inputValue(), 'தமிழ்');
      assert.equal(await preview.textContent(), 'தமிழ்');
      await page.getByRole('button', { name: 'Save product', exact: true }).click();
      await page.waitForURL(base + '/manage/products');
      await page.goto(productUrl);
      assert.equal(await page.locator('h1').textContent(), 'தமிழ்');
      await page.goto(base + '/manage/products/create');
      await page.waitForFunction(() => document.querySelector('[data-product-name-editor]').dataset.initialized === '1');
      assert.equal(await mode.inputValue(), 'bamini'); // temporary mode never changed default
      const bounds = await mode.boundingBox();
      assert.ok(bounds.x >= 0 && bounds.x + bounds.width <= viewport.width);

      if (process.env.BAMINI_REPAIR_URL) {
        await page.goto(base + process.env.BAMINI_REPAIR_URL);
        await page.locator('#shortcut-add-part').click();
        await page.locator('[data-open-quick-product]').click();
        const quick = page.locator('[data-new-repair-product]');
        await quick.locator('[data-product-name-input]').fill('tzf;fk;');
        assert.equal(await quick.locator('[data-product-name-preview]').textContent(), 'வணக்கம்');
        await quick.locator('[name=price]').fill('100');
        await quick.getByRole('button', { name: 'Create & Select Part' }).click();
        await page.locator('#modal-quick-add-product').waitFor({ state: 'hidden' });
        assert.equal(await page.locator('#modal-add-part [data-picker-query]').inputValue(), 'வணக்கம்');
        await page.locator('[data-open-quick-product]').click();
        assert.equal(await quick.locator('[data-product-name-preview]').textContent(), 'வணக்கம்');
        assert.equal(await quick.locator('[data-product-name-mode]').inputValue(), 'bamini');
      }
      assert.deepEqual(errors, []);
      await context.close();
    }

    const context = await browser.newContext();
    const page = await context.newPage();
    const disabled = process.env.BAMINI_DISABLED_URL || 'http://127.0.0.1:8142';
    await page.goto(disabled + '/login');
    await page.locator('[name=login]').fill('admin');
    await page.locator('[name=password]').fill('password');
    await page.locator('button[type=submit]').click();
    await page.waitForURL(disabled + '/');
    await page.goto(disabled + '/settings/stock');
    assert.equal(await page.locator('[name=product_name_typing_mode]').count(), 0);
    await page.goto(disabled + '/manage/products/create');
    assert.equal(await page.locator('[data-product-name-editor]').count(), 0);
    assert.equal(await page.locator('[data-product-name-mode]').count(), 0);
    assert.equal(await page.locator('script[src*="product-name-"]').count(), 0);
    await page.locator('[data-product-name-input]').fill('Normal English');
    assert.equal(await page.locator('[data-product-name-input][name=name]').inputValue(), 'Normal English');
    await context.close();
    console.log('Bamini browser tests passed: desktop/mobile create/edit, Unicode submission, mode switching, saved default, Quick Add, disabled flag.');
  } finally {
    await browser.close();
  }
})().catch((error) => { console.error(error); process.exitCode = 1; });
