/* Thin REST client. The backend is the source of truth for every business rule. */
(function () {
  const store = window.localStorage;
  const API = {
    get base() { return (store.getItem('erp_api_base') || window.ERP_CONFIG.API_BASE).replace(/\/$/, ''); },
    set base(v) { v ? store.setItem('erp_api_base', v) : store.removeItem('erp_api_base'); },
    get token() { return store.getItem('erp_token'); },
    set token(v) { v ? store.setItem('erp_token', v) : store.removeItem('erp_token'); },
    user: JSON.parse(store.getItem('erp_user') || 'null'),
    setUser(u) { API.user = u; u ? store.setItem('erp_user', JSON.stringify(u)) : store.removeItem('erp_user'); },
    can(perm) {
      const p = (API.user && API.user.permissions) || [];
      if (!perm) return true;
      const mod = perm.split('.')[0];
      return p.includes('*') || p.includes(perm) || p.includes(mod + '.*');
    },
    async req(method, path, body, query) {
      let url = API.base + path;
      if (query) {
        const qs = new URLSearchParams();
        const add = (k, v) => { if (v !== undefined && v !== null && v !== '') qs.append(k, v); };
        Object.entries(query).forEach(([k, v]) => (v && typeof v === 'object') ? Object.entries(v).forEach(([a, b]) => add(`${k}[${a}]`, b)) : add(k, v));
        const s = qs.toString(); if (s) url += '?' + s;
      }
      let res;
      try {
        res = await fetch(url, { method, headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', ...(API.token ? { Authorization: 'Bearer ' + API.token } : {}) }, body: body ? JSON.stringify(body) : undefined });
      } catch (e) { throw new Error('Cannot reach the server at ' + API.base + '. Check that the backend is running.'); }
      const data = await res.json().catch(() => ({}));
      if (res.status === 401 && path !== '/auth/login') { API.token = null; API.setUser(null); location.reload(); }
      if (!res.ok) {
        let msg = data.message || 'Something went wrong.';
        if (data.errors) msg = Object.values(data.errors).flat().join(' ');
        const err = new Error(msg); err.status = res.status; throw err;
      }
      return data;
    },
    get: (p, q) => API.req('GET', p, null, q),
    post: (p, b) => API.req('POST', p, b || {}),
    put: (p, b) => API.req('PUT', p, b || {}),
    del: (p) => API.req('DELETE', p),
  };
  window.API = API;
})();
