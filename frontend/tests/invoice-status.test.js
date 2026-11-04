import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
const window={};
vm.runInNewContext(readFileSync(new URL('../assets/js/schema.js',import.meta.url),'utf8'),{
  window,UI:{fmt:{label:s=>s,money:String,num:String,date:String,dt:String},badge:s=>s,esc:String}
});
const invoices=window.SCHEMA.RES.invoices;
test('invoice status uses the balance rather than the legacy posted status',()=>{
  const status=invoices.cols.find(c=>c.label==='Status');
  assert.equal(status.html({status:'completed',is_order:true,balance_due:'0.01'}),'pending');
  assert.equal(status.html({status:'completed',is_order:true,balance_due:'0.00'}),'completed');
  assert.equal(status.html({status:'completed',is_order:false,credit_amount:'100.00'}),'pending');
  assert.equal(invoices.filters[0].name,'payment_status');
});
test('invoice factory progress retains each product and its independent production state',()=>{
  const col=invoices.cols.find(c=>c.label==='Factory / BOM progress');
  const value=col.get({manufacturing_requests:[{product:{name:'Red sheet'},status:'queued',progress_status:'process'},{product:{name:'Pillow'},status:'completed',progress_status:'completed'}]});
  assert.match(value,/Red sheet: process/);assert.match(value,/Pillow: completed/);
});
test('factory controls use the existing guarded production transitions in order',()=>{
  const actions=window.SCHEMA.RES.production_orders.actions;
  for(const [status,label,endpoint] of [['draft','Start','release'],['released','Process','start']]){
    const action=actions.find(a=>a.label===label);
    assert.equal(action.when({status}),true);
    assert.equal(action.when({status:'completed'}),false);
    assert.equal(action.run({id:9})[1],`/production-orders/9/${endpoint}`);
  }
  assert.equal(actions.find(a=>a.label==='Completed').when({status:'in_progress'}),true);
});
