// T09–T14 — Isolamento por organização, plataforma, biblioteca neutralizada, cópias, versões de documentos e segurança.
import { test, expect } from '@playwright/test';
import { URL_BASE, CONTAS, sessao, entrarUI, idTemplate, gravarEstado, lerEstado } from './helpers.js';
import AdmZipLike from './zip.js';

test.describe.configure({ mode: 'serial' });

test('T09 Isolamento: outra organização não vê dados, ficheiros nem modelos da FACASI', async ({ request }) => {
  const plat = await sessao(CONTAS.plataforma);
  const nova = await plat.call('POST', '/platform/orgs', { name: 'Organização Beta (teste)', admin_name: 'Administrador Beta (teste)', admin_email: CONTAS.beta.email });
  expect(nova.status).toBe(201);
  const tmp = await sessao({ email: CONTAS.beta.email, password: nova.body.admin_temp_password });
  await tmp.call('POST', '/auth/password', { current: nova.body.admin_temp_password, new: CONTAS.beta.password });
  await tmp.dispose();
  const beta = await sessao(CONTAS.beta);
  const { finalT04, rascunhoT03, tplForm030 } = lerEstado();
  expect((await beta.call('GET', '/templates')).body.templates.length).toBe(0);
  for (const p of [`/records/${finalT04}`, `/records/${finalT04}/final`, `/records/${finalT04}/verify`, `/records/${rascunhoT03}`, `/templates/${tplForm030}`, `/templates/${tplForm030}/render`, `/attachments?entity_type=record&entity_id=${finalT04}`]) {
    const r = await beta.call('GET', p);
    expect(r.status, p).toBe(404);
  }
  expect((await beta.call('PUT', `/records/${rascunhoT03}`, { data: { fields: {} }, lock_version: 1 })).status).toBe(404);
  expect((await beta.call('DELETE', `/records/${rascunhoT03}`)).status).toBe(404);
  expect((await beta.call('POST', '/records', { template_id: tplForm030, data: { fields: {} } })).status).toBe(404);
  // ficheiro de apoio da FACASI não existe no espaço da Beta
  expect((await beta.call('GET', '/org/ativo/logo__mapa.png')).status).toBe(404);
  // listas não misturam organizações
  expect((await beta.call('GET', '/records')).body.total).toBe(0);
  expect((await beta.call('GET', '/archive')).body.tree.length).toBe(0);
  // armazenamento nunca é servido diretamente
  const st = await request.get(URL_BASE + 'storage/');
  expect([403, 404]).toContain(st.status());
  gravarEstado({ orgBeta: nova.body.id });
  await Promise.all([plat.dispose(), beta.dispose()]);
});

test('T10 Administração da plataforma separada: estado do sistema sem acesso a documentos', async ({ page }) => {
  await entrarUI(page, CONTAS.plataforma);
  await page.goto(URL_BASE + '#/plataforma/estado');
  await expect(page.locator('[data-teste=estado-sistema]')).toContainText('Versão do sistema');
  await expect(page.locator('[data-teste=estado-sistema]')).toContainText('1.1.0');
  await expect(page.locator('#menu')).not.toContainText('Documentos');
  await page.getByRole('button', { name: /Verificar integridade agora/ }).click();
  await expect(page.locator('.toast')).toContainText('Integridade:');
  await page.goto(URL_BASE + '#/documentos');
  await expect(page.locator('main')).toContainText('não tem acesso aos documentos');
});

test('T11 Biblioteca: nova organização recebe apenas modelos aprovados e neutralizados', async () => {
  const plat = await sessao(CONTAS.plataforma);
  // sem autorização expressa, não há biblioteca
  expect((await plat.call('POST', '/platform/library/rebuild')).status).toBe(409);
  await plat.call('POST', '/platform/library/authorization', { authorized_by: 'Direção da FACASI (registo de teste)', reference: 'Autorização de teste T11' });
  const rb = await plat.call('POST', '/platform/library/rebuild');
  expect(rb.status).toBe(200);
  const lib = (await plat.call('GET', '/platform/library')).body.items;
  expect(lib.length).toBeGreaterThan(80);
  expect(lib.every((x) => x.status === 'pendente_revisao')).toBe(true);
  // modelo com ficheiros próprios da FACASI não pode ser aprovado
  const comAtivo = lib.find((x) => x.scan_findings.some((f) => f.includes('ficheiro próprio')));
  if (comAtivo) expect((await plat.call('POST', `/platform/library/${comAtivo.id}/review`, { status: 'aprovado' })).status).toBe(422);
  // aprova três formulários genéricos
  const escolhidos = lib.filter((x) => ['FORM-030', 'FORM-001', 'FORM-022'].includes(x.code));
  for (const x of escolhidos) expect((await plat.call('POST', `/platform/library/${x.id}/review`, { status: 'aprovado' })).status).toBe(200);
  const { orgBeta } = lerEstado();
  const pv = await plat.call('POST', `/platform/orgs/${orgBeta}/provision`, { library_ids: lib.map((x) => x.id) });
  expect(pv.body.entregues).toBe(3); // só os aprovados
  const beta = await sessao(CONTAS.beta);
  const tpls = (await beta.call('GET', '/templates')).body.templates;
  expect(tpls.map((t) => t.code).sort()).toEqual(['FORM-001', 'FORM-022', 'FORM-030']);
  for (const t of tpls) {
    const r = await beta.call('GET', `/templates/${t.id}/render`);
    expect(r.body.html).not.toMatch(/FACASI/i);
    expect(r.body.html).not.toContain('{{ORG_');
  }
  // a neutralização substitui a identificação da organização de origem
  const doc1 = lib.find((x) => x.code === 'DOC-001');
  const pvw = (await plat.call('GET', `/platform/library/${doc1.id}/preview`)).body.html;
  expect(pvw).not.toMatch(/FACASI/i);
  expect(pvw).toContain('[NOME DA ORGANIZAÇÃO]');
  // a Beta pode preencher e finalizar com o seu modelo
  const t30 = tpls.find((t) => t.code === 'FORM-030');
  const c = await beta.call('POST', '/records', { template_id: t30.id, title: 'Registo da Beta', data: { fields: { k2: 'Dados da Beta' } } });
  expect(c.status).toBe(201);
  gravarEstado({ betaRecord: c.body.id });
  await Promise.all([plat.dispose(), beta.dispose()]);
});

test('T12 Cópia de segurança pelo navegador e restauro verificado', async ({ page }) => {
  const plat = await sessao(CONTAS.plataforma);
  await entrarUI(page, CONTAS.plataforma);
  await page.goto(URL_BASE + '#/plataforma/copias');
  await page.getByRole('button', { name: /Criar cópia de segurança agora/ }).click();
  await expect(page.locator('.toast.ok').filter({ hasText: 'Cópia criada' }).first()).toBeVisible({ timeout: 120000 });
  const bk = (await plat.call('GET', '/platform/backups')).body.backups[0];
  const [dl] = await Promise.all([page.waitForEvent('download'), page.locator('tr', { hasText: bk.file_name }).getByRole('button', { name: /Descarregar/ }).click()]);
  const zipPath = await dl.path();
  const z = AdmZipLike(zipPath);
  const manifest = JSON.parse(z.read('manifesto.json'));
  expect(manifest.formato).toBe('sgi-backup-1');
  expect(manifest.tabelas.records.count).toBeGreaterThan(0);
  expect(Object.keys(manifest.ficheiros).length).toBeGreaterThan(100);
  // alteração depois da cópia
  const admin = await sessao(CONTAS.admin);
  const tpl = await idTemplate(admin, 'FORM-030');
  const extra = await admin.call('POST', '/records', { template_id: tpl.id, title: 'Criado depois da cópia', data: { fields: {} } });
  await admin.dispose();
  // restauro (exige escrever RESTAURAR)
  const rr = await plat.call('POST', '/platform/backups/restore', undefined, { multipart: { name: bk.file_name, confirm: 'nao' } });
  expect(rr.status).toBe(422);
  const ok = await plat.call('POST', '/platform/backups/restore', undefined, { multipart: { name: bk.file_name, confirm: 'RESTAURAR' } });
  expect(ok.status, JSON.stringify(ok.body)).toBe(200);
  expect(ok.body.copia_previa).toMatch(/antes-restauro/);
  const admin2 = await sessao(CONTAS.admin);
  expect((await admin2.call('GET', '/records/' + extra.body.id)).status).toBe(404);
  const { finalT04 } = lerEstado();
  expect((await admin2.call('GET', `/records/${finalT04}/verify`)).body.ok).toBe(true);
  await Promise.all([plat.dispose(), admin2.dispose()]);
});

test('T13 Nova versão de documento controlado e confirmação de leitura', async ({ page }) => {
  const coord = await sessao(CONTAS.coordenador);
  const tpl = await idTemplate(coord, 'PRO-001');
  const html = '<!DOCTYPE html><html><head><title>PRO-001 v2</title></head><body><h1>Procedimento revisto (teste T13)</h1><p onclick="alert(1)">Texto</p><script>alert("x")</script></body></html>';
  expect((await coord.call('POST', `/templates/${tpl.id}/versions`, undefined, { multipart: { reason: '' , file: { name: 'pro.html', mimeType: 'text/html', buffer: Buffer.from(html) } } })).status).toBe(422);
  const nv = await coord.call('POST', `/templates/${tpl.id}/versions`, undefined, { multipart: { reason: 'Revisão de teste T13', file: { name: 'pro.html', mimeType: 'text/html', buffer: Buffer.from(html) } } });
  expect(nv.status).toBe(200);
  expect(nv.body.version_no).toBe(2);
  const det = (await coord.call('GET', `/templates/${tpl.id}`)).body;
  expect(det.versions[0].status).toBe('vigente');
  expect(det.versions[1].status).toBe('obsoleta');
  const rend = (await coord.call('GET', `/templates/${tpl.id}/render`)).body;
  expect(rend.html).toContain('Procedimento revisto');
  expect(rend.html).not.toMatch(/<script|onclick/i);
  expect(rend.trusted).toBe(false);
  // operário vê a leitura pendente e confirma
  await entrarUI(page, CONTAS.operario);
  await expect(page.locator('main')).toContainText('PRO-001');
  await page.goto(URL_BASE + '#/documentos/' + tpl.id);
  await page.getByRole('button', { name: /Confirmar leitura/ }).click();
  await expect(page.locator('.toast.ok').filter({ hasText: 'Leitura confirmada' }).first()).toBeVisible();
  const reads = (await coord.call('GET', `/templates/${tpl.id}/reads`)).body.reads;
  expect(reads.find((x) => x.name.includes('operario')).confirmed_at).toBeTruthy();
  await coord.dispose();
});

test('T14 Segurança: sem senha fixa, cookies seguros, cabeçalhos e bloqueio por organização', async ({ request }) => {
  for (const [e, p] of [['admin', 'admin'], ['admin@admin.com', 'admin'], ['admin@facasi.teste', 'admin123'], ['root', 'root']]) {
    expect((await request.post(URL_BASE + 'api.php/auth/login', { data: { email: e, password: p } })).status()).not.toBe(200);
  }
  const r = await request.post(URL_BASE + 'api.php/auth/login', { data: CONTAS.coordenador });
  const cookie = r.headers()['set-cookie'] || '';
  expect(cookie).toMatch(/HttpOnly/i);
  expect(cookie).toMatch(/SameSite=Lax/i);
  expect(r.headers()['x-content-type-options']).toBe('nosniff');
  const idx = await request.get(URL_BASE);
  expect(idx.headers()['content-security-policy']).toContain("script-src 'self'");
  // bloqueio por organização: 30 falhas em contas de uma organização bloqueiam o acesso dessa organização
  const plat = await sessao(CONTAS.plataforma);
  const g = await plat.call('POST', '/platform/orgs', { name: 'Organização Gama (teste)', admin_name: 'Admin Gama', admin_email: 'admin@gama.teste' });
  for (let i = 0; i < 30; i++) {
    await request.post(URL_BASE + 'api.php/auth/login', { data: { email: 'admin@gama.teste', password: 'errada-' + i } });
  }
  const b = await request.post(URL_BASE + 'api.php/auth/login', { data: { email: 'admin@gama.teste', password: g.body.admin_temp_password } });
  expect([423, 429]).toContain(b.status());
  // as outras organizações continuam a funcionar
  expect((await request.post(URL_BASE + 'api.php/auth/login', { data: CONTAS.admin })).status()).toBe(200);
  await plat.dispose();
});
