import { lineAmount, posTotals } from './pricing.js';
import { addCustomer, showCustomerCredit } from './documents.js';
import { $,$$,esc,fmt,badge,toast,modal,confirmBox,formModal,linesEditor,table,barChart,vBarChart,lineChart,donutChart,sparkline,kpi,PALETTE,can,unwrap,stat,card,src,f,req,today,PRODUCT,WH,I,act } from "../../components/context.js";
import { H, receiptHtml } from "../../services/workflows.js";
  async function pos(view) {
    const pendingKey='erp_pending_checkout_'+API.user.id;
    const saved=sessionStorage.getItem(pendingKey);
    if(saved){
      let pending;try{pending=JSON.parse(saved);}catch{sessionStorage.removeItem(pendingKey);return pos(view);}
      view.innerHTML=card('Checkout needs confirmation', '<p class="section-description">A previous checkout may have reached the server. Check its result or safely resend the same operation before starting another sale.</p><button class="btn btn-brand" id="checkOperation">Check status</button> <button class="btn btn-ghost" id="retryOperation">Retry same checkout</button><p id="operationMessage" role="status"></p>');
      const recover=async retry=>{const buttons=$$('button',view);buttons.forEach(b=>b.disabled=true);try{if(String(API.branch||'')!==String(pending.branch||''))throw new Error('Switch back to the original branch to resolve this checkout.');const result=retry?await API.post('/pos/checkout',pending.payload,{key:pending.key}):(await API.get('/operations/'+pending.key)).data.result;sessionStorage.removeItem(pendingKey);modal('Confirmed sale',receiptHtml(unwrap(result)));await pos(view);}catch(e){$('#operationMessage',view).textContent=e.message;if(retry&&e.status===422){sessionStorage.removeItem(pendingKey);toast('Checkout was rejected. Start a corrected sale.',true);await pos(view);}}finally{buttons.forEach(b=>b.disabled=false);}};
      $('#checkOperation',view).onclick=()=>recover(false);$('#retryOperation',view).onclick=()=>recover(true);return;
    }
    const st = { branch: API.branch || API.user.branch_id || '', cart: new Map(), cat: null, q: '', pays: [], billType:'fixed', billValue:0 };
    const load = async () => {
      if (!st.branch) {
        const br = await UI.options(src('branches', (x) => x.name, { 'filter[type]': 'showroom' }));
        view.innerHTML = card('Choose a showroom', `<label class="block max-w-sm"><span class="lbl">Showroom</span><select class="inp" id="brSel"><option value="">Select...</option>${br.map(([v, l]) => `<option value="${v}">${esc(l)}</option>`).join('')}</select></label>`);
        $('#brSel', view).onchange = (e) => { st.branch = e.target.value; if (st.branch) load(); };
        return;
      }
      try { st.cat = await API.get('/pos/catalog', { branch_id: st.branch }); } catch (e) { view.innerHTML = card('Point of sale', `<p class="text-rose-400">${esc(e.message)}</p>`); return; }
      if (!st.cat.session) {
        view.innerHTML = card('Open the till', `<p class="text-sm mb-3 text-slate-400">Start your shift by counting the cash in the drawer.</p><form id="openF" class="flex flex-wrap gap-3 items-end"><label><span class="lbl">Opening cash</span><input class="inp" type="number" step="any" min="0" name="c" value="0" required></label><button class="btn btn-brand">Open till</button></form>`);
        $('#openF', view).onsubmit = async (e) => { e.preventDefault(); if (await act('post', '/cashier-sessions/open', { opening_cash: Number(e.target.c.value), branch_id: st.branch }, 'Till opened.')) load(); };
        return;
      }
      draw();
    };
    const totals=()=>posTotals([...st.cart.values()].map(l=>({...l,price:l.p.price})),{rule:(st.cat.discounts||[]).find(x=>String(x.id)===String(st.rule)),billType:st.billType,billValue:st.billValue,redeem:st.redeem||0,pointValue:Number(st.cat.loyalty_point_value||0)});
    const draw = () => {
      const prods = (st.cat.products || []).filter((p) => !st.q || (p.name + p.sku).toLowerCase().includes(st.q.toLowerCase()));
      view.innerHTML = `<div class="grid lg:grid-cols-[1fr_26rem] gap-4 items-start"><div><div class="flex gap-2 mb-3"><input class="inp" id="pq" type="search" placeholder="Search by name or SKU" value="${esc(st.q)}"><button class="btn btn-ghost" id="endDay">Close day</button></div>
        <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-3">${prods.map((p) => `<button class="pos-tile" data-p="${p.id}" ${p.stock <= 0 ? 'disabled' : ''}><div class="font-semibold leading-tight text-white">${esc(p.name)}</div><div class="text-xs text-slate-400">${esc(p.variant || p.sku)}</div><div class="mt-2 font-display font-bold text-white">${fmt.money(p.price)}</div><div class="text-xs ${p.stock <= 5 ? 'text-amber-300' : 'text-slate-400'}">${fmt.num(p.stock, 3)} in stock</div></button>`).join('') || '<p class="col-span-full text-slate-500">No finished goods in stock at this showroom. Transfer stock from the factory first.</p>'}</div></div>
        <aside class="card card-pad lg:sticky lg:top-20" id="cartBox" aria-label="Current sale"></aside></div>`;
      $('#pq', view).oninput = (e) => { st.q = e.target.value; const pos = e.target.selectionStart; draw(); const i = $('#pq', view); i.focus(); i.setSelectionRange(pos, pos); };
      $('#endDay', view).onclick = () => H.closeSession(st.cat.session, () => load());
      $$('[data-p]', view).forEach((b) => (b.onclick = () => { const p = st.cat.products.find((x) => x.id == b.dataset.p); const l = st.cart.get(p.id) || { p, qty: 0 }; if (l.qty + 1 > p.stock) return toast('Only ' + fmt.num(p.stock, 3) + ' in stock.', true); l.qty += 1; st.cart.set(p.id, l); drawCart(); }));
      drawCart();
    };
    let cartGeneration = 0;
    const drawCart = async () => {
      const renderId = ++cartGeneration;
      if(st.cart.size)document.dispatchEvent(new Event('workspace-dirty'));
      const box = $('#cartBox', view), t = totals();
      const custOpts = await UI.options(src('customers', (x) => `${x.name} (${x.phone || x.code})`, {'filter[status]':'active','filter[is_walk_in]':0}));
      if(renderId !== cartGeneration || !box?.isConnected)return;
      box.innerHTML = `<h2 class="font-display text-lg font-bold mb-3 text-white">Current sale</h2>
        <div class="space-y-2 mb-3">${[...st.cart.values()].map((l) => `<div class="flex items-center gap-2"><div class="flex-1 min-w-0"><div class="truncate text-sm font-semibold text-white">${esc(l.p.name)}</div><div class="text-xs text-slate-400">${fmt.money(l.p.price)} each</div><button type="button" class="btn btn-ghost btn-sm" data-line-discount="${l.p.id}">Discount: ${l.discount_type==='percentage'?(l.discount_value||0)+'%':fmt.money(l.discount_value||0)}</button></div><input class="inp !w-20" type="number" step="any" min="0" data-q="${l.p.id}" value="${l.qty}" aria-label="Quantity"><div class="w-20 text-right text-sm tabular-nums text-slate-200">${fmt.money(lineAmount({...l,price:l.p.price}))}</div><button class="btn-icon !w-8 !h-8" data-x="${l.p.id}" aria-label="Remove">&times;</button></div>`).join('') || '<p class="text-sm text-slate-500">Tap a product to add it.</p>'}</div>
        <div class="grid gap-2"><label class="lbl" for="cu">Customer</label><div class="flex gap-2"><select class="inp" id="cu"><option value="">Walk-in customer</option>${custOpts.map(([v, l]) => `<option value="${v}" ${String(st.cust) === String(v) ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select><button type="button" class="btn btn-ghost btn-sm" id="posAddCustomer">+ Add customer</button></div><p data-customer-credit class="section-description"></p>
        <select class="inp" id="sp"><option value="">Salesperson</option>${(st.cat.salespeople || []).map((s) => `<option value="${s.id}" ${String(st.sp) === String(s.id) ? 'selected' : ''}>${esc(s.name)}</option>`).join('')}</select>
        <select class="inp" id="dr"><option value="">No discount</option>${(st.cat.discounts || []).map((x) => `<option value="${x.id}" ${String(st.rule) === String(x.id) ? 'selected' : ''}>${esc(x.name)}</option>`).join('')}</select>
        <button type="button" class="btn btn-ghost btn-sm" id="billDiscount">Manual bill discount: ${st.billType==='percentage'?st.billValue+'%':fmt.money(st.billValue)}</button><p class="section-description">Bill discount applies after product discounts. Choose a rule or a manual bill discount.</p>
        ${st.cust ? `<input class="inp" id="rp" type="number" min="0" step="1" placeholder="Loyalty points to redeem" value="${st.redeem || ''}">` : ''}</div>
        <dl class="mt-4 space-y-1 text-sm text-slate-300"><div class="flex justify-between"><dt>Subtotal</dt><dd>${fmt.money(t.sub)}</dd></div><div class="flex justify-between"><dt>Product discounts</dt><dd>-${fmt.money(t.productDiscount)}</dd></div><div class="flex justify-between"><dt>Bill discount</dt><dd>-${fmt.money(t.bill)}</dd></div><div class="flex justify-between"><dt>Loyalty discount</dt><dd>-${fmt.money(t.loyalty)}</dd></div><div class="flex justify-between text-lg font-bold text-white"><dt>Total</dt><dd>${fmt.money(t.total)}</dd></div></dl>
        <p class="text-xs text-slate-500 mt-1">Estimated total. The server confirms discounts and loyalty redemption before posting; configured amounts must reconcile.</p>
        <div class="mt-3 space-y-2" id="pays">${st.pays.map((p, i) => `<div class="flex gap-2"><select class="inp" data-pm="${i}">${[['cash','Cash'],['card','Card'],['bank','Bank transfer'],['koko','Koko'],['other','Other payment'],['credit','Credit']].map(([m,label]) => `<option value="${m}" ${m==='credit'&&!st.cust?'disabled':''} ${p.method === m ? 'selected' : ''}>${label}</option>`).join('')}</select><input class="inp" type="number" step="any" min="0" data-pa="${i}" value="${p.amount}" aria-label="Payment amount"><button class="btn-icon !w-10" data-px="${i}" aria-label="Remove payment">&times;</button></div>`).join('')}</div>
        <p class="section-description">Credit is available after selecting a registered customer, within their credit limit.</p><div class="flex gap-2 mt-2"><button class="btn btn-ghost btn-sm" id="addPay">Add payment</button><button class="btn btn-ghost btn-sm" id="payAll">Pay full amount in cash</button></div>
        <div id="posErr" class="hidden mt-3 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-sm px-3 py-2" role="alert"></div>
        <button class="btn btn-mint w-full mt-4 py-3" id="charge" ${st.cart.size ? '' : 'disabled'}>Charge ${fmt.money(t.total)}</button>`;
      showCustomerCredit($('[data-customer-credit]',box),st.cust);
      const discountFields=[{name:'discount_type',label:'Discount type',type:'select',required:true,options:[['fixed','Fixed amount'],['percentage','Percentage']]},{name:'discount_value',label:'Discount value',type:'number',required:true,min:0,step:'0.01'}];
      $$('[data-line-discount]',box).forEach(b=>b.onclick=()=>{const l=st.cart.get(Number(b.dataset.lineDiscount));return formModal({title:'Product discount · '+l.p.name,markSaved:false,values:{discount_type:l.discount_type||'fixed',discount_value:l.discount_value||0},fields:discountFields,extra:{html:'<p>Fixed amount applies to this whole product line.</p>'},onSubmit:async d=>{if((d.discount_type==='percentage'&&d.discount_value>100)||lineAmount({...l,...d,price:l.p.price})<0)throw new Error('Discount cannot exceed the product amount.');Object.assign(l,d);drawCart();}});});
      $('#billDiscount',box).onclick=()=>formModal({title:'Manual bill discount',markSaved:false,values:{discount_type:st.billType,discount_value:st.billValue},fields:discountFields,onSubmit:async d=>{const base=totals().sub-totals().productDiscount;if((d.discount_type==='percentage'&&d.discount_value>100)||(d.discount_type==='fixed'&&d.discount_value>base))throw new Error('Discount cannot exceed the amount after product discounts.');st.billType=d.discount_type;st.billValue=d.discount_value;st.rule='';drawCart();}});
      $$('[data-q]', box).forEach((i) => (i.onchange = () => { const l = st.cart.get(Number(i.dataset.q)); const v = Number(i.value); if (v <= 0) st.cart.delete(l.p.id); else if (v > l.p.stock) { toast('Only ' + fmt.num(l.p.stock, 3) + ' in stock.', true); } else l.qty = v; drawCart(); }));
      $$('[data-x]', box).forEach((b) => (b.onclick = () => { st.cart.delete(Number(b.dataset.x)); drawCart(); }));
      $('#posAddCustomer',box).onclick=()=>addCustomer(async customer=>{st.cust=String(customer.id);st.redeem=0;document.dispatchEvent(new Event('workspace-dirty'));await drawCart();});
      $('#cu', box).onchange = (e) => { st.cust = e.target.value; if (!st.cust) {st.redeem = 0;st.pays=st.pays.filter(p=>p.method!=='credit');} drawCart(); };
      $('#sp', box).onchange = (e) => (st.sp = e.target.value);
      $('#dr', box).onchange = (e) => { st.rule = e.target.value;st.billValue=0; drawCart(); };
      if ($('#rp', box)) $('#rp', box).onchange = (e) => { st.redeem = Math.max(0, Math.floor(Number(e.target.value))); drawCart(); };
      $$('[data-pm]', box).forEach((s) => (s.onchange = () => (st.pays[s.dataset.pm].method = s.value)));
      $$('[data-pa]', box).forEach((s) => (s.onchange = () => (st.pays[s.dataset.pa].amount = Number(s.value))));
      $$('[data-px]', box).forEach((b) => (b.onclick = () => { st.pays.splice(Number(b.dataset.px), 1); drawCart(); }));
      $('#addPay', box).onclick = () => { st.pays.push({ method: 'cash', amount: 0 }); drawCart(); };
      $('#payAll', box).onclick = () => { st.pays = [{ method: 'cash', amount: Number(totals().total.toFixed(2)) }]; drawCart(); };
      $('#charge', box).onclick = async () => {
        const err = $('#posErr', box);
        const payments = st.pays.filter((p) => Number(p.amount) > 0);
        const btn = $('#charge', box); btn.disabled = true;
        try {
          if(totals().total<0 || [...st.cart.values()].some(l=>lineAmount({...l,price:l.p.price})<0))throw new Error('Discount exceeds the remaining sale amount. Update the discounts.');
          if (!payments.length && totals().total>0) throw new Error('Add a payment, or choose "Pay full amount in cash".');
          const payload = { branch_id: Number(st.branch), customer_id: st.cust ? Number(st.cust) : null, salesperson_id: st.sp ? Number(st.sp) : null, discount_rule_id: st.rule ? Number(st.rule) : null, redeem_points: st.redeem || 0, bill_discount_type:st.billType,bill_discount_value:st.billValue, items: [...st.cart.values()].map((l) => ({ product_id: l.p.id, qty: l.qty,discount_type:l.discount_type||'fixed',discount_value:l.discount_value||0 })), payments };
          const key=crypto.randomUUID();sessionStorage.setItem(pendingKey,JSON.stringify({key,payload,branch:API.branch}));
          const inv=unwrap(await API.post('/pos/checkout',payload,{key}));sessionStorage.removeItem(pendingKey);document.dispatchEvent(new Event('workspace-saved'));
          st.cart.clear(); st.pays = []; st.cust = st.sp = st.rule = ''; st.redeem = 0;st.billType='fixed';st.billValue=0;
          toast('Sale completed.'); modal('Sale complete', receiptHtml(inv)); load();
        } catch (e) { if(e.uncertain){await pos(view);return;}sessionStorage.removeItem(pendingKey);err.textContent=e.message;err.classList.remove('hidden');btn.disabled=false; }
      };
    };
    load();
  }


export { pos };
