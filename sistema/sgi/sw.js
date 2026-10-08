/* Service worker do SGI (PWA).
 * - Casca da aplicação e ficheiros estáticos em cache → a aplicação abre sem ligação.
 * - A API NUNCA é guardada em cache pelo service worker: os dados oficiais vêm sempre do servidor (MySQL);
 *   os rascunhos sem ligação ficam na fila local (IndexedDB) gerida pela aplicação.
 * - Push "sem conteúdo": ao receber o toque, vai buscar a notificação mais recente à API.
 */
const VERSAO = 'sgi-1.1.0-20261008';
const ESCOPO = new URL(self.registration ? self.registration.scope : './', self.location).pathname; // ex.: /sgi/
const SHELL = [
  '', 'quadro.php', 'manifest.php',
  'app/css/app.css',
  'app/js/main.js', 'app/js/api.js', 'app/js/db.js', 'app/js/ui.js', 'app/js/sync.js', 'app/js/form-host.js', 'app/js/mobile.js',
  'app/js/bridge.js', 'app/js/views.js', 'app/js/views-gestao.js', 'app/js/views-admin.js',
  'app/js/vendor/qrcode.js', 'app/js/vendor/jsQR.js',
  'app/img/icone-sgi.svg', 'app/img/icone-192.png', 'app/img/icone-512.png',
].map((p) => ESCOPO + p);

self.addEventListener('install', (ev) => {
  ev.waitUntil((async () => {
    const c = await caches.open(VERSAO);
    // cache: 'reload' evita copiar respostas antigas da cache HTTP
    await Promise.all(SHELL.map(async (u) => {
      try {
        const r = await fetch(u, { cache: 'reload', credentials: 'same-origin' });
        if (r.ok) await c.put(u, r);
      } catch (e) { /* sem ligação durante a instalação: tenta no próximo arranque */ }
    }));
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', (ev) => {
  ev.waitUntil((async () => {
    for (const k of await caches.keys()) if (k !== VERSAO && k.startsWith('sgi-')) await caches.delete(k);
    await self.clients.claim();
  })());
});

self.addEventListener('fetch', (ev) => {
  const req = ev.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin || !url.pathname.startsWith(ESCOPO)) return;
  const rel = url.pathname.slice(ESCOPO.length);
  // API, assistentes, cron e ícones dinâmicos: sempre rede
  if (rel.startsWith('api.php') || rel.startsWith('instalar') || rel.startsWith('atualizar') || rel.startsWith('cron.php')) return;

  // Navegação: rede primeiro, casca em cache se não houver ligação
  if (req.mode === 'navigate' && (rel === '' || rel === 'index.php')) {
    ev.respondWith((async () => {
      try {
        const r = await fetch(req);
        if (r.ok) { const c = await caches.open(VERSAO); c.put(ESCOPO, r.clone()); }
        return r;
      } catch (e) {
        const c = await caches.match(ESCOPO);
        return c || new Response('<h1>Sem ligação</h1><p>Abra o SGI uma vez com ligação para o usar sem internet.</p>', { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
      }
    })());
    return;
  }
  if (rel === 'quadro.php') {
    ev.respondWith((async () => (await caches.match(ESCOPO + 'quadro.php')) || fetch(req))());
    return;
  }
  // Ficheiros estáticos: cache e atualização em segundo plano
  if (rel.startsWith('app/') || rel === 'manifest.php' || rel.startsWith('icone.php')) {
    ev.respondWith((async () => {
      const c = await caches.open(VERSAO);
      const hit = await c.match(req, { ignoreSearch: true });
      const net = fetch(req).then((r) => { if (r.ok) c.put(ESCOPO + rel.split('?')[0], r.clone()); return r; }).catch(() => null);
      return hit || (await net) || new Response('', { status: 504 });
    })());
  }
});

// ---- Push ----
async function tokenAcesso() {
  return new Promise((resolve) => {
    const r = indexedDB.open('sgi');
    r.onsuccess = () => {
      try {
        const t = r.result.transaction('kv', 'readonly').objectStore('kv').get('tokens');
        t.onsuccess = () => resolve(t.result && t.result.access && t.result.accessExp > Date.now() ? t.result.access : null);
        t.onerror = () => resolve(null);
      } catch (e) { resolve(null); }
    };
    r.onerror = () => resolve(null);
  });
}

self.addEventListener('push', (ev) => {
  ev.waitUntil((async () => {
    let title = 'SGI';
    let body = 'Tem novas notificações no SGI.';
    let link = '#/notificacoes';
    try {
      const tok = await tokenAcesso();
      const r = await fetch(ESCOPO + 'api.php/notifications', { credentials: 'same-origin', headers: tok ? { Authorization: 'Bearer ' + tok } : {} });
      if (r.ok) {
        const d = await r.json();
        const n = (d.notifications || []).find((x) => !x.read_at);
        if (n) { title = n.title; body = n.body || ''; link = n.link || link; }
      }
    } catch (e) { /* mostra aviso genérico */ }
    await self.registration.showNotification(title, { body, icon: ESCOPO + 'app/img/icone-192.png', badge: ESCOPO + 'app/img/icone-192.png', data: { link }, tag: 'sgi' });
  })());
});

self.addEventListener('notificationclick', (ev) => {
  ev.notification.close();
  const alvo = ESCOPO + (ev.notification.data && ev.notification.data.link ? ev.notification.data.link : '#/notificacoes');
  ev.waitUntil((async () => {
    const all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const c of all) { if (c.url.startsWith(self.location.origin + ESCOPO)) { await c.focus(); c.navigate(alvo); return; } }
    await self.clients.openWindow(alvo);
  })());
});
