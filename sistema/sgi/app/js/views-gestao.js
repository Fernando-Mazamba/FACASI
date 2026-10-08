// Ecrãs de gestão do SGI: NC/ações, calendário, equipamentos e QR, catálogos, capacitações, indicadores, relatórios, importação.
import { api, blob, state, can, BASE } from './api.js';
import { el, mount, toast, modal, confirmar, fmtData, cabecalho, vazio, tabela, clear, baixarBlob, campo, navegar, recarregar } from './ui.js';
import { qrSvg, urlQr } from './mobile.js';

let usersCache = null;
async function utilizadores() {
  if (!usersCache) usersCache = (await api('GET', '/users')).users;
  return usersCache;
}
const nomeUser = (list, id) => (list.find((u) => +u.id === +id) || {}).name || '—';

/**
 * Formulário genérico. campos: [{name, label, type:'text'|'textarea'|'date'|'number'|'select'|'user', options, required}]
 */
async function formulario(titulo, campos, valores = {}) {
  const users = campos.some((c) => c.type === 'user') ? await utilizadores() : [];
  const inputs = {};
  const corpo = campos.map((c) => {
    let i;
    if (c.type === 'textarea') i = el('textarea', { name: c.name, rows: 3 });
    else if (c.type === 'select') i = el('select', { name: c.name }, (c.optional ? [['', '—']] : []).concat(c.options).map(([v, l]) => el('option', { value: v }, l)));
    else if (c.type === 'user') i = el('select', { name: c.name }, [['', '— sem responsável —']].concat(users.map((u) => [u.id, u.name])).map(([v, l]) => el('option', { value: v }, l)));
    else i = el('input', { name: c.name, type: c.type || 'text', step: c.type === 'number' ? 'any' : undefined });
    i.value = valores[c.name] ?? (c.default ?? '');
    if (c.required) i.required = true;
    inputs[c.name] = i;
    return campo(c.label + (c.required ? ' *' : ''), i, c.help);
  });
  const err = el('p', { class: 'erro-campo', hidden: true });
  const r = await modal(titulo, [el('div', { class: 'form-grelha' }, corpo), err], [
    { label: 'Cancelar', value: null, class: 'sec' },
    { label: 'Guardar', value: 'ok', validate: () => {
      for (const c of campos) if (c.required && !String(inputs[c.name].value).trim()) { err.textContent = `Preencha «${c.label}».`; err.hidden = false; inputs[c.name].focus(); return false; }
      return true;
    } },
  ]);
  if (r !== 'ok') return null;
  const out = {};
  for (const c of campos) out[c.name] = inputs[c.name].value;
  return out;
}

async function crudPage({ titulo, rota, campos, colunas, gerir, extraAcoes, filtroQuery = '', vazioTxt = 'Sem registos.', depois }) {
  const q = el('input', { type: 'search', placeholder: 'Procurar…' });
  const lista = el('div');
  const carregar = async () => {
    const r = await api('GET', `/${rota}?q=${encodeURIComponent(q.value)}${filtroQuery}`);
    clear(lista);
    lista.appendChild(r.items.length ? tabela(colunas, r.items, gerir ? editar : null) : vazio(vazioTxt));
    if (depois) depois(r.items, lista);
  };
  const novo = async () => {
    const v = await formulario('Novo — ' + titulo, campos);
    if (!v) return;
    try { await api('POST', '/' + rota, v); toast('Guardado.', 'ok'); carregar(); } catch (e) { toast(e.message, 'erro'); }
  };
  async function editar(item) {
    const v = await formulario('Editar — ' + titulo, campos, item);
    if (!v) return;
    try { await api('PUT', `/${rota}/${item.id}`, v); toast('Guardado.', 'ok'); carregar(); } catch (e) { toast(e.message, 'erro'); }
  }
  q.addEventListener('keydown', (e) => { if (e.key === 'Enter') carregar(); });
  mount(cabecalho(titulo, gerir ? el('button', { class: 'btn', onclick: novo }, '＋ Novo') : null, ...(extraAcoes || [])),
    el('div', { class: 'filtros' }, q, el('button', { class: 'btn sec', onclick: carregar }, 'Procurar')), lista);
  await carregar();
}

const NORMAS = [['ISO 9001', 'ISO 9001'], ['ISO 14001', 'ISO 14001'], ['ISO 45001', 'ISO 45001'], ['Legal', 'Requisito legal'], ['Outro', 'Outro']];

// =================== NÃO CONFORMIDADES ===================
export async function ncs() {
  const users = await utilizadores();
  await crudPage({
    titulo: 'Não conformidades, incidentes e oportunidades', rota: 'nonconformities', gerir: can('nc.manage'),
    campos: [
      { name: 'code', label: 'Código', required: true, help: 'Ex.: NC-2026-001' }, { name: 'title', label: 'Título', required: true },
      { name: 'nc_type', label: 'Tipo', type: 'select', options: [['nc', 'Não conformidade'], ['observacao', 'Observação'], ['oportunidade', 'Oportunidade de melhoria'], ['incidente', 'Incidente'], ['acidente', 'Acidente']] },
      { name: 'standard', label: 'Norma', type: 'select', options: NORMAS, optional: true },
      { name: 'severity', label: 'Gravidade', type: 'select', options: [['baixa', 'Baixa'], ['media', 'Média'], ['alta', 'Alta']], default: 'media' },
      { name: 'status', label: 'Estado', type: 'select', options: [['aberta', 'Aberta'], ['em_tratamento', 'Em tratamento'], ['fechada', 'Fechada']] },
      { name: 'origin', label: 'Origem', help: 'Auditoria, inspeção, reclamação…' }, { name: 'detected_at', label: 'Data de deteção', type: 'date' },
      { name: 'due_date', label: 'Prazo', type: 'date' }, { name: 'owner_user_id', label: 'Responsável', type: 'user' },
      { name: 'description', label: 'Descrição', type: 'textarea' }, { name: 'root_cause', label: 'Análise de causa', type: 'textarea' },
    ],
    colunas: [{ label: 'Código', key: 'code' }, { label: 'Título', key: 'title' }, { label: 'Tipo', key: 'nc_type' }, { label: 'Gravidade', key: 'severity' },
      { label: 'Estado', key: 'status' }, { label: 'Prazo', render: (x) => fmtData(x.due_date) }, { label: 'Responsável', render: (x) => nomeUser(users, x.owner_user_id) },
      { label: '', render: (x) => el('a', { class: 'btn mini sec', href: '#/acoes?nc=' + x.id, onclick: (e) => e.stopPropagation() }, 'Ações') }],
  });
}

// =================== AÇÕES ===================
export async function acoes(params, query) {
  const users = await utilizadores();
  const ncsList = (await api('GET', '/nonconformities')).items;
  const hoje = new Date().toISOString().slice(0, 10);
  const filtro = (query.get('nc') ? '&nc_id=' + query.get('nc') : '');
  await crudPage({
    titulo: 'Ações corretivas, preventivas e de melhoria', rota: 'actions', gerir: can('nc.manage'), filtroQuery: filtro,
    campos: [
      { name: 'title', label: 'Ação', required: true },
      { name: 'nc_id', label: 'Não conformidade', type: 'select', options: ncsList.map((n) => [n.id, n.code + ' — ' + n.title]), optional: true, default: query.get('nc') || '' },
      { name: 'action_type', label: 'Tipo', type: 'select', options: [['corretiva', 'Corretiva'], ['preventiva', 'Preventiva'], ['melhoria', 'Melhoria'], ['imediata', 'Imediata/correção']] },
      { name: 'owner_user_id', label: 'Responsável', type: 'user' }, { name: 'due_date', label: 'Prazo', type: 'date' },
      { name: 'status', label: 'Estado', type: 'select', options: [['pendente', 'Pendente'], ['em_curso', 'Em curso'], ['concluida', 'Concluída'], ['cancelada', 'Cancelada']] },
      { name: 'efficacy', label: 'Eficácia', type: 'select', options: [['nao_avaliada', 'Não avaliada'], ['eficaz', 'Eficaz'], ['nao_eficaz', 'Não eficaz']] },
      { name: 'description', label: 'Descrição', type: 'textarea' },
    ],
    colunas: [{ label: 'Ação', key: 'title' }, { label: 'Tipo', key: 'action_type' }, { label: 'Responsável', render: (x) => nomeUser(users, x.owner_user_id) },
      { label: 'Prazo', render: (x) => el('span', { class: x.due_date && x.due_date < hoje && ['pendente', 'em_curso'].includes(x.status) ? 'atrasado' : '' }, fmtData(x.due_date)) },
      { label: 'Estado', key: 'status' }, { label: 'Eficácia', key: 'efficacy' },
      { label: '', render: (x) => (+x.owner_user_id === state.me.user.id && !can('nc.manage') && ['pendente', 'em_curso'].includes(x.status))
        ? el('button', { class: 'btn mini', onclick: async (e) => { e.stopPropagation(); await api('PUT', '/actions/' + x.id, { status: 'concluida' }); toast('Ação marcada como concluída.', 'ok'); recarregar(); } }, 'Concluir') : '' }],
  });
}

// =================== CALENDÁRIO ===================
const TIPOS_EV = [['auditoria', 'Auditoria'], ['capacitacao', 'Capacitação'], ['manutencao', 'Manutenção'], ['revisao', 'Revisão'], ['vencimento_legal', 'Vencimento legal'], ['reuniao', 'Reunião'], ['outro', 'Outro']];

export async function calendario(params, query) {
  const ref = query.get('mes') ? new Date(query.get('mes') + '-01T12:00:00') : new Date();
  const ano = ref.getFullYear();
  const mes = ref.getMonth();
  const from = new Date(ano, mes, 1);
  const to = new Date(ano, mes + 1, 0);
  const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  const r = await api('GET', `/calendar?from=${iso(from)}&to=${iso(to)}`);
  const users = await utilizadores();
  const camposEv = [
    { name: 'title', label: 'Título', required: true }, { name: 'event_type', label: 'Tipo', type: 'select', options: TIPOS_EV, required: true },
    { name: 'start_date', label: 'Data', type: 'date', required: true }, { name: 'end_date', label: 'Data de fim', type: 'date' },
    { name: 'recurrence', label: 'Repetição', type: 'select', options: [['nenhuma', 'Não repete'], ['mensal', 'Mensal'], ['trimestral', 'Trimestral'], ['semestral', 'Semestral'], ['anual', 'Anual']] },
    { name: 'reminder_days', label: 'Lembrar com (dias) de antecedência', type: 'number', default: 7 },
    { name: 'owner_user_id', label: 'Responsável', type: 'user' },
    { name: 'status', label: 'Estado', type: 'select', options: [['planeado', 'Planeado'], ['realizado', 'Realizado'], ['cancelado', 'Cancelado']] },
    { name: 'description', label: 'Descrição', type: 'textarea' },
  ];
  const novo = async (data) => {
    const v = await formulario('Novo evento', camposEv, { start_date: data || iso(new Date()) });
    if (!v) return;
    try { await api('POST', '/events', v); toast('Evento guardado no servidor.', 'ok'); recarregar(); } catch (e) { toast(e.message, 'erro'); }
  };
  const editar = async (ev) => {
    if (ev.derived) { if (ev.link) location.hash = ev.link; return; }
    if (!can('calendar.manage')) { modal(ev.title, el('p', null, ev.description || ''), [{ label: 'Fechar', value: 1 }]); return; }
    const v = await formulario('Editar evento', camposEv, ev);
    if (!v) return;
    try { await api('PUT', '/events/' + ev.id, v); toast('Evento atualizado.', 'ok'); recarregar(); } catch (e) { toast(e.message, 'erro'); }
  };
  // grelha mensal
  const porDia = {};
  for (const e of r.events) (porDia[e.occurrence_date] ||= []).push(e);
  const dias = [];
  const offset = (from.getDay() + 6) % 7;
  for (let i = 0; i < offset; i++) dias.push(el('div', { class: 'dia vazio' }));
  for (let d = 1; d <= to.getDate(); d++) {
    const ds = iso(new Date(ano, mes, d));
    dias.push(el('div', { class: 'dia' + (ds === iso(new Date()) ? ' hoje' : ''), ondblclick: () => can('calendar.manage') && novo(ds) },
      el('span', { class: 'num' }, String(d)),
      (porDia[ds] || []).map((e) => el('button', { class: 'evento tipo-' + e.event_type, type: 'button', onclick: () => editar(e), title: e.title }, e.title))));
  }
  const nav = (delta) => { const n = new Date(ano, mes + delta, 1); location.hash = '#/calendario?mes=' + `${n.getFullYear()}-${String(n.getMonth() + 1).padStart(2, '0')}`; };
  const importar = el('input', { type: 'file', accept: '.json,application/json', hidden: true });
  importar.addEventListener('change', async () => {
    const fd = new FormData(); fd.append('file', importar.files[0]);
    try { const x = await api('POST', '/calendar/import-legacy', fd); toast(`Importados ${x.importados} evento(s); ignorados ${x.ignorados}.`, 'ok'); recarregar(); } catch (e) { toast(e.message, 'erro'); }
  });
  mount(cabecalho('Calendário do SGI', can('calendar.manage') ? el('button', { class: 'btn', onclick: () => novo() }, '＋ Evento') : null,
    can('calendar.manage') ? el('button', { class: 'btn sec', onclick: () => importar.click(), title: 'Ficheiro JSON do calendário antigo (Descarregar → Descarregar como JSON)' }, '⬆ Importar calendário antigo') : null, importar),
  el('p', { class: 'nota' }, 'Guardado no servidor e partilhado pela organização. Inclui prazos de ações, verificações de equipamentos e capacitações planeadas. Lembretes automáticos pelas tarefas diárias.'),
  el('div', { class: 'cal-nav' }, el('button', { class: 'btn sec', onclick: () => nav(-1) }, '‹'), el('strong', null, from.toLocaleDateString('pt-PT', { month: 'long', year: 'numeric' })), el('button', { class: 'btn sec', onclick: () => nav(1) }, '›')),
  el('div', { class: 'calendario' }, ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'].map((d) => el('div', { class: 'dia-semana' }, d)), dias),
  el('section', { class: 'cartao' }, el('h2', null, 'Lista do mês'), r.events.length ? tabela([
    { label: 'Data', render: (e) => fmtData(e.occurrence_date) }, { label: 'Evento', key: 'title' }, { label: 'Tipo', key: 'event_type' },
    { label: 'Responsável', render: (e) => e.owner_name || nomeUser(users, e.owner_user_id) }], r.events, editar) : vazio('Sem eventos neste mês.')));
}

// =================== EQUIPAMENTOS, EXTINTORES E LOCAIS (QR) ===================
export async function equipamentos() {
  const forms = (await api('GET', '/templates?forms=1')).templates;
  await crudPage({
    titulo: 'Equipamentos, extintores e locais', rota: 'assets', gerir: can('assets.manage'),
    extraAcoes: [el('a', { class: 'btn sec', href: '#/etiquetas' }, '🖨 Etiquetas QR')],
    campos: [
      { name: 'kind', label: 'Tipo', type: 'select', options: [['equipamento', 'Equipamento'], ['extintor', 'Extintor'], ['local', 'Local']], required: true },
      { name: 'code', label: 'Código', required: true }, { name: 'name', label: 'Nome/descrição', required: true }, { name: 'location', label: 'Localização' },
      { name: 'brand', label: 'Marca/modelo' }, { name: 'serial_no', label: 'N.º de série' }, { name: 'next_check_date', label: 'Próxima verificação', type: 'date' },
      { name: 'form_template_id', label: 'Formulário de inspeção associado', type: 'select', options: forms.map((f) => [f.id, f.code + ' — ' + f.title]), optional: true },
      { name: 'status', label: 'Estado', type: 'select', options: [['ativo', 'Ativo'], ['inativo', 'Inativo']] }, { name: 'notes', label: 'Notas', type: 'textarea' },
    ],
    colunas: [{ label: 'Tipo', key: 'kind' }, { label: 'Código', key: 'code' }, { label: 'Nome', key: 'name' }, { label: 'Local', key: 'location' },
      { label: 'Próx. verificação', render: (x) => fmtData(x.next_check_date) }, { label: '', render: (x) => el('a', { class: 'btn mini', href: '#/equipamentos/' + x.id, onclick: (e) => e.stopPropagation() }, 'Ficha') }],
  });
}

async function svgNode(texto, cell) {
  const s = await qrSvg(texto, cell);
  const doc = new DOMParser().parseFromString(s, 'image/svg+xml');
  return document.importNode(doc.documentElement, true);
}

export async function equipamento(params) {
  const a = (await api('GET', '/assets/' + params.id)).item;
  const regs = await api('GET', '/records?asset_id=' + a.id + '&limit=20');
  const qrBox = el('div', { class: 'qr-box' });
  qrBox.appendChild(await svgNode(urlQr(a.qr_token), 6));
  const anexos = await api('GET', `/attachments?entity_type=asset&entity_id=${a.id}`).catch(() => ({ attachments: [] }));
  mount(cabecalho(`${a.code} · ${a.name}`),
    el('div', { class: 'grelha-2' },
      el('section', { class: 'cartao' }, el('h2', null, 'Ficha'),
        el('dl', { class: 'ficha' }, ...[['Tipo', a.kind], ['Localização', a.location], ['Marca/modelo', a.brand], ['N.º de série', a.serial_no], ['Próxima verificação', fmtData(a.next_check_date)], ['Estado', a.status], ['Notas', a.notes]]
          .flatMap(([k, v]) => [el('dt', null, k), el('dd', null, v || '—')])),
        a.form_template_id ? el('a', { class: 'btn', href: `#/registos/novo/${a.form_template_id}?asset=${a.id}` }, '＋ Preencher formulário de inspeção') : el('p', { class: 'nota' }, 'Sem formulário de inspeção associado.')),
      el('section', { class: 'cartao centro' }, el('h2', null, 'Etiqueta QR'), qrBox, el('p', { class: 'nota' }, 'Ao ler este código, abre-se esta ficha — apenas para utilizadores desta organização.'),
        el('button', { class: 'btn sec', onclick: () => { location.hash = '#/etiquetas?ids=' + a.id; } }, '🖨 Imprimir etiqueta'),
        can('assets.manage') ? el('button', { class: 'btn sec', onclick: async () => {
          if (!await confirmar('Gerar novo QR?', 'As etiquetas já impressas deixam de funcionar.', 'Gerar novo', true)) return;
          await api('POST', `/assets/${a.id}/regenerate-qr`); recarregar();
        } }, 'Gerar novo código') : null)),
    el('section', { class: 'cartao' }, el('h2', null, 'Registos deste equipamento'), regs.records.length ? tabela([
      { label: 'Ref.', key: 'ref' }, { label: 'Título', key: 'title' }, { label: 'Estado', key: 'status' }, { label: 'Data', render: (x) => fmtData(x.updated_at, true) }],
    regs.records, (x) => { location.hash = '#/registos/' + x.id; }) : vazio('Sem registos.')),
    el('section', { class: 'cartao' }, el('h2', null, 'Fotografias do equipamento'), el('p', null, `${anexos.attachments.length} anexo(s).`)));
}

export async function etiquetas(params, query) {
  const r = await api('GET', '/assets?status=ativo');
  const ids = query.get('ids') ? query.get('ids').split(',').map(Number) : null;
  const itens = ids ? r.items.filter((a) => ids.includes(+a.id)) : r.items;
  const grelha = el('div', { class: 'etiquetas' });
  for (const a of itens) {
    grelha.appendChild(el('div', { class: 'etiqueta' }, await svgNode(urlQr(a.qr_token), 4),
      el('div', null, el('strong', null, a.code), el('span', null, a.name), el('small', null, a.location || ''), el('small', null, state.me.org.name))));
  }
  mount(el('div', { class: 'sem-impressao' }, cabecalho('Etiquetas QR', el('button', { class: 'btn', onclick: () => window.print() }, '🖨 Imprimir')),
    el('p', { class: 'nota' }, `${itens.length} etiqueta(s). Use papel autocolante A4; recorte pelas margens.`)), itens.length ? grelha : vazio('Sem equipamentos ativos.'));
}

// =================== CATÁLOGOS ===================
const CATALOGOS = {
  trabalhadores: { titulo: 'Trabalhadores', rota: 'workers', campos: async () => {
    const pos = (await api('GET', '/positions')).items;
    return [{ name: 'name', label: 'Nome completo', required: true }, { name: 'id_number', label: 'N.º de identificação (BI)' },
      { name: 'position_id', label: 'Cargo', type: 'select', options: pos.map((p) => [p.id, p.name]), optional: true }, { name: 'department', label: 'Departamento' },
      { name: 'email', label: 'Correio', type: 'email' }, { name: 'phone', label: 'Telefone' }, { name: 'hired_at', label: 'Data de admissão', type: 'date' },
      { name: 'status', label: 'Estado', type: 'select', options: [['ativo', 'Ativo'], ['inativo', 'Inativo']] }];
  }, colunas: [{ label: 'Nome', key: 'name' }, { label: 'N.º ident.', key: 'id_number' }, { label: 'Departamento', key: 'department' }, { label: 'Estado', key: 'status' }] },
  cargos: { titulo: 'Cargos', rota: 'positions', campos: async () => [{ name: 'code', label: 'Código' }, { name: 'name', label: 'Cargo', required: true }, { name: 'department', label: 'Departamento' }, { name: 'description', label: 'Descrição', type: 'textarea' }],
    colunas: [{ label: 'Código', key: 'code' }, { label: 'Cargo', key: 'name' }, { label: 'Departamento', key: 'department' }] },
  fornecedores: { titulo: 'Fornecedores', rota: 'suppliers', campos: async () => [{ name: 'name', label: 'Nome', required: true }, { name: 'tax_id', label: 'NIF' }, { name: 'category', label: 'Categoria' },
    { name: 'contact', label: 'Contacto' }, { name: 'email', label: 'Correio', type: 'email' }, { name: 'phone', label: 'Telefone' },
    { name: 'status', label: 'Estado', type: 'select', options: [['em_avaliacao', 'Em avaliação'], ['aprovado', 'Aprovado'], ['suspenso', 'Suspenso']] },
    { name: 'last_evaluation', label: 'Última avaliação', type: 'date' }, { name: 'score', label: 'Pontuação', type: 'number' }],
  colunas: [{ label: 'Nome', key: 'name' }, { label: 'NIF', key: 'tax_id' }, { label: 'Categoria', key: 'category' }, { label: 'Estado', key: 'status' }, { label: 'Última avaliação', render: (x) => fmtData(x.last_evaluation) }, { label: 'Pontuação', key: 'score' }] },
};

export async function catalogo(params) {
  const c = CATALOGOS[params.tipo];
  if (!c) { mount(vazio('Catálogo desconhecido.')); return; }
  await crudPage({ titulo: c.titulo, rota: c.rota, gerir: can('catalog.manage'), campos: await c.campos(), colunas: c.colunas,
    extraAcoes: can('import') ? [el('a', { class: 'btn sec', href: '#/importar?entidade=' + c.rota }, '⬆ Importar folha')] : [] });
}

// =================== CAPACITAÇÕES ===================
export async function capacitacoes() {
  await crudPage({
    titulo: 'Capacitações', rota: 'trainings', gerir: can('catalog.manage'),
    campos: [{ name: 'title', label: 'Título', required: true }, { name: 'topic', label: 'Tema' }, { name: 'provider', label: 'Formador/entidade' },
      { name: 'planned_date', label: 'Data', type: 'date' }, { name: 'hours', label: 'Horas', type: 'number' },
      { name: 'status', label: 'Estado', type: 'select', options: [['planeada', 'Planeada'], ['realizada', 'Realizada'], ['cancelada', 'Cancelada']] },
      { name: 'efficacy_note', label: 'Avaliação da eficácia', type: 'textarea' }],
    colunas: [{ label: 'Título', key: 'title' }, { label: 'Data', render: (x) => fmtData(x.planned_date) }, { label: 'Horas', key: 'hours' }, { label: 'Estado', key: 'status' },
      { label: '', render: (x) => el('button', { class: 'btn mini sec', onclick: (e) => { e.stopPropagation(); participantes(x); } }, 'Participantes') }],
  });
}

async function participantes(t) {
  const ws = (await api('GET', '/workers?status=ativo')).items;
  const cur = (await api('GET', `/trainings/${t.id}/participants`)).participants;
  const map = new Map(cur.map((p) => [+p.worker_id, p]));
  const linhas = ws.map((w) => {
    const ins = el('input', { type: 'checkbox' }); ins.checked = map.has(+w.id);
    const pres = el('input', { type: 'checkbox' }); pres.checked = map.has(+w.id) && !!+map.get(+w.id).attended;
    return { w, ins, pres };
  });
  const r = await modal('Participantes — ' + t.title, ws.length ? el('div', { class: 'tabela-wrap' }, el('table', { class: 'tabela' },
    el('thead', null, el('tr', null, el('th', null, 'Trabalhador'), el('th', null, 'Inscrito'), el('th', null, 'Presente'))),
    el('tbody', null, linhas.map((l) => el('tr', null, el('td', null, l.w.name), el('td', null, l.ins), el('td', null, l.pres)))))) : vazio('Registe primeiro os trabalhadores.'),
  can('catalog.manage') ? [{ label: 'Cancelar', value: null, class: 'sec' }, { label: 'Guardar', value: 'ok' }] : [{ label: 'Fechar', value: null }]);
  if (r !== 'ok') return;
  await api('PUT', `/trainings/${t.id}/participants`, { participants: linhas.filter((l) => l.ins.checked).map((l) => ({ worker_id: l.w.id, attended: l.pres.checked })) });
  toast('Participantes guardados.', 'ok');
}

// =================== INDICADORES ===================
export async function indicadores() {
  await crudPage({
    titulo: 'Indicadores', rota: 'indicators', gerir: can('indicators.manage'),
    campos: [{ name: 'name', label: 'Indicador', required: true }, { name: 'standard', label: 'Norma', type: 'select', options: [['Geral', 'Geral'], ['ISO 9001', 'ISO 9001'], ['ISO 14001', 'ISO 14001'], ['ISO 45001', 'ISO 45001']] },
      { name: 'unit', label: 'Unidade' }, { name: 'target', label: 'Meta', type: 'number' }, { name: 'direction', label: 'Melhor quando', type: 'select', options: [['maior', 'maior ou igual à meta'], ['menor', 'menor ou igual à meta']] },
      { name: 'frequency', label: 'Frequência', type: 'select', options: [['mensal', 'Mensal'], ['trimestral', 'Trimestral'], ['semestral', 'Semestral'], ['anual', 'Anual']] }],
    colunas: [{ label: 'Indicador', key: 'name' }, { label: 'Norma', key: 'standard' }, { label: 'Meta', render: (x) => x.target === null ? '—' : `${x.direction === 'maior' ? '≥' : '≤'} ${x.target} ${x.unit || ''}` },
      { label: 'Frequência', key: 'frequency' }, { label: '', render: (x) => el('button', { class: 'btn mini sec', onclick: (e) => { e.stopPropagation(); medicoes(x); } }, 'Medições') }],
  });
}

async function medicoes(ind) {
  const vals = (await api('GET', `/indicators/${ind.id}/values`)).values;
  const periodo = el('input', { type: 'month' });
  const valor = el('input', { type: 'number', step: 'any' });
  const nota = el('input', { type: 'text', placeholder: 'Fonte da medição / nota' });
  const corpo = [tabela([{ label: 'Período', key: 'period' }, { label: 'Valor', key: 'value' }, { label: 'Nota', render: (v) => v.note || '' }, { label: 'Por', key: 'author' }], vals),
    can('indicators.manage') ? el('div', { class: 'form-grelha' }, campo('Período', periodo), campo('Valor medido (real)', valor), campo('Nota', nota)) : null];
  const r = await modal('Medições — ' + ind.name, corpo, can('indicators.manage') ? [{ label: 'Fechar', value: null, class: 'sec' }, { label: 'Registar medição', value: 'ok' }] : [{ label: 'Fechar', value: null }]);
  if (r !== 'ok') return;
  try { await api('POST', `/indicators/${ind.id}/values`, { period: periodo.value, value: valor.value, note: nota.value }); toast('Medição registada.', 'ok'); } catch (e) { toast(e.message, 'erro'); }
}

// =================== RELATÓRIOS ===================
export async function relatorios() {
  const tipos = [['indicadores', 'Indicadores'], ['nc-acoes', 'Não conformidades e ações'], ['capacitacao', 'Capacitação'], ['fornecedores', 'Fornecedores'], ['equipamentos', 'Equipamentos, extintores e locais'], ['cobertura', 'Cobertura normativa (ISO 9001/14001/45001)'], ['registos', 'Registos do SGI']];
  const de = el('input', { type: 'date', value: new Date().getFullYear() + '-01-01' });
  const ate = el('input', { type: 'date', value: new Date().getFullYear() + '-12-31' });
  const abrir = async (tipo, formato) => {
    const qs = `?format=${formato}&from=${de.value}&to=${ate.value}`;
    try {
      const b = await blob(`/reports/${tipo}${qs}`);
      if (formato === 'csv') baixarBlob(b, `${tipo}-${new Date().toISOString().slice(0, 10)}.csv`);
      else { const w = window.open(URL.createObjectURL(new Blob([b], { type: 'text/html' })), '_blank'); if (!w) baixarBlob(b, `${tipo}.html`); }
    } catch (e) { toast(e.message, 'erro'); }
  };
  mount(cabecalho('Relatórios'), el('div', { class: 'filtros' }, campo('De', de), campo('Até', ate)),
    el('ul', { class: 'lista-cartoes' }, tipos.map(([t, l]) => el('li', { class: 'cartao' }, el('h3', null, l),
      el('div', { class: 'acoes' }, el('button', { class: 'btn', onclick: () => abrir(t, 'html') }, 'HTML imprimível'), el('button', { class: 'btn sec', onclick: () => abrir(t, 'csv') }, 'CSV')),
      t === 'cobertura' ? el('p', { class: 'nota' }, 'Mapeamento cláusula → documento proposto para validação pelo coordenador do SGI. Cláusulas sem documento aparecem como PENDENTE.') : null))));
}

// =================== IMPORTAÇÃO ===================
export async function importar(params, query) {
  const ent = el('select', null, [['workers', 'Trabalhadores'], ['positions', 'Cargos'], ['assets', 'Equipamentos/extintores/locais'], ['suppliers', 'Fornecedores']].map(([v, l]) => el('option', { value: v }, l)));
  ent.value = query.get('entidade') || 'workers';
  const ficheiro = el('input', { type: 'file', accept: '.csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
  const res = el('div');
  const previa = async () => {
    if (!ficheiro.files[0]) { toast('Escolha um ficheiro .csv ou .xlsx.', 'aviso'); return; }
    const fd = new FormData(); fd.append('entity', ent.value); fd.append('file', ficheiro.files[0]);
    try {
      const r = await api('POST', '/import/preview', fd);
      clear(res);
      res.append(el('div', { class: 'cartao ' + (r.erros ? 'aviso' : 'ok') }, el('p', null, `${r.label}: ${r.total} linha(s) · ${r.ok} válida(s) · ${r.erros} com erro (não serão importadas).`),
        r.colunas_ignoradas.length ? el('p', null, 'Colunas ignoradas: ' + r.colunas_ignoradas.join(', ')) : null,
        el('div', { class: 'acoes' },
          el('button', { class: 'btn', disabled: !r.ok, onclick: async () => {
            const a = await api('POST', `/import/${r.batch_id}/apply`); toast(`${a.importados} registo(s) importado(s).`, 'ok'); clear(res);
          } }, `Gravar ${r.ok} linha(s) válida(s)`),
          el('button', { class: 'btn sec', onclick: async () => { await api('POST', `/import/${r.batch_id}/cancel`); clear(res); } }, 'Cancelar'))),
      tabela([{ label: 'Linha', key: 'linha' }, { label: 'Dados', render: (x) => Object.entries(x.dados).map(([k, v]) => `${k}: ${v}`).join(' · ') },
        { label: 'Resultado', render: (x) => x.erros.length ? el('span', { class: 'atrasado' }, x.erros.join('; ')) : el('span', { class: 'ok' }, 'OK' + (x.avisos.length ? ' — ' + x.avisos.join('; ') : '')) }], r.linhas));
    } catch (e) { toast(e.message, 'erro', 9000); }
  };
  mount(cabecalho('Importar por folha de cálculo'),
    el('div', { class: 'cartao' }, el('p', null, 'Importe CSV ou Excel (.xlsx). A primeira linha deve ter os cabeçalhos. Primeiro é mostrada uma pré-visualização com validação; nada é gravado até confirmar.'),
      campo('O que importar', ent), campo('Ficheiro', ficheiro),
      el('div', { class: 'acoes' }, el('button', { class: 'btn', onclick: previa }, 'Pré-visualizar'),
        el('button', { class: 'btn sec', onclick: async () => baixarBlob(await blob('/import/template/' + ent.value), `modelo-${ent.value}.csv`) }, 'Descarregar modelo CSV'))),
    res);
}
