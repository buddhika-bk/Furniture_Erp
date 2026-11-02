import { $,$$,esc,fmt,badge,toast,modal,confirmBox,formModal,linesEditor,table,barChart,vBarChart,lineChart,donutChart,sparkline,kpi,PALETTE,can,unwrap,stat,card,src,f,req,today,PRODUCT,WH,I,act } from "../../components/context.js";
import { H, receiptHtml } from "../../services/workflows.js";
  async function inventory(view) {
    view.innerHTML = `<div class="card"><div class="p-4 flex flex-wrap gap-2 items-end border-b border-line"><label class="min-w-[12rem]"><span class="lbl">Location</span><select class="inp" id="wh"><option value="">All locations</option></select></label>
      ${can('inventory.approve') ? '<button class="btn btn-ghost" id="open">Enter opening stock</button>' : ''}${can('inventory.create') ? '<button class="btn btn-brand" id="tr">Transfer stock</button>' : ''}</div><div id="inv" aria-live="polite"></div><div id="inventoryPager" class="p-4 flex justify-between items-center"></div></div>`;
    (await UI.options(WH)).forEach(([v, l]) => $('#wh', view).insertAdjacentHTML('beforeend', `<option value="${v}">${esc(l)}</option>`));
    let page=1, sequence=0;
    const load = async () => {
      const token=++sequence;
      $('#inv',view).innerHTML='<div class="loading-state" role="status"><div></div><div></div><div></div></div>';
      try {
      const r = await API.get('/stock_balances', { per_page: 25, page, filter: { warehouse_id: $('#wh', view).value } });
      if(token!==sequence||!view.isConnected)return;
      $('#inv', view).innerHTML = table([{ label: 'Product', get: (b) => b.product?.name }, { label: 'SKU', get: (b) => b.product?.sku }, { label: 'Location', get: (b) => b.warehouse?.name }, { label: 'On hand', num: true, get: (b) => fmt.num(b.qty, 3) }, { label: 'Reorder at', num: true, get: (b) => fmt.num(b.product?.reorder_level, 3) }, { label: 'Status', html: (b) => (Number(b.product?.reorder_level) > 0 && Number(b.qty) <= Number(b.product.reorder_level) ? badge('low') : badge('active')) }], r.data || [], 'No stock recorded yet. Enter opening stock or receive a purchase order.');
      $('#inventoryPager',view).innerHTML=`<span class="text-sm text-slate-400">${r.total} records · Page ${r.current_page} of ${r.last_page}</span><div><button id="prevStock" class="btn btn-ghost btn-sm" ${page<=1?'disabled':''}>Previous</button> <button id="nextStock" class="btn btn-ghost btn-sm" ${page>=r.last_page?'disabled':''}>Next</button></div>`;
      $('#prevStock',view).onclick=()=>{page--;load();};$('#nextStock',view).onclick=()=>{page++;load();};
      }catch(e){if(token!==sequence)return;$('#inv',view).innerHTML=`<div class="empty-state" role="alert"><p>${esc(e.message)}</p><button class="btn btn-ghost" id="retryStock">Try again</button></div>`;$('#retryStock',view).onclick=load;}
    };
    $('#wh', view).onchange = () => {page=1;load();};
    if ($('#tr', view)) $('#tr', view).onclick = () => H.newTransfer(load);
    if ($('#open', view)) $('#open', view).onclick = () => formModal({ title: 'Opening stock', fields: [f('warehouse_id', 'Location', 'select', { ...req, options: WH }), f('product_id', 'Product', 'select', { ...req, options: PRODUCT }), f('qty', 'Quantity', 'number', req), f('unit_cost', 'Unit cost', 'number', req)], onSubmit: async (d) => { await API.post('/inventory/opening', d); toast('Opening stock recorded.'); load(); } });
    await load();
  }

export { inventory };
