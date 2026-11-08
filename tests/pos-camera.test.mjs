import assert from 'node:assert/strict';
import test from 'node:test';
import { alternateRetailCode, createScanGate } from '../resources/js/pos-camera-decoder.js';

const a = { format: 'code_128', value: 'A' };
const b = { format: 'code_128', value: 'B' };

test('Code 128 needs consecutive frames, and a held code needs a sustained absence', () => {
  const accept = createScanGate();
  assert.equal(accept([a], 0), null);
  assert.equal(accept([], 125), null);
  assert.equal(accept([a], 250), null);
  assert.equal(accept([a], 375), a);
  assert.equal(accept([a], 1750), null);
  assert.equal(accept([], 1875), null);
  assert.equal(accept([a], 2000), null);
  assert.equal(accept([a], 2125), null);
  assert.equal(accept([], 2250), null);
  assert.equal(accept([], 3250), null);
  assert.equal(accept([a], 3375), null);
  assert.equal(accept([a], 3500), a);
});

test('new items can scan immediately without rearming a visible label', () => {
  const accept = createScanGate();
  assert.equal(accept([a], 0), null);
  assert.equal(accept([a], 125), a);
  assert.equal(accept([a, b], 250), null);
  assert.equal(accept([a, b], 375), b);
  assert.equal(accept([a, b], 1500), null);
});

test('UPC-A and its EAN-13 representation share one held label', () => {
  const accept = createScanGate();
  const upc = { value: '036000291452', format: 'upc_a' };
  const ean = { value: '0036000291452', format: 'ean_13' };
  assert.equal(alternateRetailCode(upc), ean.value);
  assert.equal(alternateRetailCode(ean), upc.value);
  assert.equal(accept([upc], 0), upc);
  assert.equal(accept([ean], 125), null);
});
