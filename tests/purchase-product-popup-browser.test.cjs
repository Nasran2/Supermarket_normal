// Use an isolated seeded server with BAMINI/UNIT/BARCODE/EXPIRE enabled, the
// Bamini name default, supplier "Popup supplier" and product "Existing item".
const { chromium } = require('playwright');
const assert = require('node:assert/strict');

(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    for (const width of [1440, 390]) {
      const context = await browser.newContext({ viewport: { width, height: 1000 } });
      const page = await context.newPage();
      const errors = [];
      page.on('pageerror', (error) => errors.push(error.message));
      const base = process.env.PURCHASE_POPUP_BASE_URL || 'http://127.0.0.1:8143';
      await page.goto(base + '/login');
      await page.locator('[name=login]').fill('admin');
      await page.locator('[name=password]').fill('password');
      await page.locator('button[type=submit]').click();
      await page.waitForURL(base + '/');
      await page.goto(base + '/purchases/create');
      await page.locator('#supplier-search').fill('Popup supplier');
      await page.locator('.supplier-option').filter({ hasText: 'Popup supplier' }).click();
      const supplier = await page.locator('#supplier_id').inputValue();
      await page.locator('#purchase-form [name=notes]').fill('Keep this purchase draft');
      await page.locator('#product-search').fill('Existing item');
      await page.locator('.product-option').filter({ hasText: 'EXISTING-PURCHASE' }).click();
      const existing = page.locator('.purchase-line').filter({ hasText: 'EXISTING-PURCHASE' });
      await existing.locator('[data-field=quantity]').fill('3');
      await page.locator('#product-search').fill('tzf;fk;');
      await page.locator('[data-no-purchase-products]').waitFor({ state: 'visible' });
      await page.locator('[data-add-purchase-product]').click();
      const dialog = page.locator('#purchase-product-dialog');
      const frame = page.frameLocator('[data-purchase-product-frame]');
      await frame.locator('[data-product-popup]').waitFor();
      await frame.locator('[data-product-name-editor][data-initialized="1"]').waitFor();
      await frame.locator('[data-product-name-preview]').filter({ hasText: 'வணக்கம்' }).waitFor();
      assert.equal(await frame.locator('[name=supplier_id]').inputValue(), supplier);
      assert.equal(await frame.locator('.sidebar').count(), 0);
      for (const selector of ['[name=image]', '[name="categories[]"]', '[name=unit_id]', '[name=track_expiry]', '[name=available_for_pos]', '[name=available_for_repairs]', '[name=track_stock]', '[name=default_discount_type]', '#add-conversion']) {
        assert.ok(await frame.locator(selector).count(), selector);
      }
      const name = frame.locator('[data-product-name-input]');
      await name.fill('jkpo;');
      await frame.locator('[name=sku]').fill('EXISTING-PURCHASE');
      await frame.locator('[name="opening_layers[0][cost]"]').fill('20');
      await frame.locator('[name="opening_layers[0][selling_price]"]').fill('50');
      await frame.getByRole('button', { name: 'Create product', exact: true }).click();
      await frame.locator('[data-product-popup-error]').waitFor({ state: 'visible' });
      assert.match(await frame.locator('[data-product-popup-error]').textContent(), /sku.*taken/i);
      assert.equal(await name.inputValue(), 'jkpo;');
      const sku = `POPUP-${width}-${Date.now()}`;
      await frame.locator('[name=sku]').fill(sku);

      await frame.getByRole('button', { name: 'Add category', exact: true }).click();
      const category = frame.locator('#product-categories-dialog');
      const categoryName = `Popup category ${Date.now()}`;
      await category.locator('[name=name]').fill(categoryName);
      await category.getByRole('button', { name: 'Save category', exact: true }).click();
      await category.waitFor({ state: 'hidden' });
      assert.ok((await frame.locator('#categories-select').locator('option:checked').allTextContents()).includes(categoryName));
      await frame.getByRole('button', { name: 'Add supplier', exact: true }).click();
      const supplierDialog = frame.locator('#product-suppliers-dialog');
      await supplierDialog.locator('[name=name]').fill(`Product supplier ${Date.now()}`);
      await supplierDialog.getByRole('button', { name: 'Save supplier', exact: true }).click();
      await supplierDialog.waitFor({ state: 'hidden' });
      await frame.locator('[name=image]').setInputFiles({ name: 'product.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', 'base64') });
      await frame.locator('[name=barcode]').fill(`POPUP-BAR-${Date.now()}`);
      await frame.locator('[name=default_discount_type]').selectOption('PERCENT');
      await frame.locator('[name=default_discount_value]').fill('5');
      await frame.locator('#add-conversion').click();
      const conversion = frame.locator('.conversion-row').last();
      await conversion.locator('[data-field=unit_id]').selectOption({ label: 'Box (box)' });
      await conversion.locator('[data-field=base_quantity]').fill('12');
      await conversion.locator('[data-field=converted_quantity]').fill('1');
      await conversion.locator('[data-field=price]').fill('550');
      await name.scrollIntoViewIfNeeded();
      const body = frame.locator('body');
      assert.ok(await body.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
      if (process.env.PURCHASE_POPUP_SCREENSHOTS) await dialog.screenshot({ path: `${process.env.PURCHASE_POPUP_SCREENSHOTS}/${width}.png` });
      await frame.getByRole('button', { name: 'Create product', exact: true }).click();
      await dialog.waitFor({ state: 'hidden' });
      const created = page.locator('.purchase-line').filter({ hasText: sku });
      await created.waitFor();
      assert.equal(await created.locator('[data-field-display=name]').textContent(), 'தமிழ்');
      assert.equal(await created.locator('[data-field=cost]').inputValue(), '20.00');
      assert.equal(await created.locator('[data-field=selling_price]').inputValue(), '50.00');
      assert.ok((await created.locator('[data-field=unit_id] option').allTextContents()).some((label) => label.includes('Box')));
      assert.equal(await page.locator('#purchase-form [name=notes]').inputValue(), 'Keep this purchase draft');
      assert.equal(await page.locator('#supplier_id').inputValue(), supplier);
      assert.equal(await existing.locator('[data-field=quantity]').inputValue(), '3');
      assert.equal(await page.locator('.purchase-line').count(), 2);

      await page.locator('#product-search').fill('No second product');
      await page.locator('[data-create-missing-product]').click();
      await frame.locator('[data-product-name-input]').fill('cancelled draft');
      await page.locator('[data-close-purchase-product]').click();
      await dialog.waitFor({ state: 'hidden' });
      await page.locator('[data-add-purchase-product]').click();
      assert.equal(await frame.locator('[data-product-name-input]').inputValue(), 'cancelled draft');
      await frame.locator('[data-product-popup-cancel]').click();
      await dialog.waitFor({ state: 'hidden' });
      assert.equal(await page.locator('.purchase-line').count(), 2);
      await page.locator('#save-purchase').click();
      await page.waitForURL(/\/purchases\/\d+$/);
      assert.ok((await page.locator('body').textContent()).includes('தமிழ்'));
      assert.deepEqual(errors, []);
      await context.close();
    }
    console.log('Purchase product popup passed on desktop/mobile: full form, Bamini, validation, category/supplier dialogs, image, conversions, add-to-purchase, draft preservation, cancel/reopen and purchase save.');
  } finally {
    await browser.close();
  }
})().catch((error) => { console.error(error); process.exitCode = 1; });
