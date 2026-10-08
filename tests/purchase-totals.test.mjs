import test from 'node:test';
import assert from 'node:assert/strict';
import {lineCostCents, moneyCents, amountValue, displayCents, paymentBalance, allocateCharges} from '../resources/js/purchase-totals.js';

test('fractional delivery lines round separately before summing', () => {
  const total = lineCostCents('0.001','5.00') + lineCostCents('0.001','5.00');
  assert.equal(amountValue(total),'0.02');
  assert.equal(lineCostCents('3.5','180.00'),63000n);
  assert.equal(lineCostCents('.5','100.00'),5000n);
});
test('partial supplier payment preserves exact cents and displayed due', () => {
  const total = lineCostCents('4','390') + lineCostCents('3','130');
  const paid = moneyCents('850.55');
  assert.equal(displayCents(total),'1,950.00');
  assert.equal(amountValue(total-paid),'1099.45');
});
test('large permitted totals avoid floating-point rounding and malformed input is rejected', () => {
  assert.equal(amountValue(moneyCents('9999999999999.99')),'9999999999999.99');
  for(const value of ['-1','NaN','1.001']) assert.equal(moneyCents(value),0n);
});

test('payment status is inferred and excess becomes change',()=>{
 assert.deepEqual(paymentBalance(10000n,0n),{paid:0n,due:10000n,change:0n,status:'Unpaid'});
 assert.deepEqual(paymentBalance(10000n,4000n),{paid:4000n,due:6000n,change:0n,status:'Partial'});
 assert.deepEqual(paymentBalance(10000n,10000n),{paid:10000n,due:0n,change:0n,status:'Paid'});
 assert.deepEqual(paymentBalance(10000n,12500n),{paid:10000n,due:0n,change:2500n,status:'Paid'});
});
test('charge allocations conserve every cent including zero cost deliveries',()=>{
 assert.deepEqual(allocateCharges([100000n,50000n],1001n),[667n,334n]);
 assert.deepEqual(allocateCharges([0n,0n,0n],1n),[0n,1n,0n]);
 assert.equal(allocateCharges([100n,200n,300n],99999999999n).reduce((a,b)=>a+b,0n),99999999999n);
});
