import { $,$$,esc,fmt,badge,toast,modal,confirmBox,formModal,linesEditor,table,barChart,vBarChart,lineChart,donutChart,sparkline,kpi,PALETTE,can,unwrap,stat,card,src,f,req,today,PRODUCT,WH,I,act } from "../../components/context.js";
import { H, receiptHtml } from "../../services/workflows.js";
  async function listPage(key, view) {
    const original = SCHEMA.RES[key];
    const def = {...original, filters:await Promise.all((original.filters||[]).map(async f=>({...f,options:f.source?await UI.options(f.source):f.options})))};
    const params = new URLSearchParams(location.hash.split('?')[1] || '');
    const st = { page: Math.max(1, Number(params.get('page')) || 1), search: params.get('search') || '', filter: {}, direction: params.get('direction') || 'desc' };
    for (const field of def.filters || []) st.filter[field.name] = params.get(field.name) || '';
    let rows = [], request = 0;
    const editable = def.fields && !def.onNew && can(def.perm + '.edit');
    const canNew = def.onNew ? can(def.newPerm) : def.fields && can(def.perm + '.create');
    const canDel = def.del && can(def.perm + '.delete');
    view.innerHTML = `<div class="resource-heading"><div><h2>${esc(def.title)}</h2><p>${esc(def.description||'Manage your records, keep your business moving.')}</p></div><span class="resource-mark" aria-hidden="true">${I.box}</span></div><div class="card"><div class="p-4 flex flex-wrap gap-2 items-end border-b border-line">
      <label class="flex-1 min-w-[10rem]"><span class="lbl">Search</span><input class="inp" id="q" type="search" placeholder="Search ${esc(def.title.toLowerCase())}" value="${esc(st.search)}"></label>
      ${(def.filters || []).map((fl) => `<label><span class="lbl">${esc(fl.label)}</span><select class="inp" data-filter="${fl.name}"><option value="">All</option>${(fl.options || []).map((o) => { const [v, l] = Array.isArray(o) ? o : [o, fmt.label(o)]; return `<option value="${esc(v)}" ${String(st.filter[fl.name])===String(v)?'selected':''}>${esc(l)}</option>`; }).join('')}</select></label>`).join('')}
      <label><span class="lbl">Order</span><select id="sortDirection" class="inp"><option value="desc">Newest first</option><option value="asc">Oldest first</option></select></label>${['invoices','production_orders'].includes(key)?'<button class="btn btn-ghost" id="refreshStatus">Refresh status</button>':''}${canNew ? `<button class="btn btn-brand" id="newBtn">${esc(def.newLabel || 'Add new')}</button>` : ''}</div>
      <div id="listBody" aria-live="polite"></div><div id="pager" class="p-3 flex items-center justify-between gap-2 text-sm text-slate-400"></div></div>`;
    const body = $('#listBody', view), pager = $('#pager', view);
    const acts = [...(def.actions || [])];
    if (editable) acts.push({ label: 'Edit', run: 'edit' });
    const load = async () => {
      const token = ++request;
      const query = new URLSearchParams({page:st.page, search:st.search, direction:st.direction, ...st.filter});
      history.replaceState(null, '', '#/'+key+'?'+query);
      body.setAttribute('aria-busy','true');
      body.innerHTML = '<div class="loading-state" role="status"><div></div><div></div><div></div></div>';
      try {
        const res = await API.get('/' + key, { page: st.page, per_page: 15, search: st.search, filter: st.filter, direction: st.direction });
        if (token !== request || !view.isConnected) return;
        rows = res.data || [];
        body.removeAttribute('aria-busy');
        if (!rows.length) { body.innerHTML = `<p class="p-8 text-center text-slate-500">${st.search || Object.values(st.filter).some(Boolean) ? 'No records match these filters. Try a different search.' : 'No records yet.'}${canNew ? ' Use the button above to add a record.' : ''}</p>`; pager.innerHTML = ''; return; }
        const showActs = (r) => acts.filter((a) => (!a.perm || can(a.perm)) && (!a.when || a.when(r)));
        body.innerHTML = `<div class="tbl-wrap"><table class="tbl tbl-cards"><thead><tr>${def.cols.map((c) => `<th class="${c.num ? 'num' : ''}">${esc(c.label || fmt.label(c.k))}</th>`).join('')}<th></th></tr></thead><tbody>${rows.map((r, idx) => `<tr>${def.cols.map((c) => `<td data-label="${esc(c.label || fmt.label(c.k))}" class="${c.num ? 'num' : ''}">${c.html ? c.html(r) : esc(c.get ? c.get(r) : (r[c.k] ?? ''))}</td>`).join('')}<td class="whitespace-nowrap text-right"><div class="flex flex-wrap gap-1 justify-end">${showActs(r).map((a) => `<button class="btn btn-sm ${a.danger ? 'btn-danger' : 'btn-ghost'}" data-act="${acts.indexOf(a)}" data-idx="${idx}">${esc(a.label)}</button>`).join('')}${canDel ? `<button class="btn btn-sm btn-danger" data-del="${idx}">Delete</button>` : ''}</div></td></tr>`).join('')}</tbody></table></div>`;
        pager.innerHTML = `<span>${res.total} record${res.total === 1 ? '' : 's'} — page ${res.current_page} of ${res.last_page}</span><div class="flex gap-2"><button class="btn btn-ghost btn-sm" id="prev" ${res.current_page <= 1 ? 'disabled' : ''}>Previous</button><button class="btn btn-ghost btn-sm" id="next" ${res.current_page >= res.last_page ? 'disabled' : ''}>Next</button></div>`;
        $('#prev', pager).onclick = () => { st.page--; load(); }; $('#next', pager).onclick = () => { st.page++; load(); };
      } catch (e) { if(token!==request)return; body.removeAttribute('aria-busy'); body.innerHTML = `<div class="empty-state" role="alert"><p>${esc(e.message)}</p><button class="btn btn-ghost" data-retry>Try again</button></div>`; $('[data-retry]',body).onclick=load; pager.innerHTML = ''; }
    };
    const openForm = async (row) => {
      const values = row ? { ...row } : {};
      await formModal({ title: (row ? 'Edit ' : 'New ') + def.title.toLowerCase().replace(/s$/, ''), fields: def.fields.filter(f=>(!row||!f.createOnly)&&(!f.permission||can(f.permission))), values, wide: def.fields.length > 8,
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
      const row = rows[Number(b.dataset.idx ?? b.dataset.del)];
      if (b.dataset.del !== undefined) { if (await confirmBox('Delete this record? This cannot be undone.', 'Delete')) { if (await act('del', `/${key}/${row.id}`, null, 'Deleted.')) load(); } return; }
      b.disabled=true; try { await run(acts[Number(b.dataset.act)], row); } catch(error) { toast(error.message,true); } finally { b.disabled=false; }
    });
    let t; $('#q', view).oninput = (e) => { clearTimeout(t); t = setTimeout(() => { st.search = e.target.value; st.page = 1; load(); }, 300); };
    $$('[data-filter]', view).forEach((s) => (s.onchange = () => { st.filter[s.dataset.filter] = s.value; st.page = 1; load(); }));
    if (canNew) $('#newBtn', view).onclick = () => (def.onNew ? H[def.onNew](load) : openForm(null));
    $('#sortDirection',view).value=st.direction;
    $('#sortDirection',view).onchange=e=>{st.direction=e.target.value;st.page=1;load();};
    if ($('#refreshStatus',view)) $('#refreshStatus',view).onclick=()=>load();
    await load();
  }

export { listPage };
