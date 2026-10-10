/* UI helpers: formatting, toasts, modals, forms, line editors, charts. No business rules live here. */
(function () {
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const cur = () => (window.ERP_CONFIG && window.ERP_CONFIG.CURRENCY) || '$';
  const fmt = {
    money: (v) => cur() + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
    money0: (v) => cur() + Number(v || 0).toLocaleString(undefined, { maximumFractionDigits: 0 }),
    compact: (v) => {
      const n = Number(v || 0); const abs = Math.abs(n);
      if (abs >= 1e9) return cur() + (n / 1e9).toFixed(1) + 'B';
      if (abs >= 1e6) return cur() + (n / 1e6).toFixed(1) + 'M';
      if (abs >= 1e3) return cur() + (n / 1e3).toFixed(1) + 'K';
      return cur() + n.toFixed(0);
    },
    num: (v, d = 2) => Number(v || 0).toLocaleString(undefined, { maximumFractionDigits: d }),
    date: (v) => (v ? String(v).slice(0, 10) : ''),
    dt: (v) => (v ? String(v).replace('T', ' ').slice(0, 16) : ''),
    label: (v) => String(v ?? '').replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase()),
  };
  const GOOD = ['completed', 'approved', 'received', 'paid', 'delivered', 'finalized', 'active', 'available', 'closed', 'running', 'finished', 'present'];
  const WARN = ['pending', 'draft', 'partially_received', 'in_progress', 'released', 'dispatched', 'reviewed', 'planned', 'open', 'partial', 'unpaid', 'low', 'warning', 'late', 'partially_returned'];
  const BAD  = ['rejected', 'cancelled', 'blocked', 'terminated', 'returned', 'absent', 'critical', 'resigned', 'inactive'];
  const badge = (v) => v == null || v === '' ? '' : `<span class="badge ${GOOD.includes(v) ? 'ok' : WARN.includes(v) ? 'warn' : BAD.includes(v) ? 'bad' : 'info'}">${esc(fmt.label(v))}</span>`;
  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

  function toast(msg, err) {
    const el = document.createElement('div');
    el.className = 'toast' + (err ? ' err' : '');
    el.setAttribute('role', err ? 'alert' : 'status');
    el.textContent = msg;
    $('#toasts').appendChild(el);
    setTimeout(() => el.remove(), err ? 6000 : 3200);
  }

  function modal(title, html, opts = {}) {
    const back = document.createElement('div');
    back.className = 'modal-back';
    back.innerHTML = `<div class="modal ${opts.wide ? 'wide' : ''}" role="dialog" aria-modal="true" aria-label="${esc(title)}">
      <div class="flex items-center justify-between px-5 py-4 border-b border-white/[.06] sticky top-0 z-10">
        <h2 class="font-display text-xl font-bold text-white">${esc(title)}</h2>
        <button class="btn-icon" data-close aria-label="Close">&times;</button></div>
      <div class="p-5" data-body>${html}</div></div>`;
    $('#modalRoot').appendChild(back);
    const close = () => { back.remove(); document.removeEventListener('keydown', onKey); opts.onClose && opts.onClose(); };
    const onKey = (e) => { if (e.key === 'Escape') close(); };
    document.addEventListener('keydown', onKey);
    back.addEventListener('mousedown', (e) => { if (e.target === back) close(); });
    $$('[data-close]', back).forEach((b) => b.addEventListener('click', close));
    back.close = close; back.body = $('[data-body]', back);
    return back;
  }

  const confirmBox = (msg, label = 'Confirm') => new Promise((res) => {
    let done = false;
    const m = modal('Please confirm', `<p class="mb-5 text-slate-300">${esc(msg)}</p><div class="flex justify-end gap-2"><button class="btn btn-ghost" data-no>Cancel</button><button class="btn btn-brand" data-yes>${esc(label)}</button></div>`, { onClose: () => { if (!done) { done = true; res(false); } } });
    $('[data-no]', m).onclick = () => m.close();
    $('[data-yes]', m).onclick = () => { done = true; m.remove(); res(true); };
  });

  /* ---- option sources (cached) ---- */
  const optCache = {};
  async function options(src) {
    if (Array.isArray(src)) return src.map((o) => (Array.isArray(o) ? o : [o, fmt.label(o)]));
    const key = src.res + JSON.stringify(src.params || {});
    if (!optCache[key]) {
      const r = await API.get('/' + src.res, { per_page: 200, ...(src.params || {}) }).catch(() => ({ data: [] }));
      optCache[key] = (r.data || []).map((x) => [x.id, src.label ? src.label(x) : x.name]);
    }
    return optCache[key];
  }
  const clearOptCache = () => Object.keys(optCache).forEach((k) => delete optCache[k]);

  /* ---- forms ---- */
  async function fieldHtml(f, v) {
    const id = 'f_' + f.name, req = f.required ? 'required' : '';
    const lbl = `<span class="lbl">${esc(f.label)}${f.required ? ' *' : ''}</span>`;
    let input;
    if (f.type === 'select') {
      const opts = await options(f.options);
      input = `<select class="inp" name="${f.name}" id="${id}" ${req}><option value="">${f.required ? 'Select...' : 'None'}</option>${opts.map(([val, l]) => `<option value="${esc(val)}" ${String(v) === String(val) ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>`;
    } else if (f.type === 'textarea') {
      input = `<textarea class="inp" rows="3" name="${f.name}" id="${id}" ${req}>${esc(v)}</textarea>`;
    } else if (f.type === 'checkbox') {
      return `<label class="flex items-center gap-2 pt-6"><input type="checkbox" name="${f.name}" class="w-4 h-4 accent-indigo-500" ${v === undefined ? (f.default ? 'checked' : '') : (v ? 'checked' : '')}><span class="text-sm font-semibold text-slate-200">${esc(f.label)}</span></label>`;
    } else if (f.type === 'multicheck') {
      const opts = await options(f.options);
      const cur = new Set(v || []);
      input = `<div class="grid grid-cols-2 sm:grid-cols-3 gap-2 max-h-64 overflow-y-auto border border-line rounded-xl p-3" data-multi="${f.name}">${opts.map(([val, l]) => `<label class="flex items-center gap-2 text-sm text-slate-300"><input type="checkbox" value="${esc(val)}" class="accent-indigo-500" ${cur.has(val) ? 'checked' : ''}>${esc(l)}</label>`).join('')}</div>`;
    } else {
      input = `<input class="inp" type="${f.type || 'text'}" name="${f.name}" id="${id}" ${f.step ? `step="${f.step}"` : f.type === 'number' ? 'step="any"' : ''} ${f.min !== undefined ? `min="${f.min}"` : ''} value="${esc(v ?? f.default ?? '')}" ${req} ${f.type === 'password' ? 'autocomplete="new-password"' : ''}>`;
    }
    return `<label class="block ${f.wide ? 'sm:col-span-2' : ''}">${lbl}${input}${f.hint ? `<span class="text-xs text-slate-500">${esc(f.hint)}</span>` : ''}</label>`;
  }
  async function formHtml(fields, values = {}) {
    const parts = await Promise.all(fields.map((f) => fieldHtml(f, values[f.name])));
    return `<div class="grid sm:grid-cols-2 gap-4">${parts.join('')}</div>`;
  }
  function collect(form, fields) {
    const out = {};
    fields.forEach((f) => {
      if (f.type === 'checkbox') { out[f.name] = form.elements[f.name].checked; return; }
      if (f.type === 'multicheck') { out[f.name] = $$('input:checked', $(`[data-multi="${f.name}"]`, form)).map((i) => i.value); return; }
      const raw = form.elements[f.name].value;
      if (raw === '') { out[f.name] = null; return; }
      out[f.name] = f.type === 'number' || (f.type === 'select' && f.numeric !== false && /^\d+$/.test(raw) && f.asNumber !== false) ? Number(raw) : raw;
    });
    return out;
  }
  async function formModal({ title, fields, values, submit = 'Save', extra, onSubmit, wide }) {
    const m = modal(title, '<p class="text-sm text-slate-400">Loading...</p>', { wide });
    m.body.innerHTML = `<form>${await formHtml(fields, values)}${extra ? `<div class="mt-4" data-extra>${extra.html || ''}</div>` : ''}
      <div id="formErr" class="hidden mt-4 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-sm px-4 py-3" role="alert"></div>
      <div class="mt-5 flex justify-end gap-2"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button class="btn btn-brand" type="submit">${esc(submit)}</button></div></form>`;
    $$('[data-close]', m.body).forEach((b) => b.addEventListener('click', m.close));
    const form = $('form', m.body);
    if (extra && extra.mount) extra.mount($('[data-extra]', m.body));
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = $('button[type=submit]', form); btn.disabled = true;
      try {
        const data = collect(form, fields);
        if (extra && extra.collect) Object.assign(data, extra.collect($('[data-extra]', m.body)));
        await onSubmit(data, m);
        m.close();
      } catch (err) { const box = $('#formErr', m.body); box.textContent = err.message; box.classList.remove('hidden'); btn.disabled = false; }
    });
    return m;
  }

  /* ---- line editor ---- */
  async function linesEditor(host, cols, initial = [{}], addLabel = 'Add line') {
    const rowHtml = async (row) => {
      const cells = await Promise.all(cols.map(async (c) => {
        let inner;
        if (c.type === 'select') {
          const opts = await options(c.options);
          inner = `<select class="inp" data-k="${c.name}"><option value="">${esc(c.label)}</option>${opts.map(([v, l]) => `<option value="${esc(v)}" ${String(row[c.name]) === String(v) ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>`;
        } else inner = `<input class="inp" type="number" step="any" min="0" data-k="${c.name}" placeholder="${esc(c.label)}" aria-label="${esc(c.label)}" value="${esc(row[c.name] ?? c.default ?? '')}">`;
        return `<div class="${c.type === 'select' ? 'col-span-2 sm:col-span-5' : 'col-span-1 sm:col-span-2'} min-w-0">${inner}</div>`;
      }));
      return `<div class="grid grid-cols-2 sm:grid-cols-12 gap-2 items-center" data-row>${cells.join('')}<button type="button" class="btn btn-danger btn-sm col-span-2 sm:col-span-1" data-del aria-label="Remove line">&times;</button></div>`;
    };
    host.innerHTML = `<div class="space-y-2" data-rows></div><button type="button" class="btn btn-ghost btn-sm mt-3" data-add>+ ${esc(addLabel)}</button>`;
    const rows = $('[data-rows]', host);
    const add = async (r = {}) => { const d = document.createElement('div'); d.innerHTML = await rowHtml(r); const el = d.firstChild; $('[data-del]', el).onclick = () => el.remove(); rows.appendChild(el); };
    for (const r of initial) await add(r);
    $('[data-add]', host).onclick = () => add();
    return () => $$('[data-row]', rows).map((el) => { const o = {}; $$('[data-k]', el).forEach((i) => { o[i.dataset.k] = i.value === '' ? null : (i.tagName === 'SELECT' ? Number(i.value) : Number(i.value)); }); return o; }).filter((o) => cols.some((c) => c.type === 'select' ? o[c.name] : false));
  }

  /* =========================================================
     CHARTS
     ========================================================= */
  const PALETTE = ['#818CF8', '#A78BFA', '#F472B6', '#34D399', '#FBBF24', '#38BDF8', '#FB7185', '#4ADE80', '#FACC15', '#22D3EE'];

  function emptyChart(msg) { return `<div class="grid place-items-center h-48 text-slate-500 text-sm">${esc(msg)}</div>`; }

  /* --- horizontal bar chart (with values) --- */
  function barChart(rows, key, val, fmtFn = fmt.money) {
    if (!rows || !rows.length) return emptyChart('No data in this period yet.');
    const max = Math.max(1, ...rows.map((r) => Number(r[val]) || 0));
    return `<div class="space-y-3">${rows.map((r, i) => {
      const pct = Math.max(2, (Number(r[val]) || 0) / max * 100);
      const cls = i % 4 === 1 ? 'o' : i % 4 === 2 ? 'g' : i % 4 === 3 ? 'b' : '';
      return `<div>
        <div class="flex justify-between text-sm gap-3 mb-1">
          <span class="truncate text-slate-300">${esc(r[key])}</span>
          <span class="font-semibold tabular-nums text-white">${fmtFn(r[val])}</span>
        </div>
        <div class="hbar" style="margin:0"><div class="track"><div class="fill ${cls}" style="--w:${pct}%"></div></div></div>
      </div>`;
    }).join('')}</div>`;
  }

  /* --- vertical bar chart --- */
  function vBarChart(rows, xKey, val, { height = 260, fmtY = fmt.compact, color = '#818CF8', color2 = '#A78BFA' } = {}) {
    if (!rows || !rows.length) return emptyChart('No data in this period yet.');
    const w = 720, h = height, padL = 46, padR = 12, padT = 16, padB = 32;
    const innerW = w - padL - padR, innerH = h - padT - padB;
    const max = Math.max(1, ...rows.map((r) => Number(r[val]) || 0)) * 1.15;
    const barW = Math.min(46, (innerW / rows.length) * 0.62);
    const step = innerW / rows.length;

    const grid = [0, 0.25, 0.5, 0.75, 1].map((f) => {
      const y = padT + innerH * (1 - f);
      return `<line x1="${padL}" x2="${w - padR}" y1="${y}" y2="${y}" stroke="#1F2A40" stroke-dasharray="2 4"/>` +
        `<text x="${padL - 8}" y="${y + 4}" text-anchor="end" fill="#64748B" font-size="10">${esc(fmtY(max * f))}</text>`;
    }).join('');

    const bars = rows.map((r, i) => {
      const v = Number(r[val]) || 0;
      const bh = (v / max) * innerH;
      const x = padL + step * i + (step - barW) / 2;
      const y = padT + innerH - bh;
      return `<g class="vbar" style="animation-delay:${i * 40}ms">
        <rect x="${x}" y="${y}" width="${barW}" height="${bh}" rx="6" fill="url(#vbg)" />
        <rect x="${x}" y="${y}" width="${barW}" height="3" rx="1.5" fill="${color2}" opacity=".9" />
      </g>
      <text x="${x + barW / 2}" y="${y - 6}" text-anchor="middle" fill="#E2E8F0" font-size="10" font-weight="700">${esc(fmtY(v))}</text>`;
    }).join('');

    const labels = rows.map((r, i) => `<text x="${padL + step * i + step / 2}" y="${h - 10}" text-anchor="middle" fill="#94A3B8" font-size="10">${esc(String(r[xKey]).slice(0, 12))}</text>`).join('');

    return `<svg viewBox="0 0 ${w} ${h}" class="chart-svg" preserveAspectRatio="xMidYMid meet">
      <defs><linearGradient id="vbg" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0" stop-color="${color2}"/><stop offset="1" stop-color="${color}" stop-opacity=".55"/>
      </linearGradient></defs>
      ${grid}${bars}${labels}
    </svg>`;
  }

  /* --- multi-series line / area chart --- */
  function lineChart(rows, xKey, series, { height = 280, fmtY = fmt.compact, area = true } = {}) {
    if (!rows || rows.length < 1) return emptyChart('No data in this period yet.');
    const w = 820, h = height, padL = 52, padR = 18, padT = 18, padB = 34;
    const innerW = w - padL - padR, innerH = h - padT - padB;
    const allVals = rows.flatMap((r) => series.map((s) => Number(r[s.key]) || 0));
    const max = Math.max(1, ...allVals) * 1.12;
    const stepX = rows.length > 1 ? innerW / (rows.length - 1) : innerW;
    const P = (i, v) => [padL + i * stepX, padT + innerH - (v / max) * innerH];

    const defs = series.map((s, i) => `<linearGradient id="lg-${i}" x1="0" x2="0" y1="0" y2="1">
      <stop offset="0" stop-color="${s.color}" stop-opacity="0.35"/>
      <stop offset="1" stop-color="${s.color}" stop-opacity="0"/>
    </linearGradient>`).join('');

    const grid = [0, 0.25, 0.5, 0.75, 1].map((f) => {
      const y = padT + innerH * (1 - f);
      return `<line x1="${padL}" x2="${w - padR}" y1="${y}" y2="${y}" stroke="#1F2A40" stroke-dasharray="2 4"/>
        <text x="${padL - 8}" y="${y + 4}" text-anchor="end" fill="#64748B" font-size="10">${esc(fmtY(max * f))}</text>`;
    }).join('');

    const seriesSvg = series.map((s, si) => {
      const pts = rows.map((r, i) => P(i, Number(r[s.key]) || 0));
      const line = 'M' + pts.map((p) => p.map((x) => x.toFixed(1)).join(',')).join(' L');
      const areaSvg = area
        ? `<path d="${line} L${pts[pts.length - 1][0].toFixed(1)},${padT + innerH} L${pts[0][0].toFixed(1)},${padT + innerH} Z" fill="url(#lg-${si})"/>`
        : '';
      const dots = pts.map((p) => `<circle cx="${p[0].toFixed(1)}" cy="${p[1].toFixed(1)}" r="3" fill="#0F1524" stroke="${s.color}" stroke-width="2"/>`).join('');
      return areaSvg + `<path class="draw" d="${line}" stroke="${s.color}" stroke-width="2.6" fill="none" stroke-linejoin="round" stroke-linecap="round"/>` + dots;
    }).join('');

    const labelStep = Math.max(1, Math.ceil(rows.length / 9));
    const xLabels = rows.map((r, i) => {
      if (i % labelStep !== 0 && i !== rows.length - 1) return '';
      const raw = String(r[xKey]);
      return `<text x="${P(i, 0)[0].toFixed(1)}" y="${h - 10}" text-anchor="middle" fill="#94A3B8" font-size="10">${esc(raw.length > 7 ? raw.slice(-5) : raw)}</text>`;
    }).join('');

    return `<svg viewBox="0 0 ${w} ${h}" class="chart-svg" preserveAspectRatio="xMidYMid meet">
      <defs>${defs}</defs>${grid}${seriesSvg}${xLabels}
    </svg>`;
  }

  /* --- donut chart --- */
  function donutChart(segments, { size = 220, thickness = 24, centerLabel = '', centerCaption = '' } = {}) {
    if (!segments || !segments.length) return emptyChart('No data.');
    const total = segments.reduce((a, s) => a + Math.max(0, Number(s.value) || 0), 0);
    if (total <= 0) return emptyChart('No data.');
    const r = (size / 2) - thickness / 2 - 2;
    const cx = size / 2, cy = size / 2;
    const circ = 2 * Math.PI * r;
    let offset = 0;
    const arcs = segments.map((s, i) => {
      const val = Math.max(0, Number(s.value) || 0);
      const frac = val / total;
      const dash = frac * circ;
      const color = s.color || PALETTE[i % PALETTE.length];
      const seg = `<circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="${color}"
        stroke-width="${thickness}" stroke-dasharray="${dash.toFixed(2)} ${(circ - dash).toFixed(2)}"
        stroke-dashoffset="${(-offset).toFixed(2)}" transform="rotate(-90 ${cx} ${cy})" stroke-linecap="butt" />`;
      offset += dash;
      return seg;
    }).join('');

    const legend = `<ul class="chart-legend mt-3">${segments.map((s, i) => {
      const color = s.color || PALETTE[i % PALETTE.length];
      const pct = total > 0 ? ((Number(s.value) || 0) / total * 100).toFixed(1) : '0.0';
      return `<li style="list-style:none"><span class="dot" style="background:${color}"></span>${esc(s.label)} <span class="text-slate-400">· ${pct}%</span></li>`;
    }).join('')}</ul>`;

    return `<div class="donut-wrap" style="max-width:${size}px;margin:0 auto">
      <svg viewBox="0 0 ${size} ${size}" class="chart-svg spin-in">${arcs}</svg>
      <div class="donut-center">
        ${centerLabel ? `<div><div class="big">${esc(centerLabel)}</div>${centerCaption ? `<div class="cap">${esc(centerCaption)}</div>` : ''}</div>` : ''}
      </div>
    </div>${legend}`;
  }

  /* --- sparkline (area) --- */
  function sparkline(rows, val) {
    if (!rows || rows.length < 2) return emptyChart('Not enough days to draw a trend yet.');
    const w = 600, h = 140, max = Math.max(1, ...rows.map((r) => Number(r[val]) || 0));
    const step = w / (rows.length - 1);
    const pts = rows.map((r, i) => `${(i * step).toFixed(1)},${(h - 12 - (Number(r[val]) / max) * (h - 30)).toFixed(1)}`);
    return `<svg viewBox="0 0 ${w} ${h}" class="w-full h-40" role="img" aria-label="Trend">
      <defs><linearGradient id="sg" x1="0" x2="0" y1="0" y2="1">
        <stop offset="0" stop-color="#818CF8" stop-opacity=".55"/><stop offset="1" stop-color="#818CF8" stop-opacity="0"/>
      </linearGradient></defs>
      <polygon fill="url(#sg)" points="0,${h} ${pts.join(' ')} ${w},${h}"/>
      <polyline class="draw" fill="none" stroke="#A5B4FC" stroke-width="2.6" stroke-linejoin="round" stroke-linecap="round" points="${pts.join(' ')}"/>
    </svg>
    <div class="flex justify-between text-xs text-slate-500 mt-1"><span>${esc(rows[0].day || '')}</span><span>${esc(rows[rows.length - 1].day || '')}</span></div>`;
  }

  /* --- kpi card helper (used by dashboard + reports) --- */
  function kpi(label, value, { icon = '', tone = '', delta = null, deltaDir = 'up', hero = false } = {}) {
    const cls = hero ? 'stat stat-hero' : 'stat';
    const iconSvg = icon ? `<div class="icon-badge ${tone}">${icon}</div>` : '';
    const deltaHtml = delta != null
      ? `<div class="delta ${deltaDir}">${deltaDir === 'up' ? '▲' : '▼'} ${esc(delta)}</div>`
      : '';
    return `<div class="${cls}">
      ${iconSvg}
      <div class="lbl">${esc(label)}</div>
      <div class="v mt-1 break-words">${value}</div>
      ${deltaHtml ? `<div class="mt-2">${deltaHtml}</div>` : ''}
    </div>`;
  }

  /* --- table --- */
  function table(cols, rows, empty = 'Nothing here yet.') {
    if (!rows.length) return `<p class="p-6 text-center text-slate-500">${esc(empty)}</p>`;
    return `<div class="tbl-wrap"><table class="tbl"><thead><tr>${cols.map((c) => `<th class="${c.num ? 'num' : ''}">${esc(c.label)}</th>`).join('')}</tr></thead><tbody>${rows.map((r) => `<tr>${cols.map((c) => `<td class="${c.num ? 'num' : ''}">${c.html ? c.html(r) : esc(c.get ? c.get(r) : r[c.k])}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
  }

  const download = (name, text, type = 'text/csv') => { const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([text], { type })); a.download = name; a.click(); URL.revokeObjectURL(a.href); };

  window.UI = { esc, fmt, badge, $, $$, toast, modal, confirmBox, options, clearOptCache, formHtml, collect, formModal, linesEditor, barChart, vBarChart, lineChart, donutChart, sparkline, kpi, table, download, PALETTE };
})();