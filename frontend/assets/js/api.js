/* Shared transport. Never automatically replay a write. */
(function () {
  const storage = sessionStorage;
  for (const key of ['erp_token', 'erp_user']) {
    if (!storage.getItem(key) && localStorage.getItem(key)) storage.setItem(key, localStorage.getItem(key));
    localStorage.removeItem(key);
  }
  function readUser() { try { return JSON.parse(storage.getItem('erp_user') || 'null'); } catch { return null; } }
  const API = {
    get base() { return (localStorage.getItem('erp_api_base') || window.ERP_CONFIG.API_BASE).replace(/\/$/, ''); },
    set base(value) {
      if (!value) return localStorage.removeItem('erp_api_base');
      const url = new URL(value, location.href);
      if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) throw new Error('Enter an HTTP or HTTPS API address.');
      if (location.protocol === 'https:' && url.protocol !== 'https:') throw new Error('Use an HTTPS API address.');
      localStorage.setItem('erp_api_base', url.href.replace(/\/$/, ''));
    },
    get token() { return storage.getItem('erp_token'); },
    set token(value) { value ? storage.setItem('erp_token', value) : storage.removeItem('erp_token'); },
    get branch() { return storage.getItem('erp_branch') || ''; },
    set branch(value) { value ? storage.setItem('erp_branch', value) : storage.removeItem('erp_branch'); },
    user: readUser(),
    setUser(user) { API.user = user; user ? storage.setItem('erp_user', JSON.stringify(user)) : storage.removeItem('erp_user'); },
    clear() { API.token = null; API.setUser(null); API.branch = ''; window.UI?.clearOptCache(); },
    can(permission) { const p = API.user?.permissions || []; return !permission || p.includes('*') || p.includes(permission) || p.includes(permission.split('.')[0] + '.*'); },
    async req(method, path, body, query, options = {}) {
      const url = new URL(API.base + path, location.href);
      const add = (key, value) => { if (value !== undefined && value !== null && value !== '') url.searchParams.append(key, value); };
      Object.entries(query || {}).forEach(([key, value]) => value && typeof value === 'object' ? Object.entries(value).forEach(([k,v]) => add(`${key}[${k}]`, v)) : add(key, value));
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), options.timeout || 30000);
      const abort = () => controller.abort();
      options.signal?.addEventListener('abort', abort, { once: true });
      if (options.signal?.aborted) controller.abort();
      try {
        const response = await fetch(url, {
          method, signal: controller.signal,
          headers: { Accept: 'application/json', ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}), ...(API.token ? { Authorization: 'Bearer ' + API.token } : {}), ...(API.branch ? { 'X-Branch-Id': API.branch } : {}), ...(options.key ? { 'Idempotency-Key': options.key } : {}) },
          body: body === undefined ? undefined : JSON.stringify(body),
        });
        const data = response.status === 204 ? null : await response.json().catch(() => ({ message: 'The server returned an unexpected response.' }));
        if (!response.ok) {
          const error = Object.assign(new Error(data?.message || 'The request could not be completed.'), { status: response.status, fields: data?.errors || {}, requestId: data?.request_id || response.headers.get('X-Request-Id'), retryAfter: response.headers.get('Retry-After') });
          if (response.status === 401 && path !== '/auth/login') { API.clear(); window.dispatchEvent(new CustomEvent('session-expired')); }
          error.uncertain = method !== 'GET' && response.status >= 500;
          throw error;
        }
        return data;
      } catch (error) {
        if (error.status) throw error;
        if (options.signal?.aborted) throw error;
        throw Object.assign(new Error(error.name === 'AbortError' ? 'The request timed out. Check a submitted transaction before trying again.' : 'Cannot reach the server. Check your connection and try again.'), { cause: error, uncertain: method !== 'GET', status: 0 });
      } finally { clearTimeout(timeout); options.signal?.removeEventListener('abort', abort); }
    },
    get: (p,q,o) => API.req('GET',p,undefined,q,o),
    post: (p,b={},o) => API.req('POST',p,b,undefined,o),
    put: (p,b,o) => API.req('PUT',p,b,undefined,o),
    patch: (p,b,o) => API.req('PATCH',p,b,undefined,o),
    del: p => API.req('DELETE',p),
  };
  window.API = API;
})();
