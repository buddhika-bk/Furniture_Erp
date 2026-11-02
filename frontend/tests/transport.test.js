import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { readFileSync } from 'node:fs';
import { toCsv } from '../src/services/csv.js';
const source=readFileSync(new URL('../assets/js/api.js',import.meta.url),'utf8');
const storage=()=>{const map=new Map();return {getItem:k=>map.get(k)??null,setItem:(k,v)=>map.set(k,String(v)),removeItem:k=>map.delete(k)};};
function setup(fetch){
 const events=[],sessionStorage=storage(),localStorage=storage();
 localStorage.setItem('erp_token','legacy');
 const window={ERP_CONFIG:{API_BASE:'http://localhost:8000/api'},dispatchEvent:e=>events.push(e.type)};
 vm.runInNewContext(source,{window,sessionStorage,localStorage,location:{href:'http://localhost:8080',protocol:'http:'},URL,AbortController,setTimeout,clearTimeout,fetch,CustomEvent:class{constructor(type){this.type=type;}}});
 return {api:window.API,sessionStorage,localStorage,events};
}
const response=(status,data)=>({status,ok:status<400,headers:new Headers(),json:async()=>data});
test('credentials migrate to session storage and nested filters preserve zero',async()=>{
 let call;const {api,localStorage,sessionStorage}=setup(async(...args)=>{call=args;return response(200,{data:[]});});
 api.branch=2;await api.get('/products',{filter:{is_active:0},search:'a & b'});
 assert.equal(localStorage.getItem('erp_token'),null);assert.equal(sessionStorage.getItem('erp_token'),'legacy');
 assert.equal(call[0].searchParams.get('filter[is_active]'),'0');assert.equal(call[0].searchParams.get('search'),'a & b');
 assert.equal(call[1].headers['X-Branch-Id'],'2');assert.equal(call[1].headers.Authorization,'Bearer legacy');
});
test('uncertain checkout never retries automatically and preserves idempotency header',async()=>{
 let count=0;const {api}=setup(async(_,options)=>{count++;assert.equal(options.headers['Idempotency-Key'],'sale-key');throw new TypeError('offline');});
 await assert.rejects(api.post('/pos/checkout',{}, {key:'sale-key'}),e=>e.uncertain&&e.status===0);assert.equal(count,1);
});
test('server failure is an uncertain write; validation failure is definitive',async()=>{
 const a=setup(async()=>response(503,{message:'Unavailable'})).api;
 await assert.rejects(a.post('/pos/checkout',{}),e=>e.uncertain&&e.status===503);
 const b=setup(async()=>response(422,{message:'Invalid',errors:{name:['Required']}})).api;
 await assert.rejects(b.post('/products',{}),e=>!e.uncertain&&e.fields.name[0]==='Required');
});
test('expired authentication clears branch and identity',async()=>{
 const {api,events}=setup(async()=>response(401,{message:'Expired'}));api.branch=3;api.setUser({id:1});
 await assert.rejects(api.get('/apps'));assert.equal(api.token,null);assert.equal(api.user,null);assert.equal(api.branch,'');assert.deepEqual(events,['session-expired']);
});
test('already canceled requests carry an aborted signal',async()=>{
 const {api}=setup(async(_,o)=>{assert.equal(o.signal.aborted,true);throw new DOMException('Aborted','AbortError');});
 const controller=new AbortController();controller.abort();await assert.rejects(api.get('/search',{}, {signal:controller.signal}),e=>e.name==='AbortError');
});
test('CSV quotes embedded text and neutralizes spreadsheet formulas without changing numeric negatives',()=>{
 assert.equal(toCsv([{name:'a,"b"\nc',value:'=SUM(A1)',amount:-3}]),'"name","value","amount"\r\n"a,""b""\nc","\'=SUM(A1)","-3"');
 assert.equal(toCsv([]),'');
});
