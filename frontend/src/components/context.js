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


export { $,$$,esc,fmt,badge,toast,modal,confirmBox,formModal,linesEditor,table,barChart,vBarChart,lineChart,donutChart,sparkline,kpi,PALETTE,can,unwrap,stat,card,src,f,req,today,PRODUCT,WH,I,act };
