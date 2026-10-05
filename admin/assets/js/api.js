/* AH5 Office — API client
   Designed & Developed by Anwar Hossain — https://anwar.com.bd */

const BASE = (() => {
  // /admin/ -> project root -> /api/v1
  const path = window.location.pathname;
  const root = path.replace(/\/admin\/?.*$/, '');
  return root + '/api/v1';
})();

const store = {
  get access()  { return localStorage.getItem('ah5_access'); },
  get refresh() { return localStorage.getItem('ah5_refresh'); },
  get user()    { try { return JSON.parse(localStorage.getItem('ah5_user') || 'null'); } catch { return null; } },
  save(tokens, user) {
    if (tokens) {
      localStorage.setItem('ah5_access', tokens.access_token);
      localStorage.setItem('ah5_refresh', tokens.refresh_token);
    }
    if (user) localStorage.setItem('ah5_user', JSON.stringify(user));
  },
  clear() {
    ['ah5_access', 'ah5_refresh', 'ah5_user'].forEach(k => localStorage.removeItem(k));
  }
};

let refreshing = null;

async function raw(method, path, body, retry = true) {
  const headers = { 'Accept': 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (store.access) headers['Authorization'] = 'Bearer ' + store.access;

  let res;
  try {
    res = await fetch(BASE + path, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body)
    });
  } catch {
    throw new ApiError('Cannot reach the server. Check your connection.', 0);
  }

  if (res.status === 401 && retry && store.refresh) {
    const ok = await refreshTokens();
    if (ok) return raw(method, path, body, false);
    store.clear();
    window.dispatchEvent(new CustomEvent('ah5:signed-out'));
    throw new ApiError('Session expired. Sign in again.', 401);
  }

  let data = null;
  try { data = await res.json(); } catch { /* non-JSON body */ }

  if (!res.ok || !data || data.success === false) {
    throw new ApiError(
      (data && data.message) || 'Request failed (' + res.status + ')',
      res.status,
      (data && data.errors) || null
    );
  }
  return data;
}

async function refreshTokens() {
  if (refreshing) return refreshing;
  refreshing = (async () => {
    try {
      const res = await fetch(BASE + '/auth/refresh', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ refresh_token: store.refresh })
      });
      const data = await res.json();
      if (!res.ok || !data.success) return false;
      store.save(data.data.tokens, null);
      return true;
    } catch {
      return false;
    } finally {
      refreshing = null;
    }
  })();
  return refreshing;
}

export class ApiError extends Error {
  constructor(message, status, errors) {
    super(message);
    this.status = status;
    this.errors = errors;
  }
  /** "Name is required, Amount must be a number" */
  get detail() {
    if (!this.errors) return this.message;
    return Object.values(this.errors).flat().join(', ');
  }
}

function qs(params = {}) {
  const parts = Object.entries(params)
    .filter(([, v]) => v !== undefined && v !== null && v !== '')
    .map(([k, v]) => encodeURIComponent(k) + '=' + encodeURIComponent(v));
  return parts.length ? '?' + parts.join('&') : '';
}

export const api = {
  base: BASE,
  store,
  get:    (p, params)  => raw('GET', p + qs(params)),
  post:   (p, body)    => raw('POST', p, body ?? {}),
  patch:  (p, body)    => raw('PATCH', p, body ?? {}),
  del:    (p)          => raw('DELETE', p),

  async login(email, password) {
    const res = await fetch(BASE + '/auth/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        email, password,
        platform: 'web',
        device_name: navigator.userAgent.slice(0, 90)
      })
    });
    const data = await res.json().catch(() => null);
    if (!res.ok || !data || !data.success) {
      throw new ApiError((data && data.message) || 'Sign in failed', res.status);
    }
    store.save(data.data.tokens, data.data.user);
    return data.data.user;
  },

  async logout() {
    try { await raw('POST', '/auth/logout', { refresh_token: store.refresh }, false); } catch { /* ignore */ }
    store.clear();
  }
};
