import { $,$$,esc,fmt,badge,toast,modal,confirmBox,formModal,linesEditor,table,barChart,vBarChart,lineChart,donutChart,sparkline,kpi,PALETTE,can,unwrap,stat,card,src,f,req,today,PRODUCT,WH,I,act } from "../../components/context.js";
import { H, receiptHtml } from "../../services/workflows.js";
import { toCsv } from '../../services/csv.js';
  const REPORTS = [
    ['sales-by-day', 'Sales by day'],
    ['sales-by-branch', 'Sales by branch'],
    ['sales-by-product', 'Sales by product'],
    ['salesperson', 'Salesperson performance'],
    ['product-profitability', 'Product profitability'],
    ['branch-profitability', 'Branch profitability'],
    ['profit-and-loss', 'Profit and loss'],
    ['stock', 'Stock on hand'],
    ['production', 'Production output and wastage'],
    ['receivables', 'Customer credit'],
    ['invoice-balances', 'Outstanding invoice balances (all dates)'],
    ['payables', 'Supplier outstanding'],
    ['expenses', 'Expenses by category'],
    ['marketing', 'Marketing spend'],
    ['delivery-costs', 'Delivery and vehicle costs'],
  ];

  async function reports(view) {
    const d = new Date(), from = `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-01`;
    view.innerHTML = `
      <div class="rep-hero mb-5">
        <div class="flex flex-wrap items-end gap-3 justify-between">
          <div>
            <div class="chip-eyebrow">BUSINESS INTELLIGENCE</div>
            <h2 class="mt-3">Reports & insights</h2>
            <p class="text-slate-400 text-sm mt-1">A clearer perspective on your business. Explore performance, stock and profitability.</p>
          </div>
          <div class="flex flex-wrap gap-3 items-end">
            <label class="min-w-[14rem]"><span class="lbl">Report</span><select class="inp" id="rp">${REPORTS.map(([k, l]) => `<option value="${k}">${l}</option>`).join('')}</select></label>
            <label><span class="lbl">From</span><input class="inp" type="date" id="from" value="${from}"></label>
            <label><span class="lbl">To</span><input class="inp" type="date" id="to" value="${today()}"></label>
            <button class="btn btn-brand" id="run">Run report</button>
            <button class="btn btn-ghost" id="csv">↓ Export CSV</button>
          </div>
        </div>
        <div class="report-shortcuts" aria-label="Quick reports">${[['sales-by-day','Sales overview'],['stock','Inventory'],['product-profitability','Profitability'],['production','Manufacturing'],['expenses','Expenses']].map(([key,label])=>`<button data-report="${key}" class="${key==='sales-by-day'?'selected':''}" aria-pressed="${key==='sales-by-day'}">${label}</button>`).join('')}<span class="report-status" id="reportStatus" aria-live="polite">From your business records</span></div>
      </div>

      <div id="kpis" class="report-kpi-grid mb-5"></div>
      <div id="charts" class="grid lg:grid-cols-3 gap-4 mb-5"></div>
      <div class="card">
        <div class="panel-head"><div><h3 class="font-display font-bold text-white">Report details</h3><p class="sub text-slate-400 text-sm">The numbers behind the picture.</p></div></div>
        <div id="out" class="p-4"></div>
      </div>`;

    let last = [];

    const parseNum = (v) => {
      if (v === null || v === undefined || v === '') return null;
      const n = Number(v);
      return isNaN(n) ? null : n;
    };

    const renderKpis = (key, rows, data) => {
      const box = $('#kpis', view); box.innerHTML = '';
      if (!rows || !rows.length) {
        if (data && typeof data === 'object' && !Array.isArray(data)) {
          box.innerHTML = Object.entries(data).filter(([, v]) => typeof v === 'number' || /^-?\d+(\.\d+)?$/.test(String(v)))
            .slice(0, 4).map(([k, v]) => kpi(fmt.label(k), fmt.money(Number(v)))).join('');
        }
        return;
      }
      const k = key;
      if (k === 'sales-by-day') {
        const total = rows.reduce((a, r) => a + Number(r.total || 0), 0);
        const inv = rows.reduce((a, r) => a + Number(r.invoices || 0), 0);
        const best = rows.reduce((a, r) => (Number(r.total) > Number(a.total) ? r : a), rows[0]);
        box.innerHTML = kpi('Total sales', fmt.money(total), { hero: true })
          + kpi('Invoices', fmt.num(inv, 0), { tone: 'sky', icon: I.cart })
          + kpi('Avg per day', fmt.money(total / Math.max(1, rows.length)), { tone: 'mint', icon: I.chart })
          + kpi('Best day', fmt.money(best?.total), { tone: 'amber', icon: I.spark });
      } else if (k === 'sales-by-branch' || k === 'branch-profitability') {
        const total = rows.reduce((a, r) => a + Number(r.total ?? r.revenue ?? 0), 0);
        box.innerHTML = kpi('Branches', fmt.num(rows.length, 0), { tone: 'sky', icon: I.box })
          + kpi('Total', fmt.money(total), { hero: true })
          + kpi('Best branch', fmt.money(Math.max(0, ...rows.map((r) => Number(r.total ?? r.revenue ?? 0)))), { tone: 'mint', icon: I.chart })
          + kpi('Avg per branch', fmt.money(total / Math.max(1, rows.length)), { tone: 'violet', icon: I.chart });
      } else if (k === 'sales-by-product' || k === 'product-profitability') {
        const rev = rows.reduce((a, r) => a + Number(r.revenue || 0), 0);
        const profit = rows.reduce((a, r) => a + Number(r.profit || (Number(r.revenue) - Number(r.cost)) || 0), 0);
        const qty = rows.reduce((a, r) => a + Number(r.qty || 0), 0);
        box.innerHTML = kpi('Revenue', fmt.money(rev), { hero: true })
          + kpi('Profit', fmt.money(profit), { tone: 'mint', icon: I.chart })
          + kpi('Units sold', fmt.num(qty, 0), { tone: 'sky', icon: I.box })
          + kpi('Products', fmt.num(rows.length, 0), { tone: 'violet', icon: I.spark });
      } else if (k === 'profit-and-loss') {
        const o = data;
        box.innerHTML = kpi('Revenue', fmt.money(o.revenue), { hero: true })
          + kpi('COGS', fmt.money(o.cost_of_goods_sold), { tone: 'rose', icon: I.wallet })
          + kpi('Gross profit', fmt.money(o.gross_profit), { tone: 'mint', icon: I.chart })
          + kpi('Net profit', fmt.money(o.net_profit), { tone: 'violet', icon: I.spark });
      } else if (k === 'expenses' || k === 'marketing') {
        const total = rows.reduce((a, r) => a + Number(r.total ?? r.amount ?? r.spent ?? 0), 0);
        box.innerHTML = kpi('Categories', fmt.num(rows.length, 0), { tone: 'sky', icon: I.box })
          + kpi('Total', fmt.money(total), { hero: true })
          + kpi('Largest', fmt.money(Math.max(0, ...rows.map((r) => Number(r.total ?? r.amount ?? r.spent ?? 0)))), { tone: 'amber', icon: I.wallet })
          + kpi('Avg', fmt.money(total / Math.max(1, rows.length)), { tone: 'mint', icon: I.chart });
      } else if (k === 'receivables' || k === 'payables') {
        const balKey = k === 'receivables' ? 'credit_balance' : 'balance';
        const total = rows.reduce((a, r) => a + Number(r[balKey] || 0), 0);
        box.innerHTML = kpi('Accounts', fmt.num(rows.length, 0), { tone: 'sky', icon: I.users })
          + kpi('Total outstanding', fmt.money(total), { hero: true })
          + kpi('Largest', fmt.money(Math.max(0, ...rows.map((r) => Number(r[balKey] || 0)))), { tone: 'rose', icon: I.alert })
          + kpi('Average', fmt.money(total / Math.max(1, rows.length)), { tone: 'violet', icon: I.chart });
      } else if (k === 'stock') {
        box.innerHTML = kpi('Stock value', fmt.money(rows.reduce((sum,r)=>sum+Number(r.value||0),0)), {hero:true})
          + kpi('Stock lines', fmt.num(rows.length,0), {tone:'sky',icon:I.box})
          + kpi('At or below reorder', fmt.num(rows.filter(r=>Number(r.qty)<=Number(r.reorder_level)).length,0), {tone:'amber',icon:I.alert})
          + kpi('Locations', fmt.num(new Set(rows.map(r=>r.warehouse)).size,0), {tone:'mint',icon:I.box});
      } else if (k === 'delivery-costs') {
        const o = data;
        box.innerHTML = kpi('Deliveries', fmt.num(o.deliveries, 0), { tone: 'sky', icon: I.cart })
          + kpi('Charges', fmt.money(o.delivery_charges), { hero: true })
          + kpi('Trip fuel', fmt.money(o.trip_fuel_cost), { tone: 'amber', icon: I.wallet })
          + kpi('Maintenance', fmt.money(o.vehicle_maintenance), { tone: 'rose', icon: I.wallet });
      } else {
        box.innerHTML = kpi('Rows', fmt.num(rows.length, 0), { tone: 'sky', icon: I.box });
      }
    };

    const renderCharts = (key, rows, data) => {
      const box = $('#charts', view);
      box.innerHTML = '';
      if (!rows || !rows.length) return;

      const colorFor = (i) => PALETTE[i % PALETTE.length];

      if (key === 'sales-by-day') {
        box.innerHTML = `
          <div class="chart-card lg:col-span-2">
            <div class="panel-head"><div><h3>Sales trend</h3><p class="sub">Daily totals</p></div></div>
            ${lineChart(rows, 'day', [{ key: 'total', color: '#818CF8' }, { key: 'invoices', color: '#34D399' }], { area: true })}
            <div class="chart-legend mt-3"><span><span class="dot" style="background:#818CF8"></span>Sales</span><span><span class="dot" style="background:#34D399"></span>Invoices</span></div>
          </div>
          <div class="chart-card">
            <div class="panel-head"><div><h3>Top days</h3><p class="sub">Highest sales days</p></div></div>
            ${barChart([...rows].sort((a, b) => Number(b.total) - Number(a.total)).slice(0, 6), 'day', 'total', fmt.money)}
          </div>`;
      } else if (key === 'sales-by-branch') {
        const segs = rows.map((r, i) => ({ label: r.branch, value: Number(r.total) || 0, color: colorFor(i) }));
        box.innerHTML = `
          <div class="chart-card lg:col-span-2">
            <div class="panel-head"><div><h3>Branch revenue</h3><p class="sub">Vertical comparison</p></div></div>
            ${vBarChart(rows, 'branch', 'total')}
          </div>
          <div class="chart-card">
            <div class="panel-head"><div><h3>Mix</h3><p class="sub">Contribution by branch</p></div></div>
            ${donutChart(segs, { size: 210, thickness: 22, centerLabel: fmt.num(rows.length, 0), centerCaption: 'branches' })}
          </div>`;
      } else if (key === 'sales-by-product' || key === 'product-profitability') {
        const top = [...rows].sort((a, b) => Number(b.revenue || 0) - Number(a.revenue || 0)).slice(0, 8);
        const segs = top.slice(0, 6).map((r, i) => ({ label: r.name || r.sku, value: Number(r.revenue) || 0, color: colorFor(i) }));
        box.innerHTML = `
          <div class="chart-card lg:col-span-2">
            <div class="panel-head"><div><h3>Top products by revenue</h3><p class="sub">Ranked, with quantity</p></div></div>
            ${barChart(top, 'name', 'revenue', fmt.money)}
          </div>
          <div class="chart-card">
            <div class="panel-head"><div><h3>Revenue mix</h3><p class="sub">Top products</p></div></div>
            ${donutChart(segs, { size: 210, thickness: 22, centerLabel: fmt.compact(top.reduce((a, r) => a + Number(r.revenue || 0), 0)), centerCaption: 'revenue' })}
          </div>`;
      } else if (key === 'profit-and-loss') {
        const o = data;
        const segs = [
          { label: 'COGS', value: Number(o.cost_of_goods_sold) || 0, color: '#FB7185' },
          { label: 'Operating expenses', value: Number(o.operating_expenses) || 0, color: '#FBBF24' },
          { label: 'Payroll', value: Number(o.payroll) || 0, color: '#A78BFA' },
          { label: 'Net profit', value: Math.max(0, Number(o.net_profit) || 0), color: '#34D399' },
        ];
        box.innerHTML = `
          <div class="chart-card lg:col-span-2">
            <div class="panel-head"><div><h3>Revenue vs Cost</h3><p class="sub">Waterfall from revenue to net profit</p></div></div>
            ${vBarChart([
              { label: 'Revenue', val: o.revenue },
              { label: 'COGS', val: o.cost_of_goods_sold },
              { label: 'Gross', val: o.gross_profit },
              { label: 'Opex', val: o.operating_expenses },
              { label: 'Payroll', val: o.payroll },
              { label: 'Net', val: o.net_profit },
            ], 'label', 'val')}
          </div>
          <div class="chart-card">
            <div class="panel-head"><div><h3>Where the money goes</h3><p class="sub">Breakdown</p></div></div>
            ${donutChart(segs, { size: 210, thickness: 22, centerLabel: fmt.compact(o.revenue), centerCaption: 'revenue' })}
          </div>`;
      } else if (key === 'expenses' || key === 'marketing') {
        const valKey = rows[0].total !== undefined ? 'total' : rows[0].amount !== undefined ? 'amount' : 'spent';
        const segs = rows.map((r, i) => ({ label: r.category || r.name || r.campaign || 'Item', value: Number(r[valKey]) || 0, color: colorFor(i) }));
        box.innerHTML = `
          <div class="chart-card">
            <div class="panel-head"><div><h3>Breakdown</h3><p class="sub">Share of total</p></div></div>
            ${donutChart(segs, { size: 220, thickness: 24, centerLabel: fmt.compact(segs.reduce((a, s) => a + s.value, 0)), centerCaption: 'total' })}
          </div>
          <div class="chart-card lg:col-span-2">
            <div class="panel-head"><div><h3>Ranking</h3><p class="sub">Largest first</p></div></div>
            ${barChart([...rows].sort((a, b) => Number(b[valKey]) - Number(a[valKey])), rows[0].category ? 'category' : (rows[0].name ? 'name' : 'campaign'), valKey, fmt.money)}
          </div>`;
      } else if (key === 'receivables' || key === 'payables') {
        const balKey = key === 'receivables' ? 'credit_balance' : 'balance';
        const nameKey = key === 'receivables' ? 'name' : 'supplier';
        const top = [...rows].sort((a, b) => Number(b[balKey]) - Number(a[balKey])).slice(0, 8);
        const segs = top.slice(0, 6).map((r, i) => ({ label: r[nameKey], value: Number(r[balKey]) || 0, color: colorFor(i) }));
        box.innerHTML = `
          <div class="chart-card lg:col-span-2">
            <div class="panel-head"><div><h3>Top balances</h3><p class="sub">Outstanding by counterparty</p></div></div>
            ${barChart(top, nameKey, balKey, fmt.money)}
          </div>
          <div class="chart-card">
            <div class="panel-head"><div><h3>Concentration</h3><p class="sub">Top 6 share</p></div></div>
            ${donutChart(segs, { size: 210, thickness: 22, centerLabel: fmt.compact(top.reduce((a, r) => a + Number(r[balKey] || 0), 0)), centerCaption: 'total' })}
          </div>`;
      } else if (key === 'salesperson') {
        box.innerHTML = `
          <div class="chart-card lg:col-span-2">
            <div class="panel-head"><div><h3>Sales by salesperson</h3><p class="sub">Ranked by sales</p></div></div>
            ${barChart([...rows].sort((a, b) => Number(b.sales) - Number(a.sales)), 'salesperson', 'sales', fmt.money)}
          </div>
          <div class="chart-card">
            <div class="panel-head"><div><h3>Commissions</h3><p class="sub">Paid on those sales</p></div></div>
            ${barChart([...rows].sort((a, b) => Number(b.commission) - Number(a.commission)).slice(0, 6), 'salesperson', 'commission', fmt.money)}
          </div>`;
      } else if (key === 'production') {
        const counts = { draft: 0, released: 0, in_progress: 0, completed: 0, cancelled: 0 };
        rows.forEach((r) => { counts[r.status] = (counts[r.status] || 0) + 1; });
        const segs = Object.entries(counts).filter(([, v]) => v > 0).map(([k, v], i) => ({ label: fmt.label(k), value: v, color: colorFor(i) }));
        box.innerHTML = `
          <div class="chart-card">
            <div class="panel-head"><div><h3>Status mix</h3><p class="sub">Orders by status</p></div></div>
            ${donutChart(segs, { size: 220, thickness: 24, centerLabel: fmt.num(rows.length, 0), centerCaption: 'orders' })}
          </div>
          <div class="chart-card lg:col-span-2">
            <div class="panel-head"><div><h3>Planned vs Produced</h3><p class="sub">Top 8 orders</p></div></div>
            ${barChart(rows.slice(0, 8), 'number', 'qty_produced', fmt.num)}
          </div>`;
      } else if (key === 'stock') {
        const top = [...rows].sort((a, b) => Number(b.value) - Number(a.value)).slice(0, 8);
        const low = rows.filter((r) => Number(r.reorder_level) > 0 && Number(r.qty) <= Number(r.reorder_level));
        const segs = [
          { label: 'Healthy', value: rows.length - low.length, color: '#34D399' },
          { label: 'At or below reorder', value: low.length, color: '#F59E0B' },
        ];
        box.innerHTML = `
          <div class="chart-card lg:col-span-2">
            <div class="panel-head"><div><h3>Top stock by value</h3><p class="sub">Qty × cost</p></div></div>
            ${barChart(top, 'name', 'value', fmt.money)}
          </div>
          <div class="chart-card">
            <div class="panel-head"><div><h3>Stock health</h3><p class="sub">Lines vs reorder level</p></div></div>
            ${donutChart(segs, { size: 210, thickness: 22, centerLabel: fmt.num(rows.length, 0), centerCaption: 'lines' })}
          </div>`;
      } else if (key === 'delivery-costs') {
        const o = data;
        const segs = [
          { label: 'Trip fuel', value: Number(o.trip_fuel_cost) || 0, color: '#FBBF24' },
          { label: 'Vehicle fuel (logged)', value: Number(o.vehicle_fuel_logged) || 0, color: '#818CF8' },
          { label: 'Maintenance', value: Number(o.vehicle_maintenance) || 0, color: '#FB7185' },
        ];
        box.innerHTML = `
          <div class="chart-card">
            <div class="panel-head"><div><h3>Cost mix</h3><p class="sub">Vehicle & trip costs</p></div></div>
            ${donutChart(segs, { size: 220, thickness: 24, centerLabel: fmt.compact(segs.reduce((a, s) => a + s.value, 0)), centerCaption: 'total' })}
          </div>
          <div class="chart-card lg:col-span-2">
            <div class="panel-head"><div><h3>Charges vs Costs</h3><p class="sub">Revenue side vs expense side</p></div></div>
            ${vBarChart([
              { label: 'Charges', val: o.delivery_charges },
              { label: 'Trip fuel', val: o.trip_fuel_cost },
              { label: 'Fuel log', val: o.vehicle_fuel_logged },
              { label: 'Maint.', val: o.vehicle_maintenance },
            ], 'label', 'val')}
          </div>`;
      } else if (key === 'branch-profitability') {
        const top = [...rows].sort((a, b) => Number(b.profit) - Number(a.profit));
        box.innerHTML = `
          <div class="chart-card lg:col-span-3">
            <div class="panel-head"><div><h3>Revenue, COGS and profit</h3><p class="sub">Side-by-side comparison</p></div></div>
            ${lineChart(top, 'branch', [
              { key: 'revenue', color: '#818CF8' },
              { key: 'cogs', color: '#FBBF24' },
              { key: 'expenses', color: '#FB7185' },
              { key: 'profit', color: '#34D399' },
            ], { area: false })}
            <div class="chart-legend mt-3">
              <span><span class="dot" style="background:#818CF8"></span>Revenue</span>
              <span><span class="dot" style="background:#FBBF24"></span>COGS</span>
              <span><span class="dot" style="background:#FB7185"></span>Expenses</span>
              <span><span class="dot" style="background:#34D399"></span>Profit</span>
            </div>
          </div>`;
      }
    };

    let reportRequest = 0;
    const run = async () => {
      const request = ++reportRequest;last=[];
      $('#csv',view).disabled=true;
      $('#reportStatus',view).textContent='Updating report…';
      const out = $('#out', view); out.innerHTML = '<p class="text-slate-500">Loading...</p>';
      $('#charts', view).innerHTML = ''; $('#kpis', view).innerHTML = '';
      try {
        const key = $('#rp', view).value;
        const r = await API.get('/reports/' + key, { from: $('#from', view).value, to: $('#to', view).value });
        if(request!==reportRequest || !view.isConnected)return;
        $('#reportStatus',view).textContent='Updated '+new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
        $$('[data-report]',view).forEach(b=>{b.classList.toggle('selected',b.dataset.report===key);b.setAttribute('aria-pressed',String(b.dataset.report===key));});
        let data = r.data;
        const isObject = data && typeof data === 'object' && !Array.isArray(data);
        let rows = Array.isArray(data) ? data : Object.entries(data || {}).map(([k, v]) => ({ item: fmt.label(k), value: v }));
        last = rows;
        $('#csv',view).disabled=!rows.length;

        renderKpis(key, rows, isObject ? data : null);
        renderCharts(key, rows, isObject ? data : null);

        if (!rows.length) { out.innerHTML = '<p class="text-slate-500">No records for this period.</p>'; return; }
        const keys = Object.keys(rows[0]);
        const cell = (k, v) => {
          if (v === null || v === undefined || v === '') return '';
          const n = parseNum(v);
          if (n !== null) {
            return /qty|count|hours|units|percent|invoices|deliveries|version|points|lines|reorder_level/i.test(k) ? fmt.num(n, 2) : fmt.money(n);
          }
          return esc(v);
        };
        out.innerHTML = table(keys.map((k) => ({ label: fmt.label(k), num: parseNum(rows[0][k]) !== null, html: (x) => cell(k, x[k]) })), rows);
      } catch (e) { if(request!==reportRequest || !view.isConnected)return;$('#reportStatus',view).textContent='Report unavailable';out.innerHTML = `<p class="text-rose-400" role="alert">${esc(e.message)}</p>`; }
    };
    $('#run', view).onclick = run;
    $$('[data-report]',view).forEach(b=>b.onclick=()=>{$('#rp',view).value=b.dataset.report;run();});
    $('#csv', view).onclick = () => { if (!last.length) return toast('Run a report first.', true); UI.download($('#rp', view).value + '.csv', toCsv(last)); };
    run();
  }

export { reports };
