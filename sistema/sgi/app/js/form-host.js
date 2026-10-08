// Mostra um modelo HTML na moldura isolada (quadro.php) e comunica com a ponte por postMessage.
import { BASE } from './api.js';
import { el } from './ui.js';

let bridgeSrc = null;
async function bridgeCode() {
  if (!bridgeSrc) {
    const r = await fetch(BASE + '/app/js/bridge.js?v=' + (document.documentElement.dataset.version || ''), { cache: 'no-cache' });
    bridgeSrc = await r.text();
  }
  return bridgeSrc;
}

function nonce() {
  return [...crypto.getRandomValues(new Uint8Array(16))].map((b) => b.toString(16).padStart(2, '0')).join('');
}

/**
 * @param {HTMLElement} container
 * @param {{html:string, mode:'edit'|'view', data?:object, onChange?:Function, onLink?:Function}} opts
 */
export async function hostForm(container, opts) {
  const n = nonce();
  const origin = location.origin;
  const cfg = { nonce: n, origin, mode: opts.mode, data: opts.data || null };
  const code = (await bridgeCode()).replace(/<\/script/gi, '<\\/script');
  const cfgJson = JSON.stringify(cfg).replace(/</g, '\\u003c');
  const inject = `<script data-sgi-ui="1">window.__SGI_CFG__=${cfgJson};\n${code}<\/script>`;
  let html = String(opts.html || '');
  // a ponte tem de correr antes dos scripts do modelo
  if (/<head[^>]*>/i.test(html)) html = html.replace(/<head[^>]*>/i, (m) => m + inject);
  else if (/<html[^>]*>/i.test(html)) html = html.replace(/<html[^>]*>/i, (m) => m + '<head>' + inject + '</head>');
  else html = inject + html;

  const frame = el('iframe', {
    class: 'quadro-modelo',
    title: opts.title || 'Documento',
    sandbox: 'allow-scripts allow-modals allow-popups allow-popups-to-escape-sandbox allow-downloads',
    src: BASE + '/quadro.php?n=' + n.slice(0, 8),
    referrerpolicy: 'no-referrer',
  });
  container.appendChild(frame);

  const pending = new Map();
  let readyResolve;
  const ready = new Promise((r) => { readyResolve = r; });
  let seq = 0;

  function onMsg(ev) {
    if (ev.source !== frame.contentWindow) return;
    const m = ev.data || {};
    if (m.t === 'quadro-pronto') {
      frame.contentWindow.postMessage({ t: 'carregar', html }, '*');
      return;
    }
    if (m.nonce !== n) return;
    if (m.t === 'ready') readyResolve(m);
    else if (m.t === 'changed') opts.onChange && opts.onChange();
    else if (m.t === 'link') opts.onLink && opts.onLink(m.file);
    else if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); }
  }
  window.addEventListener('message', onMsg);

  function call(t, extra = {}, timeout = 20000) {
    return new Promise((resolve, reject) => {
      const id = 'c' + (++seq);
      pending.set(id, resolve);
      frame.contentWindow.postMessage(Object.assign({ t, id, nonce: n }, extra), '*');
      setTimeout(() => { if (pending.has(id)) { pending.delete(id); reject(new Error('O documento não respondeu.')); } }, timeout);
    });
  }

  // se o ecrã mudar entretanto (moldura removida), cancela de imediato em vez de esperar
  let vigia;
  let limite;
  const cancelado = new Promise((_, rej) => { vigia = setInterval(() => { if (!frame.isConnected) rej(Object.assign(new Error('Documento fechado.'), { cancelado: true })); }, 300); });
  const demorado = new Promise((_, rej) => { limite = setTimeout(() => rej(new Error('O documento demorou demasiado a carregar.')), 25000); });
  let info;
  try {
    info = await Promise.race([ready, cancelado, demorado]);
  } catch (e) {
    window.removeEventListener('message', onMsg);
    throw e;
  } finally {
    clearInterval(vigia);
    clearTimeout(limite);
  }
  return {
    frame,
    info,
    collect: async () => (await call('collect')).data,
    snapshot: async () => { const r = await call('snapshot'); return { html: r.html, data: r.data }; },
    mark: (keys) => frame.contentWindow.postMessage({ t: 'mark', keys, nonce: n }, '*'),
    print: () => frame.contentWindow.postMessage({ t: 'print', nonce: n }, '*'),
    fillTest: async (seed) => (await call('fill-test', { seed })).data,
    destroy: () => { window.removeEventListener('message', onMsg); frame.remove(); },
  };
}
