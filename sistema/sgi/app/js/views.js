// Ecrãs principais (uso em campo e no escritório).
import { api, blob, state, can, BASE, ApiError, ping } from './api.js';
import { el, mount, toast, modal, confirmar, pedirTexto, fmtData, fmtBytes, badge, cabecalho, vazio, tabela, uuid, clear, baixarBlob, campo, ESTADOS, navegar } from './ui.js';
import { outbox, docsOffline, versoes, cache } from './db.js';
import { hostForm } from './form-host.js';
import { saveLocal, syncAll, uploadAttachments, refreshSyncBadge } from './sync.js';
import { escolherFoto, comprimirImagem, recolherAssinatura, pedirLocalizacao, retirarConsentimentoGeo, iniciarLeitorQr, lerQrDeImagem, tokenDoTexto, ativarPush, desativarPush, estadoPush, pushSuportado } from './mobile.js';

const orgId = () => state.me.user.org_id;
const userId = () => state.me.user.id;

// =================== INÍCIO (painel por perfil) ===================
export async function inicio() {
  let d;
  let deCache = null;
  try {
    d = await api('GET', '/dashboard');
    await cache.set('dashboard:' + userId(), d);
  } catch (e) {
    if (!e.offline) throw e;
    const entry = await (await import('./db.js')).cache.getEntry('dashboard:' + userId());
    if (!entry) throw e;
    d = entry.value;
    deCache = entry.at;
  }
  const locais = await outbox.list(userId());
  const cards = [];
  const kpi = (label, valor, href, tipo = '') => el('a', { class: 'kpi ' + tipo, href }, el('strong', null, String(valor)), el('span', null, label));
  const kpis = [kpi('Os meus rascunhos', d.meus_rascunhos, '#/registos?status=rascunho&mine=1')];
  if (locais.length) kpis.push(kpi('Por sincronizar', locais.length, '#/sincronizar', locais.some((x) => x.status === 'conflito') ? 'alerta' : 'aviso'));
  if (d.aprovacoes_pendentes !== undefined) kpis.push(kpi('Aprovações pendentes', d.aprovacoes_pendentes, '#/aprovacoes', d.aprovacoes_pendentes ? 'aviso' : ''));
  if (d.acoes_atrasadas !== undefined) kpis.push(kpi('Ações atrasadas', d.acoes_atrasadas, '#/acoes?atrasadas=1', d.acoes_atrasadas ? 'alerta' : ''));
  if (d.nc_abertas !== undefined) kpis.push(kpi('NC abertas', d.nc_abertas, '#/nc'));
  if (d.registos_mes !== undefined) kpis.push(kpi('Registos finais no mês', d.registos_mes, '#/arquivo'));
  if (d.documentos_vigentes !== undefined) kpis.push(kpi('Documentos vigentes', d.documentos_vigentes, '#/documentos'));
  kpis.push(kpi('Notificações por ler', d.notificacoes_nao_lidas, '#/notificacoes', d.notificacoes_nao_lidas ? 'aviso' : ''));
  cards.push(el('div', { class: 'kpis' }, kpis));

  const lista = (titulo, itens, render, vazioTxt) => el('section', { class: 'cartao' }, el('h2', null, titulo),
    itens && itens.length ? el('ul', { class: 'lista' }, itens.map(render)) : vazio(vazioTxt));

  cards.push(el('div', { class: 'grelha-2' },
    lista('As minhas ações', d.minhas_acoes, (a) => el('li', null, el('a', { href: '#/acoes/' + a.id }, a.title),
      el('small', { class: a.due_date && a.due_date < new Date().toISOString().slice(0, 10) ? 'atrasado' : '' }, ' prazo ' + fmtData(a.due_date))), 'Sem ações atribuídas.'),
    lista('Próximos 30 dias', d.proximos_eventos, (e) => el('li', null, el('strong', null, fmtData(e.occurrence_date)), ' ', e.title, el('small', null, ' · ' + e.event_type)), 'Sem eventos planeados.'),
    lista('Confirmar leitura (novas versões)', d.leituras_pendentes, (t) => el('li', null, el('a', { href: '#/documentos/' + t.id }, t.code + ' — ' + t.title), el('small', null, ' v' + t.version_no)), 'Nenhuma leitura pendente.'),
    lista('Equipamentos a verificar', d.equipamentos_a_verificar, (a) => el('li', null, el('a', { href: '#/equipamentos/' + a.id }, a.code + ' — ' + a.name), el('small', null, ' ' + fmtData(a.next_check_date))), 'Nada a verificar nos próximos 30 dias.'),
  ));
  if (d.indicadores && d.indicadores.length) {
    cards.push(el('section', { class: 'cartao' }, el('h2', null, 'Indicadores'), tabela([
      { label: 'Indicador', key: 'name' },
      { label: 'Último valor', render: (i) => i.last ? `${i.last.value} ${i.unit || ''} (${i.last.period})` : 'sem medição' },
      { label: 'Meta', render: (i) => i.target ?? '—' },
      { label: 'Situação', render: (i) => i.cumpre === null ? '—' : el('span', { class: i.cumpre ? 'ok' : 'atrasado' }, i.cumpre ? 'Cumpre' : 'Não cumpre') },
    ], d.indicadores)));
  }
  mount(cabecalho('Olá, ' + state.me.user.name.split(' ')[0], el('span', { class: 'perfil-tag' }, d.role_label)),
    deCache ? el('p', { class: 'aviso' }, 'Sem ligação: painel da última consulta (' + fmtData(deCache, true) + ').') : null,
    el('div', { class: 'atalhos' },
      el('a', { class: 'btn', href: '#/documentos?formularios=1' }, '＋ Novo registo'),
      el('a', { class: 'btn sec', href: '#/qr' }, '▣ Ler QR'),
      el('a', { class: 'btn sec', href: '#/documentos' }, '🔎 Procurar documento')),
    cards);
}

// =================== DOCUMENTOS ===================
export async function documentos(params, query) {
  const offlineList = await docsOffline.list(orgId());
  const offlineIds = new Set(offlineList.map((d) => d.templateId));
  let data = null;
  if (!query.get('offline')) {
    try {
      data = await api('GET', '/templates?' + new URLSearchParams({ q: query.get('q') || '', category: query.get('categoria') || '', forms: query.get('formularios') ? '1' : '' }));
    } catch (e) { if (!e.offline) throw e; }
  }
  const q = el('input', { type: 'search', placeholder: 'Procurar por código ou título…', value: query.get('q') || '', 'aria-label': 'Procurar' });
  const cat = el('select', { 'aria-label': 'Categoria' }, el('option', { value: '' }, 'Todas as categorias'),
    (data ? data.categories : []).map((c) => el('option', { value: c.category }, `${c.category} (${c.n})`)));
  cat.value = query.get('categoria') || '';
  const soForm = el('input', { type: 'checkbox' });
  soForm.checked = !!query.get('formularios');
  const aplicar = () => {
    const p = new URLSearchParams();
    if (q.value) p.set('q', q.value);
    if (cat.value) p.set('categoria', cat.value);
    if (soForm.checked) p.set('formularios', '1');
    location.hash = '#/documentos' + (p.toString() ? '?' + p : '');
  };
  q.addEventListener('keydown', (e) => { if (e.key === 'Enter') aplicar(); });
  cat.addEventListener('change', aplicar);
  soForm.addEventListener('change', aplicar);

  let corpo;
  if (data) {
    corpo = data.templates.length ? el('ul', { class: 'lista-docs' }, data.templates.map((t) => el('li', null,
      el('a', { href: '#/documentos/' + t.id },
        el('span', { class: 'codigo' }, t.code), el('span', { class: 'titulo' }, t.title),
        el('small', null, `${t.category} · v${t.version_no} · ${fmtData(t.version_date)}`,
          t.is_form ? ' · formulário' : '', offlineIds.has(t.id) ? ' · 📥 offline' : '', t.read_at ? ' · ✓ lido' : ''))))) : vazio('Nenhum documento encontrado.');
  } else {
    corpo = el('div', null, el('p', { class: 'aviso' }, 'Sem ligação: só estão disponíveis os documentos que marcou como "Disponível offline" neste dispositivo.'),
      offlineList.length ? el('ul', { class: 'lista-docs' }, offlineList.map((d) => el('li', null, el('a', { href: '#/documentos/' + d.templateId },
        el('span', { class: 'codigo' }, d.code), el('span', { class: 'titulo' }, d.title), el('small', null, `v${d.versionNo} · cópia de ${fmtData(d.copiedAt, true)}`))))) : vazio('Nenhum documento disponível offline.'));
  }
  mount(cabecalho(soForm.checked ? 'Escolher formulário' : 'Documentos vigentes'),
    el('div', { class: 'filtros' }, q, cat, el('label', { class: 'chk' }, soForm, 'Só formulários'), el('button', { class: 'btn sec', onclick: aplicar }, 'Procurar')),
    corpo);
}

async function obterModelo(templateId, versionId) {
  // online: servidor; offline: cópia marcada pelo utilizador ou cópia interna da versão
  try {
    const r = await api('GET', `/templates/${templateId}/render` + (versionId ? '?version_id=' + versionId : ''));
    await versoes.put(orgId(), r);
    const off = await docsOffline.get(orgId(), Number(templateId));
    if (off && off.versionId !== r.version_id && r.version_status === 'vigente') {
      // atualiza a cópia offline para a versão vigente
      await guardarOffline(r, off.isForm);
    }
    return { ...r, origem: 'servidor' };
  } catch (e) {
    if (!e.offline) throw e;
    if (versionId) {
      const v = await versoes.get(orgId(), Number(versionId));
      if (v) return { ...v, origem: 'local' };
    }
    const off = await docsOffline.get(orgId(), Number(templateId));
    if (off && (!versionId || off.versionId === Number(versionId))) return { template_id: off.templateId, code: off.code, title: off.title, version_id: off.versionId, version_no: off.versionNo, html: off.html, copied_at: off.copiedAt, origem: 'offline' };
    throw new ApiError(0, 'sem_ligacao', 'Sem ligação e sem cópia offline deste documento. Marque-o como "Disponível offline" quando tiver ligação.');
  }
}

async function guardarOffline(r, isForm) {
  await docsOffline.put({ orgId: orgId(), userId: userId(), templateId: r.template_id, versionId: r.version_id, versionNo: r.version_no, code: r.code, title: r.title, html: r.html, copiedAt: r.copied_at || new Date().toISOString(), isForm: !!isForm });
}

export async function documento(params) {
  const id = Number(params.id);
  let t = null, versions = [];
  try {
    const r = await api('GET', '/templates/' + id);
    t = r.template; versions = r.versions;
  } catch (e) { if (!e.offline) throw e; }
  const modelo = await obterModelo(id, null);
  const off = await docsOffline.get(orgId(), id);
  const isForm = t ? t.is_form : (off ? off.isForm : false);
  const frameBox = el('div', { class: 'quadro-box' });
  const acoes = [];
  if (isForm) acoes.push(el('a', { class: 'btn', href: '#/registos/novo/' + id }, '＋ Preencher novo registo'));
  if (t && !t.my_read_at) {
    acoes.push(el('button', { class: 'btn sec', onclick: async (ev) => {
      ev.target.disabled = true;
      try { await api('POST', `/templates/${id}/read`); toast('Leitura confirmada.', 'ok'); documento(params); } catch (e) { toast(e.message, 'erro'); ev.target.disabled = false; }
    } }, '✓ Confirmar leitura'));
  }
  const offBtn = el('button', { class: 'btn sec', onclick: async () => {
    if (await docsOffline.get(orgId(), id)) { await docsOffline.del(orgId(), id); toast('Removido deste dispositivo.', 'info'); }
    else {
      if (modelo.origem !== 'servidor') { toast('Precisa de ligação para guardar a cópia atual.', 'aviso'); return; }
      await guardarOffline(modelo, isForm); toast('Disponível offline neste dispositivo (cópia de ' + fmtData(modelo.copied_at, true) + ').', 'ok');
    }
    documento(params);
  } }, off ? '📥 Remover do modo offline' : '📥 Disponível offline');
  acoes.push(offBtn);
  acoes.push(el('button', { class: 'btn sec', onclick: () => {
    baixarBlob(new Blob([modelo.html], { type: 'text/html;charset=utf-8' }), `${modelo.code}-v${modelo.version_no}.html`);
  } }, '⬇ Baixar para o computador'));
  const info = el('p', { class: 'meta' }, `${modelo.code} · versão ${modelo.version_no}`,
    t ? ` · ${t.category} · ${t.status}` : '',
    modelo.origem === 'offline' ? el('strong', { class: 'aviso-inline' }, ` · Cópia offline de ${fmtData(modelo.copied_at, true)} — pode não ser a versão vigente atual`) : '',
    t && t.my_read_at ? ` · leitura confirmada em ${fmtData(t.my_read_at, true)}` : '');
  const gestao = t && can('docs.manage') ? painelGestaoDocumento(t, versions, () => documento(params)) : null;
  const historico = versions.length ? el('details', { class: 'cartao' }, el('summary', null, `Histórico de versões (${versions.length})`),
    tabela([{ label: 'Versão', key: 'version_no' }, { label: 'Estado', key: 'status' }, { label: 'Data', render: (v) => fmtData(v.created_at, true) },
      { label: 'Motivo', render: (v) => v.reason || '—' }, { label: 'Autor', render: (v) => v.author || 'Sistema' }, { label: 'Leituras', key: 'n_reads' },
      { label: 'SHA-256', render: (v) => el('code', { class: 'hash' }, v.sha256.slice(0, 16) + '…') }], versions)) : null;
  mount(cabecalho(modelo.title), info, el('div', { class: 'acoes-barra' }, acoes), frameBox, historico, gestao);
  await hostForm(frameBox, { html: modelo.html, mode: 'view', title: modelo.title, onLink: abrirLigacao });
}

async function abrirLigacao(file) {
  try {
    const r = await api('GET', '/templates?status=vigente');
    const t = r.templates.find((x) => (x.source_file || '').toLowerCase() === file.toLowerCase());
    if (t) location.hash = '#/documentos/' + t.id;
    else toast('Documento «' + file + '» não existe no SGI desta organização.', 'aviso');
  } catch (e) { toast(e.message, 'erro'); }
}

function painelGestaoDocumento(t, versions, recarregar) {
  const aprov = el('input', { type: 'checkbox' }); aprov.checked = t.requires_approval;
  const papel = el('select', null, [['supervisor', 'Supervisor de campo'], ['coordenador', 'Coordenador do SGI'], ['direcao', 'Direção'], ['admin', 'Administrador']].map(([v, l]) => el('option', { value: v }, l)));
  papel.value = t.approver_role || 'coordenador';
  const obrig = el('input', { type: 'text', value: (t.required_fields || []).join(', '), placeholder: 'ex.: k0, k3' });
  const ficheiro = el('input', { type: 'file', accept: '.html,.htm,text/html' });
  const motivo = el('input', { type: 'text', placeholder: 'Motivo da nova versão (obrigatório)' });
  return el('details', { class: 'cartao' }, el('summary', null, 'Gestão do documento (coordenador do SGI)'),
    el('h3', null, 'Aprovação prévia e campos obrigatórios'),
    el('label', { class: 'chk' }, aprov, 'Exigir aprovação antes de finalizar os registos deste modelo'),
    campo('Perfil aprovador mínimo', papel),
    campo('Campos obrigatórios adicionais (chaves k0, k1… mostradas ao preencher)', obrig),
    el('button', { class: 'btn', onclick: async () => {
      try {
        await api('PUT', '/templates/' + t.id, { requires_approval: aprov.checked, approver_role: papel.value, required_fields: obrig.value.split(/[\s,;]+/).filter(Boolean) });
        toast('Configuração guardada.', 'ok'); recarregar();
      } catch (e) { toast(e.message, 'erro'); }
    } }, 'Guardar configuração'),
    el('h3', null, 'Nova versão controlada (HTML)'),
    el('p', { class: 'nota' }, 'A versão atual passa a obsoleta e fica no histórico. Os utilizadores recebem aviso para confirmar a leitura. Scripts do ficheiro enviado são removidos por segurança.'),
    ficheiro, motivo,
    el('button', { class: 'btn', onclick: async () => {
      if (!ficheiro.files[0] || !motivo.value.trim()) { toast('Escolha o ficheiro HTML e indique o motivo.', 'aviso'); return; }
      const fd = new FormData(); fd.append('file', ficheiro.files[0]); fd.append('reason', motivo.value.trim());
      try { const r = await api('POST', `/templates/${t.id}/versions`, fd); toast('Versão ' + r.version_no + ' publicada.', 'ok'); recarregar(); } catch (e) { toast(e.message, 'erro'); }
    } }, 'Publicar nova versão'),
    el('button', { class: 'btn sec', onclick: async () => {
      const r = await api('GET', `/templates/${t.id}/reads`);
      modal('Confirmações de leitura (versão vigente)', tabela([{ label: 'Utilizador', key: 'name' }, { label: 'Perfil', key: 'role' }, { label: 'Confirmou', render: (x) => x.confirmed_at ? fmtData(x.confirmed_at, true) : 'pendente' }], r.reads), [{ label: 'Fechar', value: 1 }]);
    } }, 'Ver confirmações de leitura'));
}

// =================== REGISTOS ===================
export async function registos(params, query) {
  const p = new URLSearchParams();
  for (const k of ['status', 'mine', 'q', 'categoria', 'tipo', 'ano', 'mes', 'template_id', 'asset_id']) if (query.get(k)) p.set(k, query.get(k));
  const locais = await outbox.list(userId());
  let r = null;
  try { r = await api('GET', '/records?' + p); } catch (e) { if (!e.offline) throw e; }
  const st = el('select', { 'aria-label': 'Estado' }, el('option', { value: '' }, 'Todos os estados'), Object.entries(ESTADOS).slice(0, 3).map(([v, l]) => el('option', { value: v }, l)));
  st.value = query.get('status') || '';
  const q = el('input', { type: 'search', placeholder: 'Procurar…', value: query.get('q') || '' });
  const go = () => { const n = new URLSearchParams(p); n.set('status', st.value); n.set('q', q.value); for (const [k, v] of [...n]) if (!v) n.delete(k); location.hash = '#/registos?' + n; };
  st.addEventListener('change', go);
  q.addEventListener('keydown', (e) => { if (e.key === 'Enter') go(); });
  mount(cabecalho('Registos', el('a', { class: 'btn', href: '#/documentos?formularios=1' }, '＋ Novo')),
    el('div', { class: 'filtros' }, q, st, el('button', { class: 'btn sec', onclick: go }, 'Filtrar')),
    locais.length ? el('section', { class: 'cartao aviso' }, el('h2', null, 'Neste dispositivo (pendentes de sincronizar)'),
      el('ul', { class: 'lista' }, locais.map((x) => el('li', null, el('a', { href: '#/registos/local/' + x.id }, x.title), ' ', badge(x.status === 'conflito' ? 'conflito' : 'local'), el('small', null, ' ' + fmtData(x.updatedAt, true)))))) : null,
    r ? (r.records.length ? tabela([
      { label: 'Ref.', key: 'ref' }, { label: 'Título', key: 'title' }, { label: 'Modelo', key: 'template_code' },
      { label: 'Estado', render: (x) => badge(x.status) }, { label: 'Versão', key: 'version_no' },
      { label: 'Autor', render: (x) => x.created_by_name }, { label: 'Atualizado', render: (x) => fmtData(x.updated_at, true) },
    ], r.records, (x) => { location.hash = '#/registos/' + x.id; }) : vazio('Sem registos.')) : el('p', { class: 'aviso' }, 'Sem ligação: a lista do servidor não está disponível.'));
}

export async function novoRegisto(params, query) {
  const modelo = await obterModelo(Number(params.tpl), null);
  const assetId = query.get('asset') ? Number(query.get('asset')) : null;
  await editorRegisto({ modelo, rec: null, local: null, assetId });
}

export async function registo(params) {
  const id = Number(params.id);
  let rec;
  try {
    rec = await api('GET', '/records/' + id);
  } catch (e) {
    if (!e.offline) throw e;
    const loc = await outbox.byRecord(id);
    if (loc) { location.hash = '#/registos/local/' + loc.id; return; }
    const c = await cache.get('record:' + id);
    if (!c) throw e;
    rec = c;
    rec._cache = true;
  }
  await cache.set('record:' + id, rec);
  const loc = await outbox.byRecord(id);
  if (loc && rec.status === 'rascunho') {
    toast('Existe uma versão local deste rascunho por sincronizar neste dispositivo.', 'aviso', 7000);
  }
  if (rec.status === 'rascunho' && rec.can_edit) {
    const modelo = await obterModelo(rec.template_id, rec.template_version_id);
    return editorRegisto({ modelo, rec, local: loc && loc.status !== 'conflito' ? loc : null });
  }
  return vistaRegisto(rec);
}

export async function registoLocal(params) {
  const loc = await outbox.get(params.uuid);
  if (!loc || loc.userId !== userId()) { mount(vazio('Rascunho local não encontrado neste dispositivo.')); return; }
  if (loc.status === 'conflito') return resolverConflito(loc);
  const modelo = await obterModelo(loc.templateId, loc.templateVersionId);
  return editorRegisto({ modelo, rec: loc.recordId ? { id: loc.recordId, lock_version: loc.baseLockVersion, status: 'rascunho', title: loc.title, template_id: loc.templateId, template_version_id: loc.templateVersionId } : null, local: loc });
}

/** Editor de rascunho: Guardar (servidor; sem ligação → fila local) e Descarregar (finalizar, só com ligação). */
async function editorRegisto({ modelo, rec, local, assetId }) {
  const ctx = {
    recordId: rec ? rec.id : (local ? local.recordId : null),
    lock: rec ? rec.lock_version : (local ? local.baseLockVersion : null),
    localId: local ? local.id : null,
    clientUuid: local && !local.recordId ? local.id : uuid(),
    templateId: modelo.template_id,
    templateVersionId: modelo.version_id,
    geo: (local && local.geo) || (rec && rec.geo) || null,
    assetId: assetId || (local && local.assetId) || (rec && rec.asset_id) || null,
    dirty: false,
    pendingAtt: local ? (local.attachments || []) : [],
  };
  const data = local ? local.data : (rec ? rec.data : null);
  const titulo = el('input', { type: 'text', class: 'titulo-registo', 'aria-label': 'Título do registo', value: (local && local.title) || (rec && rec.title) || `${modelo.code} — ${modelo.title}` });
  const estado = el('p', { class: 'estado-guardado', role: 'status' });
  const setEstado = (txt, tipo = '') => { estado.textContent = txt; estado.className = 'estado-guardado ' + tipo; };
  if (local) setEstado('Versão local deste dispositivo — pendente de sincronizar (' + fmtData(local.updatedAt, true) + ').', 'aviso');
  else if (rec) setEstado(`Rascunho guardado no servidor (${rec.ref || 'REG'} · alteração n.º ${rec.lock_version}).`, 'ok');
  else setEstado('Novo registo — ainda não guardado.', '');
  const frameBox = el('div', { class: 'quadro-box' });
  const anexosBox = el('div', { class: 'anexos' });
  const geoInfo = el('small', { class: 'geo-info' }, ctx.geo ? `📍 ${ctx.geo.lat}, ${ctx.geo.lng} (±${ctx.geo.accuracy || '?'} m)` : '');

  const btnGuardar = el('button', { class: 'btn', type: 'button', onclick: () => guardar() }, '💾 Guardar');
  const btnFinal = el('button', { class: 'btn finalizar', type: 'button', onclick: () => finalizar() }, '📤 Descarregar (finalizar)');
  const motivoOffline = el('small', { class: 'motivo-offline' }, 'Finalizar exige ligação ao servidor.');
  const atualizarBotoes = () => {
    btnFinal.disabled = !state.online;
    btnFinal.title = state.online ? 'Finaliza, gera a versão final com SHA-256 e arquiva no SGI.' : 'Sem ligação: finalizar e aprovar exigem confirmação do servidor.';
    motivoOffline.hidden = state.online;
  };
  atualizarBotoes();
  const onl = setInterval(atualizarBotoes, 1500);
  window.addEventListener('hashchange', () => clearInterval(onl), { once: true });

  const barra = el('div', { class: 'barra-registo' }, btnGuardar, btnFinal, motivoOffline,
    el('button', { class: 'btn sec', type: 'button', onclick: () => foto() }, '📷 Foto'),
    el('button', { class: 'btn sec', type: 'button', onclick: () => assinatura() }, '✍ Assinatura'),
    el('button', { class: 'btn sec', type: 'button', onclick: async () => { const g = await pedirLocalizacao(); if (g) { ctx.geo = g; geoInfo.textContent = `📍 ${g.lat}, ${g.lng} (±${g.accuracy} m)`; ctx.dirty = true; toast('Localização associada. Guarde o registo.', 'info'); } } }, '📍 Localização'),
    el('button', { class: 'btn sec', type: 'button', onclick: () => host.print() }, '🖨 Imprimir'),
    rec && rec.id ? el('button', { class: 'btn sec perigo-texto', type: 'button', onclick: () => eliminar() }, '🗑 Eliminar rascunho') : null);

  mount(cabecalho(modelo.code + ' · ' + (rec && rec.ref ? rec.ref : 'novo registo')),
    el('p', { class: 'meta' }, `${modelo.title} · modelo v${modelo.version_no}`, modelo.origem === 'offline' ? ` · cópia offline de ${fmtData(modelo.copied_at, true)}` : ''),
    titulo, estado, geoInfo, barra, frameBox,
    el('section', { class: 'cartao' }, el('h2', null, 'Fotografias, assinaturas e anexos'), anexosBox));

  const host = await hostForm(frameBox, { html: modelo.html, mode: 'edit', data, title: modelo.title, onChange: () => { ctx.dirty = true; setEstado('Alterações por guardar.', 'aviso'); }, onLink: abrirLigacao });
  window.__sgiEditor = { host, ctx }; // usado pelos testes automáticos
  titulo.addEventListener('input', () => { ctx.dirty = true; });
  renderAnexos();

  async function renderAnexos() {
    clear(anexosBox);
    const locais = ctx.pendingAtt.filter((a) => !a.uploaded);
    if (locais.length) anexosBox.appendChild(el('ul', { class: 'lista' }, locais.map((a) => el('li', null, `${a.kind === 'assinatura' ? '✍' : '📷'} ${a.name} — ${fmtBytes(a.blob.size)} `, badge('local')))));
    if (ctx.recordId && state.online) {
      try {
        const r = await api('GET', `/attachments?entity_type=record&entity_id=${ctx.recordId}`);
        anexosBox.appendChild(listaAnexos(r.attachments, true, renderAnexos));
      } catch (e) { /* offline */ }
    }
    if (!anexosBox.childNodes.length) anexosBox.appendChild(vazio('Sem anexos.'));
  }

  async function guardar({ silencioso = false } = {}) {
    btnGuardar.disabled = true;
    try {
      const d = await host.collect();
      const body = { data: d, title: titulo.value.trim() || modelo.code, geo: ctx.geo || undefined };
      if (state.online || !ctx.localId) {
        try {
          let r;
          if (ctx.recordId) r = await api('PUT', '/records/' + ctx.recordId, { ...body, lock_version: ctx.lock }, { idem: 'save-' + uuid() });
          else r = await api('POST', '/records', { ...body, template_id: ctx.templateId, client_uuid: ctx.clientUuid, asset_id: ctx.assetId || undefined }, { idem: 'create-' + ctx.clientUuid + '-' + Date.now() });
          if (r.duplicado && r.lock_version !== ctx.lock) {
            ctx.recordId = r.id; ctx.lock = r.lock_version;
            r = await api('PUT', '/records/' + r.id, { ...body, lock_version: r.lock_version });
          }
          ctx.recordId = r.id; ctx.lock = r.lock_version; ctx.dirty = false;
          // anexos locais pendentes
          if (ctx.pendingAtt.some((a) => !a.uploaded)) {
            await uploadAttachments(r.id, ctx.pendingAtt).catch((e) => toast('Alguns anexos ainda não foram enviados: ' + e.message, 'aviso'));
          }
          if (ctx.localId) { await outbox.del(ctx.localId); ctx.localId = null; refreshSyncBadge(); }
          setEstado(`Rascunho guardado no servidor às ${new Date().toLocaleTimeString('pt-PT')} (${r.ref} · alteração n.º ${ctx.lock}).`, 'ok');
          if (!silencioso) toast('Rascunho guardado no servidor.', 'ok');
          if (location.hash.startsWith('#/registos/novo') || location.hash.startsWith('#/registos/local')) history.replaceState(null, '', '#/registos/' + r.id);
          renderAnexos();
          return r;
        } catch (e) {
          if (e.status === 409 && e.code === 'conflito') {
            const item = await saveLocal({ id: ctx.localId || undefined, recordId: ctx.recordId, templateId: ctx.templateId, templateVersionId: ctx.templateVersionId, title: body.title, data: d, baseLockVersion: ctx.lock, geo: ctx.geo, assetId: ctx.assetId, attachments: ctx.pendingAtt, status: 'conflito' });
            item.server = e.data.servidor; await outbox.put(item);
            toast('Conflito: outra pessoa alterou este rascunho. A sua versão ficou guardada neste dispositivo. Escolha como resolver.', 'aviso', 9000);
            location.hash = '#/registos/local/' + item.id;
            return null;
          }
          if (!e.offline) throw e;
        }
      }
      // Sem ligação → fila local
      const item = await saveLocal({ id: ctx.localId || ctx.clientUuid, recordId: ctx.recordId, templateId: ctx.templateId, templateVersionId: ctx.templateVersionId, title: body.title, data: d, baseLockVersion: ctx.lock, geo: ctx.geo, assetId: ctx.assetId, attachments: ctx.pendingAtt });
      ctx.localId = item.id; ctx.dirty = false;
      setEstado('Sem ligação: guardado APENAS neste dispositivo — pendente de sincronizar (' + new Date().toLocaleTimeString('pt-PT') + ').', 'aviso');
      if (!silencioso) toast('Sem ligação: rascunho guardado só neste dispositivo. Será sincronizado quando houver ligação.', 'aviso', 7000);
      return null;
    } catch (e) {
      toast('Não foi guardado: ' + e.message, 'erro', 8000);
      setEstado('Erro ao guardar: ' + e.message, 'erro');
      return null;
    } finally {
      btnGuardar.disabled = false;
    }
  }

  async function finalizar() {
    if (!state.online) { await ping(); }
    if (!state.online) {
      await modal('Finalizar exige ligação', el('p', null, 'Para finalizar (Descarregar) é necessária ligação ao servidor: a versão final, o SHA-256 e o bloqueio são feitos no servidor. Guarde o rascunho e finalize quando tiver ligação.'), [{ label: 'Entendi', value: 1 }]);
      return;
    }
    // campos obrigatórios (verificação prévia no aparelho; o servidor volta a verificar)
    const d = await host.collect();
    const falta = (d.required || []).filter((k) => !String(d.fields[k] || '').replace(/<[^>]*>/g, '').trim());
    if (falta.length) {
      host.mark(falta);
      toast('Preencha os campos obrigatórios assinalados: ' + falta.map((k) => d.labels[k] || k).join(', '), 'erro', 8000);
      return;
    }
    const ok = await confirmar('Finalizar e arquivar?', 'Depois de finalizado, o registo fica bloqueado (não pode ser alterado nem eliminado) e é arquivado em Registos / Categoria / Tipo / Ano / Mês com SHA-256. Correções posteriores criam uma nova versão vinculada. Se o modelo exigir aprovação prévia, será enviado ao aprovador.', 'Finalizar');
    if (!ok) return;
    const saved = await guardar({ silencioso: true });
    if (!saved || !ctx.recordId) { toast('Não foi possível guardar no servidor antes de finalizar.', 'erro'); return; }
    btnFinal.disabled = true;
    try {
      const snap = await host.snapshot();
      const r = await api('POST', `/records/${ctx.recordId}/finalize`, { final_html: snap.html, lock_version: ctx.lock, confirm: true });
      toast(r.mensagem || 'Finalizado.', 'ok', 7000);
      navegar('#/registos/' + r.id);
    } catch (e) {
      if (e.code === 'obrigatorios') {
        const keys = Object.entries(d.labels).filter(([, l]) => (e.data.campos || []).includes(l)).map(([k]) => k);
        host.mark(keys);
      }
      toast(e.message, 'erro', 9000);
    } finally { btnFinal.disabled = !state.online; }
  }

  async function foto() {
    const f = await escolherFoto();
    if (!f) return;
    let c;
    try { c = await comprimirImagem(f); } catch (e) { toast(e.message, 'erro'); return; }
    const legenda = await pedirTexto('Legenda da fotografia', 'Descreva a evidência (opcional)', { obrigatorio: false });
    await anexar({ blob: c.blob, name: 'foto-' + new Date().toISOString().replace(/[:.]/g, '-') + '.jpg', kind: 'foto', caption: legenda || '' });
    toast(`Fotografia comprimida no aparelho: ${fmtBytes(c.original)} → ${fmtBytes(c.comprimido)}.`, 'info');
  }

  async function assinatura() {
    const s = await recolherAssinatura();
    if (!s) return;
    await anexar({ blob: s.blob, name: 'assinatura-' + s.nome.replace(/[^\w-]+/g, '_') + '.png', kind: 'assinatura', caption: 'Assinatura no ecrã de ' + s.nome + ' (registo visual, não é assinatura digital certificada)' });
  }

  async function anexar(att) {
    att.idemKey = 'att-' + uuid();
    if (ctx.geo) { att.lat = ctx.geo.lat; att.lng = ctx.geo.lng; }
    if (state.online) {
      if (!ctx.recordId) { const r = await guardar({ silencioso: true }); if (!r) { ctx.pendingAtt.push(att); renderAnexos(); return; } }
      try {
        const fd = new FormData();
        fd.append('entity_type', 'record'); fd.append('entity_id', String(ctx.recordId)); fd.append('kind', att.kind);
        if (att.caption) fd.append('caption', att.caption);
        if (att.lat != null) { fd.append('lat', String(att.lat)); fd.append('lng', String(att.lng)); }
        fd.append('file', att.blob, att.name);
        await api('POST', '/attachments', fd, { idem: att.idemKey });
        toast('Anexo guardado no servidor.', 'ok');
        renderAnexos();
        return;
      } catch (e) { if (!e.offline) { toast(e.message, 'erro'); return; } }
    }
    ctx.pendingAtt.push(att);
    await guardar({ silencioso: true });
    toast('Sem ligação: anexo guardado neste dispositivo, pendente de sincronizar.', 'aviso');
    renderAnexos();
  }

  async function eliminar() {
    if (!await confirmar('Eliminar rascunho?', 'O rascunho e os seus anexos serão eliminados do servidor. Registos finais nunca podem ser eliminados.', 'Eliminar', true)) return;
    try { await api('DELETE', '/records/' + ctx.recordId); if (ctx.localId) await outbox.del(ctx.localId); toast('Rascunho eliminado.', 'ok'); location.hash = '#/registos'; } catch (e) { toast(e.message, 'erro'); }
  }
}

function listaAnexos(list, podeEliminar, recarregar) {
  if (!list.length) return vazio('Sem anexos no servidor.');
  return el('ul', { class: 'lista-anexos' }, list.map((a) => {
    const img = el('img', { alt: a.caption || a.original_name, class: 'miniatura', loading: 'lazy' });
    if (/^image\//.test(a.mime)) blob(`/attachments/${a.id}/file`).then((b) => { img.src = URL.createObjectURL(b); }).catch(() => {});
    return el('li', null, /^image\//.test(a.mime) ? img : el('span', { class: 'icone-ficheiro' }, '📄'),
      el('div', null, el('strong', null, a.kind === 'assinatura' ? 'Assinatura (visual)' : a.kind === 'foto' ? 'Fotografia' : 'Ficheiro'), ' ', a.original_name,
        el('small', null, ` · ${fmtBytes(+a.size_bytes)} · ${fmtData(a.created_at, true)} · ${a.author || ''}`, a.geo_lat ? ` · 📍 ${(+a.geo_lat).toFixed(5)}, ${(+a.geo_lng).toFixed(5)}` : ''),
        a.caption ? el('p', null, a.caption) : null,
        el('code', { class: 'hash' }, 'SHA-256 ' + a.sha256.slice(0, 20) + '…'),
        el('div', { class: 'acoes' },
          el('button', { class: 'btn mini sec', onclick: async () => baixarBlob(await blob(`/attachments/${a.id}/file?download=1`), a.original_name) }, 'Baixar'),
          podeEliminar ? el('button', { class: 'btn mini sec', onclick: async () => {
            if (!await confirmar('Remover anexo?', a.original_name, 'Remover', true)) return;
            try { await api('DELETE', '/attachments/' + a.id); recarregar(); } catch (e) { toast(e.message, 'erro'); }
          } }, 'Remover') : null)));
  }));
}

async function vistaRegisto(rec) {
  const box = el('div', { class: 'quadro-box' });
  const acoes = [];
  const info = [];
  if (rec.status === 'final') {
    acoes.push(el('button', { class: 'btn sec', onclick: async () => baixarBlob(await blob(`/records/${rec.id}/final?download=1`), `${rec.ref}-v${rec.version_no}.html`) }, '⬇ Baixar para o computador'));
    acoes.push(el('button', { class: 'btn sec', onclick: async () => {
      try { const v = await api('GET', `/records/${rec.id}/verify`); toast(v.ok ? 'Integridade confirmada: SHA-256 igual ao registado.' : 'ATENÇÃO: o ficheiro final não corresponde ao SHA-256 registado.', v.ok ? 'ok' : 'erro', 8000); } catch (e) { toast(e.message, 'erro'); }
    } }, '🔒 Verificar integridade'));
    acoes.push(el('button', { class: 'btn sec', onclick: async () => {
      const motivo = await pedirTexto('Corrigir registo final', 'Motivo da correção (fica registado com autor e data). O registo original mantém-se inalterado.', { multiline: true });
      if (!motivo) return;
      try { const r = await api('POST', `/records/${rec.id}/correct`, { reason: motivo }); toast('Nova versão vinculada criada como rascunho.', 'ok'); location.hash = '#/registos/' + r.id; }
      catch (e) { if (e.code === 'correcao_existente') location.hash = '#/registos/' + e.data.id; toast(e.message, 'erro'); }
    } }, '✎ Corrigir (nova versão)'));
    info.push(el('div', { class: 'cartao ok' },
      el('p', null, el('strong', null, 'Registo final bloqueado.'), ` Finalizado por ${rec.finalized_by_name || '—'} em ${fmtData(rec.finalized_at, true)}.`),
      el('p', null, `Arquivo: Registos / ${rec.archive.categoria} / ${rec.archive.tipo} / ${rec.archive.ano} / ${String(rec.archive.mes).padStart(2, '0')}`),
      el('p', null, 'SHA-256: ', el('code', { class: 'hash' }, rec.final_sha256), ` (${fmtBytes(rec.final_size)})`),
      el('p', { class: 'nota' }, 'O SHA-256 permite detetar alterações ao ficheiro, mas não é uma assinatura digital certificada.')));
  } else if (rec.status === 'pendente_aprovacao') {
    info.push(el('div', { class: 'cartao aviso' }, el('p', null, 'Aguarda aprovação prévia. Não pode ser alterado até à decisão.')));
  } else if (rec.status === 'rascunho') {
    info.push(el('div', { class: 'cartao' }, el('p', null, 'Rascunho de ' + (rec.created_by_name || '—') + '. Só o autor ou o coordenador do SGI o podem editar.')));
  }
  if (rec._cache) info.push(el('p', { class: 'aviso' }, 'Sem ligação: dados da última consulta neste dispositivo.'));
  const aprovs = (rec.approvals || []).length ? el('section', { class: 'cartao' }, el('h2', null, 'Aprovações'), tabela([
    { label: 'Estado', key: 'status' }, { label: 'Pedido por', key: 'requested_by_name' }, { label: 'Perfil', key: 'approver_role' },
    { label: 'Decisão por', render: (a) => a.decided_by_name || '—' }, { label: 'Data', render: (a) => fmtData(a.decided_at || a.created_at, true) }, { label: 'Comentário', render: (a) => a.comment || '—' }], rec.approvals)) : null;
  const versBox = el('section', { class: 'cartao' }, el('h2', null, 'Versões vinculadas'));
  const anexBox = el('section', { class: 'cartao' }, el('h2', null, 'Anexos e evidências'));
  mount(cabecalho(rec.ref + ' · ' + rec.title), el('p', { class: 'meta' }, `${rec.template_code} · `, badge(rec.status), ` · versão ${rec.version_no}`,
    rec.correction_reason ? ` · correção: ${rec.correction_reason}` : ''), info, el('div', { class: 'acoes-barra' }, acoes), box, aprovs, versBox, anexBox);
  if (rec.status === 'final') {
    try {
      const b = await blob(`/records/${rec.id}/final`);
      const url = URL.createObjectURL(new Blob([b], { type: 'text/html' }));
      box.appendChild(el('iframe', { class: 'quadro-modelo', sandbox: '', src: url, title: 'Versão final' }));
    } catch (e) { box.appendChild(el('p', { class: 'aviso' }, 'Versão final indisponível sem ligação.')); }
  } else {
    const modelo = await obterModelo(rec.template_id, rec.template_version_id);
    await hostForm(box, { html: modelo.html, mode: 'view', data: rec.data, title: rec.title });
  }
  if (!rec._cache) {
    api('GET', `/records/${rec.id}/versions`).then((v) => {
      versBox.appendChild(tabela([{ label: 'Ref.', render: (x) => el('a', { href: '#/registos/' + x.id }, 'REG-' + String(x.id).padStart(6, '0')) },
        { label: 'Versão', key: 'version_no' }, { label: 'Estado', render: (x) => badge(x.status) }, { label: 'Autor', key: 'author' },
        { label: 'Motivo da correção', render: (x) => x.correction_reason || '—' }, { label: 'Finalizado', render: (x) => fmtData(x.finalized_at, true) }], v.versions));
    }).catch(() => {});
    api('GET', `/attachments?entity_type=record&entity_id=${rec.id}`).then((r) => anexBox.appendChild(listaAnexos(r.attachments, false))).catch(() => {});
  }
}

// =================== ARQUIVO ===================
export async function arquivo() {
  const r = await api('GET', '/archive');
  const arvore = {};
  for (const n of r.tree) {
    ((arvore[n.categoria] ||= {})[n.tipo] ||= []).push(n);
  }
  mount(cabecalho('Arquivo de registos finais'), el('p', { class: 'nota' }, 'Registos / Categoria / Tipo / Ano / Mês'),
    Object.keys(arvore).length ? el('ul', { class: 'arvore' }, Object.entries(arvore).map(([cat, tipos]) => el('li', null, el('details', { open: true },
      el('summary', null, '📁 ' + cat),
      el('ul', null, Object.entries(tipos).map(([tipo, meses]) => el('li', null, el('details', null, el('summary', null, '📁 ' + tipo),
        el('ul', null, meses.map((m) => el('li', null, el('a', { href: '#/registos?' + new URLSearchParams({ status: 'final', categoria: cat, tipo, ano: m.ano, mes: m.mes }) },
          `📁 ${m.ano} / ${String(m.mes).padStart(2, '0')}`), el('small', null, ` (${m.n})`)))))))))))) : vazio('Ainda não há registos finais.'));
}

// =================== APROVAÇÕES ===================
export async function aprovacoes() {
  const r = await api('GET', '/approvals?status=pendente');
  mount(cabecalho('Aprovações pendentes'),
    r.approvals.length ? el('ul', { class: 'lista-cartoes' }, r.approvals.map((a) => el('li', { class: 'cartao' },
      el('h3', null, el('a', { href: '#/registos/' + a.record_id }, `REG-${String(a.record_id).padStart(6, '0')} · ${a.title}`)),
      el('p', { class: 'meta' }, `${a.template_code} · pedido por ${a.requested_by_name} em ${fmtData(a.created_at, true)} · perfil mínimo: ${a.approver_role}`),
      a.can_decide ? el('div', { class: 'acoes' },
        el('button', { class: 'btn', onclick: () => decidir(a, 'aprovar') }, 'Aprovar e finalizar'),
        el('button', { class: 'btn sec', onclick: () => decidir(a, 'rejeitar') }, 'Devolver para correção')) : el('p', { class: 'nota' }, 'Não pode decidir este pedido (perfil insuficiente ou é o requerente).')))) : vazio('Não há aprovações pendentes.'));
}

async function decidir(a, decisao) {
  if (!state.online) { toast('Aprovar exige ligação ao servidor.', 'aviso'); return; }
  const comentario = await pedirTexto(decisao === 'aprovar' ? 'Aprovar registo' : 'Devolver para correção', decisao === 'aprovar' ? 'Comentário (opcional)' : 'Motivo (obrigatório)', { obrigatorio: decisao !== 'aprovar', multiline: true });
  if (comentario === null) return;
  try { await api('POST', '/approvals/' + a.id, { decision: decisao, comment: comentario }); toast(decisao === 'aprovar' ? 'Aprovado: registo finalizado e arquivado.' : 'Devolvido ao autor.', 'ok'); aprovacoes(); }
  catch (e) { toast(e.message, 'erro'); }
}

// =================== SINCRONIZAÇÃO E CONFLITOS ===================
export async function sincronizar() {
  const list = await outbox.list(userId());
  const btn = el('button', { class: 'btn', onclick: async () => {
    btn.disabled = true;
    await ping();
    const r = await syncAll();
    toast(`Enviados: ${r.enviados} · conflitos: ${r.conflitos} · erros: ${r.erros}`, r.conflitos || r.erros ? 'aviso' : 'ok');
    sincronizar();
  } }, '⟳ Sincronizar agora');
  btn.disabled = !state.online;
  mount(cabecalho('Sincronização', btn),
    el('p', { class: 'nota' }, 'Os rascunhos abaixo estão guardados APENAS neste dispositivo. O guardado oficial é o servidor. Cada envio usa uma chave de idempotência (sem duplicados) e a versão do rascunho (sem sobrescrever trabalho de outra pessoa).'),
    list.length ? tabela([
      { label: 'Título', key: 'title' },
      { label: 'Estado', render: (x) => x.status === 'conflito' ? badge('conflito') : x.status === 'erro' ? el('span', { class: 'estado estado-erro' }, 'Erro: ' + (x.error || '')) : badge('local') },
      { label: 'Guardado no aparelho', render: (x) => fmtData(x.updatedAt, true) },
      { label: 'Anexos locais', render: (x) => String((x.attachments || []).filter((a) => !a.uploaded).length) },
      { label: '', render: (x) => el('a', { class: 'btn mini', href: '#/registos/local/' + x.id }, x.status === 'conflito' ? 'Resolver' : 'Abrir') },
    ], list) : vazio('Nada por sincronizar. ✓'));
}

async function resolverConflito(loc) {
  let server = loc.server;
  if (state.online && (loc.recordId || (server && server.id))) {
    try { server = await api('GET', '/records/' + (loc.recordId || server.id)); } catch (e) { /* usa cópia */ }
  }
  const mine = (loc.data && loc.data.fields) || {};
  const theirs = (server && server.data && server.data.fields) || {};
  const labels = Object.assign({}, (server && server.data && server.data.labels) || {}, (loc.data && loc.data.labels) || {});
  const keys = [...new Set([...Object.keys(mine), ...Object.keys(theirs)])].filter((k) => (mine[k] || '') !== (theirs[k] || ''));
  const txt = (v) => String(v || '').replace(/<[^>]*>/g, ' ').trim() || '(vazio)';
  const recId = loc.recordId || (server && server.id);
  const acoes = [];
  if (loc.serverLocked || (server && server.status !== 'rascunho')) {
    acoes.push(el('p', { class: 'aviso' }, 'O registo no servidor já não é um rascunho (foi enviado para aprovação ou finalizado). A sua versão local mantém-se guardada; pode guardá-la como novo rascunho.'));
  } else {
    acoes.push(el('button', { class: 'btn', onclick: async () => {
      if (!await confirmar('Manter a minha versão?', 'A sua versão substitui a do servidor. A versão do servidor fica registada no histórico de auditoria.', 'Manter a minha')) return;
      try { await api('PUT', '/records/' + recId, { data: loc.data, title: loc.title, lock_version: server.lock_version }, { idem: 'res-' + uuid() }); await outbox.del(loc.id); refreshSyncBadge(); toast('Conflito resolvido: a sua versão foi guardada.', 'ok'); navegar('#/registos/' + recId); }
      catch (e) { toast(e.message, 'erro'); }
    } }, 'Manter a minha versão'));
    acoes.push(el('button', { class: 'btn sec', onclick: async () => {
      if (!await confirmar('Usar a versão do servidor?', 'A sua versão local será eliminada deste dispositivo.', 'Usar a do servidor', true)) return;
      await outbox.del(loc.id); refreshSyncBadge(); location.hash = '#/registos/' + recId;
    } }, 'Usar a versão do servidor'));
  }
  acoes.push(el('button', { class: 'btn sec', onclick: async () => {
    try {
      const r = await api('POST', '/records', { template_id: loc.templateId, title: loc.title + ' (cópia do conflito)', data: loc.data, client_uuid: uuid() }, { idem: 'copy-' + uuid() });
      await outbox.del(loc.id); refreshSyncBadge(); toast('A sua versão foi guardada como novo rascunho ' + r.ref + '.', 'ok'); location.hash = '#/registos/' + r.id;
    } catch (e) { toast(e.message, 'erro'); }
  } }, 'Guardar a minha como novo rascunho'));
  if (!state.online) acoes.length = 0;
  mount(cabecalho('Resolver conflito'),
    el('div', { class: 'cartao aviso' }, el('p', null, `«${loc.title}» foi alterado no servidor depois da sua cópia${server && server.updated_at ? ' (servidor: ' + fmtData(server.updated_at, true) + ')' : ''}. A sua versão está guardada neste dispositivo e não será perdida.`),
      loc.error ? el('p', null, loc.error) : null),
    state.online ? null : el('p', { class: 'aviso' }, 'Precisa de ligação para resolver o conflito.'),
    keys.length ? tabela([{ label: 'Campo', render: (k) => labels[k] || k }, { label: 'A minha versão (dispositivo)', render: (k) => txt(mine[k]) }, { label: 'Versão do servidor', render: (k) => txt(theirs[k]) }], keys) : vazio('Os campos são iguais; apenas a versão de controlo mudou.'),
    el('div', { class: 'acoes-barra' }, acoes));
}

// =================== NOTIFICAÇÕES ===================
export async function notificacoes() {
  const r = await api('GET', '/notifications');
  mount(cabecalho('Notificações', el('button', { class: 'btn sec', onclick: async () => { await api('POST', '/notifications/read-all'); notificacoes(); } }, 'Marcar todas como lidas')),
    r.notifications.length ? el('ul', { class: 'lista-cartoes' }, r.notifications.map((n) => el('li', { class: 'cartao' + (n.read_at ? '' : ' nao-lida') },
      el('strong', null, n.title), n.body ? el('p', null, n.body) : null, el('small', null, fmtData(n.created_at, true)),
      n.link ? el('a', { class: 'btn mini', href: n.link, onclick: () => api('POST', `/notifications/${n.id}/read`).catch(() => {}) }, 'Abrir') : null))) : vazio('Sem notificações.'));
  document.getElementById('badge-notif').hidden = true;
}

// =================== QR ===================
export async function leitorQr() {
  const video = el('video', { class: 'leitor-video', muted: true, playsinline: true, 'aria-label': 'Imagem da câmara' });
  const msg = el('p', { class: 'nota' }, 'Aponte a câmara para a etiqueta QR do equipamento, extintor ou local.');
  let parar = null;
  const resultado = async (txt) => {
    const tok = tokenDoTexto(txt);
    if (parar) parar();
    if (!tok) { toast('Este QR não é uma etiqueta do SGI.', 'aviso'); return; }
    location.hash = '#/qr/' + tok;
  };
  const iniciar = el('button', { class: 'btn', onclick: async () => {
    try { parar = await iniciarLeitorQr(video, resultado); iniciar.hidden = true; }
    catch (e) { toast(e.name === 'NotAllowedError' ? 'Permissão da câmara recusada.' : e.message, 'erro'); }
  } }, '📷 Ativar câmara');
  const ficheiro = el('input', { type: 'file', accept: 'image/*', capture: 'environment', 'aria-label': 'Ler QR de uma fotografia' });
  ficheiro.addEventListener('change', async () => {
    if (!ficheiro.files[0]) return;
    const txt = await lerQrDeImagem(ficheiro.files[0]).catch(() => null);
    if (!txt) { toast('Não foi encontrado nenhum código QR na imagem.', 'aviso'); return; }
    resultado(txt);
  });
  const manual = el('input', { type: 'text', placeholder: 'Ou cole o endereço/código da etiqueta' });
  window.addEventListener('hashchange', () => { if (parar) parar(); }, { once: true });
  mount(cabecalho('Ler código QR'), msg, iniciar, video,
    el('div', { class: 'cartao' }, el('label', { class: 'campo' }, el('span', null, 'Ler de uma fotografia'), ficheiro),
      el('div', { class: 'linha' }, manual, el('button', { class: 'btn sec', onclick: () => resultado(manual.value.trim()) }, 'Abrir'))));
}

export async function abrirQr(params) {
  try {
    const r = await api('GET', '/qr/' + encodeURIComponent(params.token));
    location.replace('#/equipamentos/' + r.asset.id);
  } catch (e) {
    if (e.code === 'qr_desconhecido') {
      mount(cabecalho('Código QR não reconhecido'), el('div', { class: 'cartao erro' }, el('p', null, 'Esta etiqueta não pertence a nenhum equipamento, extintor ou local da sua organização.'), el('a', { class: 'btn', href: '#/qr' }, 'Ler outro código')));
      return;
    }
    throw e;
  }
}

// =================== PERFIL ===================
export async function perfil() {
  const u = state.me.user;
  const atual = el('input', { type: 'password', autocomplete: 'current-password' });
  const nova = el('input', { type: 'password', autocomplete: 'new-password', minlength: 10 });
  const nova2 = el('input', { type: 'password', autocomplete: 'new-password', minlength: 10 });
  const pwForm = el('form', { class: 'cartao', onsubmit: async (ev) => {
    ev.preventDefault();
    if (nova.value !== nova2.value) { toast('As palavras-passe não coincidem.', 'erro'); return; }
    try {
      const r = await api('POST', '/auth/password', { current: atual.value, new: nova.value });
      if (r.csrf) state.csrf = r.csrf;
      state.me.user.must_change_password = false;
      toast('Palavra-passe alterada. As sessões noutros dispositivos foram terminadas.', 'ok');
      pwForm.reset();
    } catch (e) { toast(e.message, 'erro'); }
  } }, el('h2', null, 'Alterar palavra-passe'), u.must_change_password ? el('p', { class: 'aviso' }, 'Tem de definir uma nova palavra-passe (a atual é temporária).') : null,
  campo('Palavra-passe atual', atual), campo('Nova palavra-passe (mín. 10, letras e números)', nova), campo('Repetir nova palavra-passe', nova2), el('button', { class: 'btn' }, 'Alterar'));

  const devBox = el('section', { class: 'cartao' }, el('h2', null, 'Dispositivos ligados'));
  const prefsBox = el('section', { class: 'cartao' }, el('h2', null, 'Notificações'));
  const localBox = el('section', { class: 'cartao' }, el('h2', null, 'Dados neste dispositivo'));
  mount(cabecalho('Perfil'), el('div', { class: 'cartao' }, el('p', null, el('strong', null, u.name), ' · ', u.email), el('p', null, u.role_label, state.me.org ? ' · ' + state.me.org.name : ''),
    el('p', { class: 'nota' }, `Sessão: ${state.mode === 'token' ? 'aplicação (token deste dispositivo)' : 'navegador (sessão)'} · versão ${state.me.version}`)), pwForm, devBox, prefsBox, localBox);

  // dispositivos
  try {
    const d = await api('GET', '/auth/devices');
    devBox.appendChild(d.devices.length ? tabela([
      { label: 'Dispositivo', render: (x) => [x.name, x.current ? el('strong', null, ' (este)') : ''] },
      { label: 'Ligado em', render: (x) => fmtData(x.created_at, true) }, { label: 'Último uso', render: (x) => fmtData(x.last_seen_at, true) },
      { label: 'Estado', render: (x) => x.active ? 'ativo' : 'encerrado ' + fmtData(x.revoked_at, true) },
      { label: '', render: (x) => x.active ? el('button', { class: 'btn mini sec', onclick: async () => {
        if (!await confirmar('Encerrar dispositivo?', `Os tokens de «${x.name}» deixam de funcionar imediatamente.`, 'Encerrar', true)) return;
        await api('DELETE', '/auth/devices/' + x.id); toast('Dispositivo encerrado.', 'ok');
        if (x.current) { location.reload(); return; }
        perfil();
      } }, 'Encerrar') : '' },
    ], d.devices) : vazio('Nenhuma aplicação ligada. As sessões de navegador não aparecem aqui.'));
  } catch (e) { devBox.appendChild(el('p', { class: 'aviso' }, e.message)); }

  // notificações
  try {
    const p = await api('GET', '/me/prefs');
    const em = el('input', { type: 'checkbox' }); em.checked = p.notify_email;
    const pu = el('input', { type: 'checkbox' }); pu.checked = p.notify_push;
    const save = () => api('PUT', '/me/prefs', { notify_email: em.checked, notify_push: pu.checked }).then(() => toast('Preferências guardadas.', 'ok'));
    em.addEventListener('change', save); pu.addEventListener('change', save);
    const est = await estadoPush().catch(() => 'nao_suportado');
    const estTxt = { ativo: 'ativas neste dispositivo', inativo: 'desligadas neste dispositivo', bloqueado: 'bloqueadas nas definições do dispositivo', nao_suportado: 'não suportadas por este navegador', servidor_desligado: 'desligadas no servidor' }[est];
    prefsBox.append(
      el('label', { class: 'chk' }, em, 'Receber por correio eletrónico', state.me.smtp ? '' : ' (correio não configurado no servidor)'),
      el('label', { class: 'chk' }, pu, 'Receber notificações push'),
      el('p', null, 'Push: ', el('strong', null, estTxt)),
      pushSuportado() && state.me.push_key ? el('div', { class: 'acoes' },
        est !== 'ativo' ? el('button', { class: 'btn', onclick: async () => { try { await ativarPush(); toast('Notificações ativadas neste dispositivo.', 'ok'); perfil(); } catch (e) { toast(e.message, 'erro', 8000); } } }, 'Ativar neste dispositivo')
          : el('button', { class: 'btn sec', onclick: async () => { await desativarPush(); perfil(); } }, 'Desativar neste dispositivo'),
        el('button', { class: 'btn sec', onclick: async () => { await api('POST', '/push/test'); toast('Notificação de teste enviada.', 'ok'); } }, 'Enviar teste')) : null,
      el('p', { class: 'nota' }, 'As notificações internas (sino 🔔) funcionam sempre, mesmo sem push.'));
  } catch (e) { prefsBox.appendChild(el('p', { class: 'aviso' }, e.message)); }

  const off = await docsOffline.list(orgId());
  localBox.append(
    el('p', null, `Documentos disponíveis offline: ${off.length}`),
    el('div', { class: 'acoes' },
      el('button', { class: 'btn sec', onclick: async () => { for (const d of off) await docsOffline.del(orgId(), d.templateId); toast('Cópias offline removidas.', 'ok'); perfil(); } }, 'Remover cópias offline'),
      el('button', { class: 'btn sec', onclick: () => { retirarConsentimentoGeo(); toast('Consentimento de localização retirado neste dispositivo.', 'ok'); } }, 'Retirar consentimento de localização')));
}

// =================== INSTALAR APP ===================
export function instrucoesInstalacao() {
  modal('Instalar a aplicação', conteudoInstalacao(), [{ label: 'Fechar', value: 1 }]);
}

function conteudoInstalacao() {
  const ios = /iPhone|iPad|iPod/i.test(navigator.userAgent);
  const btn = window.__sgiInstallPrompt ? el('button', { class: 'btn', onclick: async () => { const p = window.__sgiInstallPrompt; p.prompt(); await p.userChoice; window.__sgiInstallPrompt = null; } }, '📲 Instalar agora') : null;
  return el('div', null, btn,
    el('h3', null, 'Android (Chrome)'),
    el('ol', null, el('li', null, 'Abra este endereço no Chrome.'), el('li', null, 'Toque em ⋮ (menu) → «Adicionar ao ecrã principal» ou «Instalar aplicação».'), el('li', null, 'Confirme. O ícone do SGI aparece no ecrã inicial.')),
    el('h3', null, 'iPhone / iPad (Safari)'),
    el('ol', null, el('li', null, 'Abra este endereço no Safari (não noutro navegador).'), el('li', null, 'Toque em Partilhar (quadrado com seta ↑).'), el('li', null, 'Escolha «Adicionar ao ecrã principal» e toque em «Adicionar».')),
    ios ? el('p', { class: 'nota' }, 'No iPhone, as notificações push só funcionam com a aplicação adicionada ao ecrã principal (iOS 16.4 ou superior).') : null,
    el('p', { class: 'nota' }, 'Depois de instalada, inicie sessão uma vez com ligação. A aplicação abre sem internet e guarda rascunhos neste dispositivo até sincronizar.'));
}

export async function instalarApp() {
  mount(cabecalho('Instalar a aplicação'), el('div', { class: 'cartao' }, conteudoInstalacao()));
}

// =================== PALAVRA-PASSE ESQUECIDA ===================
export async function esqueciPalavraPasse(email) {
  const e = await pedirTexto('Recuperar acesso', 'Correio eletrónico da sua conta', { valor: email || '' });
  if (!e) return;
  try { const r = await api('POST', '/auth/forgot', { email: e }, { public: true }); modal('Recuperar acesso', el('p', null, r.mensagem), [{ label: 'OK', value: 1 }]); }
  catch (err) { toast(err.message, 'erro'); }
}

export async function redefinir(params) {
  document.getElementById('topo').hidden = true;
  const pw = el('input', { type: 'password', autocomplete: 'new-password', minlength: 10, required: true });
  const pw2 = el('input', { type: 'password', autocomplete: 'new-password', minlength: 10, required: true });
  mount(el('form', { class: 'login', onsubmit: async (ev) => {
    ev.preventDefault();
    if (pw.value !== pw2.value) { toast('As palavras-passe não coincidem.', 'erro'); return; }
    try { await api('POST', '/auth/reset', { token: params.token, password: pw.value }, { public: true }); toast('Palavra-passe redefinida. Inicie sessão.', 'ok'); location.hash = '#/inicio'; }
    catch (e) { toast(e.message, 'erro'); }
  } }, el('h1', null, 'Nova palavra-passe'), campo('Nova palavra-passe', pw), campo('Repetir', pw2), el('button', { class: 'btn largo' }, 'Guardar')));
}
