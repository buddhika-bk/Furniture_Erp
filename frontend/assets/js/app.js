/* Application shell: sign-in, navigation, hash router, alerts. */
(function () {
  const { $, $$, esc, fmt, toast, modal, badge } = UI;
  const LOGIN = $('#login'), APP = $('#app'); const getView = () => $('#view');
  const find = (key) => SCHEMA.NAV.flatMap((g) => g.items).find((i) => i[0] === key);

  function buildNav() {
    $('#nav').innerHTML = SCHEMA.NAV.map((g) => {
      const items = g.items.filter((i) => API.can(i[2]));
      return items.length ? `<div><div class="nav-group-title">${esc(g.title)}</div>${items.map((i) => `<a class="nav-link" href="#/${i[0]}" data-key="${i[0]}">${esc(i[1])}</a>`).join('')}</div>` : '';
    }).join('');
  }
  const closeNav = () => { $('#sidebar').classList.add('-translate-x-full'); $('#scrim').classList.add('hidden'); };
  const openNav = () => { $('#sidebar').classList.remove('-translate-x-full'); $('#scrim').classList.remove('hidden'); };

  async function route() {
    const key = (location.hash.replace(/^#\//, '') || 'dashboard').split('?')[0];
    let item = find(key);
    if (!item || !API.can(item[2])) {
      const first = SCHEMA.NAV.flatMap((g) => g.items).find((i) => API.can(i[2]));
      if (first && first[0] !== key) { location.hash = '#/' + first[0]; return; }
      getView().innerHTML = '<div class="card card-pad text-slate-300">Your role does not have access to any screens yet. Ask an administrator.</div>'; return;
    }
    $('#pageTitle').textContent = item[1];
    document.title = item[1] + ' - Aurum ERP';
    $$('.nav-link').forEach((a) => a.classList.toggle('active', a.dataset.key === key));
    closeNav();
    const old = getView(); const clone = old.cloneNode(false); old.replaceWith(clone); clone.innerHTML = '<p class="text-slate-500">Loading...</p>';
    try {
      if (PAGES[key] && key !== 'listPage') await PAGES[key](clone);
      else if (SCHEMA.RES[key]) await PAGES.listPage(key, clone);
      window.scrollTo({ top: 0 });
    } catch (e) { clone.innerHTML = `<div class="card card-pad text-rose-400">${esc(e.message)}</div>`; }
  }

  async function refreshBell() {
    if (!API.can('dashboard.view')) return;
    try { const r = await API.get('/alerts', { per_page: 10 }); $('#bellDot').classList.toggle('hidden', !(r.data || []).some((a) => !a.read_at)); window.__alerts = r.data || []; } catch (e) { /* alerts are optional */ }
  }
  function showAlerts() {
    const rows = window.__alerts || [];
    const m = modal('Alerts', rows.length ? `<ul class="space-y-2">${rows.map((a) => `<li class="flex gap-3 items-start p-3 rounded-xl ${a.read_at ? '' : 'bg-brand-50/60 border border-indigo-500/20'}">${badge(a.level)}<div><div class="text-sm text-slate-200">${esc(a.message)}</div><div class="text-xs text-slate-500">${esc(fmt.dt(a.created_at))}</div></div></li>`).join('')}</ul><button class="btn btn-ghost mt-4" id="readAll">Mark all as read</button>` : '<p class="text-slate-400">No alerts. Low stock, overdue credit and payment reminders appear here.</p>');
    const b = $('#readAll', m); if (b) b.onclick = async () => { await API.post('/alerts/read'); m.close(); refreshBell(); };
  }

  function enter() {
    LOGIN.classList.add('hidden'); APP.classList.remove('hidden');
    $('#whoName').textContent = API.user.name;
    $('#whoRole').textContent = [API.user.role, API.user.branch].filter(Boolean).join(' · ');
    const init = $('#whoInitial'); if (init) init.textContent = (API.user.name || 'A').trim().charAt(0).toUpperCase();
    buildNav(); refreshBell(); route();
  }

  $('#loginForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const err = $('#loginError'); err.classList.add('hidden');
    const base = $('#apiBase').value.trim(); if (base) API.base = base;
    const btn = $('button[type=submit]', e.target); btn.disabled = true;
    try {
      const r = await API.post('/auth/login', { email: e.target.email.value, password: e.target.password.value });
      API.token = r.token; API.setUser(r.user); enter();
    } catch (ex) { err.textContent = ex.message; err.classList.remove('hidden'); }
    btn.disabled = false;
  });
  $('#logout').onclick = async () => { try { await API.post('/auth/logout'); } catch (e) { /* already signed out */ } API.token = null; API.setUser(null); location.hash = ''; location.reload(); };
  $('#openNav').onclick = openNav; $('#closeNav').onclick = closeNav; $('#scrim').onclick = closeNav;
  $('#bell').onclick = showAlerts;
  window.addEventListener('hashchange', () => { if (!APP.classList.contains('hidden')) route(); });
  $('#apiBase').value = localStorage.getItem('erp_api_base') || '';

  if (API.token && API.user) {
    API.get('/auth/me').then((r) => { API.setUser(r.user); enter(); }).catch(() => { API.token = null; API.setUser(null); });
  }
})();