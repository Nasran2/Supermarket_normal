// Run against isolated seeded servers, with BAMINI=true on :8141 and false on
// :8142, and a stock product named வணக்கம் தமிழ் (SKU BAMINI-SEARCH-CHECK).
const { chromium } = require('playwright');
const assert = require('node:assert/strict');

async function login(page, base) {
  await page.goto(base + '/login');
  await page.locator('[name=login]').fill('admin');
  await page.locator('[name=password]').fill('password');
  await page.locator('button[type=submit]').click();
  await page.waitForURL(base + '/');
}

(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    for (const width of [1440, 390]) {
      const context = await browser.newContext({ viewport: { width, height: 1000 } });
      const page = await context.newPage();
      const errors = [];
      page.on('pageerror', (error) => errors.push(error.message));
      const base = process.env.BAMINI_BASE_URL || 'http://127.0.0.1:8141';
      await login(page, base);
      await page.goto(base + '/pos');
      const search = page.locator('#product-search');
      const tile = page.locator('.product-tile').filter({ hasText: 'வணக்கம் தமிழ்' });
      const find = async (query) => {
        const response = page.waitForResponse((r) => {
          const url = new URL(r.url());
          return url.pathname === '/pos/products' && url.searchParams.get('q') === query;
        });
        await search.fill(query);
        assert.equal((await response).status(), 200);
        await page.waitForFunction(() => document.querySelector('#product-results').getAttribute('aria-busy') === 'false');
        await tile.waitFor({ state: 'visible' });
        assert.equal(await search.inputValue(), query);
      };
      for (const query of ['tz', 'tzf;fk;', 'jkpo;', 'தமிழ்', 'BAMINI-SEARCH']) await find(query);
      await find('tz');
      await tile.click();
      await page.locator('.cart-item-name').filter({ hasText: 'வணக்கம் தமிழ்' }).waitFor();
      await page.goto(base + '/manage/products?q=tz');
      await page.locator('.product-list-identity').filter({ hasText: 'BAMINI-SEARCH-CHECK' }).waitFor();
      await page.goto(base + '/purchases/create');
      const option = page.locator('.product-option').filter({ hasText: 'BAMINI-SEARCH-CHECK' });
      await page.locator('#product-search').fill('tz');
      await option.waitFor({ state: 'visible' });
      await page.locator('#product-search').fill('jkpo;');
      await option.waitFor({ state: 'visible' });
      await page.locator('#product-search').fill('no-matching-product');
      await option.waitFor({ state: 'hidden' });
      assert.deepEqual(errors, []);
      await context.close();
    }

    const context = await browser.newContext();
    const page = await context.newPage();
    const base = process.env.BAMINI_DISABLED_URL || 'http://127.0.0.1:8142';
    await login(page, base);
    const response = await page.request.get(base + '/pos/products?q=tz');
    assert.equal(response.status(), 200);
    assert.ok(!(await response.json()).some((product) => product.sku === 'BAMINI-SEARCH-CHECK'));
    await page.goto(base + '/purchases/create');
    await page.locator('#product-search').fill('tz');
    await page.locator('.product-option').filter({ hasText: 'BAMINI-SEARCH-CHECK' }).waitFor({ state: 'hidden' });
    await context.close();
    console.log('Bamini search browser tests passed: POS results/cart, product list, purchase picker, desktop/mobile and disabled flag.');
  } finally {
    await browser.close();
  }
})().catch((error) => { console.error(error); process.exitCode = 1; });
