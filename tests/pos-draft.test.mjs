import test from 'node:test';
import assert from 'node:assert/strict';
import { draftStore } from '../resources/js/pos-draft.js';
const memory = () => {
  const values = new Map();
  return {
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, value),
    removeItem: (key) => values.delete(key),
  };
};
const order = {
  token: '0259b35a-9ec9-4c4e-af65-7f92e654522d',
  customer: '12',
  attempted: false,
  items: [
    {
      id: 4,
      name: 'Tea',
      unit_id: 2,
      quantity: '2.5',
      price: '100.00',
      unit_price: '95.00',
      discount_type: 'PERCENT',
      discount_value: '5',
    },
  ],
};
test('draft preserves quantity, unit, adjusted price, discount, customer and retry token', () => {
  const storage = memory();
  draftStore(storage, 'cashier:1').write(order);
  const restored = draftStore(storage, 'cashier:1').read();
  assert.deepEqual(restored.items, order.items);
  assert.equal(restored.customer, '12');
  assert.equal(restored.token, order.token);
  draftStore(storage, 'cashier:1').write({ ...order, attempted: true });
  assert.equal(draftStore(storage, 'cashier:1').read().attempted, true);
});
test('cancelling or successful payment clears only this cashier draft', () => {
  const storage = memory();
  draftStore(storage, 'cashier:1').write(order);
  draftStore(storage, 'cashier:2').write(order);
  draftStore(storage, 'cashier:1').clear();
  assert.equal(draftStore(storage, 'cashier:1').read(), null);
  assert.deepEqual(draftStore(storage, 'cashier:2').read().items, order.items);
});
test('one product retains separate selected stock prices across reloads', () => {
  const store = draftStore(memory(), 'cart');
  const items = ['130.00', '140.00'].map((stock_price) => ({
    ...order.items[0], stock_price, price: stock_price, unit_price: null,
  }));
  store.write({ ...order, items });
  assert.deepEqual(store.read().items, items);
  store.write({ ...order, items: [items[0], items[0]] });
  assert.throws(() => store.read());
  store.write({ ...order, items: [{ ...items[0], stock_price: '-1' }] });
  assert.throws(() => store.read());
});
test('malformed or incompatible saved carts are rejected before restoration', () => {
  for (const invalid of [
    '{broken',
    JSON.stringify({ ...order, version: 99 }),
    JSON.stringify({ ...order, version: 1, items: [{ ...order.items[0], quantity: '-1' }] }),
    JSON.stringify({ ...order, version: 1, items: [...order.items, ...order.items] }),
  ]) {
    const storage = memory();
    storage.setItem('cart', invalid);
    assert.throws(() => draftStore(storage, 'cart').read());
  }
});

test('same-price stock rows persist independently and invalid stock selections are rejected', () => {
  const store = draftStore(memory(), 'cart');
  const items = [11,12].map((stock_layer_id) => ({...order.items[0],stock_price:'490.00',stock_layer_id,price:'490.00',unit_price:null}));
  store.write({...order,items});
  assert.deepEqual(store.read().items,items);
  store.write({...order,items:[items[0],items[0]]});
  assert.throws(()=>store.read());
  for (const stock_layer_id of [-1,0,'11',1.5]) {
    store.write({...order,items:[{...items[0],stock_layer_id}]});
    assert.throws(()=>store.read());
  }
});

test('bill discount persists alongside line adjustments and older drafts remain compatible', () => {
  const storage = memory();
  const store = draftStore(storage, 'cart');
  store.write(order);
  assert.equal(store.read().billDiscount, undefined);
  for (const billDiscount of [
    { type: 'AMOUNT', value: '100.50' },
    { type: 'PERCENT', value: '12.5' },
  ]) {
    store.write({ ...order, billDiscount });
    assert.deepEqual(store.read().billDiscount, billDiscount);
    assert.deepEqual(store.read().items, order.items);
  }
  store.write({ ...order, billDiscount: { type: 'PERCENT', value: '100.01' } });
  assert.throws(() => store.read());
});
