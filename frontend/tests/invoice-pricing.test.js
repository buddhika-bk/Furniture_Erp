import test from 'node:test';
import assert from 'node:assert/strict';
import {lineAmount, invoiceAmount, posTotals} from '../src/modules/sales/pricing.js';

test('invoice combines line discounts, delivery and multiple service charges', () => {
  const lines = [{price:100,qty:2,discount_type:'percentage',discount_value:10},
    {price:50,qty:3,discount_type:'fixed',discount_value:20}];
  assert.equal(lineAmount(lines[0]),180);
  assert.equal(lineAmount(lines[1]),130);
  assert.equal(invoiceAmount(lines,25,[{amount:15},{amount:5}]),355);
});

test('fractional quantities and percentage half cents round up like the server', () => {
  assert.equal(lineAmount({price:1,qty:1.005}),1.01);
  assert.equal(lineAmount({price:1.01,qty:1,discount_type:'percentage',discount_value:50}),0.5);
  assert.equal(invoiceAmount([{price:0.1,qty:1},{price:0.2,qty:1}]),0.3);
});

test('excessive fixed discounts remain negative for validation instead of silently changing the discount', () => {
  assert.equal(lineAmount({price:10,qty:1,discount_type:'fixed',discount_value:11}),-1);
  assert.equal(lineAmount({price:10,qty:1,discount_type:'percentage',discount_value:100}),0);
});

test('POS discounts apply to each product then the bill, before loyalty', () => {
  const lines=[{price:100,qty:2,discount_type:'percentage',discount_value:10},{price:50,qty:3,discount_type:'fixed',discount_value:20}];
  assert.deepEqual(posTotals(lines,{billType:'percentage',billValue:10,redeem:100,pointValue:0.05}),{sub:350,productDiscount:40,bill:31,loyalty:5,disc:76,total:274});
  assert.equal(posTotals(lines,{billType:'fixed',billValue:20}).total,290);
  assert.equal(posTotals([{price:0.29,qty:1}],{billType:'percentage',billValue:50}).total,0.14);
});
test('POS discounts remain visible when a quantity change makes them excessive', () => {
  assert.equal(posTotals([{price:10,qty:1}],{billValue:11}).total,-1);
  assert.equal(posTotals([{price:10,qty:1}],{billType:'percentage',billValue:100}).total,0);
});
