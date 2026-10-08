// Cliente da API: sessão + CSRF no navegador; tokens de curta duração + renovação na aplicação instalada.
import { kv } from './db.js';

export const BASE = document.documentElement.dataset.base || '';
export const API = BASE + '/api.php';

export class ApiError extends Error {
  constructor(status, code, message, data = {}) {
    super(message);
    this.status = status;
    this.code = code;
    this.data = data;
    this.offline = status === 0;
  }
}

export const state = {
  mode: null,          // 'sessao' | 'token'
  csrf: null,
  me: null,            // {user, org, ...}
  access: null,
  accessExp: 0,
  refresh: null,
  deviceId: null,
  online: navigator.onLine,
};

const listeners = new Set();
export function onAuthChange(fn) { listeners.add(fn); }
function emit(ev) { for (const fn of listeners) { try { fn(ev); } catch (e) { console.error(e); } } }

/** A aplicação instalada (ou Capacitor) usa tokens; o navegador usa sessão. */
export function preferredMode() {
  const standalone = window.matchMedia && window.matchMedia('(display-mode: standalone)').matches;
  if (standalone || navigator.standalone || window.Capacitor) return 'token';
  try { if (localStorage.getItem('sgi_modo') === 'token') return 'token'; } catch (e) {}
  return 'sessao';
}

export async function loadTokens() {
  const t = await kv.get('tokens');
  if (t) {
    state.access = t.access; state.accessExp = t.accessExp; state.refresh = t.refresh; state.deviceId = t.deviceId;
  }
  return !!(t && t.refresh);
}

async function saveTokens(res) {
  state.access = res.access_token;
  state.accessExp = Date.now() + (res.expires_in - 20) * 1000;
  if (res.refresh_token) state.refresh = res.refresh_token;
  if (res.device_id) state.deviceId = res.device_id;
  await kv.set('tokens', { access: state.access, accessExp: state.accessExp, refresh: state.refresh, deviceId: state.deviceId });
}

export async function clearTokens() {
  state.access = null; state.refresh = null; state.accessExp = 0; state.deviceId = null;
  await kv.del('tokens');
}

let refreshing = null;
async function refreshTokens() {
  if (!state.refresh) throw new ApiError(401, 'refresh_invalido', 'Sessão da aplicação terminada.');
  if (!refreshing) {
    refreshing = (async () => {
      const r = await rawFetch('POST', '/auth/refresh', { refresh_token: state.refresh }, { noAuth: true });
      await saveTokens(r);
      return r;
    })().finally(() => { refreshing = null; });
  }
  return refreshing;
}

async function rawFetch(method, path, body, opts = {}) {
  const headers = { Accept: 'application/json' };
  let payload;
  if (body instanceof FormData) payload = body;
  else if (body !== undefined && body !== null) { headers['Content-Type'] = 'application/json'; payload = JSON.stringify(body); }
  if (!opts.noAuth) {
    if (state.mode === 'token' && state.access) headers.Authorization = 'Bearer ' + state.access;
    if (state.mode === 'sessao' && state.csrf && method !== 'GET') headers['X-CSRF-Token'] = state.csrf;
  }
  if (opts.idem) headers['Idempotency-Key'] = opts.idem;
  let res;
  try {
    res = await fetch(API + path, { method, headers, body: payload, credentials: 'same-origin', cache: 'no-store' });
  } catch (e) {
    setOnline(false);
    throw new ApiError(0, 'sem_ligacao', 'Sem ligação ao servidor.');
  }
  setOnline(true);
  if (opts.raw && res.ok) return res;
  let data = null;
  const ct = res.headers.get('Content-Type') || '';
  if (ct.includes('application/json')) data = await res.json().catch(() => null);
  if (!res.ok) {
    const d = data || {};
    throw new ApiError(res.status, d.code || 'erro', d.error || `Erro ${res.status}`, d);
  }
  return data;
}

/** Pedido à API com renovação automática do token e repetição única em caso de CSRF expirado. */
export async function api(method, path, body, opts = {}) {
  if (state.mode === 'token' && state.refresh && (!state.access || Date.now() > state.accessExp)) {
    try { await refreshTokens(); } catch (e) { if (!e.offline) { await lostAuth(); } throw e; }
  }
  try {
    return await rawFetch(method, path, body, opts);
  } catch (e) {
    if (e.status === 401 && state.mode === 'token' && state.refresh && !opts._retried) {
      try { await refreshTokens(); } catch (e2) { if (!e2.offline) await lostAuth(); throw e2; }
      return api(method, path, body, Object.assign({}, opts, { _retried: true }));
    }
    if (e.status === 403 && e.code === 'csrf' && !opts._retried) {
      await whoami();
      return api(method, path, body, Object.assign({}, opts, { _retried: true }));
    }
    if (e.status === 401 && !opts.public) {
      await lostAuth();
    }
    throw e;
  }
}

async function lostAuth() {
  if (state.mode === 'token') await clearTokens();
  state.me = null;
  emit('saiu');
}

export async function whoami() {
  const r = await rawFetch('GET', '/auth/me');
  state.me = r && r.user ? r : null;
  if (r && r.csrf) state.csrf = r.csrf;
  return r;
}

export async function login(email, password, mode) {
  state.mode = mode;
  if (mode === 'token') {
    const name = deviceName();
    const r = await rawFetch('POST', '/auth/token', { email, password, device_name: name }, { noAuth: true });
    await saveTokens(r);
  } else {
    const r = await rawFetch('POST', '/auth/login', { email, password }, { noAuth: true });
    state.csrf = r.csrf;
  }
  try { localStorage.setItem('sgi_modo', mode); } catch (e) {}
  await whoami();
  emit('entrou');
  return state.me;
}

export async function logout() {
  try {
    if (state.mode === 'token') await api('POST', '/auth/token/revoke');
    else await api('POST', '/auth/logout');
  } catch (e) { /* sem ligação: termina localmente */ }
  await clearTokens();
  state.me = null;
  state.csrf = null;
  emit('saiu');
}

export function deviceName() {
  const ua = navigator.userAgent;
  let so = /Android/i.test(ua) ? 'Android' : /iPhone|iPad|iPod/i.test(ua) ? 'iPhone/iPad' : /Windows/i.test(ua) ? 'Windows' : /Mac OS/i.test(ua) ? 'Mac' : /Linux/i.test(ua) ? 'Linux' : 'Dispositivo';
  const nav = /Edg\//.test(ua) ? 'Edge' : /Chrome\//.test(ua) ? 'Chrome' : /Firefox\//.test(ua) ? 'Firefox' : /Safari\//.test(ua) ? 'Safari' : '';
  return `${window.Capacitor ? 'App' : 'PWA'} · ${so}${nav ? ' · ' + nav : ''}`;
}

/** Descarrega um recurso protegido (com sessão ou token) como Blob. */
export async function blob(path) {
  const doFetch = async () => {
    const headers = {};
    if (state.mode === 'token' && state.access) headers.Authorization = 'Bearer ' + state.access;
    return fetch(API + path, { headers, credentials: 'same-origin', cache: 'no-store' });
  };
  if (state.mode === 'token' && state.refresh && Date.now() > state.accessExp) await refreshTokens();
  let res;
  try { res = await doFetch(); } catch (e) { setOnline(false); throw new ApiError(0, 'sem_ligacao', 'Sem ligação ao servidor.'); }
  if (res.status === 401 && state.mode === 'token') { await refreshTokens(); res = await doFetch(); }
  if (!res.ok) {
    const d = await res.json().catch(() => ({}));
    throw new ApiError(res.status, d.code || 'erro', d.error || 'Erro ' + res.status, d);
  }
  return res.blob();
}

// ---- Estado da ligação ----
const onlineListeners = new Set();
export function onOnlineChange(fn) { onlineListeners.add(fn); }
export function setOnline(v) {
  if (state.online === v) return;
  state.online = v;
  for (const fn of onlineListeners) { try { fn(v); } catch (e) { console.error(e); } }
}
window.addEventListener('online', () => { ping(); });
window.addEventListener('offline', () => setOnline(false));

export async function ping() {
  try {
    const r = await fetch(API + '/auth/me', { credentials: 'same-origin', cache: 'no-store', headers: state.mode === 'token' && state.access ? { Authorization: 'Bearer ' + state.access } : {} });
    setOnline(r.status < 500 || r.status === 503);
    return true;
  } catch (e) {
    setOnline(false);
    return false;
  }
}

export function can(perm) {
  return !!(state.me && state.me.user && state.me.user.perms.includes(perm));
}
