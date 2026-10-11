import test from 'node:test';
import assert from 'node:assert/strict';
import { createProductScanner, exactProductMatch } from '../resources/js/pos-scanner.js';

test('only a complete barcode or SKU matches; names and prefixes do not auto-add', () => {
  const product = { id: 1, name: 'Tea', barcode: '9004675446225', sku: 'TEA-100' };
  assert.equal(exactProductMatch([product], ' 9004675446225 '), product);
  assert.equal(exactProductMatch([product], 'TEA-100'), product);
  for (const code of ['', 'Tea', '900467', 'TEA'])
    assert.equal(exactProductMatch([product], code), undefined);
  const skuMatch = { id: 2, sku: product.barcode };
  assert.equal(exactProductMatch([skuMatch, product], product.barcode), product);
});

test('rapid scans preserve each captured code, order, and repeated quantities', async () => {
  let resolveFirst;
  const first = new Promise((resolve) => {
    resolveFirst = resolve;
  });
  const lookups = [];
  const added = [];
  const scan = createProductScanner({
    lookup: async (code) => {
      lookups.push(code);
      if (lookups.length === 1) await first;
      return [{ id: code === 'A' ? 1 : 2, barcode: code }];
    },
    add: (product) => {
      added.push(product.id);
      return true;
    },
    missing: () => assert.fail('unexpected missing product'),
    error: () => assert.fail('unexpected scan error'),
  });
  const requests = [scan(' A '), scan('B'), scan('A')];
  await Promise.resolve();
  assert.deepEqual(lookups, ['A']);
  resolveFirst();
  assert.deepEqual(await Promise.all(requests), [true, true, true]);
  assert.deepEqual(lookups, ['A', 'B', 'A']);
  assert.deepEqual(added, [1, 2, 1]);
});

test('missing products and failed lookups do not add stale results or stop the next scan', async () => {
  const added = [],
    failures = [],
    missing = [];
  const scan = createProductScanner({
    lookup: async (code) => {
      if (code === 'OFFLINE') throw new Error('Offline');
      return [{ id: 1, barcode: 'FOUND' }];
    },
    add: (product) => {
      added.push(product.id);
      return true;
    },
    missing: (code) => missing.push(code),
    error: (failure) => failures.push(failure.message),
  });
  assert.deepEqual(await Promise.all([scan('MISSING'), scan('OFFLINE'), scan('FOUND'), scan('')]), [
    false,
    false,
    true,
    false,
  ]);
  assert.deepEqual(added, [1]);
  assert.deepEqual(missing, ['MISSING']);
  assert.deepEqual(failures, ['Offline']);
});

test('a price choice completes before the next scan is added, and old-order scans can be discarded', async () => {
  let finishChoice;
  const choice = new Promise((resolve) => {
    finishChoice = resolve;
  });
  let version = 1;
  const added = [];
  const scan = createProductScanner({
    lookup: async (code) => [{ barcode: code }],
    add: async (product, context) => {
      if (product.barcode === 'CHOICE') await choice;
      if (context !== version) return false;
      added.push(product.barcode);
      return true;
    },
    missing: () => assert.fail('unexpected missing product'),
    error: () => assert.fail('unexpected scan error'),
  });
  const old = scan('CHOICE', version);
  const next = scan('NEXT', 2);
  version = 2;
  finishChoice();
  assert.deepEqual(await Promise.all([old, next]), [false, true]);
  assert.deepEqual(added, ['NEXT']);
});
