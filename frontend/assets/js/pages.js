/* Screens. Every state-changing action calls a backend endpoint; the server re-validates and applies the business rules. */
(function () {
  const { $, $$, esc, fmt, badge, toast, modal, confirmBox, formModal, linesEditor, table, barChart, vBarChart, lineChart, donutChart, sparkline, kpi, PALETTE } = UI;
  const can = (p) => API.can(p);
  const unwrap = (r) => (r && r.data !== undefined ? r.data : r);
  const stat = (label, value, cls = '') => kpi(label, value, { hero: cls.includes('stat-hero') });
  const card = (title, body, extra = '') => `<section class="card card-pad ${extra}">${title ? `<div class="panel-head"><h2 class="font-display text-lg font-bold text-white">${esc(title)}</h2></div>` : ''}${body}</section>`;
  const src = (res, label, params) => ({ res, label, params });
  const f = (name, label, type, extra) => Object.assign({ name, label, type: type || 'text' }, extra || {});
  const req = { required: true };
  const today = () => new Date().toISOString().slice(0, 10);
  const PRODUCT = src('products', (x) => `${x.sku} - ${x.name}${x.variant ? ' (' + x.variant + ')' : ''}`);
  const WH = src('warehouses', (x) => x.name);

  // icons (inline svg, currentColor)
  const I = {
    cash: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/></svg>`,
    cart: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M3 3h2l2.4 12.2a2 2 0 0 0 2 1.6h8.4a2 2 0 0 0 2-1.6L21.6 8H6"/></svg>`,
    chart: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/></svg>`,
    box: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8V7l-9-5-9 5v10l9 5 9-5v-1"/><path d="M3.3 7 12 12l8.7-5"/><path d="M12 22V12"/></svg>`,
    wallet: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="18" height="14" rx="2"/><path d="M3 10h18"/><circle cx="16" cy="14" r="1.4"/></svg>`,
    alert: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h17a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`,
    factory: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M3 21V10l6 4V10l6 4V10l6 4v7"/><path d="M9 17h.01M15 17h.01"/></svg>`,
    users: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>`,
    spark: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3 1.8 4.7L18.5 9l-4.7 1.8L12 15.5l-1.8-4.7L5.5 9l4.7-1.3Z"/></svg>`,
  };

  async function act(method, path, body, ok = 'Done.') {
    try { const r = await API[method](path, body); toast(ok); return r; } catch (e) { toast(e.message, true); return null; }
  }

  /* =========================================================
     workflow handlers
     ========================================================= */
  const H = {
    async newTransfer(reload) {
      let get;
      await formModal({ title: 'New stock transfer', wide: true, submit: 'Transfer stock',
        fields: [f('from_warehouse_id', 'From', 'select', { ...req, options: WH }), f('to_warehouse_id', 'To', 'select', { ...req, options: WH }), f('note', 'Note', 'text', { wide: true })],
        extra: { html: '<p class="lbl">Items</p><div data-lines></div>', mount: async (h) => { get = await linesEditor($('[data-lines]', h), [{ name: 'product_id', label: 'Product', type: 'select', options: PRODUCT }, { name: 'qty', label: 'Qty' }]); }, collect: () => ({ items: get() }) },
        onSubmit: async (d) => { if (!d.items.length) throw new Error('Add at least one item.'); await API.post('/inventory/transfers', d); toast('Stock transferred.'); reload(); } });
    },
    async newAdjustment(reload) {
      await formModal({ title: 'Stock count / adjustment', submit: 'Submit for approval',
        fields: [f('warehouse_id', 'Location', 'select', { ...req, options: WH }), f('product_id', 'Product', 'select', { ...req, options: PRODUCT }), f('counted_qty', 'Counted quantity', 'number', req), f('reason', 'Reason', 'text', { wide: true })],
        onSubmit: async (d) => { await API.post('/inventory/adjustments', d); toast('Adjustment sent for approval.'); reload(); } });
    },
    async newPO(reload) {
      let get;
      await formModal({ title: 'New purchase order', wide: true, submit: 'Create order',
        fields: [f('supplier_id', 'Supplier', 'select', { ...req, options: src('suppliers') }), f('warehouse_id', 'Deliver to', 'select', { ...req, options: WH }), f('order_date', 'Order date', 'date', { default: today() }), f('notes', 'Notes', 'text')],
        extra: { html: '<p class="lbl">Items</p><div data-lines></div>', mount: async (h) => { get = await linesEditor($('[data-lines]', h), [{ name: 'product_id', label: 'Product', type: 'select', options: PRODUCT }, { name: 'qty', label: 'Qty' }, { name: 'unit_price', label: 'Unit price' }]); }, collect: () => ({ items: get() }) },
        onSubmit: async (d) => { if (!d.items.length) throw new Error('Add at least one item.'); await API.post('/purchase-orders', d); toast('Purchase order created.'); reload(); } });
    },
    async poView(r) {
      const o = unwrap(await API.get('/purchase_orders/' + r.id));
      modal('Purchase order ' + o.number, `<div class="flex gap-3 mb-4 flex-wrap">${badge(o.status)}<span class="text-slate-300">${esc(o.supplier?.name || '')}</span><span class="font-semibold text-white">${fmt.money(o.total)}</span></div>` +
        table([{ label: 'Product', get: (i) => i.product?.name }, { label: 'Ordered', num: true, get: (i) => fmt.num(i.qty, 3) }, { label: 'Received', num: true, get: (i) => fmt.num(i.received_qty, 3) }, { label: 'Unit price', num: true, get: (i) => fmt.money(i.unit_price) }], o.items || []), { wide: true });
    },
    async poReceive(r, reload) {
      const o = unwrap(await API.get('/purchase_orders/' + r.id));
      const rows = (o.items || []).filter((i) => Number(i.qty) - Number(i.received_qty) > 0);
      const m = modal('Receive goods - ' + o.number, `<form><div class="space-y-3">${rows.map((i) => `<label class="block"><span class="lbl">${esc(i.product?.name)} (outstanding ${fmt.num(Number(i.qty) - Number(i.received_qty), 3)})</span><input class="inp" type="number" step="any" min="0" data-item="${i.id}" value="${Number(i.qty) - Number(i.received_qty)}"></label>`).join('')}
        <label class="block"><span class="lbl">Batch / lot number</span><input class="inp" name="batch_no"></label></div><div id="formErr" class="hidden mt-4 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-sm px-4 py-3"></div>
        <div class="mt-5 flex justify-end gap-2"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button class="btn btn-mint">Receive into stock</button></div></form>`);
      $$('[data-close]', m).forEach((b) => (b.onclick = m.close));
      $('form', m).onsubmit = async (e) => {
        e.preventDefault();
        const lines = $$('[data-item]', m).map((i) => ({ item_id: Number(i.dataset.item), qty: Number(i.value) })).filter((l) => l.qty > 0);
        try { await API.post(`/purchase-orders/${r.id}/receive`, { batch_no: e.target.elements.batch_no.value || null, lines }); toast('Goods received. Stock and supplier invoice updated.'); m.close(); reload(); }
        catch (err) { const b = $('#formErr', m); b.textContent = err.message; b.classList.remove('hidden'); }
      };
    },
    async paySupplier(r, reload) {
      await formModal({ title: 'Pay supplier invoice ' + r.number, submit: 'Record payment',
        values: { amount: (Number(r.amount) - Number(r.paid)).toFixed(2) },
        fields: [f('amount', 'Amount', 'number', req), f('account_id', 'Pay from account', 'select', { ...req, options: src('accounts') })],
        onSubmit: async (d) => { await API.post(`/supplier-invoices/${r.id}/pay`, d); toast('Payment recorded.'); reload(); } });
    },
    async newBom(reload) { return H.editBom(null, reload); },
    async editBom(r, reload) {
      const b = r ? unwrap(await API.get('/boms/' + r.id)) : {};
      let get;
      await formModal({ title: r ? 'Edit recipe' : 'New recipe', wide: true, submit: 'Save recipe', values: b,
        fields: [f('product_id', 'Finished product', 'select', { ...req, options: src('products', (x) => `${x.sku} - ${x.name}`, { 'filter[type]': 'finished_good' }) }), f('output_qty', 'Output quantity per batch', 'number', req), f('labour_cost', 'Direct labour per batch', 'number'), f('packaging_cost', 'Packaging per batch', 'number'), f('overhead_cost', 'Factory overhead per batch', 'number'), f('extra_cost', 'Other configured costs per batch', 'number'), f('is_active', 'Active', 'checkbox', { default: true }), f('notes', 'Notes', 'text')],
        extra: { html: '<p class="lbl">Materials per batch (wastage % is added to the required quantity)</p><div data-lines></div>', mount: async (h) => { get = await linesEditor($('[data-lines]', h), [{ name: 'product_id', label: 'Material', type: 'select', options: PRODUCT }, { name: 'qty', label: 'Qty' }, { name: 'wastage_percent', label: 'Wastage %' }], (b.items || [{}]).map((i) => ({ product_id: i.product_id, qty: i.qty, wastage_percent: i.wastage_percent }))); }, collect: () => ({ items: get() }) },
        onSubmit: async (d) => { if (!d.items.length) throw new Error('Add at least one material.'); r ? await API.put('/boms/' + r.id, d) : await API.post('/boms', d); toast('Recipe saved.'); reload(); } });
    },
    async bomCost(r) {
      const c = unwrap(await API.get(`/boms/${r.id}/cost`));
      const segs = ['material', 'labour', 'packaging', 'overhead', 'extra'].map((k, i) => ({ label: fmt.label(k), value: Number(c[k]) || 0, color: PALETTE[i] }));
      modal('Cost per unit - ' + (r.product?.name || ''),
        `<div class="grid sm:grid-cols-2 gap-5 items-center">
          <div>${donutChart(segs, { size: 200, thickness: 22, centerLabel: fmt.money(c.total), centerCaption: 'total / unit' })}</div>
          <div class="space-y-2">${segs.map((s) => `<div class="flex items-center justify-between text-sm"><span class="text-slate-300"><span class="dot" style="display:inline-block;width:.7rem;height:.7rem;border-radius:50%;background:${s.color};margin-right:.5rem"></span>${esc(s.label)}</span><span class="font-semibold text-white tabular-nums">${fmt.money(s.value)}</span></div>`).join('')}
          <div class="flex items-center justify-between text-base border-t border-line pt-3 mt-3"><span class="text-slate-400">Total</span><span class="font-display font-extrabold text-white">${fmt.money(c.total)}</span></div></div>
        </div>`, { wide: true });
    },
    async newProduction(reload) {
      await formModal({ title: 'New production order', submit: 'Create order',
        fields: [f('bom_id', 'Recipe', 'select', { ...req, options: src('boms', (x) => `${x.product?.name || 'Recipe'} v${x.version}`) }), f('batches', 'Number of batches', 'number', { ...req, default: 1 }), f('source_warehouse_id', 'Take materials from', 'select', { ...req, options: WH }), f('target_warehouse_id', 'Put finished goods in', 'select', { ...req, options: WH }), f('planned_date', 'Planned date', 'date', { default: today() }), f('notes', 'Notes')],
        onSubmit: async (d) => { await API.post('/production-orders', d); toast('Production order created.'); reload(); } });
    },
    async productionView(r) {
      const o = unwrap(await API.get('/production_orders/' + r.id));
      modal('Production order ' + o.number, `<div class="flex flex-wrap gap-3 mb-4">${badge(o.status)}<span class="text-slate-300">Batch ${esc(o.batch_no || '-')}</span><span class="text-slate-300">Planned ${fmt.num(o.qty_planned)}</span><span class="text-slate-300">Produced ${fmt.num(o.qty_produced)}</span><span class="text-slate-300">Unit cost ${fmt.money(o.unit_cost)}</span></div>` +
        table([{ label: 'Material', get: (m) => m.product?.name }, { label: 'Required', num: true, get: (m) => fmt.num(m.required_qty, 3) }, { label: 'Issued', num: true, get: (m) => fmt.num(m.issued_qty, 3) }, { label: 'Unit cost', num: true, get: (m) => fmt.money(m.unit_cost) }], o.materials || [], 'Materials are listed once the order is released.'), { wide: true });
    },
    async productionComplete(r, reload) {
      await formModal({ title: 'Complete ' + r.number, submit: 'Complete production', values: { qty_produced: r.qty_planned, wastage_qty: 0 },
        fields: [f('qty_produced', 'Quantity produced', 'number', req), f('wastage_qty', 'Wastage (units)', 'number')],
        onSubmit: async (d) => { await API.post(`/production-orders/${r.id}/complete`, d); toast('Materials consumed and finished goods received.'); reload(); } });
    },
    async newExpense(reload) {
      await formModal({ title: 'New expense', submit: 'Submit expense',
        fields: [f('expense_category_id', 'Category', 'select', { ...req, options: src('expense_categories') }), f('amount', 'Amount', 'number', req), f('expense_date', 'Date', 'date', { ...req, default: today() }), f('branch_id', 'Branch', 'select', { options: src('branches') }), f('account_id', 'Paid from', 'select', { options: src('accounts') }), f('campaign_id', 'Marketing campaign', 'select', { options: src('campaigns') }), f('description', 'Description', 'text', { wide: true })],
        onSubmit: async (d) => { await API.post('/expenses', d); toast('Expense saved.'); reload(); } });
    },
    async newPeriod(reload) {
      await formModal({ title: 'New payroll period', fields: [f('name', 'Name', 'text', { ...req, hint: 'For example: October 2026' }), f('start_date', 'Start', 'date', req), f('end_date', 'End', 'date', req)],
        onSubmit: async (d) => { await API.post('/payroll-periods', d); toast('Period created. Generate payslips next.'); reload(); } });
    },
    async periodView(r) {
      const p = unwrap(await API.get('/payroll-periods/' + r.id));
      modal('Payslips - ' + p.name, `<div class="mb-3">${badge(p.status)}</div>` + table([{ label: 'Employee', get: (s) => s.employee?.name }, { label: 'Basic', num: true, get: (s) => fmt.money(s.basic) }, { label: 'Allowances', num: true, get: (s) => fmt.money(s.allowances) }, { label: 'OT hours', num: true, get: (s) => fmt.num(s.overtime_hours) }, { label: 'OT pay', num: true, get: (s) => fmt.money(s.overtime_amount) }, { label: 'Commission', num: true, get: (s) => fmt.money(s.commission) }, { label: 'Deductions', num: true, get: (s) => fmt.money(s.deductions) }, { label: 'Net pay', num: true, get: (s) => fmt.money(s.net) }], p.payslips || [], 'No payslips yet. Choose Generate.'), { wide: true });
    },
    async newDelivery(reload) {
      await formModal({ title: 'New delivery', submit: 'Create delivery',
        fields: [f('invoice_id', 'Invoice', 'select', { options: src('invoices', (x) => x.number) }), f('vehicle_id', 'Vehicle', 'select', { options: src('vehicles', (x) => x.plate) }), f('driver_id', 'Driver', 'select', { options: src('employees', (x) => x.name, { 'filter[is_salesperson]': '' }) }), f('destination', 'Destination', 'text', req), f('route', 'Route'), f('delivery_charge', 'Delivery charge', 'number'), f('fuel_cost', 'Expected fuel cost', 'number')],
        onSubmit: async (d) => { await API.post('/delivery-orders', d); toast('Delivery created.'); reload(); } });
    },
    async closeSession(r, reload) {
      await formModal({ title: 'Close the till', submit: 'Close day', values: {}, fields: [f('closing_cash', 'Counted cash in drawer', 'number', req), f('notes', 'Notes', 'text', { wide: true })],
        onSubmit: async (d) => { const x = unwrap(await API.post(`/cashier-sessions/${r.id}/close`, d)); toast(`Day closed. Variance ${fmt.money(x.variance)}.`); reload(); } });
    },
    async invoiceView(r) {
      const i = unwrap(await API.get('/invoices/' + r.id));
      modal('Invoice ' + i.number, receiptHtml(i), { wide: false });
    },
    async invoiceReturn(r, reload) {
      const i = unwrap(await API.get('/invoices/' + r.id));
      const rows = (i.items || []).filter((x) => Number(x.qty) - Number(x.returned_qty) > 0);
      const m = modal('Return items - ' + i.number, `<form class="space-y-3">${rows.map((x) => `<label class="block"><span class="lbl">${esc(x.product?.name)} (can return up to ${fmt.num(Number(x.qty) - Number(x.returned_qty), 3)})</span><input class="inp" type="number" step="any" min="0" data-p="${x.product_id}" value="0"></label>`).join('')}
        <label class="block"><span class="lbl">Refund method</span><select class="inp" name="refund_method"><option value="cash">Cash</option><option value="card">Card</option><option value="bank">Bank</option><option value="credit">Reduce customer credit</option></select></label>
        <label class="block"><span class="lbl">Reason</span><input class="inp" name="reason"></label><div id="formErr" class="hidden rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-sm px-4 py-3"></div>
        <div class="flex justify-end gap-2"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button class="btn btn-mint">Process return</button></div></form>`);
      $$('[data-close]', m).forEach((b) => (b.onclick = m.close));
      $('form', m).onsubmit = async (e) => {
        e.preventDefault();
        const items = $$('[data-p]', m).map((x) => ({ product_id: Number(x.dataset.p), qty: Number(x.value) })).filter((x) => x.qty > 0);
        try { if (!items.length) throw new Error('Enter a quantity to return.'); await API.post(`/invoices/${r.id}/return`, { items, refund_method: e.target.elements.refund_method.value, reason: e.target.elements.reason.value || null }); toast('Return processed. Stock, loyalty and commission were adjusted.'); m.close(); reload(); }
        catch (err) { const b = $('#formErr', m); b.textContent = err.message; b.classList.remove('hidden'); }
      };
    },
    async customerProfile(r, reload) {
      const h = await API.get(`/customers/${r.id}/history`);
      const c = h.customer;
      const m = modal(c.name, `<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
        ${kpi('Tier', esc(c.tier || '-'), { tone: 'violet', icon: I.spark })}
        ${kpi('Points', fmt.num(c.loyalty_points, 0), { tone: 'amber', icon: I.spark })}
        ${kpi('Credit limit', fmt.money(c.credit_limit), { tone: 'sky', icon: I.wallet })}
        ${kpi('Owes', fmt.money(c.credit_balance), { hero: true })}
        </div>
        ${can('crm.edit') ? '<button class="btn btn-mint mb-4" data-collect>Collect payment</button>' : ''}
        <h3 class="font-display font-bold text-white mb-3 mt-2">Recent purchases</h3>${table([{ label: 'Invoice', k: 'number' }, { label: 'Date', get: (x) => fmt.dt(x.created_at) }, { label: 'Total', num: true, get: (x) => fmt.money(x.total) }, { label: 'Status', html: (x) => badge(x.status) }], h.invoices || [], 'No purchases yet.')}
        <h3 class="font-display font-bold text-white mb-3 mt-6">Loyalty points</h3>${table([{ label: 'Type', html: (x) => badge(x.type) }, { label: 'Points', num: true, get: (x) => fmt.num(x.points, 0) }, { label: 'Note', k: 'note' }], h.points || [], 'No point activity yet.')}`, { wide: true });
      const btn = $('[data-collect]', m);
      if (btn) btn.onclick = () => formModal({ title: 'Collect payment', submit: 'Record payment', fields: [f('amount', 'Amount', 'number', req), f('method', 'Method', 'select', { ...req, options: ['cash', 'card', 'bank'] }), f('reference', 'Reference', 'text', { wide: true })],
        onSubmit: async (d) => { await API.post(`/customers/${r.id}/payments`, d); toast('Payment recorded against oldest balances.'); m.close(); reload(); } });
    },
  };

  function receiptHtml(i) {
    return `<div id="receipt" class="text-sm"><div class="text-center mb-3"><div class="font-display text-xl font-extrabold grad-text">Aurum</div><div class="text-slate-300">Invoice ${esc(i.number)}</div><div class="text-xs text-slate-500">${esc(fmt.dt(i.created_at))}</div></div>
      ${table([{ label: 'Item', get: (x) => x.product?.name || '' }, { label: 'Qty', num: true, get: (x) => fmt.num(x.qty, 3) }, { label: 'Price', num: true, get: (x) => fmt.money(x.unit_price) }, { label: 'Total', num: true, get: (x) => fmt.money(x.line_total) }], i.items || [])}
      <dl class="mt-3 space-y-1 text-slate-300"><div class="flex justify-between"><dt>Subtotal</dt><dd>${fmt.money(i.subtotal)}</dd></div><div class="flex justify-between"><dt>Discount</dt><dd>-${fmt.money(Number(i.discount) + Number(i.loyalty_discount || 0))}</dd></div><div class="flex justify-between font-bold text-base text-white"><dt>Total</dt><dd>${fmt.money(i.total)}</dd></div>
      ${(i.payments || []).map((p) => `<div class="flex justify-between"><dt>Paid by ${esc(p.method)}</dt><dd>${fmt.money(p.amount)}</dd></div>`).join('')}${Number(i.credit_amount) > 0 ? `<div class="flex justify-between"><dt>On credit</dt><dd>${fmt.money(i.credit_amount)}</dd></div>` : ''}</dl>
      ${Number(i.points_earned) ? `<p class="mt-3 text-xs text-slate-400">Points earned: ${fmt.num(i.points_earned, 0)}</p>` : ''}<button class="btn btn-brand w-full mt-4 print:hidden" onclick="window.print()">Print receipt</button></div>`;
  }

  /* =========================================================
     generic list screen
     ========================================================= */
  async function listPage(key, view) {
    const def = SCHEMA.RES[key];
    const st = { page: 1, search: '', filter: {} };
    const editable = def.fields && !def.onNew && can(def.perm + '.edit');
    const canNew = def.onNew ? can(def.newPerm) : def.fields && can(def.perm + '.create');
    const canDel = def.del && can(def.perm + '.delete');
    view.innerHTML = `<div class="card"><div class="p-4 flex flex-wrap gap-2 items-end border-b border-line">
      <label class="flex-1 min-w-[10rem]"><span class="lbl">Search</span><input class="inp" id="q" type="search" placeholder="Search ${esc(def.title.toLowerCase())}"></label>
      ${(def.filters || []).map((fl) => `<label><span class="lbl">${esc(fl.label)}</span><select class="inp" data-filter="${fl.name}"><option value="">All</option>${(fl.options || []).map((o) => { const [v, l] = Array.isArray(o) ? o : [o, fmt.label(o)]; return `<option value="${esc(v)}">${esc(l)}</option>`; }).join('')}</select></label>`).join('')}
      ${canNew ? `<button class="btn btn-brand" id="newBtn">${esc(def.newLabel || 'Add new')}</button>` : ''}</div>
      <div id="listBody" aria-live="polite"></div><div id="pager" class="p-3 flex items-center justify-between gap-2 text-sm text-slate-400"></div></div>`;
    const body = $('#listBody', view), pager = $('#pager', view);
    const acts = [...(def.actions || [])];
    if (editable) acts.push({ label: 'Edit', run: 'edit' });
    const load = async () => {
      body.innerHTML = '<p class="p-6 text-center text-slate-500">Loading...</p>';
      try {
        const res = await API.get('/' + key, { page: st.page, per_page: 15, search: st.search, filter: st.filter });
        const rows = res.data || [];
        window.__rows = rows;
        if (!rows.length) { body.innerHTML = `<p class="p-8 text-center text-slate-500">Nothing here yet.${canNew ? ' Use the button above to add the first one.' : ''}</p>`; pager.innerHTML = ''; return; }
        const showActs = (r) => acts.filter((a) => (!a.perm || can(a.perm)) && (!a.when || a.when(r)));
        body.innerHTML = `<div class="tbl-wrap"><table class="tbl tbl-cards"><thead><tr>${def.cols.map((c) => `<th class="${c.num ? 'num' : ''}">${esc(c.label || fmt.label(c.k))}</th>`).join('')}<th></th></tr></thead><tbody>${rows.map((r, idx) => `<tr>${def.cols.map((c) => `<td data-label="${esc(c.label || fmt.label(c.k))}" class="${c.num ? 'num' : ''}">${c.html ? c.html(r) : esc(c.get ? c.get(r) : (r[c.k] ?? ''))}</td>`).join('')}<td class="whitespace-nowrap text-right"><div class="flex flex-wrap gap-1 justify-end">${showActs(r).map((a) => `<button class="btn btn-sm ${a.danger ? 'btn-danger' : 'btn-ghost'}" data-act="${acts.indexOf(a)}" data-idx="${idx}">${esc(a.label)}</button>`).join('')}${canDel ? `<button class="btn btn-sm btn-danger" data-del="${idx}">Delete</button>` : ''}</div></td></tr>`).join('')}</tbody></table></div>`;
        pager.innerHTML = `<span>${res.total} record${res.total === 1 ? '' : 's'} — page ${res.current_page} of ${res.last_page}</span><div class="flex gap-2"><button class="btn btn-ghost btn-sm" id="prev" ${res.current_page <= 1 ? 'disabled' : ''}>Previous</button><button class="btn btn-ghost btn-sm" id="next" ${res.current_page >= res.last_page ? 'disabled' : ''}>Next</button></div>`;
        $('#prev', pager).onclick = () => { st.page--; load(); }; $('#next', pager).onclick = () => { st.page++; load(); };
      } catch (e) { body.innerHTML = `<p class="p-6 text-center text-rose-400">${esc(e.message)}</p>`; pager.innerHTML = ''; }
    };
    const openForm = async (row) => {
      const values = row ? { ...row } : {};
      await formModal({ title: (row ? 'Edit ' : 'New ') + def.title.toLowerCase().replace(/s$/, ''), fields: def.fields, values, wide: def.fields.length > 8,
        onSubmit: async (d) => {
          if (def.passwordOptionalOnEdit && row && !d.password) delete d.password;
          if (!row) Object.keys(d).forEach((k) => d[k] === null && delete d[k]);
          row ? await API.put(`/${key}/${row.id}`, d) : await API.post('/' + key, d);
          UI.clearOptCache(); toast('Saved.'); load();
        } });
    };
    const run = async (a, row) => {
      if (a.run === 'edit') return openForm(row);
      if (typeof a.run === 'string') return H[a.run](row, load);
      if (a.confirm && !(await confirmBox(a.confirm))) return;
      const [method, path, b] = a.run(row);
      if (await act(method, path, b)) load();
    };
    view.addEventListener('click', async (e) => {
      const b = e.target.closest('button[data-act],button[data-del]'); if (!b) return;
      const row = window.__rows[Number(b.dataset.idx ?? b.dataset.del)];
      if (b.dataset.del !== undefined) { if (await confirmBox('Delete this record? This cannot be undone.', 'Delete')) { if (await act('del', `/${key}/${row.id}`, null, 'Deleted.')) load(); } return; }
      run(acts[Number(b.dataset.act)], row);
    });
    let t; $('#q', view).oninput = (e) => { clearTimeout(t); t = setTimeout(() => { st.search = e.target.value; st.page = 1; load(); }, 300); };
    $$('[data-filter]', view).forEach((s) => (s.onchange = () => { st.filter[s.dataset.filter] = s.value; st.page = 1; load(); }));
    if (canNew) $('#newBtn', view).onclick = () => (def.onNew ? H[def.onNew](load) : openForm(null));
    load();
  }

  /* =========================================================
     dashboard
     ========================================================= */
  async function dashboard(view) {
    const d = new Date(), from = new Date(d.getFullYear(), d.getMonth(), 1).toISOString().slice(0, 10);
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
            ${kpi('Receivables', fmt.money(x.receivables), { tone: 'amber', icon: I.wallet })}
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

  /* =========================================================
     inventory by location
     ========================================================= */
  async function inventory(view) {
    view.innerHTML = `<div class="card"><div class="p-4 flex flex-wrap gap-2 items-end border-b border-line"><label class="min-w-[12rem]"><span class="lbl">Location</span><select class="inp" id="wh"><option value="">All locations</option></select></label>
      ${can('inventory.approve') ? '<button class="btn btn-ghost" id="open">Enter opening stock</button>' : ''}${can('inventory.create') ? '<button class="btn btn-brand" id="tr">Transfer stock</button>' : ''}</div><div id="inv"></div></div>`;
    (await UI.options(WH)).forEach(([v, l]) => $('#wh', view).insertAdjacentHTML('beforeend', `<option value="${v}">${esc(l)}</option>`));
    const load = async () => {
      const r = await API.get('/stock_balances', { per_page: 200, filter: { warehouse_id: $('#wh', view).value } });
      $('#inv', view).innerHTML = table([{ label: 'Product', get: (b) => b.product?.name }, { label: 'SKU', get: (b) => b.product?.sku }, { label: 'Location', get: (b) => b.warehouse?.name }, { label: 'On hand', num: true, get: (b) => fmt.num(b.qty, 3) }, { label: 'Reorder at', num: true, get: (b) => fmt.num(b.product?.reorder_level, 3) }, { label: 'Status', html: (b) => (Number(b.product?.reorder_level) > 0 && Number(b.qty) <= Number(b.product.reorder_level) ? badge('low') : badge('active')) }], r.data || [], 'No stock recorded yet. Enter opening stock or receive a purchase order.');
    };
    $('#wh', view).onchange = load;
    if ($('#tr', view)) $('#tr', view).onclick = () => H.newTransfer(load);
    if ($('#open', view)) $('#open', view).onclick = () => formModal({ title: 'Opening stock', fields: [f('warehouse_id', 'Location', 'select', { ...req, options: WH }), f('product_id', 'Product', 'select', { ...req, options: PRODUCT }), f('qty', 'Quantity', 'number', req), f('unit_cost', 'Unit cost', 'number', req)], onSubmit: async (d) => { await API.post('/inventory/opening', d); toast('Opening stock recorded.'); load(); } });
    load();
  }

  /* =========================================================
     reports — modern chart-driven studio
     ========================================================= */
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
    ['payables', 'Supplier outstanding'],
    ['expenses', 'Expenses by category'],
    ['marketing', 'Marketing spend'],
    ['delivery-costs', 'Delivery and vehicle costs'],
  ];

  async function reports(view) {
    const d = new Date(), from = new Date(d.getFullYear(), d.getMonth(), 1).toISOString().slice(0, 10);
    view.innerHTML = `
      <div class="rep-hero mb-5">
        <div class="flex flex-wrap items-end gap-3 justify-between">
          <div>
            <div class="chip-eyebrow">Reports Studio</div>
            <h2 class="mt-3">Pick a report, set the period, run.</h2>
            <p class="text-slate-400 text-sm mt-1">Charts and totals are computed on the fly from the same transactional tables the app uses.</p>
          </div>
          <div class="flex flex-wrap gap-3 items-end">
            <label class="min-w-[14rem]"><span class="lbl">Report</span><select class="inp" id="rp">${REPORTS.map(([k, l]) => `<option value="${k}">${l}</option>`).join('')}</select></label>
            <label><span class="lbl">From</span><input class="inp" type="date" id="from" value="${from}"></label>
            <label><span class="lbl">To</span><input class="inp" type="date" id="to" value="${today()}"></label>
            <button class="btn btn-brand" id="run">Run report</button>
            <button class="btn btn-ghost" id="csv">CSV</button>
          </div>
        </div>
      </div>

      <div id="kpis" class="report-kpi-grid mb-5"></div>
      <div id="charts" class="grid lg:grid-cols-3 gap-4 mb-5"></div>
      <div class="card">
        <div class="panel-head"><div><h3 class="font-display font-bold text-white">Detail</h3><p class="sub text-slate-400 text-sm">Every row from the underlying query.</p></div></div>
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

    const run = async () => {
      const out = $('#out', view); out.innerHTML = '<p class="text-slate-500">Loading...</p>';
      $('#charts', view).innerHTML = ''; $('#kpis', view).innerHTML = '';
      try {
        const key = $('#rp', view).value;
        const r = await API.get('/reports/' + key, { from: $('#from', view).value, to: $('#to', view).value });
        let data = r.data;
        const isObject = data && typeof data === 'object' && !Array.isArray(data);
        let rows = Array.isArray(data) ? data : Object.entries(data || {}).map(([k, v]) => ({ item: fmt.label(k), value: v }));
        last = rows;

        renderKpis(key, rows, isObject ? data : null);
        renderCharts(key, rows, isObject ? data : null);

        if (!rows.length) { out.innerHTML = '<p class="text-slate-500">No records for this period.</p>'; return; }
        const keys = Object.keys(rows[0]);
        const cell = (k, v) => {
          if (v === null || v === undefined || v === '') return '';
          const n = parseNum(v);
          if (n !== null) {
            return /qty|count|hours|units|percent|invoices|deliveries|version|points|lines/i.test(k) ? fmt.num(n, 2) : fmt.money(n);
          }
          return esc(v);
        };
        out.innerHTML = table(keys.map((k) => ({ label: fmt.label(k), num: parseNum(rows[0][k]) !== null, html: (x) => cell(k, x[k]) })), rows);
      } catch (e) { out.innerHTML = `<p class="text-rose-400">${esc(e.message)}</p>`; }
    };
    $('#run', view).onclick = run;
    $('#csv', view).onclick = () => { if (!last.length) return toast('Run a report first.', true); const k = Object.keys(last[0]); UI.download($('#rp', view).value + '.csv', [k.join(','), ...last.map((r) => k.map((c) => JSON.stringify(r[c] ?? '')).join(','))].join('\n')); };
    run();
  }

  /* =========================================================
     settings
     ========================================================= */
  async function settings(view) {
    const { data } = await API.get('/settings');
    view.innerHTML = `<form class="card card-pad max-w-3xl space-y-4"><p class="text-sm text-slate-400">These values drive calculations such as loyalty earning, overtime and credit rules. Changes apply to new transactions only.</p>${data.map((s) => `<label class="block"><span class="lbl">${esc(s.description || fmt.label(s.key))}</span><input class="inp" name="${esc(s.key)}" value="${esc(s.value)}" ${can('settings.edit') ? '' : 'disabled'}><span class="text-xs text-slate-500">${esc(s.key)}</span></label>`).join('')}${can('settings.edit') ? '<button class="btn btn-brand">Save settings</button>' : ''}</form>`;
    $('form', view).onsubmit = async (e) => { e.preventDefault(); const o = {}; $$('input', e.target).forEach((i) => (o[i.name] = i.value)); if (await act('put', '/settings', { settings: o }, 'Settings saved.')) UI.clearOptCache(); };
  }

  /* =========================================================
     point of sale
     ========================================================= */
  async function pos(view) {
    const st = { branch: API.user.branch_id || '', cart: new Map(), cat: null, q: '', pays: [] };
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
    const totals = () => {
      let sub = 0; st.cart.forEach((l) => (sub += l.p.price * l.qty));
      const rule = (st.cat.discounts || []).find((x) => String(x.id) === String(st.rule));
      let disc = 0; if (rule && sub >= Number(rule.min_total || 0)) disc = rule.type === 'percent' ? sub * Number(rule.value) / 100 : Math.min(sub, Number(rule.value));
      return { sub, disc, total: Math.max(0, sub - disc) };
    };
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
    const drawCart = async () => {
      const box = $('#cartBox', view), t = totals();
      const custOpts = await UI.options(src('customers', (x) => `${x.name} (${x.phone || x.code})`));
      box.innerHTML = `<h2 class="font-display text-lg font-bold mb-3 text-white">Current sale</h2>
        <div class="space-y-2 mb-3">${[...st.cart.values()].map((l) => `<div class="flex items-center gap-2"><div class="flex-1 min-w-0"><div class="truncate text-sm font-semibold text-white">${esc(l.p.name)}</div><div class="text-xs text-slate-400">${fmt.money(l.p.price)}</div></div><input class="inp !w-20" type="number" step="any" min="0" data-q="${l.p.id}" value="${l.qty}" aria-label="Quantity"><div class="w-20 text-right text-sm tabular-nums text-slate-200">${fmt.money(l.p.price * l.qty)}</div><button class="btn-icon !w-8 !h-8" data-x="${l.p.id}" aria-label="Remove">&times;</button></div>`).join('') || '<p class="text-sm text-slate-500">Tap a product to add it.</p>'}</div>
        <div class="grid gap-2"><select class="inp" id="cu"><option value="">Walk-in customer</option>${custOpts.map(([v, l]) => `<option value="${v}" ${String(st.cust) === String(v) ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>
        <select class="inp" id="sp"><option value="">Salesperson</option>${(st.cat.salespeople || []).map((s) => `<option value="${s.id}" ${String(st.sp) === String(s.id) ? 'selected' : ''}>${esc(s.name)}</option>`).join('')}</select>
        <select class="inp" id="dr"><option value="">No discount</option>${(st.cat.discounts || []).map((x) => `<option value="${x.id}" ${String(st.rule) === String(x.id) ? 'selected' : ''}>${esc(x.name)}</option>`).join('')}</select>
        ${st.cust ? `<input class="inp" id="rp" type="number" min="0" step="1" placeholder="Loyalty points to redeem" value="${st.redeem || ''}">` : ''}</div>
        <dl class="mt-4 space-y-1 text-sm text-slate-300"><div class="flex justify-between"><dt>Subtotal</dt><dd>${fmt.money(t.sub)}</dd></div><div class="flex justify-between"><dt>Discount</dt><dd>-${fmt.money(t.disc)}</dd></div><div class="flex justify-between text-lg font-bold text-white"><dt>Total</dt><dd>${fmt.money(t.total)}</dd></div></dl>
        <p class="text-xs text-slate-500 mt-1">Tax, tier discount and loyalty value are confirmed by the server when you charge.</p>
        <div class="mt-3 space-y-2" id="pays">${st.pays.map((p, i) => `<div class="flex gap-2"><select class="inp" data-pm="${i}">${['cash', 'card', 'bank', 'credit'].map((m) => `<option ${p.method === m ? 'selected' : ''}>${m}</option>`).join('')}</select><input class="inp" type="number" step="any" min="0" data-pa="${i}" value="${p.amount}" aria-label="Payment amount"><button class="btn-icon !w-10" data-px="${i}" aria-label="Remove payment">&times;</button></div>`).join('')}</div>
        <div class="flex gap-2 mt-2"><button class="btn btn-ghost btn-sm" id="addPay">Add payment</button><button class="btn btn-ghost btn-sm" id="payAll">Pay full amount in cash</button></div>
        <div id="posErr" class="hidden mt-3 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-sm px-3 py-2" role="alert"></div>
        <button class="btn btn-mint w-full mt-4 py-3" id="charge" ${st.cart.size ? '' : 'disabled'}>Charge ${fmt.money(t.total)}</button>`;
      $$('[data-q]', box).forEach((i) => (i.onchange = () => { const l = st.cart.get(Number(i.dataset.q)); const v = Number(i.value); if (v <= 0) st.cart.delete(l.p.id); else if (v > l.p.stock) { toast('Only ' + fmt.num(l.p.stock, 3) + ' in stock.', true); } else l.qty = v; drawCart(); }));
      $$('[data-x]', box).forEach((b) => (b.onclick = () => { st.cart.delete(Number(b.dataset.x)); drawCart(); }));
      $('#cu', box).onchange = (e) => { st.cust = e.target.value; if (!st.cust) st.redeem = 0; drawCart(); };
      $('#sp', box).onchange = (e) => (st.sp = e.target.value);
      $('#dr', box).onchange = (e) => { st.rule = e.target.value; drawCart(); };
      if ($('#rp', box)) $('#rp', box).onchange = (e) => (st.redeem = Number(e.target.value));
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
          if (!payments.length) throw new Error('Add a payment, or choose "Pay full amount in cash".');
          const inv = unwrap(await API.post('/pos/checkout', { branch_id: Number(st.branch), customer_id: st.cust ? Number(st.cust) : null, salesperson_id: st.sp ? Number(st.sp) : null, discount_rule_id: st.rule ? Number(st.rule) : null, redeem_points: st.redeem || 0, items: [...st.cart.values()].map((l) => ({ product_id: l.p.id, qty: l.qty })), payments }));
          st.cart.clear(); st.pays = []; st.cust = st.sp = st.rule = ''; st.redeem = 0;
          toast('Sale completed.'); modal('Sale complete', receiptHtml(inv)); load();
        } catch (e) { err.textContent = e.message; err.classList.remove('hidden'); btn.disabled = false; }
      };
    };
    load();
  }

  window.PAGES = { listPage, dashboard, inventory, reports, settings, pos };
})();