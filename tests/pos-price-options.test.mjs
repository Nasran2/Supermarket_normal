import test from 'node:test';
import assert from 'node:assert/strict';
import {availablePriceOptions, sellingPriceOptions, priceOptionForItem, stockChoiceKey} from '../resources/js/pos-price-options.js';

test('depleted prices do not require a popup when only one selling price remains', () => {
  const available = {stock_price:'490.00', quantity:'10.000'};
  assert.deepEqual(availablePriceOptions({price_options:[
    {stock_price:'460.00', quantity:'0.000'}, available,
    {stock_price:'520.00', quantity:'0.000'},
  ]}), [available]);
});

test('all available selling prices keep their corresponding stock quantities', () => {
  const prices = [{stock_price:'490.00',quantity:'44.000'}, {stock_price:'520.00',quantity:'10.000'}];
  assert.deepEqual(availablePriceOptions({price_options:prices}), prices);
  assert.deepEqual(availablePriceOptions({price_options:[]}), []);
  assert.deepEqual(availablePriceOptions({}), []);
});

test('invalid and negative stock is excluded while a valid free item remains available', () => {
  assert.deepEqual(availablePriceOptions({price_options:[
    {stock_price:'100',quantity:'-1'}, {stock_price:'100',quantity:'bad'},
    {stock_price:'bad',quantity:'1'}, {stock_price:'-1',quantity:'1'},
    {stock_price:'0.00',quantity:'0.250'},
  ]}), [{stock_price:'0.00',quantity:'0.250'}]);
});

test('existing explicit stock-row drafts still restore the selected delivery', () => {
  const product = {price_options:[
    {stock_layer_id:11,stock_price:'490.00',quantity:'44.000'},
    {stock_layer_id:12,stock_price:'490.00',quantity:'10.000'},
  ]};
  assert.equal(availablePriceOptions(product).length,2);
  const first = {id:8,unit_id:1,stock_layer_id:11,stock_price:'490.00'};
  const second = {...first,stock_layer_id:12};
  assert.notEqual(stockChoiceKey(first),stockChoiceKey(second));
  assert.equal(priceOptionForItem(product,second),product.price_options[1]);
  assert.equal(priceOptionForItem({price_options:[product.price_options[0]]},second),undefined);
  assert.equal(priceOptionForItem(product,{...second,stock_price:'500.00'}),undefined);
  assert.equal(priceOptionForItem(product,{stock_price:'490'}).quantity,'54.000');
  assert.equal(priceOptionForItem(product,{stock_price:'490'}).stock_layer_id,null);
});

test('same-price deliveries combine into one automatic choice without restricting FIFO', () => {
  const units = [{id:1,price:'490.00'}];
  const product = {price_options:[
    {stock_layer_id:11,stock_price:'490.00',quantity:'44.000',units,reference:'old'},
    {stock_layer_id:12,stock_price:'490',quantity:'10.000',units,reference:'new'},
    {stock_layer_id:13,stock_price:'520.00',quantity:'0.000',units},
  ]};
  assert.deepEqual(sellingPriceOptions(product),[
    {stock_price:'490.00',quantity:'54.000',stock_layer_id:null,units},
  ]);
  assert.deepEqual(priceOptionForItem(product,{}),sellingPriceOptions(product)[0]);
});

test('different prices remain separate choices and same-price decimal quantities sum exactly', () => {
  const product = {price_options:[
    {stock_layer_id:1,stock_price:'240.00',quantity:'0.100'},
    {stock_layer_id:2,stock_price:'240',quantity:'0.200'},
    {stock_layer_id:3,stock_price:'250.00',quantity:'2.500'},
    {stock_layer_id:4,stock_price:'260.00',quantity:'0.000'},
  ]};
  const options = sellingPriceOptions(product);
  assert.equal(options.length,2);
  assert.deepEqual(options.map(({stock_price,quantity}) => ({stock_price,quantity})),[
    {stock_price:'240.00',quantity:'0.300'},
    {stock_price:'250.00',quantity:'2.500'},
  ]);
  assert.ok(options.every((option) => option.stock_layer_id === null));
  assert.equal(priceOptionForItem(product,{}),undefined);
  assert.deepEqual(sellingPriceOptions({}),[]);
});
