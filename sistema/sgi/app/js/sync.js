// Sincronização da fila local (IndexedDB) com o servidor.
// Idempotency-Key ⇒ nunca duplica; lock_version ⇒ nunca sobrescreve o trabalho de outra pessoa (conflito → resolução).
import { api, state, ApiError } from './api.js';
import { outbox } from './db.js';
import { uuid } from './ui.js';

const listeners = new Set();
export function onSyncChange(fn) { listeners.add(fn); }
async function notify() {
  const n = await pendingCount();
  for (const fn of listeners) { try { fn(n); } catch (e) { console.error(e); } }
}

export async function pendingCount() {
  if (!state.me || !state.me.user) return { pendente: 0, conflito: 0 };
  const list = await outbox.list(state.me.user.id);
  return { pendente: list.filter((x) => x.status === 'pendente' || x.status === 'erro').length, conflito: list.filter((x) => x.status === 'conflito').length };
}

/** Guarda localmente (pendente de sincronizar). */
export async function saveLocal(entry) {
  const now = new Date().toISOString();
  const item = Object.assign({
    id: uuid(),
    status: 'pendente',
    createdAt: now,
    attachments: [],
  }, entry, {
    userId: state.me.user.id,
    orgId: state.me.user.org_id,
    updatedAt: now,
    idemKey: 'sync-' + uuid(),   // nova chave por cada versão local
    status: entry.status === 'conflito' ? 'conflito' : 'pendente',
  });
  await outbox.put(item);
  notify();
  return item;
}

/** Envia um item da fila. Devolve o registo do servidor ou lança ApiError (409 = conflito). */
export async function pushItem(item) {
  let rec;
  if (!item.recordId) {
    rec = await api('POST', '/records', {
      template_id: item.templateId, title: item.title, data: item.data, client_uuid: item.id, geo: item.geo || undefined, asset_id: item.assetId || undefined,
    }, { idem: item.idemKey });
    if (rec.duplicado) {
      // O registo já tinha sido criado numa sincronização anterior (resposta perdida). Aplicar a versão local mais recente
      // apenas se ninguém o alterou desde então; caso contrário, é um conflito.
      if (rec.lock_version === 1) {
        rec = await api('PUT', '/records/' + rec.id, { data: item.data, title: item.title, lock_version: 1, geo: item.geo || undefined }, { idem: item.idemKey + '-u' });
      } else {
        throw new ApiError(409, 'conflito', 'O rascunho foi alterado no servidor.', { servidor: rec });
      }
    }
  } else {
    rec = await api('PUT', '/records/' + item.recordId, {
      data: item.data, title: item.title, lock_version: item.baseLockVersion, geo: item.geo || undefined,
    }, { idem: item.idemKey });
  }
  await uploadAttachments(rec.id, item.attachments || []);
  return rec;
}

/** Envia anexos guardados no dispositivo (fotografias/assinaturas captadas sem ligação). */
export async function uploadAttachments(recordId, atts) {
  for (const att of atts) {
    if (att.uploaded) continue;
    const fd = new FormData();
    fd.append('entity_type', 'record');
    fd.append('entity_id', String(recordId));
    fd.append('kind', att.kind);
    if (att.caption) fd.append('caption', att.caption);
    if (att.lat != null) { fd.append('lat', String(att.lat)); fd.append('lng', String(att.lng)); }
    fd.append('file', att.blob, att.name);
    await api('POST', '/attachments', fd, { idem: att.idemKey });
    att.uploaded = true;
  }
}

let running = null;
/** Processa toda a fila do utilizador atual. */
export function syncAll() {
  if (running) return running;
  running = (async () => {
    const res = { enviados: 0, conflitos: 0, erros: 0 };
    if (!state.me || !state.me.user || !state.online) return res;
    const list = await outbox.list(state.me.user.id);
    for (const item of list) {
      if (item.status !== 'pendente' && item.status !== 'erro') continue;
      try {
        await pushItem(item);
        await outbox.del(item.id);
        res.enviados++;
      } catch (e) {
        if (e instanceof ApiError && e.offline) break;
        if (e instanceof ApiError && e.status === 409 && e.code === 'conflito') {
          item.status = 'conflito';
          item.server = e.data.servidor || null;
          await outbox.put(item);
          res.conflitos++;
        } else if (e instanceof ApiError && (e.code === 'registo_final_bloqueado' || e.code === 'registo_em_aprovacao')) {
          item.status = 'conflito';
          item.error = e.message;
          item.serverLocked = true;
          await outbox.put(item);
          res.conflitos++;
        } else {
          item.status = 'erro';
          item.error = e.message;
          await outbox.put(item);
          res.erros++;
        }
      }
    }
    notify();
    return res;
  })().finally(() => { running = null; });
  return running;
}

export { notify as refreshSyncBadge };
