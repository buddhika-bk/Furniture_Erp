import { $,$$,esc,fmt,badge,toast,modal,confirmBox,formModal,linesEditor,table,barChart,vBarChart,lineChart,donutChart,sparkline,kpi,PALETTE,can,unwrap,stat,card,src,f,req,today,PRODUCT,WH,I,act } from "../components/context.js";
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
    return `<div id="receipt" class="text-sm"><div class="text-center mb-3"><div class="font-display text-xl font-extrabold grad-text">Webpoint One</div><div class="text-slate-300">Invoice ${esc(i.number)}</div><div class="text-xs text-slate-500">${esc(fmt.dt(i.created_at))}</div></div>
      ${table([{ label: 'Item', get: (x) => x.product?.name || '' }, { label: 'Qty', num: true, get: (x) => fmt.num(x.qty, 3) }, { label: 'Price', num: true, get: (x) => fmt.money(x.unit_price) }, { label: 'Total', num: true, get: (x) => fmt.money(x.line_total) }], i.items || [])}
      <dl class="mt-3 space-y-1 text-slate-300"><div class="flex justify-between"><dt>Subtotal</dt><dd>${fmt.money(i.subtotal)}</dd></div><div class="flex justify-between"><dt>Discount</dt><dd>-${fmt.money(Number(i.discount) + Number(i.loyalty_discount || 0))}</dd></div><div class="flex justify-between font-bold text-base text-white"><dt>Total</dt><dd>${fmt.money(i.total)}</dd></div>
      ${(i.payments || []).map((p) => `<div class="flex justify-between"><dt>Paid by ${esc(p.method)}</dt><dd>${fmt.money(p.amount)}</dd></div>`).join('')}${Number(i.credit_amount) > 0 ? `<div class="flex justify-between"><dt>On credit</dt><dd>${fmt.money(i.credit_amount)}</dd></div>` : ''}</dl>
      ${Number(i.points_earned) ? `<p class="mt-3 text-xs text-slate-400">Points earned: ${fmt.num(i.points_earned, 0)}</p>` : ''}<button class="btn btn-brand w-full mt-4 print:hidden" onclick="window.print()">Print receipt</button></div>`;
  }

  /* =========================================================
     generic list screen
     ========================================================= */

export { H, receiptHtml };
