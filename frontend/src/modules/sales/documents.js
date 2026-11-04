import { sriLankaDate } from '../../components/clock.js';
import { lineAmount, invoiceAmount } from './pricing.js';
const { $, $$, esc, fmt, toast, modal, formModal, table } = UI;
const methods = [['cash','Cash'],['card','Card'],['bank','Bank transfer'],['koko','Koko'],['other','Other payment']];
const options = rows => rows.map(([v,t])=>`<option value="${esc(v)}">${esc(t)}</option>`).join('');
const formatMoney = (n, currency = window.ERP_CONFIG?.CURRENCY || 'LKR') => currency + ' ' + Number(n || 0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
const pendingName = name => `webpoint_document_${API.user.id}_${name}`;
const feedback = error => Object.values(error.fields || {}).flat().join(' ') || error.message;

// Persist the exact operation before submission; retries always retain its original key and payload.
async function submitOnce(name, path, payload) {
  const storageKey = pendingName(name);
  const pending = JSON.parse(sessionStorage.getItem(storageKey) || 'null') || {path, payload, branch:API.branch, key:crypto.randomUUID()};
  if (String(pending.branch) !== String(API.branch)) throw new Error('Switch to the original branch before retrying this operation.');
  sessionStorage.setItem(storageKey, JSON.stringify(pending));
  try { const result = await API.post(pending.path, pending.payload, {key:pending.key}); sessionStorage.removeItem(storageKey); return result.data; }
  catch(error) { if (!error.uncertain && error.status !== 409) sessionStorage.removeItem(storageKey); throw error; }
}

export async function downloadPdf(type, id, number, factory=false, print=false) {
  const preview=print?window.open("about:blank","_blank"):null;
  const response = await fetch(`${API.base}/sales/${type}/${id}/${factory?'factory-pdf':'pdf'}`, {headers:{Accept:'application/pdf', Authorization:'Bearer '+API.token, ...(API.branch ? {'X-Branch-Id':API.branch} : {})}});
  if (!response.ok) { preview?.close(); const data = await response.json().catch(()=>({})); throw new Error(data.message || 'Could not download the PDF.'); }
  if (!response.headers.get('content-type')?.includes('application/pdf')) throw new Error('The server did not return a PDF.');
  const url = URL.createObjectURL(await response.blob());
  if(preview){preview.location.href=url;setTimeout(()=>URL.revokeObjectURL(url),300000);return;}
  const a = document.createElement('a'); a.href=url; a.download=`${number}${factory?'-factory':''}.pdf`; a.click(); setTimeout(()=>URL.revokeObjectURL(url),30000);
}

async function printDocuments(d, preview) {
  if(!preview){toast('Invoice saved. Use Print copies to open the print dialog.');return;}
  try {
    const response=await fetch(`${API.base}/sales/invoices/${d.id}/print`,{headers:{Accept:'text/html',Authorization:'Bearer '+API.token,...(API.branch?{'X-Branch-Id':API.branch}:{})}});
    if(!response.ok)throw new Error('Invoice saved, but print preview could not load. Use the saved invoice to retry.');
    const url=URL.createObjectURL(new Blob([await response.text()],{type:'text/html'}));
    preview.location.href=url;setTimeout(()=>URL.revokeObjectURL(url),300000);
  }catch(error){preview.close();toast(error.message,true);}
}

export function documentView(d, quote=false) {
  const money=n=>formatMoney(n,d.shop_snapshot?.currency);
  const requests = d.manufacturing_requests || [];
  const m = modal(`${quote?'Quotation':'Invoice'} ${d.number}`, `<div class="document-summary"><div><span class="eyebrow">${esc(d.shop_snapshot?.name || 'Webpoint One')}</span><h2>${esc(d.customer_snapshot?.name || d.customer?.name || 'Walk-in customer')}</h2><p>${esc(d.customer_snapshot?.phone || '')}</p></div><div><span>Total</span><h2>${money(d.total)}</h2>${quote?`<p>Valid until ${esc(d.valid_until)}</p>`:`<p>Status: <strong>${esc(fmt.label(d.payment_status || (Number(d.is_order?d.balance_due:d.credit_amount)>0?'pending':'completed')))}</strong></p><p>Paid ${money(d.paid)} · Balance ${money(d.is_order?d.balance_due:d.credit_amount)}</p>`}</div></div>
    ${!quote?`<p>Loyalty points earned: <strong>${Number(d.points_earned||0)}</strong></p>`:''}
    ${d.is_special?'<p class="document-special">Special order — existing stock is not used.</p>':''}
    ${table([{label:'Product',get:r=>r.description||r.product?.name},{label:'Quantity',get:r=>fmt.num(r.qty,3)},...(!quote&&d.is_order?[{label:'Allocated',get:r=>fmt.num(r.allocated_qty,3)},{label:'To make',get:r=>fmt.num(Math.max(0,r.qty-r.allocated_qty),3)}]:[]),{label:'Amount',get:r=>money(r.line_total)}],d.items||[])}
    ${requests.length?`<h3>Factory requests</h3>${table([{label:'Reference',k:'number'},{label:'Quantity',k:'qty'},{label:'Status',get:r=>fmt.label(r.progress_status || r.status)},{label:'Production order',get:r=>r.production_order?.number||'Awaiting BOM approval'}],requests)}`:''}
    ${!quote ? `<h3>Payments</h3>${table([{label:'Payment date',get:r=>r.payment_date||sriLankaDate(new Date(r.created_at))},{label:'Method',get:r=>methods.find(m=>m[0]===r.method)?.[1]||r.method},{label:'Amount',get:r=>money(r.amount)},{label:'Reference',k:'reference'}],d.payments||[],'No payments received yet.')}`:''}
    ${!quote?`<p>Delivery: ${money(d.delivery_charge)} · Services: ${money((d.service_charges||[]).reduce((n,c)=>n+Number(c.amount),0))}</p>`:''}
    ${d.notes?`<p class="document-notes">${esc(d.notes)}</p>`:''}
    ${!quote?'<button class="btn btn-ghost" data-refresh-invoice>Refresh status</button>':''}<button class="btn btn-brand" data-pdf>Download ${quote?'quotation':'invoice'} PDF</button><button class="btn btn-ghost" data-print>Open / print customer PDF</button>${quote?'':'<button class="btn btn-brand" data-print-copies>Print copies</button>'}${!quote&&requests.length?'<button class="btn btn-brand" data-factory>Download factory BOM PDF</button><button class="btn btn-ghost" data-print-factory>Open / print factory PDF</button>':''}<p class="section-description">Use the PDF viewer’s Print button. Both documents remain available from this invoice for reprinting.</p><p data-pdf-error role="alert"></p>`, {wide:true});
  $('[data-pdf]',m).onclick=async e=>{e.target.disabled=true;try{await downloadPdf(quote?'quotations':'invoices',d.id,d.number);}catch(error){$('[data-pdf-error]',m).textContent=error.message;}finally{e.target.disabled=false;}};
  if(!quote)$('[data-print-copies]',m).onclick=()=>printDocuments(d,window.open('about:blank','_blank'));
  for(const [selector,factory,print] of [['[data-print]',false,true],['[data-factory]',true,false],['[data-print-factory]',true,true]]) {
    const b=$(selector,m);if(b)b.onclick=async()=>{b.disabled=true;try{await downloadPdf(quote?'quotations':'invoices',d.id,d.number,factory,print);}catch(e){$('[data-pdf-error]',m).textContent=e.message;}finally{b.disabled=false;}};
  }
  if(!quote)$('[data-refresh-invoice]',m).onclick=async e=>{e.target.disabled=true;try{const latest=await API.get('/invoices/'+d.id);m.close();documentView(latest.data);}catch(error){$('[data-pdf-error]',m).textContent=feedback(error);e.target.disabled=false;}};
  return m;
}

export async function showCustomerCredit(host, id) {
  if(!host)return;
  host.dataset.customer=String(id||'');
  if(!id){host.textContent='Walk-in customer · Full payment required';return;}
  host.textContent='Loading credit limit…';
  try {const r=await API.get(`/sales/customers/${id}/credit`);if(host.isConnected&&host.dataset.customer===String(id))host.textContent='Customer credit limit: '+formatMoney(r.data.credit_limit);}
  catch(error){if(host.isConnected&&host.dataset.customer===String(id))host.textContent='Credit limit unavailable. Reselect the customer to retry.';}
}

export async function addCustomer(onCreated) {
  return formModal({title:'CRM · Add new customer',markSaved:false,
    fields:SCHEMA.RES.customers.fields.filter(f=>['name','phone','email','address','notes','credit_limit'].includes(f.name)).map(f=>f.name==='credit_limit'?{...f,required:true,min:0,max:999999999,step:'0.01',hint:'Enter 0 for no credit facility.'}:f),
    onSubmit:async d=>{
      const customer=await submitOnce('new_customer','/sales/customers',d);
      UI.clearOptCache();
      try { await onCreated(customer); } catch(error) { toast('Customer was registered, but the selector could not refresh. Reopen the page to select the saved customer.',true); }
      toast('Customer registered.');
    }});
}

export async function collectPayment(row, reload) {
  const money=n=>formatMoney(n,row.shop_snapshot?.currency);
  if (sessionStorage.getItem(pendingName('payment_'+row.id))) {
    const m=modal('Confirm pending payment','<p>A previous payment may already be recorded. Retry the original operation safely.</p><button class="btn btn-brand" data-retry>Confirm original payment</button><p role="alert"></p>');
    $('[data-retry]',m).onclick=async e=>{e.target.disabled=true;try{const updated=await submitOnce('payment_'+row.id);m.close();reload();documentView(updated);toast('Payment confirmed.');}catch(error){$('p[role=alert]',m).textContent=feedback(error);e.target.disabled=false;}};return;
  }
  await formModal({title:'Payment · '+row.number, submit:'Record payment', values:{payment_date:sriLankaDate()}, fields:[
    {name:'payment_date',label:'Payment date (Sri Lanka)',type:'date',required:true,max:sriLankaDate()},
    {name:'method',label:'Payment method',type:'select',required:true,options:methods},
    {name:'amount',label:'Amount (remaining '+money(row.balance_due)+')',type:'number',required:true,step:'0.01',min:0.01,max:Number(row.balance_due)},
    {name:'reference',label:'Transaction reference',type:'text'}
  ],onSubmit:async d=>{const updated=await submitOnce('payment_'+row.id,`/invoices/${row.id}/payments`,d);toast('Payment recorded. Updated invoice ready to print.');reload();setTimeout(()=>documentView(updated),0);}});
}

export const newInvoice = view => editor(view,false);
export const newQuotation = view => editor(view,true);

async function editor(view, quote) {
  let currency;const money=n=>formatMoney(n,currency);
  const type=quote?'quotations':'invoices';
  const pending=sessionStorage.getItem(pendingName(type));
  if(pending){
    view.innerHTML='<section class="card card-pad"><h2>Confirm your previous submission</h2><p>Your last submission may have reached the server. Confirm it before starting another document.</p><button class="btn btn-brand" data-retry>Confirm original submission</button><p role="alert"></p></section>';
    $('[data-retry]',view).onclick=async e=>{e.target.disabled=true;try{const d=await submitOnce(type);document.dispatchEvent(new Event('workspace-saved'));await editor(view,quote);documentView(d,quote);}catch(error){$('p[role=alert]',view).textContent=feedback(error);if(!sessionStorage.getItem(pendingName(type)))await editor(view,quote);else e.target.disabled=false;}};return;
  }
  let branch=API.branch||(!API.user.all_branches ? API.user.branch_id : '')||'';
  if(!branch){
    const result=await API.get('/lookups/branches',quote?{}:{filter:{type:'showroom'}});
    view.innerHTML=`<section class="card card-pad"><h2>Choose a branch</h2><p>Select the branch issuing this ${quote?'quotation':'invoice'}.</p><select class="inp" data-branch><option value="">Select branch</option>${options(result.data.map(b=>[b.id,b.name]))}</select></section>`;
    $('[data-branch]',view).onchange=e=>{if(e.target.value){API.branch=e.target.value;document.querySelector('#branchSelect').value=e.target.value;editor(view,quote);}};return;
  }
  const customers=await UI.options({res:'customers',label:r=>r.name,params:{'filter[is_walk_in]':0,'filter[status]':'active'}});
  const cart=new Map(); let products=[],page=1,lastPage=1,seq=0;
  const date=new Date();date.setDate(date.getDate()+30);const valid=`${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`;
  view.innerHTML=`<div class="resource-heading"><div><span class="eyebrow">SALES & POS</span><h2>New ${quote?'quotation':'invoice'}</h2><p>${quote?'Build an offer from every active product category. No stock or payments are posted.':'Allocate available stock and send shortages to your factory automatically.'}</p></div><a class="btn btn-ghost" href="#/${type}">View ${type}</a></div>
  <form class="document-editor" id="documentForm"><section class="card card-pad"><h3>Customer details</h3><div class="document-fields">
  <label><span class="lbl">Existing customer</span><select class="inp" name="customer_id"><option value="">${quote?'Enter customer details':'Walk-in customer'}</option>${options(customers)}</select>${quote?'':'<button type="button" class="btn btn-ghost" data-add-customer>+ Add customer</button>'}</label>
  ${quote?'':'<p data-customer-credit class="section-description">Walk-in customer · Full payment required</p>'}
  <label><span class="lbl">Customer name</span><input class="inp" name="customer_name" maxlength="255" ${quote?'required':'value="Walk-in customer" readonly'}></label>
  <label><span class="lbl">Phone</span><input class="inp" name="customer_phone" maxlength="40"></label>
  <label><span class="lbl">Address</span><input class="inp" name="customer_address" maxlength="1000"></label>
  <label><span class="lbl">${quote?'Valid until':'Requested delivery date'}</span><input class="inp" type="date" name="${quote?'valid_until':'due_date'}" ${quote?`required value="${valid}"`:''}></label></div>
  <label><span class="lbl">${quote?'Notes / terms':'Customer notes / terms'}</span><textarea class="inp" name="notes" rows="3" maxlength="5000"></textarea></label></section>
  <div class="document-columns"><section class="card card-pad"><h3>Product catalogue</h3><div class="document-search"><input class="inp" type="search" data-search placeholder="Search product name or SKU" aria-label="Search products"><select class="inp" data-category aria-label="Product category"><option value="">All categories</option></select></div><div data-products aria-live="polite"></div><div class="document-pagination"><button type="button" class="btn btn-ghost" data-prev>Previous</button><span data-page></span><button type="button" class="btn btn-ghost" data-next>Next</button></div></section>
  <section class="card card-pad"><h3>${quote?'Quotation':'Invoice'} items</h3><div data-lines></div>${quote?'':`<label><span class="lbl">Delivery charge</span><input class="inp" name="delivery_charge" type="number" min="0" step="0.01" value="0"></label><h3>Additional service charges</h3><div data-services></div><button type="button" class="btn btn-ghost" data-add-service>+ Add new service charge</button>`}<div class="document-total"><span>Total</span><strong data-total></strong></div>
  ${quote?'':`<h3>Payment received now</h3><div class="document-fields"><label><span class="lbl">Method</span><select class="inp" name="method">${options(methods)}</select></label><label><span class="lbl">Amount (registered customers may pay an advance)</span><input class="inp" name="amount" type="number" min="0" step="0.01" value="0" required></label><label><span class="lbl">Transaction reference</span><input class="inp" name="reference" maxlength="255"></label></div><p>Balance due: <strong data-balance></strong></p><p class="section-description">Record later payments from the invoice list. Koko and other methods record receipts; they do not charge an external payment gateway.</p>`}
  <p role="alert" data-error></p><button class="btn btn-brand" type="submit" data-save>Create ${quote?'quotation':'invoice'}</button><p class="section-description">A PDF download is available after saving.</p></section></div></form>`;
  const form=$('#documentForm',view);
  const services=[];
  const lineTotal=lineAmount;
  const total=()=>invoiceAmount([...cart.values()],quote?0:form.elements.delivery_charge.value,quote?[]:services);
  const totals=()=>{if(!quote){const walkin=!form.elements.customer_id.value;form.elements.amount.readOnly=walkin;if(walkin)form.elements.amount.value=total().toFixed(2);form.elements.amount.max=total();}$('[data-total]',view).textContent=money(total());if(!quote)$('[data-balance]',view).textContent=money(Math.max(0,total()-Number(form.elements.amount.value)));};
  const dirty=()=>document.dispatchEvent(new Event('workspace-dirty'));
  function drawLines(){

    $('[data-lines]',view).innerHTML=[...cart.values()].map(l=>`<div class="document-line"><div>${quote?`<strong>${esc(l.name)}</strong>`:`<button type="button" class="btn btn-ghost" data-edit-line="${l.id}"><strong>${esc(l.name)}</strong> · Edit details</button>`}<small>${money(l.price)} each${quote?'':` · Stock ${fmt.num(l.stock,3)} · Factory ${fmt.num(l.special_bom?l.qty:Math.max(0,l.qty-l.stock),3)}`}</small>${quote?'':`<small>${esc(l.customer_description||'')} · Discount ${l.discount_type==='percentage'?(l.discount_value||0)+'%':money(l.discount_value||0)} · ${esc(l.warranty?.name||'No warranty')} · Net ${money(lineTotal(l))}${l.special_bom?' · Special BOM':''}</small>`}</div><input class="inp" type="number" min="0.001" max="1000000" step="0.001" value="${l.qty}" data-qty="${l.id}" aria-label="Quantity for ${esc(l.name)}" required><button type="button" class="btn btn-ghost" data-remove="${l.id}" aria-label="Remove ${esc(l.name)}">×</button></div>`).join('')||'<p class="section-description">Add products from the catalogue.</p>';
    $$('[data-edit-line]',view).forEach(b=>b.onclick=async()=>{
      const l=cart.get(Number(b.dataset.editLine));
      await formModal({title:'Product details · '+l.name,wide:true,markSaved:false,values:{unit_price:l.price,discount_type:l.discount_type||'fixed',discount_value:l.discount_value||0,customer_description:l.customer_description||'',special_bom:!!l.special_bom,manufacturing_notes:l.manufacturing_notes||''},fields:[
        {name:'unit_price',label:'Unit price',type:'number',required:true,min:0,step:'0.01'},
        {name:'discount_type',label:'Discount type',type:'select',options:[['fixed','Fixed amount (whole line)'],['percentage','Percentage of line amount']]},
        {name:'discount_value',label:'Discount value',type:'number',min:0,step:'0.01'},
        {name:'customer_description',label:'Product description (customer invoice)',type:'textarea'},
        {name:'special_bom',label:'Special BOM Request — manufacture all units, keep store stock',type:'checkbox',default:false},
        {name:'manufacturing_notes',label:'Factory instructions (required for special BOM; internal only)',type:'textarea'}
      ],extra:{html:`<p><strong>Product warranty:</strong> ${esc(l.warranty?.name||'No warranty')} ${l.warranty?`· ${l.warranty.duration_months} months`:''}</p><p>${esc(l.warranty?.terms||'')}</p>`},onSubmit:async d=>{
        const next={...l,...d,price:d.unit_price,discount_value:d.discount_value||0};
        if(lineTotal(next)<0||(next.discount_type==='percentage'&&next.discount_value>100))throw new Error('Discount cannot exceed the line amount.');
        if(next.special_bom&&!next.manufacturing_notes?.trim())throw new Error('Enter special factory instructions.');
        cart.set(l.id,next);dirty();drawLines();
      }});
    });
    $$('[data-qty]',view).forEach(el=>el.onchange=()=>{cart.get(Number(el.dataset.qty)).qty=Number(el.value);dirty();drawLines();});
    $$('[data-remove]',view).forEach(el=>el.onclick=()=>{cart.delete(Number(el.dataset.remove));dirty();drawLines();});totals();
  }
  async function load(){
    const generation=++seq;$('[data-products]',view).textContent='Loading products…';
    try{const r=await API.get('/sales/catalog',{branch_id:branch,quotation:quote?1:0,search:$('[data-search]',view).value,category_id:$('[data-category]',view).value,page});if(generation!==seq||!view.isConnected)return;
      if($('[data-category]',view).options.length===1)$('[data-category]',view).insertAdjacentHTML('beforeend',options(r.categories.map(c=>[c.id,c.name])));
      currency=r.currency;totals();products=r.products.data;lastPage=r.products.last_page;
      $('[data-products]',view).innerHTML=products.map(p=>`<button type="button" class="document-product" data-add="${p.id}"><span><strong>${esc(p.name)}</strong><small>${esc(p.sku)} · ${esc(p.category||'Uncategorised')}</small></span><span>${money(p.price)}<small>${quote?'Add to quotation':fmt.num(p.stock,3)+' in stock'}</small></span></button>`).join('')||'<p>No products match your search.</p>';
      $('[data-page]',view).textContent=`${page} / ${lastPage}`;$('[data-prev]',view).disabled=page<=1;$('[data-next]',view).disabled=page>=lastPage;
      $$('[data-add]',view).forEach(b=>b.onclick=()=>{const p=products.find(p=>p.id===Number(b.dataset.add));const line=cart.get(p.id)||{...p,qty:0};line.qty++;cart.set(p.id,line);dirty();drawLines();});
    }catch(error){if(generation===seq)$('[data-products]',view).textContent=feedback(error);}
  }
  let timer;$('[data-search]',view).oninput=()=>{clearTimeout(timer);timer=setTimeout(()=>{page=1;load();},250);};$('[data-category]',view).onchange=()=>{page=1;load();};
  $('[data-prev]',view).onclick=()=>{page--;load();};$('[data-next]',view).onclick=()=>{page++;load();};
  form.elements.customer_id.onchange=()=>{const selected=!!form.elements.customer_id.value;form.elements.customer_name.required=!selected;['customer_name','customer_phone','customer_address'].forEach(name=>form.elements[name].disabled=selected);};
  if(!quote){
    form.elements.amount.oninput=totals;form.elements.delivery_charge.oninput=()=>{dirty();totals();};
    const selectCustomer=form.elements.customer_id.onchange;form.elements.customer_id.onchange=()=>{selectCustomer();totals();dirty();showCustomerCredit($('[data-customer-credit]',view),form.elements.customer_id.value);};
    const drawServices=()=>{$('[data-services]',view).innerHTML=services.map((c,i)=>`<p>${esc(c.name)} · ${money(c.amount)} <button type="button" class="btn btn-ghost" data-remove-service="${i}">Remove</button></p>`).join('');$$('[data-remove-service]',view).forEach(b=>b.onclick=()=>{services.splice(Number(b.dataset.removeService),1);dirty();drawServices();});totals();};
    $('[data-add-service]',view).onclick=()=>formModal({title:'Add service charge',markSaved:false,fields:[{name:'name',label:'Service description',type:'text',required:true},{name:'amount',label:'Charge',type:'number',required:true,min:0,step:'0.01'}],onSubmit:async d=>{services.push(d);dirty();drawServices();}});
    $('[data-add-customer]',view).onclick=()=>addCustomer(async customer=>{const option=new Option(customer.name,customer.id);form.elements.customer_id.add(option);form.elements.customer_id.value=customer.id;form.elements.customer_id.onchange();});
  }
  form.onsubmit=async e=>{e.preventDefault();let printWindow=null;const button=$('[data-save]',view);button.disabled=true;$('[data-error]',view).textContent='';try{
    if(!cart.size)throw new Error('Add at least one product.');
    const payload=Object.fromEntries(new FormData(form));payload.branch_id=Number(branch);payload.customer_id=payload.customer_id?Number(payload.customer_id):null;payload.items=[...cart.values()].map(l=>({product_id:l.id,qty:l.qty,...(quote?{}:{unit_price:l.price,discount_type:l.discount_type||'fixed',discount_value:l.discount_value||0,customer_description:l.customer_description||null,special_bom:!!l.special_bom,manufacturing_notes:l.manufacturing_notes||null})}));
    if(!quote){payload.delivery_charge=Number(form.elements.delivery_charge.value||0);payload.service_charges=services;delete payload.is_special;const amount=Number(payload.amount);if(amount>total())throw new Error('Payment exceeds the total.');payload.payments=amount>0?[{method:payload.method,amount,reference:payload.reference||null}]:[];delete payload.method;delete payload.amount;delete payload.reference;if(!payload.due_date)payload.due_date=null;}
    if(!quote&&[...cart.values()].some(l=>lineTotal(l)<0))throw new Error('A product discount exceeds its amount.');
    if(!quote)printWindow=window.open('about:blank','_blank');
    const d=await submitOnce(type,'/sales/'+type,payload);document.dispatchEvent(new Event('workspace-saved'));await editor(view,quote);documentView(d,quote);toast(`${quote?'Quotation':'Invoice'} created.`);if(!quote)await printDocuments(d,printWindow);
  }catch(error){printWindow?.close();if(sessionStorage.getItem(pendingName(type))){await editor(view,quote);}else{$('[data-error]',view).textContent=feedback(error);button.disabled=false;}}};
  drawLines();await load();
}

function factoryActions(request){
  if(!API.can('manufacturing.edit')||!request.production_order)return '';
  const step={draft:['release','Start'],released:['start','Process'],in_progress:['complete','Completed']}[request.production_order.status];
  return step?`<button class="btn btn-brand" data-request="${request.id}" data-progress="${step[0]}">${step[1]}</button>`:'';
}
export async function manufacturingRequests(view) {
  let page=1;
  view.innerHTML='<div class="resource-heading"><div><h2>Customer production requests</h2><p>Confirm the BOM, then update each order: Start → Process → Completed. Process issues raw materials; Completed allocates the goods. Completed goods are allocated directly to the customer invoice.</p></div></div><section class="card card-pad"><label class="lbl">Status<select class="inp" data-status><option value="">All statuses</option><option value="awaiting_bom">Awaiting BOM approval</option><option value="ready">Ready</option><option value="start">Start</option><option value="process">Process</option><option value="completed">Completed</option></select></label><button class="btn btn-ghost" data-refresh>Refresh status</button><div data-requests></div><div class="document-pagination"><button class="btn btn-ghost" data-prev>Previous</button><span data-page></span><button class="btn btn-ghost" data-next>Next</button></div></section>';
  const load=async()=>{try{const r=await API.get('/manufacturing-requests',{page,status:$('[data-status]',view).value});
    $('[data-requests]',view).innerHTML=r.data.map(x=>`<article class="factory-request"><div><h3>${esc(x.number)} · ${esc(x.product?.name)}</h3><p>${esc(x.invoice?.number)} · ${fmt.num(x.qty,3)} units · ${esc(fmt.label(x.progress_status || x.status))}${x.is_special?' · SPECIAL':''}</p><p class="document-notes">${esc(x.notes||'Standard product specification')}</p>${x.production_order?`<a href="#/production_orders?search=${encodeURIComponent(x.production_order.number)}">${esc(x.production_order.number)} · BOM #${x.production_order.bom_id} →</a>`:''}</div><button class="btn btn-ghost" data-factory-pdf="${x.invoice_id}" data-number="${esc(x.invoice?.number)}">Factory PDF</button>${factoryActions(x)}${x.status==='awaiting_bom'&&API.can('manufacturing.edit')?`<button class="btn btn-brand" data-approve="${x.id}" data-product="${x.product_id}">Confirm BOM & create order</button>`:''}</article>`).join('')||'<p>No production requests.</p>';
    $('[data-page]',view).textContent=`${r.current_page} / ${r.last_page}`;$('[data-prev]',view).disabled=page<=1;$('[data-next]',view).disabled=page>=r.last_page;
    $$('[data-factory-pdf]',view).forEach(b=>b.onclick=async()=>{try{await downloadPdf('invoices',b.dataset.factoryPdf,b.dataset.number,true);}catch(e){toast(e.message,true);}});
    $$('[data-progress]',view).forEach(b=>b.onclick=async()=>{
      const row=r.data.find(x=>String(x.id)===b.dataset.request); if(!row)return;
      const order=row.production_order,action=b.dataset.progress;
      if(action==='complete'){
        await formModal({title:'Complete '+row.number,submit:'Mark completed',values:{qty_produced:row.qty,wastage_qty:0},fields:[
          {name:'qty_produced',label:'Quantity produced',type:'number',required:true},
          {name:'wastage_qty',label:'Wastage quantity',type:'number'}
        ],onSubmit:async d=>{await API.post(`/production-orders/${order.id}/complete`,d);toast('Production completed and invoice updated.');await load();}});
      }else{b.disabled=true;try{await API.post(`/production-orders/${order.id}/${action}`);toast(action==='release'?'Status updated: Start.':'Status updated: Process. Materials issued.');await load();}catch(error){toast(feedback(error),true);await load();}}
    });
    $$('[data-approve]',view).forEach(b=>b.onclick=async()=>{try{await formModal({title:'Confirm manufacturing recipe',submit:'Create production order',fields:[
      {name:'bom_id',label:'Approved BOM for this product',type:'select',required:true,options:{res:'boms',label:b=>`${b.product?.name} · v${b.version}`,params:{'filter[product_id]':b.dataset.product,'filter[is_active]':1}}},
      {name:'factory_warehouse_id',label:'Factory raw material warehouse',type:'select',required:true,options:{res:'warehouses',label:w=>w.name,params:{'filter[type]':'factory_raw'}}}
    ],onSubmit:async d=>{await submitOnce('factory_'+b.dataset.approve,`/manufacturing-requests/${b.dataset.approve}/approve`,d);toast('Production order created.');load();}});}catch(error){toast(feedback(error),true);}});
  }catch(error){$('[data-requests]',view).textContent=feedback(error);}};
  $('[data-refresh]',view).onclick=()=>load();
  $('[data-status]',view).onchange=()=>{page=1;load();};$('[data-prev]',view).onclick=()=>{page--;load();};$('[data-next]',view).onclick=()=>{page++;load();};await load();
}
