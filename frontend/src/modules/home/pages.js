import { $,$$,esc,fmt,badge,toast,modal,confirmBox,formModal,linesEditor,table,barChart,vBarChart,lineChart,donutChart,sparkline,kpi,PALETTE,can,unwrap,stat,card,src,f,req,today,PRODUCT,WH,I,act } from "../../components/context.js";
import { H, receiptHtml } from "../../services/workflows.js";
  async function dashboard(view) {
    const d = new Date(), from = `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-01`;
    view.innerHTML = `<div class="rep-hero mb-5">
      <div class="flex flex-wrap items-end gap-3 justify-between">
        <div>
          <div class="chip-eyebrow">Overview</div>
          <h2 class="mt-3">Welcome back — here's what's happening across your business.</h2>
        </div>
        <div class="flex flex-wrap gap-3 items-end">
          <label><span class="lbl">From</span><input class="inp" type="date" id="from" value="${from}"></label>
          <label><span class="lbl">To</span><input class="inp" type="date" id="to" value="${today()}"></label>
          ${!API.user.branch_id ? '<label><span class="lbl">Branch</span><select class="inp" id="br"><option value="">All branches</option></select></label>' : ''}
          <button class="btn btn-brand" id="go">Update</button>
        </div>
      </div>
    </div><div id="dash"></div>`;
    if ($('#br', view)) (await UI.options({ res: 'branches' }).catch(() => [])).forEach(([v, l]) => $('#br', view).insertAdjacentHTML('beforeend', `<option value="${v}">${esc(l)}</option>`));
    const draw = async () => {
      const box = $('#dash', view); box.innerHTML = '<p class="text-slate-500">Loading...</p>';
      try {
        const x = await API.get('/dashboard', { from: $('#from', view).value, to: $('#to', view).value, branch_id: $('#br', view)?.value });
        const topProds = (x.sales_by_branch || []).slice(0, 6).map((r, i) => ({ label: r.branch, value: Number(r.total) || 0, color: PALETTE[i % PALETTE.length] }));

        box.innerHTML = `
          <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
            ${kpi('Sales', fmt.money(x.sales), { hero: true })}
            ${kpi('Gross profit', fmt.money(x.gross_profit), { tone: 'mint', icon: I.chart })}
            ${kpi('Net profit', fmt.money(x.net_profit), { tone: 'violet', icon: I.chart })}
            ${kpi('Invoices', fmt.num(x.invoice_count, 0), { tone: 'sky', icon: I.cart })}
            ${kpi('Expenses', fmt.money(x.expenses), { tone: 'rose', icon: I.wallet })}
            ${kpi('Receivables', x.receivables === null ? 'Organization only' : fmt.money(x.receivables), { tone: 'amber', icon: I.wallet })}
            ${kpi('Payables', fmt.money(x.payables), { tone: 'rose', icon: I.wallet })}
            ${kpi('Stock value', fmt.money(x.stock_value), { tone: 'mint', icon: I.box })}
            ${kpi('Produced units', fmt.num(x.production_output), { tone: 'violet', icon: I.factory })}
            ${kpi('Open production orders', fmt.num(x.open_production_orders, 0), { tone: 'sky', icon: I.factory })}
            ${kpi('Low stock items', fmt.num(x.low_stock_items, 0), { tone: 'amber', icon: I.alert })}
            ${kpi('Waiting for approval', fmt.num(x.pending_approvals, 0), { tone: 'rose', icon: I.alert })}
          </div>

          <div class="grid lg:grid-cols-3 gap-4 mb-5">
            <div class="chart-card lg:col-span-2">
              <div class="panel-head"><div><h3>Sales trend</h3><p class="sub">Daily totals for the selected period</p></div></div>
              ${lineChart(x.sales_by_day || [], 'day', [{ key: 'total', color: '#818CF8', label: 'Sales' }])}
            </div>
            <div class="chart-card">
              <div class="panel-head"><div><h3>Sales by branch</h3><p class="sub">Contribution mix</p></div></div>
              ${donutChart(topProds, { size: 210, thickness: 22, centerLabel: fmt.compact(x.sales), centerCaption: 'total' })}
            </div>
          </div>

          <div class="chart-card">
            <div class="panel-head"><div><h3>Branch leaderboard</h3><p class="sub">Ranked by sales in the period</p></div></div>
            ${barChart(x.sales_by_branch || [], 'branch', 'total', fmt.money)}
          </div>`;
      } catch (e) { box.innerHTML = `<p class="text-rose-400">${esc(e.message)}</p>`; }
    };
    $('#go', view).onclick = draw; draw();
  }

export { dashboard };
