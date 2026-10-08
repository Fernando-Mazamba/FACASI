// Administração da organização e da plataforma.
import { api, blob, state, BASE } from './api.js';
import { el, mount, toast, modal, confirmar, fmtData, fmtBytes, cabecalho, vazio, tabela, clear, baixarBlob, campo, input, select, formData } from './ui.js';

function mostrarSenhaTemporaria(titulo, senha, extra = '') {
  return modal(titulo, [el('p', null, 'Palavra-passe temporária (mostrada UMA única vez; o utilizador terá de a alterar no primeiro acesso):'),
    el('p', { class: 'senha-temp' }, el('code', null, senha)), extra ? el('p', { class: 'nota' }, extra) : null], [{ label: 'Já anotei', value: 1 }]);
}

// =================== UTILIZADORES ===================
export async function utilizadores() {
  const r = await api('GET', '/users');
  const roles = Object.entries(r.roles);
  const novo = async () => {
    const f = el('form', { class: 'form-grelha' }, campo('Nome', input('name', '', { required: true })), campo('Correio eletrónico', input('email', '', { type: 'email', required: true })),
      campo('Perfil', select('role', roles, 'operario')));
    const ok = await modal('Novo utilizador', f, [{ label: 'Cancelar', value: null, class: 'sec' }, { label: 'Criar', value: 'ok', validate: () => f.reportValidity() }]);
    if (ok !== 'ok') return;
    try { const x = await api('POST', '/users', formData(f)); await mostrarSenhaTemporaria('Utilizador criado', x.temp_password); utilizadores(); } catch (e) { toast(e.message, 'erro'); }
  };
  const editar = async (u) => {
    const f = el('form', { class: 'form-grelha' }, campo('Nome', input('name', u.name, { required: true })), campo('Perfil', select('role', roles, u.role)),
      campo('Estado', select('status', [['ativo', 'Ativo'], ['inativo', 'Inativo (sem acesso)']], u.status)));
    const ok = await modal('Editar ' + u.email, [f, u.locked_until ? el('p', { class: 'aviso' }, 'Conta bloqueada até ' + fmtData(u.locked_until, true)) : null], [
      { label: 'Cancelar', value: null, class: 'sec' }, { label: 'Dispositivos', value: 'dev', class: 'sec' }, { label: 'Nova palavra-passe temporária', value: 'pw', class: 'sec' }, { label: 'Guardar', value: 'ok' }]);
    try {
      if (ok === 'ok') { await api('PUT', '/users/' + u.id, Object.assign(formData(f), u.locked_until ? { unlock: 1 } : {})); toast('Guardado.', 'ok'); utilizadores(); }
      if (ok === 'pw') { const x = await api('POST', `/users/${u.id}/reset-password`); await mostrarSenhaTemporaria('Palavra-passe redefinida', x.temp_password, 'As aplicações ligadas deste utilizador foram encerradas.'); }
      if (ok === 'dev') dispositivosDe(u);
    } catch (e) { toast(e.message, 'erro'); }
  };
  mount(cabecalho('Utilizadores', el('button', { class: 'btn', onclick: novo }, '＋ Novo utilizador')),
    el('p', { class: 'nota' }, 'Os nomes, cargos e contactos devem ser fornecidos pela organização — o SGI não inventa trabalhadores nem assinaturas.'),
    tabela([{ label: 'Nome', key: 'name' }, { label: 'Correio', key: 'email' }, { label: 'Perfil', key: 'role_label' }, { label: 'Estado', render: (u) => u.status + (u.locked_until ? ' (bloqueado)' : '') },
      { label: 'Último acesso', render: (u) => fmtData(u.last_login_at, true) }], r.users, editar));
}

async function dispositivosDe(u) {
  const d = await api('GET', '/auth/devices?user_id=' + u.id);
  const body = el('div');
  const render = (list) => { clear(body); body.appendChild(list.length ? tabela([{ label: 'Dispositivo', key: 'name' }, { label: 'Último uso', render: (x) => fmtData(x.last_seen_at, true) }, { label: 'Estado', render: (x) => x.active ? 'ativo' : 'encerrado' },
    { label: '', render: (x) => x.active ? el('button', { class: 'btn mini sec', onclick: async () => { await api('DELETE', '/auth/devices/' + x.id); toast('Encerrado.', 'ok'); render((await api('GET', '/auth/devices?user_id=' + u.id)).devices); } }, 'Encerrar') : '' }], list) : vazio('Sem aplicações ligadas.')); };
  render(d.devices);
  await modal('Dispositivos de ' + u.name, body, [{ label: 'Fechar', value: 1 }]);
}

// =================== ORGANIZAÇÃO ===================
export async function organizacao() {
  const { org } = await api('GET', '/org');
  const f = el('form', { class: 'cartao form-grelha', onsubmit: async (ev) => {
    ev.preventDefault();
    try { await api('PUT', '/org', formData(f)); toast('Dados da organização guardados.', 'ok'); } catch (e) { toast(e.message, 'erro'); }
  } }, campo('Nome', input('name', org.name, { required: true })), campo('Denominação legal', input('legal_name', org.legal_name)),
  campo('NIF', input('tax_id', org.tax_id), 'Apenas o NIF real fornecido pela organização.'), campo('Morada', input('address', org.address)), el('button', { class: 'btn' }, 'Guardar'));
  const logo = el('input', { type: 'file', accept: 'image/png,image/jpeg,image/webp' });
  mount(cabecalho('Organização'), f,
    el('section', { class: 'cartao' }, el('h2', null, 'Logótipo'), el('p', { class: 'nota' }, 'Usado nos documentos e no ícone da aplicação instalada.'), logo,
      el('button', { class: 'btn', onclick: async () => {
        if (!logo.files[0]) return;
        const fd = new FormData(); fd.append('file', logo.files[0]);
        try { await api('POST', '/org/logo', fd); toast('Logótipo atualizado.', 'ok'); } catch (e) { toast(e.message, 'erro'); }
      } }, 'Carregar logótipo')),
    el('section', { class: 'cartao' }, el('h2', null, 'Exportar dados da organização'),
      el('p', null, 'Descarrega um ZIP com todos os dados (JSON) e ficheiros desta organização (registos finais, anexos, documentos). Não inclui palavras-passe.'),
      el('button', { class: 'btn sec', onclick: async (ev) => { ev.target.disabled = true; try { baixarBlob(await blob('/org/export'), `exportacao-${org.slug}-${new Date().toISOString().slice(0, 10)}.zip`); } catch (e) { toast(e.message, 'erro'); } ev.target.disabled = false; } }, '⬇ Exportar (ZIP)')));
}

export async function auditoria() {
  const r = await api('GET', '/audit');
  mount(cabecalho('Registo de auditoria'), tabela([{ label: 'Data', render: (a) => fmtData(a.created_at, true) }, { label: 'Utilizador', render: (a) => a.user_name || '—' },
    { label: 'Ação', key: 'action' }, { label: 'Elemento', render: (a) => a.entity ? `${a.entity} #${a.entity_id || ''}` : '—' }, { label: 'Detalhe', render: (a) => (a.details || '').slice(0, 160) }, { label: 'IP', key: 'ip' }], r.audit));
}

// =================== PLATAFORMA ===================
export async function plataforma(params) {
  const sec = params.sec || 'estado';
  const abas = el('nav', { class: 'abas' }, [['estado', 'Estado do sistema'], ['organizacoes', 'Organizações'], ['biblioteca', 'Biblioteca'], ['copias', 'Cópias de segurança'], ['correio', 'Correio e push']]
    .map(([k, l]) => el('a', { href: '#/plataforma/' + k, class: k === sec ? 'ativo' : '' }, l)));
  const corpo = el('div');
  mount(cabecalho('Administração da plataforma'), el('p', { class: 'nota' }, 'A administração da plataforma gere a infraestrutura e não tem acesso aos documentos e registos das organizações.'), abas, corpo);
  const fn = { estado: secEstado, organizacoes: secOrgs, biblioteca: secBiblioteca, copias: secCopias, correio: secCorreio }[sec] || secEstado;
  await fn(corpo);
}

async function secEstado(c) {
  const s = await api('GET', '/platform/status');
  const linha = (k, v, ok) => el('tr', null, el('th', null, k), el('td', { class: ok === false ? 'atrasado' : '' }, v));
  const integ = s.integridade;
  c.append(el('div', { class: 'tabela-wrap' }, el('table', { class: 'tabela ficha-tabela', 'data-teste': 'estado-sistema' }, el('tbody', null,
    linha('Versão do sistema', s.versao_sistema), linha('Versão do esquema', s.versao_esquema, s.migracoes_pendentes.length === 0),
    linha('Migrações pendentes', s.migracoes_pendentes.length ? s.migracoes_pendentes.join(', ') : 'nenhuma', s.migracoes_pendentes.length === 0),
    linha('PHP', `${s.php} (${s.php_sapi})`), linha('Servidor web', s.servidor_web || '—'), linha('MySQL/MariaDB', s.mysql),
    linha('Extensões', Object.entries(s.extensoes).map(([k, v]) => `${k}${v ? '' : ' ✗'}`).join(' · '), Object.values(s.extensoes).every(Boolean)),
    linha('Limites PHP', Object.entries(s.limites).map(([k, v]) => `${k}=${v}`).join(' · ')),
    linha('Espaço livre em disco', s.disco_livre_bytes ? `${fmtBytes(s.disco_livre_bytes)} de ${fmtBytes(s.disco_total_bytes)}` : 'indisponível (hospedagem limita a consulta)'),
    linha('Ficheiros do SGI', `${s.armazenamento_ficheiros} ficheiros · ${fmtBytes(s.armazenamento_bytes)} · BD ${fmtBytes(s.bd_bytes)}`),
    linha('Pasta de dados fora da pasta pública', s.storage_fora_publico ? 'sim' : 'não (protegida por .htaccess)', s.storage_fora_publico ? true : null),
    linha('HTTPS', s.https ? 'sim' : 'não — ative o certificado SSL no cPanel', s.https),
    linha('Última cópia de segurança', s.ultima_copia ? fmtData(s.ultima_copia, true) + ' · ' + s.ultima_copia_ficheiro : 'nunca', !!s.ultima_copia),
    linha('Última execução das tarefas diárias', s.ultima_tarefa_diaria ? fmtData(s.ultima_tarefa_diaria, true) : 'nunca', !!s.ultima_tarefa_diaria),
    linha('Verificação de integridade das versões finais', integ ? `${fmtData(integ.verificado_em, true)} · ${integ.ok} OK · ${integ.falhas} com problema` : 'ainda não executada', integ ? integ.falhas === 0 : null),
    linha('Correio SMTP', s.smtp_ativo ? 'ativo' : 'desligado (notificações internas apenas)'),
    linha('Notificações push', s.push_ativo ? 'ativas' : (s.push_suportado ? 'desligadas' : 'não suportadas neste servidor')),
    linha('Organizações / utilizadores ativos / registos finais', `${s.organizacoes} / ${s.utilizadores} / ${s.registos_finais}`),
    linha('Instalado / atualizado', `${fmtData(s.instalado_em, true)} / ${s.atualizado_em ? fmtData(s.atualizado_em, true) : '—'}`)))),
  el('div', { class: 'acoes-barra' },
    el('button', { class: 'btn', onclick: async (ev) => { ev.target.disabled = true; try { const r = await api('POST', '/platform/integrity'); toast(`Integridade: ${r.ok} OK, ${r.falhas} com problema.`, r.falhas ? 'erro' : 'ok', 8000); plataforma({ sec: 'estado' }); } catch (e) { toast(e.message, 'erro'); } } }, '🔒 Verificar integridade agora'),
    el('button', { class: 'btn sec', onclick: async () => { const r = await api('POST', '/platform/cron'); toast('Tarefas diárias executadas: ' + JSON.stringify(r), 'ok', 8000); plataforma({ sec: 'estado' }); } }, '⏱ Executar tarefas diárias'),
    el('a', { class: 'btn sec', href: BASE + '/atualizar/' }, '⬆ Assistente de atualização')),
  integ && integ.falhas ? el('section', { class: 'cartao erro' }, el('h2', null, 'Versões finais com problema'), tabela([{ label: 'Registo', key: 'registo' }, { label: 'Org.', key: 'org_id' }, { label: 'Problema', key: 'problema' }], integ.detalhe)) : null);
}

async function secOrgs(c) {
  const r = await api('GET', '/platform/orgs');
  const lib = await api('GET', '/platform/library');
  const aprovados = lib.items.filter((x) => x.status === 'aprovado');
  const nova = async () => {
    const f = el('form', { class: 'form-grelha' }, campo('Nome da organização', input('name', '', { required: true })), campo('Identificador (opcional)', input('slug')),
      campo('NIF (opcional, real)', input('tax_id')), campo('Nome do administrador', input('admin_name', '', { required: true })), campo('Correio do administrador', input('admin_email', '', { type: 'email', required: true })));
    const libSel = aprovados.length ? el('fieldset', null, el('legend', null, `Entregar modelos aprovados e neutralizados (${aprovados.length})`),
      el('label', { class: 'chk' }, el('input', { type: 'checkbox', name: 'todos', checked: true }), 'Todos os modelos aprovados')) : el('p', { class: 'nota' }, 'Não há modelos aprovados na biblioteca: a organização começa sem documentos.');
    const ok = await modal('Nova organização', [f, libSel], [{ label: 'Cancelar', value: null, class: 'sec' }, { label: 'Criar', value: 'ok', validate: () => f.reportValidity() }]);
    if (ok !== 'ok') return;
    const body = formData(f);
    const todos = libSel.querySelector && libSel.querySelector('input[name=todos]');
    if (todos && todos.checked) body.library_ids = aprovados.map((x) => x.id);
    try { const x = await api('POST', '/platform/orgs', body); await mostrarSenhaTemporaria('Organização criada', x.admin_temp_password, `Modelos entregues: ${x.modelos_entregues}.`); plataforma({ sec: 'organizacoes' }); } catch (e) { toast(e.message, 'erro'); }
  };
  c.append(el('div', { class: 'acoes-barra' }, el('button', { class: 'btn', onclick: nova }, '＋ Nova organização')),
    tabela([{ label: 'Organização', key: 'name' }, { label: 'Estado', key: 'status' }, { label: 'Origem da biblioteca', render: (o) => +o.is_library_source ? 'sim' : '' },
      { label: 'Utilizadores', key: 'users' }, { label: 'Documentos', key: 'templates' }, { label: 'Registos', key: 'records' },
      { label: '', render: (o) => el('div', { class: 'acoes' },
        el('button', { class: 'btn mini sec', onclick: async () => { await api('PUT', '/platform/orgs/' + o.id, { status: o.status === 'ativa' ? 'suspensa' : 'ativa' }); plataforma({ sec: 'organizacoes' }); } }, o.status === 'ativa' ? 'Suspender' : 'Reativar'),
        !+o.is_library_source && aprovados.length ? el('button', { class: 'btn mini sec', onclick: async () => { const x = await api('POST', `/platform/orgs/${o.id}/provision`, { library_ids: aprovados.map((a) => a.id) }); toast(`${x.entregues} modelo(s) entregue(s).`, 'ok'); plataforma({ sec: 'organizacoes' }); } }, 'Entregar modelos') : null,
        el('button', { class: 'btn mini sec', onclick: async () => {
          const a = (await api('GET', `/platform/orgs/${o.id}/admins`)).admins;
          if (!a.length) { toast('Sem administradores.', 'aviso'); return; }
          if (!await confirmar('Repor acesso do administrador?', `Gerar palavra-passe temporária para ${a[0].name} (${a[0].email}).`)) return;
          const x = await api('POST', `/platform/orgs/${o.id}/admin-reset`, { user_id: a[0].id }); mostrarSenhaTemporaria('Acesso reposto', x.temp_password);
        } }, 'Repor admin')) }], r.orgs));
}

async function secBiblioteca(c) {
  const r = await api('GET', '/platform/library');
  const authBox = r.autorizacao
    ? el('div', { class: 'cartao ok' }, el('p', null, `Autorização expressa registada: ${r.autorizacao.autorizado_por} — ref. ${r.autorizacao.referencia} (${fmtData(r.autorizacao.em, true)}, por ${r.autorizacao.registado_por}).`))
    : (() => {
      const f = el('form', { class: 'form-grelha' }, campo('Quem autoriza (pela organização de origem)', input('authorized_by', '', { required: true })), campo('Referência do documento de autorização', input('reference', '', { required: true })));
      return el('div', { class: 'cartao aviso' }, el('p', null, 'Os modelos da organização de origem só podem ser usados como biblioteca (neutralizada) para outras organizações com autorização expressa. Sem autorização, novas organizações não recebem modelos.'), f,
        el('button', { class: 'btn', onclick: async () => { if (!f.reportValidity()) return; await api('POST', '/platform/library/authorization', formData(f)); plataforma({ sec: 'biblioteca' }); } }, 'Registar autorização'));
    })();
  const preview = async (x) => {
    const h = await api('GET', `/platform/library/${x.id}/preview`);
    const url = URL.createObjectURL(new Blob([h.html], { type: 'text/html' }));
    const fr = el('iframe', { class: 'quadro-modelo', sandbox: '', src: url, title: 'Pré-visualização' });
    const ok = await modal(`${x.code} — revisão`, [x.scan_findings.length ? el('div', { class: 'aviso' }, el('p', null, 'Verificação automática encontrou:'), el('ul', null, x.scan_findings.map((f) => el('li', null, f)))) : el('p', { class: 'ok' }, 'Sem achados automáticos. Reveja o conteúdo antes de aprovar.'), fr],
      [{ label: 'Fechar', value: null, class: 'sec' }, { label: 'Rejeitar', value: 'rejeitado', class: 'sec' }, { label: 'Aprovar (revi o conteúdo)', value: 'aprovado' }]);
    if (!ok) return;
    try { await api('POST', `/platform/library/${x.id}/review`, { status: ok }); toast('Revisão registada.', 'ok'); plataforma({ sec: 'biblioteca' }); } catch (e) { toast(e.message, 'erro', 8000); }
  };
  c.append(authBox,
    r.autorizacao ? el('div', { class: 'acoes-barra' }, el('button', { class: 'btn sec', onclick: async () => { const x = await api('POST', '/platform/library/rebuild'); toast(`Biblioteca atualizada (${x.novos} novo(s)).`, 'ok'); plataforma({ sec: 'biblioteca' }); } }, '⟳ Gerar/atualizar biblioteca neutralizada')) : null,
    el('p', { class: 'nota' }, 'Neutralização automática: nome da organização, NIF e correio substituídos por marcadores. Cada modelo exige revisão humana; modelos com ficheiros próprios (certificados, imagens) não podem ser aprovados.'),
    r.items.length ? tabela([{ label: 'Código', key: 'code' }, { label: 'Título', key: 'title' }, { label: 'Categoria', key: 'category' }, { label: 'Estado', key: 'status' },
      { label: 'Achados', render: (x) => x.scan_findings.length ? x.scan_findings.join('; ') : '—' }], r.items, preview) : vazio('Biblioteca vazia.'));
}

async function secCopias(c) {
  const r = await api('GET', '/platform/backups');
  const up = el('input', { type: 'file', accept: '.zip,application/zip' });
  c.append(el('div', { class: 'acoes-barra' }, el('button', { class: 'btn', onclick: async (ev) => {
    ev.target.disabled = true; ev.target.textContent = 'A criar cópia…';
    try { const x = await api('POST', '/platform/backups'); toast(`Cópia criada: ${x.file} (${fmtBytes(x.size)}).`, 'ok'); plataforma({ sec: 'copias' }); } catch (e) { toast(e.message, 'erro'); ev.target.disabled = false; }
  } }, '💾 Criar cópia de segurança agora')),
  el('p', { class: 'nota' }, 'Cópia completa: base de dados + ficheiros, com manifesto de contagens e SHA-256. Guarde as cópias também fora do servidor (descarregue-as). Quem controla o servidor pode ler as cópias: guarde-as em local seguro.'),
  r.backups.length ? tabela([{ label: 'Ficheiro', key: 'file_name' }, { label: 'Motivo', key: 'reason' }, { label: 'Data', render: (b) => fmtData(b.created_at, true) }, { label: 'Tamanho', render: (b) => fmtBytes(+b.size_bytes) },
    { label: '', render: (b) => b.exists ? el('div', { class: 'acoes' },
      el('button', { class: 'btn mini', onclick: async () => baixarBlob(await blob('/platform/backups/' + encodeURIComponent(b.file_name)), b.file_name) }, '⬇ Descarregar'),
      el('button', { class: 'btn mini sec', onclick: () => restaurar(b.file_name) }, 'Restaurar'),
      el('button', { class: 'btn mini sec', onclick: async () => { if (await confirmar('Eliminar cópia?', b.file_name, 'Eliminar', true)) { await api('DELETE', '/platform/backups/' + encodeURIComponent(b.file_name)); plataforma({ sec: 'copias' }); } } }, 'Eliminar')) : 'ficheiro removido' }], r.backups) : vazio('Ainda não há cópias.'),
  el('section', { class: 'cartao' }, el('h2', null, 'Restaurar a partir de um ficheiro'), up, el('button', { class: 'btn sec', onclick: () => up.files[0] && restaurar(null, up.files[0]) }, 'Restaurar ficheiro carregado')));
}

async function restaurar(nome, ficheiro) {
  const conf = el('input', { type: 'text', placeholder: 'RESTAURAR' });
  const ok = await modal('Restaurar cópia', [el('p', { class: 'aviso' }, 'Todos os dados atuais serão substituídos pelos da cópia. Antes, é criada automaticamente uma cópia do estado atual.'), campo('Escreva RESTAURAR para confirmar', conf)],
    [{ label: 'Cancelar', value: null, class: 'sec' }, { label: 'Restaurar', value: 'ok', class: 'perigo' }]);
  if (ok !== 'ok') return;
  const fd = new FormData(); fd.append('confirm', conf.value);
  if (ficheiro) fd.append('file', ficheiro); else fd.append('name', nome);
  try { const r = await api('POST', '/platform/backups/restore', fd); toast(`Restaurado (esquema ${r.versao_esquema}). Cópia prévia: ${r.copia_previa}.`, 'ok', 10000); } catch (e) { toast(e.message, 'erro', 10000); }
}

async function secCorreio(c) {
  const s = await api('GET', '/platform/smtp');
  const p = await api('GET', '/platform/push');
  const f = el('form', { class: 'cartao form-grelha', onsubmit: async (ev) => {
    ev.preventDefault();
    try { await api('PUT', '/platform/smtp', formData(f)); toast('Configuração de correio guardada.', 'ok'); } catch (e) { toast(e.message, 'erro'); }
  } }, el('h2', null, 'Correio SMTP (opcional)'),
  el('label', { class: 'chk' }, el('input', { type: 'checkbox', name: 'enabled', checked: s.enabled }), 'Ativar envio de correio (notificações e recuperação de palavra-passe)'),
  campo('Servidor SMTP', input('host', s.host, { placeholder: 'mail.seudominio.com' })), campo('Porta', input('port', s.port, { type: 'number' })),
  campo('Segurança', select('security', [['tls', 'STARTTLS (587)'], ['ssl', 'SSL/TLS (465)'], ['nenhuma', 'Nenhuma (não recomendado)']], s.security)),
  campo('Utilizador', input('user', s.user, { autocomplete: 'off' })), campo('Palavra-passe', input('password', '', { type: 'password', autocomplete: 'new-password', placeholder: s.has_password ? '•••••• (guardada; deixe vazio para manter)' : '' })),
  campo('Remetente (correio)', input('from_email', s.from_email, { type: 'email' })), campo('Remetente (nome)', input('from_name', s.from_name)),
  el('p', { class: 'nota' }, 'No cPanel: Contas de e-mail → Ligar dispositivos → «Definições manuais». A palavra-passe fica cifrada na base de dados.'),
  el('button', { class: 'btn' }, 'Guardar'));
  const para = input('to', '', { type: 'email', placeholder: 'destinatario@exemplo.com' });
  const pf = el('form', { class: 'cartao form-grelha', onsubmit: async (ev) => {
    ev.preventDefault();
    try { await api('PUT', '/platform/push', formData(pf)); toast('Configuração push guardada.', 'ok'); plataforma({ sec: 'correio' }); } catch (e) { toast(e.message, 'erro', 8000); }
  } }, el('h2', null, 'Notificações push (Web Push / VAPID)'),
  el('p', null, p.suportado ? `Servidor compatível. Subscrições ativas: ${p.subscricoes}.` : 'Este servidor não suporta Web Push (faltam OpenSSL P-256 ou cURL). As notificações internas continuam a funcionar.'),
  el('label', { class: 'chk' }, el('input', { type: 'checkbox', name: 'enabled', checked: p.ativo, disabled: !p.suportado }), 'Permitir notificações push'),
  campo('Contacto VAPID', input('contact', p.contacto, { placeholder: 'mailto:admin@seudominio.com' })),
  p.chave_publica ? el('p', { class: 'nota' }, 'Chave pública VAPID: ', el('code', { class: 'hash' }, p.chave_publica)) : null,
  el('button', { class: 'btn' }, 'Guardar'));
  c.append(f, el('div', { class: 'cartao' }, el('h2', null, 'Testar correio'), para, el('button', { class: 'btn sec', onclick: async () => {
    try { const r = await api('POST', '/platform/smtp/test', { to: para.value }); toast(r.mensagem, 'ok'); } catch (e) { toast(e.message, 'erro', 10000); }
  } }, 'Enviar mensagem de teste')), pf);
}
