// IndexedDB local: fila de rascunhos por sincronizar, documentos disponíveis offline, caches de listas e tokens.
// O único guardado OFICIAL é o MySQL do servidor; o que está aqui é sempre identificado como local.

const NOME = 'sgi';
const VERSAO = 1;
let dbp = null;

function open() {
  if (dbp) return dbp;
  dbp = new Promise((resolve, reject) => {
    const r = indexedDB.open(NOME, VERSAO);
    r.onupgradeneeded = () => {
      const d = r.result;
      if (!d.objectStoreNames.contains('kv')) d.createObjectStore('kv');
      if (!d.objectStoreNames.contains('outbox')) d.createObjectStore('outbox', { keyPath: 'id' });
      if (!d.objectStoreNames.contains('docs')) d.createObjectStore('docs', { keyPath: 'key' });
      if (!d.objectStoreNames.contains('versoes')) d.createObjectStore('versoes', { keyPath: 'key' });
      if (!d.objectStoreNames.contains('cache')) d.createObjectStore('cache', { keyPath: 'key' });
    };
    r.onsuccess = () => resolve(r.result);
    r.onerror = () => reject(r.error);
  });
  return dbp;
}

async function tx(store, mode, fn) {
  const d = await open();
  return new Promise((resolve, reject) => {
    const t = d.transaction(store, mode);
    const s = t.objectStore(store);
    let res;
    Promise.resolve(fn(s)).then((v) => { res = v; });
    t.oncomplete = () => resolve(res);
    t.onerror = () => reject(t.error);
    t.onabort = () => reject(t.error);
  });
}

function req(r) {
  return new Promise((resolve, reject) => { r.onsuccess = () => resolve(r.result); r.onerror = () => reject(r.error); });
}

export const idb = {
  async get(store, key) { return tx(store, 'readonly', (s) => req(s.get(key))); },
  async put(store, value, key) { return tx(store, 'readwrite', (s) => req(key === undefined ? s.put(value) : s.put(value, key))); },
  async del(store, key) { return tx(store, 'readwrite', (s) => req(s.delete(key))); },
  async all(store) { return tx(store, 'readonly', (s) => req(s.getAll())); },
  async clear(store) { return tx(store, 'readwrite', (s) => req(s.clear())); },
};

export const kv = {
  get: (k) => idb.get('kv', k),
  set: (k, v) => idb.put('kv', v, k),
  del: (k) => idb.del('kv', k),
};

// ---- Fila de sincronização (rascunhos) ----
export const outbox = {
  async list(userId) {
    const all = await idb.all('outbox');
    return all.filter((x) => x.userId === userId).sort((a, b) => (b.updatedAt || '').localeCompare(a.updatedAt || ''));
  },
  get: (id) => idb.get('outbox', id),
  put: (item) => idb.put('outbox', item),
  del: (id) => idb.del('outbox', id),
  async byRecord(recordId) {
    const all = await idb.all('outbox');
    return all.find((x) => x.recordId === recordId) || null;
  },
};

// ---- Documentos marcados como disponíveis offline ----
export const docsOffline = {
  key: (orgId, templateId) => `${orgId}:${templateId}`,
  async list(orgId) { return (await idb.all('docs')).filter((d) => d.orgId === orgId); },
  async get(orgId, templateId) { return idb.get('docs', `${orgId}:${templateId}`); },
  async put(d) { d.key = `${d.orgId}:${d.templateId}`; return idb.put('docs', d); },
  async del(orgId, templateId) { return idb.del('docs', `${orgId}:${templateId}`); },
};

// ---- Cópias internas de versões de modelos (para continuar rascunhos sem ligação) ----
export const versoes = {
  async get(orgId, versionId) { return idb.get('versoes', `${orgId}:${versionId}`); },
  async put(orgId, v) { return idb.put('versoes', Object.assign({ key: `${orgId}:${v.version_id}`, orgId }, v)); },
};

export const cache = {
  async get(key) { const r = await idb.get('cache', key); return r ? r.value : null; },
  async getEntry(key) { return idb.get('cache', key); },
  async set(key, value) { return idb.put('cache', { key, value, at: new Date().toISOString() }); },
};

export async function limparDadosLocais({ manterFila = false } = {}) {
  await idb.clear('docs');
  await idb.clear('versoes');
  await idb.clear('cache');
  if (!manterFila) await idb.clear('outbox');
}
