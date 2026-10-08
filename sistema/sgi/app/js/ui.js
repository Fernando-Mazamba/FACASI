// Utilitários de interface. Todo o texto vindo da API é inserido como texto (nunca innerHTML).

export function el(tag, attrs, ...children) {
  const e = document.createElement(tag);
  if (attrs) {
    for (const [k, v] of Object.entries(attrs)) {
      if (v === undefined || v === null || v === false) continue;
      if (k === 'class') e.className = v;
      else if (k === 'style' && typeof v === 'object') Object.assign(e.style, v);
      else if (k.startsWith('on') && typeof v === 'function') e.addEventListener(k.slice(2), v);
      else if (k === 'dataset') Object.assign(e.dataset, v);
      else if (v === true) e.setAttribute(k, '');
      else e.setAttribute(k, String(v));
    }
  }
  append(e, children);
  return e;
}

function append(parent, children) {
  for (const c of children.flat(Infinity)) {
    if (c === null || c === undefined || c === false) continue;
    parent.appendChild(c instanceof Node ? c : document.createTextNode(String(c)));
  }
}

export function clear(node) {
  while (node.firstChild) node.removeChild(node.firstChild);
  return node;
}

export function mount(...children) {
  const main = document.getElementById('conteudo');
  clear(main);
  append(main, children);
  main.scrollTop = 0;
  window.scrollTo(0, 0);
  return main;
}

export function toast(msg, type = 'info', ms = 4500) {
  const box = document.getElementById('toasts');
  const t = el('div', { class: 'toast ' + type, role: type === 'erro' ? 'alert' : 'status' }, msg);
  box.appendChild(t);
  setTimeout(() => t.remove(), ms);
  return t;
}

export function modal(title, body, actions = []) {
  return new Promise((resolve) => {
    const prev = document.activeElement;
    const close = (v) => { overlay.remove(); if (prev && prev.focus) prev.focus(); resolve(v); };
    const footer = el('div', { class: 'modal-acoes' },
      actions.map((a) => el('button', { class: 'btn ' + (a.class || ''), type: 'button', onclick: async () => {
        if (a.validate) { const ok = await a.validate(); if (!ok) return; }
        close(a.value);
      } }, a.label)));
    const dlg = el('div', { class: 'modal', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'modal-titulo' },
      el('h2', { id: 'modal-titulo' }, title), el('div', { class: 'modal-corpo' }, body), footer);
    const overlay = el('div', { class: 'modal-fundo', onclick: (ev) => { if (ev.target === overlay) close(null); } }, dlg);
    overlay.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') close(null); });
    document.body.appendChild(overlay);
    const f = dlg.querySelector('input,select,textarea,button');
    if (f) f.focus();
  });
}

export async function confirmar(title, text, okLabel = 'Confirmar', danger = false) {
  const r = await modal(title, el('p', null, text), [
    { label: 'Cancelar', value: false, class: 'sec' },
    { label: okLabel, value: true, class: danger ? 'perigo' : '' },
  ]);
  return r === true;
}

export async function pedirTexto(title, label, { obrigatorio = true, multiline = false, valor = '' } = {}) {
  const input = multiline ? el('textarea', { rows: 4 }) : el('input', { type: 'text' });
  input.value = valor;
  const err = el('p', { class: 'erro-campo', hidden: true }, 'Campo obrigatório.');
  const r = await modal(title, el('label', null, label, input, err), [
    { label: 'Cancelar', value: null, class: 'sec' },
    { label: 'Continuar', value: 'ok', validate: () => { if (obrigatorio && !input.value.trim()) { err.hidden = false; input.focus(); return false; } return true; } },
  ]);
  return r === 'ok' ? input.value.trim() : null;
}

export function fmtData(s, comHora = false) {
  if (!s) return '—';
  const d = new Date(String(s).replace(' ', 'T'));
  if (isNaN(d)) return String(s);
  return comHora ? d.toLocaleString('pt-PT', { dateStyle: 'short', timeStyle: 'short' }) : d.toLocaleDateString('pt-PT');
}

export function fmtBytes(n) {
  if (n == null) return '—';
  if (n < 1024) return n + ' B';
  if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
  if (n < 1073741824) return (n / 1048576).toFixed(1) + ' MB';
  return (n / 1073741824).toFixed(2) + ' GB';
}

export const ESTADOS = {
  rascunho: 'Rascunho',
  pendente_aprovacao: 'Pendente de aprovação',
  final: 'Final (arquivado)',
  local: 'Pendente de sincronizar',
  conflito: 'Conflito por resolver',
};

export function badge(status) {
  return el('span', { class: 'estado estado-' + status }, ESTADOS[status] || status);
}

export function cabecalho(titulo, ...extra) {
  return el('div', { class: 'pagina-cabecalho' }, el('h1', null, titulo), extra.length ? el('div', { class: 'acoes' }, extra) : null);
}

export function vazio(texto) {
  return el('p', { class: 'vazio' }, texto);
}

export function tabela(colunas, linhas, onRow) {
  const thead = el('thead', null, el('tr', null, colunas.map((c) => el('th', null, c.label))));
  const tbody = el('tbody', null, linhas.map((r) => {
    const tr = el('tr', onRow ? { class: 'clicavel', tabindex: 0, onclick: () => onRow(r), onkeydown: (e) => { if (e.key === 'Enter') onRow(r); } } : null,
      colunas.map((c) => el('td', { 'data-label': c.label }, c.render ? c.render(r) : (r[c.key] ?? '—'))));
    return tr;
  }));
  return el('div', { class: 'tabela-wrap' }, el('table', { class: 'tabela' }, thead, tbody));
}

export function uuid() {
  if (crypto.randomUUID) return crypto.randomUUID();
  const b = crypto.getRandomValues(new Uint8Array(16));
  b[6] = (b[6] & 0x0f) | 0x40; b[8] = (b[8] & 0x3f) | 0x80;
  const h = [...b].map((x) => x.toString(16).padStart(2, '0')).join('');
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

export function campo(label, input, ajuda) {
  return el('label', { class: 'campo' }, el('span', null, label), input, ajuda ? el('small', null, ajuda) : null);
}

export function input(name, value = '', attrs = {}) {
  const i = el('input', Object.assign({ name, type: 'text' }, attrs));
  i.value = value ?? '';
  return i;
}

export function select(name, options, value = '', attrs = {}) {
  const s = el('select', Object.assign({ name }, attrs), options.map(([v, l]) => el('option', { value: v }, l)));
  s.value = value ?? '';
  return s;
}

export function formData(form) {
  const o = {};
  for (const e of form.elements) {
    if (!e.name) continue;
    if (e.type === 'checkbox') o[e.name] = e.checked ? 1 : 0;
    else o[e.name] = e.value;
  }
  return o;
}

export function baixarBlob(blob, nome) {
  const a = el('a', { href: URL.createObjectURL(blob), download: nome });
  document.body.appendChild(a);
  a.click();
  setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 2000);
}

/** Navega para uma rota; se já estiver nela, volta a desenhar o ecrã. */
export function navegar(hash) {
  if (location.hash === hash) window.dispatchEvent(new HashChangeEvent('hashchange'));
  else location.hash = hash;
}
