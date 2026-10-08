// T25–T30 — Melhorias web: painel por perfil, calendário no servidor, relatórios HTML/CSV, importação por folha,
// correio SMTP configurável + recuperação de palavra-passe, estado do sistema e deteção de adulteração.
import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import net from 'node:net';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { URL_BASE, CONTAS, sessao, entrarUI, lerEstado } from './helpers.js';

test.describe.configure({ mode: 'serial' });

test('T25 Painel por perfil (direção, coordenador, supervisor, operário) com indicadores, prazos e tarefas', async ({ page }) => {
  const coord = await sessao(CONTAS.coordenador);
  const ind = await coord.call('POST', '/indicators', { name: 'Taxa de inspeções realizadas (teste)', standard: 'ISO 45001', unit: '%', target: 90, direction: 'maior', frequency: 'mensal' });
  expect(ind.status).toBe(201);
  const per = new Date().toISOString().slice(0, 7);
  expect((await coord.call('POST', `/indicators/${ind.body.id}/values`, { period: per, value: '85', note: 'Medição de teste' })).status).toBe(200);
  const d = { };
  for (const [k, conta] of [['operario', CONTAS.operario], ['supervisor', CONTAS.supervisor], ['coordenador', CONTAS.coordenador], ['direcao', { email: 'direcao@facasi.teste', password: 'Direcao12345' }]]) {
    const s = await sessao(conta);
    d[k] = (await s.call('GET', '/dashboard')).body;
    await s.dispose();
  }
  expect(d.operario.nc_abertas).toBeUndefined();
  expect(d.operario.aprovacoes_pendentes).toBeUndefined();
  expect(d.supervisor.nc_abertas).toBeDefined();
  expect(d.supervisor.indicadores).toBeUndefined();
  expect(d.coordenador.indicadores.some((i) => i.name.includes('inspeções') && i.cumpre === false)).toBe(true);
  expect(d.direcao.indicadores).toBeDefined();
  await entrarUI(page, { email: 'direcao@facasi.teste', password: 'Direcao12345' });
  await expect(page.locator('.perfil-tag')).toHaveText('Direção');
  await expect(page.locator('main')).toContainText('Não cumpre');
  await coord.dispose();
});

test('T26 Calendário do SGI no servidor (partilhado, recorrente, importação do calendário antigo e lembretes)', async ({ page, browser }) => {
  await entrarUI(page, CONTAS.coordenador);
  await page.goto(URL_BASE + '#/calendario');
  await page.getByRole('button', { name: '＋ Evento' }).click();
  const hoje = new Date();
  const em3 = new Date(hoje.getTime() + 3 * 86400000).toISOString().slice(0, 10);
  await page.locator('.modal [name=title]').fill('Auditoria interna ISO 45001 (teste)');
  await page.locator('.modal [name=event_type]').selectOption('auditoria');
  await page.locator('.modal [name=start_date]').fill(em3);
  await page.locator('.modal [name=recurrence]').selectOption('anual');
  await page.locator('.modal [name=reminder_days]').fill('5');
  await page.getByRole('button', { name: 'Guardar' }).click();
  await expect(page.locator('.toast.ok').filter({ hasText: 'guardado no servidor' })).toBeVisible();
  // outro utilizador, noutro navegador, vê o mesmo evento (não está guardado só no navegador)
  const ctx2 = await browser.newContext();
  const p2 = await ctx2.newPage();
  await entrarUI(p2, CONTAS.operario);
  const m = em3.slice(0, 7);
  await p2.goto(URL_BASE + '#/calendario?mes=' + m);
  await expect(p2.locator('.calendario')).toContainText('Auditoria interna ISO 45001');
  // recorrência anual: aparece no ano seguinte
  const op = await sessao(CONTAS.operario);
  const prox = String(Number(em3.slice(0, 4)) + 1) + em3.slice(4);
  const cal = (await op.call('GET', `/calendar?from=${prox}&to=${prox}`)).body.events;
  expect(cal.some((e) => e.title.includes('Auditoria interna ISO 45001'))).toBe(true);
  // importação do JSON do calendário antigo (guardado só no navegador)
  const legado = [{ id: 1, date: em3, activity: 'Revisão pela direção (calendário antigo)', process: 'revision', norms: ['9001', '45001'], clause: '9.3', responsible: 'Direção', status: 'planned', observations: '' }];
  const f = path.resolve('results/calendario-antigo.json');
  fs.writeFileSync(f, JSON.stringify(legado));
  const input = page.locator('input[type=file][accept*=json]');
  await input.setInputFiles(f);
  await expect(page.locator('.toast.ok').filter({ hasText: 'Importados 1' })).toBeVisible();
  // lembretes: as tarefas diárias criam notificações
  const plat = await sessao(CONTAS.plataforma);
  const cron = await plat.call('POST', '/platform/cron');
  expect(cron.body.lembretes).toBeGreaterThan(0);
  const coord = await sessao(CONTAS.coordenador);
  const nts = (await coord.call('GET', '/notifications')).body.notifications;
  expect(nts.some((n) => n.title.includes('Lembrete'))).toBe(true);
  await Promise.all([ctx2.close(), op.dispose(), plat.dispose(), coord.dispose()]);
});

test('T27 Relatórios em HTML imprimível e CSV (indicadores, NC/ações, capacitação, fornecedores, equipamentos, cobertura)', async ({ page }) => {
  const coord = await sessao(CONTAS.coordenador);
  await coord.call('POST', '/suppliers', { name: '=HYPERLINK("http://x")', status: 'em_avaliacao' });
  for (const t of ['indicadores', 'nc-acoes', 'capacitacao', 'fornecedores', 'equipamentos', 'cobertura', 'registos']) {
    const h = await coord.call('GET', `/reports/${t}?format=html`);
    expect(h.status, t).toBe(200);
    expect(h.headers['content-type']).toContain('text/html');
    const html = h.body.toString();
    expect(html).toContain('<table>');
    expect(html).toContain('@media print');
    const c = await coord.call('GET', `/reports/${t}?format=csv`);
    expect(c.status, t).toBe(200);
    expect(c.headers['content-type']).toContain('text/csv');
    expect(c.body.slice(0, 3).toString('hex')).toBe('efbbbf');
  }
  const forn = (await coord.call('GET', '/reports/fornecedores?format=csv')).body.toString();
  expect(forn).toContain(`"'=HYPERLINK`);
  const cob = (await coord.call('GET', '/reports/cobertura?format=json')).body;
  const asp = cob.rows.find((r) => r.clausula.startsWith('6.1.2 (14001)'));
  // âmbito fornecido pela FACASI: ISO 9001 e ISO 45001 ⇒ cláusulas só da ISO 14001 não aplicáveis, com justificação
  expect(asp.estado).toBe('nao_aplicavel');
  expect(asp.nota).toContain('Justificação');
  const ctx = cob.rows.find((r) => r.clausula === '4.1');
  expect(ctx.documentos).toContain('DOC-002');
  // o operário não acede a relatórios
  const op = await sessao(CONTAS.operario);
  expect((await op.call('GET', '/reports/indicadores')).status).toBe(403);
  await entrarUI(page, CONTAS.coordenador);
  await page.goto(URL_BASE + '#/relatorios');
  const [dl] = await Promise.all([page.waitForEvent('download'), page.locator('.cartao', { hasText: 'Fornecedores' }).getByRole('button', { name: 'CSV' }).click()]);
  expect(dl.suggestedFilename()).toMatch(/fornecedores-.*\.csv/);
  await Promise.all([coord.dispose(), op.dispose()]);
});

test('T28 Importação por folha (CSV e Excel) com pré-visualização e validação antes de gravar', async ({ page }) => {
  const coord = await sessao(CONTAS.coordenador);
  const antes = (await coord.call('GET', '/workers')).body.items.length;
  const csv = '﻿nome;numero_identificacao;cargo;departamento;email;data_admissao\n' +
    'Trabalhador Importado A (teste);IMP-001;;Operações;a@exemplo.ao;15/03/2026\n' +
    ';IMP-002;;Operações;b@exemplo.ao;\n' +
    'Trabalhador Importado C (teste);IMP-003;;Operações;email-invalido;\n' +
    'Trabalhador Importado D (teste);IMP-001;;Operações;d@exemplo.ao;\n';
  const f = path.resolve('results/trabalhadores.csv');
  fs.writeFileSync(f, csv);
  await entrarUI(page, CONTAS.coordenador);
  await page.goto(URL_BASE + '#/importar?entidade=workers');
  await page.locator('input[type=file]').setInputFiles(f);
  await page.getByRole('button', { name: 'Pré-visualizar' }).click();
  await expect(page.locator('main')).toContainText('4 linha(s) · 1 válida(s) · 3 com erro');
  await expect(page.locator('main')).toContainText('correio inválido');
  await expect(page.locator('main')).toContainText('duplicado dentro do ficheiro');
  // nada foi gravado durante a pré-visualização
  expect((await coord.call('GET', '/workers')).body.items.length).toBe(antes);
  await page.getByRole('button', { name: /Gravar 1 linha/ }).click();
  await expect(page.locator('.toast.ok').filter({ hasText: '1 registo(s) importado(s)' }).first()).toBeVisible();
  const ws = (await coord.call('GET', '/workers?q=IMP-001')).body.items;
  expect(ws.length).toBe(1);
  expect(ws[0].hired_at).toBe('2026-03-15');
  // Excel (.xlsx): equipamentos
  const xf = path.resolve('results/equipamentos.xlsx');
  execFileSync('php', [path.resolve('fixtures/gerar-xlsx.php'), xf, JSON.stringify([['tipo', 'codigo', 'nome', 'localizacao', 'proxima_verificacao'], ['extintor', 'EXT-IMP-1', 'Extintor CO2 5 kg (teste)', 'Oficina', 46387], ['maquina', 'EQ-IMP-2', 'Tipo inválido', '', '']])]);
  const prev = await coord.call('POST', '/import/preview', undefined, { multipart: { entity: 'assets', file: { name: 'equipamentos.xlsx', mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', buffer: fs.readFileSync(xf) } } });
  expect(prev.status, JSON.stringify(prev.body)).toBe(200);
  expect(prev.body.ok).toBe(1);
  expect(prev.body.linhas[0].dados.next_check_date).toBe('2026-12-31');
  expect(prev.body.linhas[1].erros.join()).toContain('tipo');
  expect((await coord.call('POST', `/import/${prev.body.batch_id}/apply`)).body.importados).toBe(1);
  const eq = (await coord.call('GET', '/assets?q=EXT-IMP-1')).body.items[0];
  expect(eq.qr_token).toMatch(/^[a-f0-9]{32}$/);
  await coord.dispose();
});

test('T29 Correio SMTP configurável pela interface + recuperação de palavra-passe', async ({ page }) => {
  const mensagens = [];
  const srv = net.createServer((sock) => {
    let data = false; let buf = '';
    sock.write('220 smtp-teste ESMTP\r\n');
    sock.on('data', (chunk) => {
      buf += chunk.toString();
      let i;
      while ((i = buf.indexOf('\r\n')) >= 0) {
        const line = buf.slice(0, i); buf = buf.slice(i + 2);
        if (data) {
          if (line === '.') { data = false; sock.write('250 OK\r\n'); mensagens[mensagens.length - 1] += ''; }
          else mensagens[mensagens.length - 1] += line + '\n';
          continue;
        }
        const cmd = line.slice(0, 4).toUpperCase();
        if (cmd === 'EHLO' || cmd === 'HELO') sock.write('250-smtp-teste\r\n250 OK\r\n');
        else if (cmd === 'DATA') { data = true; mensagens.push(''); sock.write('354 Fim com .\r\n'); }
        else if (cmd === 'QUIT') { sock.write('221 Adeus\r\n'); sock.end(); }
        else sock.write('250 OK\r\n');
      }
    });
  });
  await new Promise((r) => srv.listen(2525, '127.0.0.1', r));
  try {
    await entrarUI(page, CONTAS.plataforma);
    await page.goto(URL_BASE + '#/plataforma/correio');
    const f = page.locator('form', { hasText: 'Correio SMTP' });
    await f.locator('[name=enabled]').check();
    await f.locator('[name=host]').fill('127.0.0.1');
    await f.locator('[name=port]').fill('2525');
    await f.locator('[name=security]').selectOption('nenhuma');
    await f.locator('[name=from_email]').fill('sgi@facasi.teste');
    await f.getByRole('button', { name: 'Guardar' }).click();
    await expect(page.locator('.toast.ok').filter({ hasText: 'correio guardada' }).first()).toBeVisible();
    await page.locator('input[name=to]').fill('teste@facasi.teste');
    await page.getByRole('button', { name: 'Enviar mensagem de teste' }).click();
    await expect(page.locator('.toast.ok').filter({ hasText: 'Mensagem enviada' }).first()).toBeVisible();
    expect(mensagens.length).toBe(1);
    // recuperação de palavra-passe pelo ecrã de entrada
    const ctx = await page.context().browser().newContext();
    const p2 = await ctx.newPage();
    await p2.goto(URL_BASE);
    await p2.getByRole('link', { name: 'Esqueci-me da palavra-passe' }).click();
    await p2.locator('.modal input').fill(CONTAS.supervisor.email);
    await p2.getByRole('button', { name: 'Continuar' }).click();
    await expect(p2.locator('.modal')).toContainText('Se o endereço estiver registado');
    await expect.poll(() => mensagens.length).toBe(2);
    const corpo = Buffer.from(mensagens[1].split('\n\n').slice(1).join('').replace(/\s/g, ''), 'base64').toString('utf8');
    const link = corpo.match(/https?:\/\/\S+#\/redefinir\/[a-f0-9]{64}/)[0];
    await p2.goto(link);
    const nova = 'NovaSupervisor456';
    await p2.locator('input[type=password]').first().fill(nova);
    await p2.locator('input[type=password]').nth(1).fill(nova);
    await p2.getByRole('button', { name: 'Guardar' }).click();
    await expect(p2.locator('.toast.ok').filter({ hasText: 'redefinida' }).first()).toBeVisible();
    const s = await sessao({ email: CONTAS.supervisor.email, password: nova });
    await s.call('POST', '/auth/password', { current: nova, new: CONTAS.supervisor.password });
    await s.dispose();
    // ligação de redefinição só funciona uma vez
    const tok = link.split('/').pop();
    const again = await page.request.post(URL_BASE + 'api.php/auth/reset', { data: { token: tok, password: 'OutraSenha789' } });
    expect(again.status()).toBe(422);
    await ctx.close();
  } finally {
    srv.close();
  }
});

test('T30 Estado do sistema: integridade das versões finais deteta ficheiro adulterado (limite documentado)', async ({ page }) => {
  const { finalT18 } = lerEstado();
  const admin = await sessao(CONTAS.admin);
  const rec = (await admin.call('GET', '/records/' + finalT18)).body;
  const storage = process.env.SGI_STORAGE;
  // quem controla o servidor consegue alterar ficheiros; o SGI deteta-o pela comparação do SHA-256
  const files = execFileSync('bash', ['-c', `ls ${storage}/org_1/registos/*/*/*/*/REG-${String(finalT18).padStart(6, '0')}-v1.html`]).toString().trim();
  const original = fs.readFileSync(files);
  try {
    fs.appendFileSync(files, '<!-- alterado diretamente no servidor -->');
    expect((await admin.call('GET', `/records/${finalT18}/verify`)).body.ok).toBe(false);
    await entrarUI(page, CONTAS.plataforma);
    await page.goto(URL_BASE + '#/plataforma/estado');
    await page.getByRole('button', { name: /Verificar integridade agora/ }).click();
    await expect(page.locator('.toast')).toContainText('1 com problema');
    await page.goto(URL_BASE + '#/plataforma/estado');
    await expect(page.locator('main')).toContainText('hash não confere');
    await expect(page.locator('[data-teste=estado-sistema]')).toContainText('Última cópia de segurança');
    await expect(page.locator('[data-teste=estado-sistema]')).toContainText('Última execução das tarefas diárias');
  } finally {
    fs.writeFileSync(files, original);
  }
  expect((await admin.call('GET', `/records/${finalT18}/verify`)).body.ok).toBe(true);
  expect(rec.final_sha256).toMatch(/^[a-f0-9]{64}$/);
  await admin.dispose();
});
