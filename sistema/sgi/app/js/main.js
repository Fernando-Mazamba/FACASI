// Arranque da aplicação: sessão/tokens, rotas, menu, estado da ligação e sincronização.
import { api, state, whoami, login, logout, preferredMode, loadTokens, onAuthChange, onOnlineChange, ping, can, BASE, ApiError } from './api.js';
import { el, mount, toast, clear, modal } from './ui.js';
import { syncAll, onSyncChange, pendingCount, refreshSyncBadge } from './sync.js';
import * as V from './views.js';
import * as G from './views-gestao.js';
import * as A from './views-admin.js';

const ROUTES = [
  ['inicio', V.inicio],
  ['documentos', V.documentos],
  ['documentos/:id', V.documento],
  ['registos', V.registos],
  ['registos/novo/:tpl', V.novoRegisto],
  ['registos/local/:uuid', V.registoLocal],
  ['registos/:id', V.registo],
  ['arquivo', V.arquivo],
  ['aprovacoes', V.aprovacoes],
  ['sincronizar', V.sincronizar],
  ['notificacoes', V.notificacoes],
  ['qr', V.leitorQr],
  ['qr/:token', V.abrirQr],
  ['perfil', V.perfil],
  ['instalar', V.instalarApp],
  ['redefinir/:token', V.redefinir],
  ['nc', G.ncs],
  ['acoes', G.acoes],
  ['acoes/:id', G.acoes],
  ['calendario', G.calendario],
  ['equipamentos', G.equipamentos],
  ['equipamentos/:id', G.equipamento],
  ['etiquetas', G.etiquetas],
  ['catalogos/:tipo', G.catalogo],
  ['capacitacoes', G.capacitacoes],
  ['indicadores', G.indicadores],
  ['relatorios', G.relatorios],
  ['importar', G.importar],
  ['utilizadores', A.utilizadores],
  ['organizacao', A.organizacao],
  ['auditoria', A.auditoria],
  ['plataforma', A.plataforma],
  ['plataforma/:sec', A.plataforma],
];

function matchRoute(hash) {
  const path = hash.replace(/^#\/?/, '').split('?')[0];
  const query = new URLSearchParams(hash.split('?')[1] || '');
  const parts = path.split('/').filter(Boolean);
  for (const [pat, fn] of ROUTES) {
    const pp = pat.split('/');
    if (pp.length !== parts.length) continue;
    const params = {};
    let ok = true;
    for (let i = 0; i < pp.length; i++) {
      if (pp[i].startsWith(':')) params[pp[i].slice(1)] = decodeURIComponent(parts[i]);
      else if (pp[i] !== parts[i]) { ok = false; break; }
    }
    if (ok) return { fn, params, query, path };
  }
  return null;
}

let rendering = 0;
async function render() {
  const my = ++rendering;
  const hash = location.hash || '#/inicio';
  if (hash.startsWith('#/redefinir/')) {
    const m = matchRoute(hash);
    return m.fn(m.params, m.query);
  }
  if (!state.me || !state.me.user) {
    // Leitura de QR antes de iniciar sessão: guardar destino
    if (hash.startsWith('#/qr/')) sessionStorage.setItem('sgi_destino', hash);
    return ecraLogin();
  }
  if (state.me.user.must_change_password && hash !== '#/perfil') {
    location.hash = '#/perfil';
    toast('Defina uma nova palavra-passe antes de continuar.', 'aviso');
    return;
  }
  if (can('platform') && (hash === '#/inicio' || hash === '#/')) { history.replaceState(null, '', '#/plataforma'); return render(); }
  const m = matchRoute(hash);
  menuAtivo(hash);
  if (!m) { mount(el('h1', null, 'Página não encontrada'), el('a', { href: '#/inicio', class: 'btn' }, 'Voltar ao início')); return; }
  try {
    await m.fn(m.params, m.query);
  } catch (e) {
    if (my !== rendering) return;
    console.error(e);
    if (e instanceof ApiError && e.status === 503 && e.code === 'atualizacao_pendente') {
      mount(el('div', { class: 'cartao aviso' }, el('h1', null, 'Sistema em atualização'), el('p', null, e.message),
        can('platform') ? el('a', { class: 'btn', href: BASE + '/atualizar/' }, 'Abrir assistente de atualização') : null));
      return;
    }
    mount(el('div', { class: 'cartao erro' }, el('h1', null, e.offline ? 'Sem ligação' : 'Não foi possível abrir esta página'),
      el('p', null, e.offline ? 'Esta página precisa de ligação ao servidor. Os documentos marcados como disponíveis offline e os rascunhos locais continuam acessíveis.' : e.message),
      el('div', { class: 'acoes' }, el('a', { class: 'btn', href: '#/inicio' }, 'Início'), e.offline ? el('a', { class: 'btn sec', href: '#/documentos?offline=1' }, 'Documentos offline') : null)));
  }
}

function ecraLogin() {
  document.getElementById('topo').hidden = true;
  document.getElementById('menu').hidden = true;
  document.getElementById('barra-inferior').hidden = true;
  const modo = preferredMode();
  const email = el('input', { type: 'email', name: 'email', autocomplete: 'username', required: true, inputmode: 'email' });
  const pw = el('input', { type: 'password', name: 'password', autocomplete: 'current-password', required: true });
  const msg = el('p', { class: 'erro-campo', role: 'alert', hidden: true });
  const btn = el('button', { class: 'btn largo', type: 'submit' }, 'Entrar');
  const form = el('form', { class: 'login', onsubmit: async (ev) => {
    ev.preventDefault();
    msg.hidden = true;
    btn.disabled = true;
    btn.textContent = 'A entrar…';
    try {
      await login(email.value, pw.value, modo);
      const dest = sessionStorage.getItem('sgi_destino') || (can('platform') ? '#/plataforma' : '#/inicio');
      sessionStorage.removeItem('sgi_destino');
      // define o destino ANTES de mostrar o menu (sem disparar hashchange), depois desenha uma única vez
      history.replaceState(null, '', dest);
      await arrancar();
      render();
    } catch (e) {
      msg.textContent = e.offline ? 'Sem ligação ao servidor. Para iniciar sessão é necessária ligação.' : e.message;
      msg.hidden = false;
    } finally {
      btn.disabled = false;
      btn.textContent = 'Entrar';
    }
  } },
  el('img', { src: BASE + '/app/img/icone-sgi.svg', alt: '', width: 64, height: 64, class: 'login-logo' }),
  el('h1', null, 'Sistema de Gestão Integrado'),
  el('p', { class: 'sub' }, 'ISO 9001 · ISO 14001 · ISO 45001'),
  el('label', { class: 'campo' }, el('span', null, 'Correio eletrónico'), email),
  el('label', { class: 'campo' }, el('span', null, 'Palavra-passe'), pw),
  msg, btn,
  el('p', { class: 'nota' }, modo === 'token' ? 'Aplicação instalada: a sessão usa um token deste dispositivo, que pode encerrar em Perfil → Dispositivos.' : 'Sessão do navegador.'),
  el('a', { href: '#', class: 'link', onclick: async (ev) => { ev.preventDefault(); await V.esqueciPalavraPasse(email.value); } }, 'Esqueci-me da palavra-passe'),
  el('a', { href: '#/instalar', class: 'link', onclick: (ev) => { ev.preventDefault(); V.instrucoesInstalacao(); } }, 'Instalar a aplicação no telemóvel'));
  mount(form);
  email.focus();
}

function itensMenu() {
  const u = state.me.user;
  const i = [];
  const add = (href, label, icon, ok = true) => { if (ok) i.push({ href, label, icon }); };
  if (can('platform')) {
    add('#/plataforma', 'Plataforma', '🛠');
    add('#/plataforma/organizacoes', 'Organizações', '🏢');
    add('#/plataforma/biblioteca', 'Biblioteca de modelos', '📚');
    add('#/plataforma/copias', 'Cópias de segurança', '💾');
    add('#/plataforma/correio', 'Correio e notificações', '✉');
    add('#/plataforma/estado', 'Estado do sistema', '📈');
    add('#/perfil', 'Perfil e dispositivos', '👤');
    return i;
  }
  add('#/inicio', 'Início', '🏠');
  add('#/documentos', 'Documentos', '📄');
  add('#/registos', 'Registos', '🗂');
  add('#/arquivo', 'Arquivo', '🗄');
  add('#/aprovacoes', 'Aprovações', '✅', can('approvals.decide'));
  add('#/qr', 'Ler QR', '▣');
  add('#/calendario', 'Calendário', '📅');
  add('#/nc', 'Não conformidades', '⚠');
  add('#/acoes', 'Ações', '🎯');
  add('#/equipamentos', 'Equipamentos e QR', '🧯');
  add('#/capacitacoes', 'Capacitações', '🎓');
  add('#/catalogos/trabalhadores', 'Trabalhadores', '👷');
  add('#/catalogos/cargos', 'Cargos', '🪪');
  add('#/catalogos/fornecedores', 'Fornecedores', '🚚');
  add('#/indicadores', 'Indicadores', '📊', can('reports.view'));
  add('#/relatorios', 'Relatórios', '🧾', can('reports.view'));
  add('#/importar', 'Importar folha', '⬆', can('import'));
  add('#/utilizadores', 'Utilizadores', '👥', can('users.manage'));
  add('#/organizacao', 'Organização', '🏢', can('org.settings'));
  add('#/auditoria', 'Auditoria', '🔍', can('users.manage'));
  add('#/sincronizar', 'Sincronização', '⟳');
  add('#/perfil', 'Perfil e dispositivos', '👤');
  add('#/instalar', 'Instalar aplicação', '📲');
  return i;
}

function montarLayout() {
  const topo = document.getElementById('topo');
  const menu = document.getElementById('menu');
  const barra = document.getElementById('barra-inferior');
  topo.hidden = false;
  menu.hidden = false;
  const org = state.me.org;
  document.getElementById('nome-org').textContent = org ? org.name : 'Administração da plataforma';
  const logo = document.getElementById('logo-org');
  if (org && org.has_logo) {
    import('./api.js').then(({ blob }) => blob('/org/logo').then((b) => { logo.src = URL.createObjectURL(b); }).catch(() => {}));
  }
  clear(menu);
  menu.appendChild(el('div', { class: 'menu-utilizador' }, el('strong', null, state.me.user.name), el('small', null, state.me.user.role_label)));
  for (const it of itensMenu()) menu.appendChild(el('a', { href: it.href, class: 'menu-item' }, el('span', { 'aria-hidden': 'true' }, it.icon), it.label));
  menu.appendChild(el('button', { class: 'menu-item sair', type: 'button', onclick: sair }, el('span', { 'aria-hidden': 'true' }, '⎋'), 'Terminar sessão'));
  clear(barra);
  if (!can('platform')) {
    barra.hidden = false;
    for (const [href, icon, label] of [['#/inicio', '🏠', 'Início'], ['#/documentos', '📄', 'Docs'], ['#/qr', '▣', 'QR'], ['#/registos', '🗂', 'Registos'], ['#/notificacoes', '🔔', 'Avisos']]) {
      barra.appendChild(el('a', { href, class: 'barra-item' }, el('span', { 'aria-hidden': 'true' }, icon), el('small', null, label)));
    }
  } else barra.hidden = true;
  const btnMenu = document.getElementById('btn-menu');
  btnMenu.onclick = () => {
    const open = !document.body.classList.contains('menu-aberto');
    document.body.classList.toggle('menu-aberto', open);
    btnMenu.setAttribute('aria-expanded', String(open));
  };
}

function menuAtivo(hash) {
  document.body.classList.remove('menu-aberto');
  for (const a of document.querySelectorAll('.menu-item[href], .barra-item')) {
    const h = a.getAttribute('href');
    a.classList.toggle('ativo', hash === h || (h !== '#/inicio' && hash.startsWith(h + '/')) || hash.startsWith(h + '?'));
  }
}

async function sair() {
  const p = await pendingCount();
  if (p.pendente + p.conflito > 0) {
    const r = await modal('Rascunhos por sincronizar', el('p', null, `Tem ${p.pendente + p.conflito} rascunho(s) guardado(s) apenas neste dispositivo. Se terminar a sessão, ficam guardados neste dispositivo até voltar a entrar com a mesma conta.`),
      [{ label: 'Cancelar', value: null, class: 'sec' }, { label: 'Terminar sessão', value: 'sair' }]);
    if (r !== 'sair') return;
  }
  await logout();
  const { limparDadosLocais } = await import('./db.js');
  await limparDadosLocais({ manterFila: true });
  location.hash = '#/inicio';
  render();
}

function atualizarRede(online) {
  const s = document.getElementById('estado-rede');
  s.textContent = online ? '' : 'Sem ligação';
  s.className = 'estado-rede' + (online ? '' : ' offline');
  document.body.classList.toggle('offline', !online);
  if (online) syncAll().then((r) => {
    if (r.enviados) toast(`${r.enviados} rascunho(s) sincronizado(s) com o servidor.`, 'ok');
    if (r.conflitos) toast(`${r.conflitos} conflito(s) a resolver em Sincronização.`, 'aviso', 8000);
  });
}

async function atualizarNotificacoes() {
  if (!state.me || !state.me.user || !state.online) return;
  try {
    const r = await api('GET', '/notifications');
    const b = document.getElementById('badge-notif');
    b.hidden = !r.unread;
    b.textContent = r.unread > 99 ? '99+' : String(r.unread);
  } catch (e) { /* silencioso */ }
}

async function arrancar() {
  // perfil guardado no dispositivo para abrir a aplicação sem ligação (apenas dados locais)
  if (state.me && state.me.user) { const { cache } = await import('./db.js'); await cache.set('me', state.me); }
  montarLayout();
  refreshSyncBadge();
  atualizarNotificacoes();
  if (state.online) syncAll();
}

onSyncChange((n) => {
  const b = document.getElementById('btn-sync');
  const total = n.pendente + n.conflito;
  b.hidden = total === 0;
  const badge = document.getElementById('badge-sync');
  badge.textContent = String(total);
  b.classList.toggle('conflito', n.conflito > 0);
});

onAuthChange((ev) => {
  if (ev === 'saiu') { state.me = null; render(); }
});
onOnlineChange(atualizarRede);

// Pedido de instalação (Android/Chrome)
window.addEventListener('beforeinstallprompt', (ev) => { ev.preventDefault(); window.__sgiInstallPrompt = ev; document.body.classList.add('pode-instalar'); });
window.addEventListener('appinstalled', () => { toast('Aplicação instalada.', 'ok'); window.__sgiInstallPrompt = null; });

async function init() {
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register(BASE + '/sw.js', { scope: BASE + '/' }).catch((e) => console.warn('SW', e));
  }
  state.mode = preferredMode();
  if (state.mode === 'token') await loadTokens();
  try {
    await whoami();
  } catch (e) {
    if (e.offline) {
      // Sem ligação: usar o último perfil conhecido deste dispositivo (só leitura local).
      const { cache } = await import('./db.js');
      const last = await cache.get('me');
      if (last && (state.mode === 'sessao' || state.refresh)) state.me = last;
      atualizarRede(false);
    } else if (e.status === 503 && e.code === 'nao_instalado') {
      location.href = BASE + '/instalar/';
      return;
    }
  }
  if (state.me && state.me.user) await arrancar();
  window.addEventListener('hashchange', render);
  render();
  setInterval(() => { atualizarNotificacoes(); if (state.online) syncAll(); else ping(); }, 60000);
}

window.__sgi = { state, syncAll, render };
init();
