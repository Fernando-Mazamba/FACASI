// T03–T08 — Guardar (rascunho no MySQL), Descarregar (finalizar), bloqueio, aprovação prévia, correção e "Baixar para o computador".
import { test, expect } from '@playwright/test';
import { URL_BASE, CONTAS, sessao, entrarUI, esperarEditor, idTemplate, gravarEstado, lerEstado } from './helpers.js';

test.describe.configure({ mode: 'serial' });

test('T03 Guardar = rascunho no MySQL; a confirmação só aparece depois de o servidor confirmar', async ({ page }) => {
  const admin = await sessao(CONTAS.admin);
  const tpl = await idTemplate(admin, 'FORM-030');
  await entrarUI(page, CONTAS.operario);
  await page.goto(URL_BASE + '#/registos/novo/' + tpl.id);
  await esperarEditor(page);
  // servidor lento: a mensagem de sucesso não pode aparecer antes da resposta
  let libertar;
  const bloqueio = new Promise((r) => { libertar = r; });
  await page.route('**/api.php/records', async (route) => { await bloqueio; await route.continue(); });
  await page.evaluate(() => window.__sgiEditor.host.fillTest('T03'));
  await page.getByRole('button', { name: '💾 Guardar' }).click();
  await page.waitForTimeout(800);
  await expect(page.locator('.estado-guardado')).not.toHaveClass(/ok/);
  await expect(page.locator('.toast.ok')).toHaveCount(0);
  libertar();
  await expect(page.locator('.estado-guardado')).toHaveClass(/ok/);
  await expect(page.locator('.estado-guardado')).toContainText('guardado no servidor');
  await page.unroute('**/api.php/records');
  const id = await page.evaluate(() => window.__sgiEditor.ctx.recordId);
  const r = await admin.call('GET', '/records/' + id);
  expect(r.body.status).toBe('rascunho');
  expect(r.body.data.fields.k2).toBe('VT03-2');
  gravarEstado({ rascunhoT03: id, tplForm030: tpl.id });
  await admin.dispose();
});

test('T04 Descarregar = valida obrigatórios, pede confirmação, gera final com SHA-256 e arquiva Categoria/Tipo/Ano/Mês', async ({ page }) => {
  const admin = await sessao(CONTAS.admin);
  const tpl = await idTemplate(admin, 'FORM-007');
  // configura campo obrigatório k1
  expect((await admin.call('PUT', '/templates/' + tpl.id, { required_fields: ['k1'] })).status).toBe(200);
  await entrarUI(page, CONTAS.operario);
  await page.goto(URL_BASE + '#/registos/novo/' + tpl.id);
  await esperarEditor(page);
  await page.getByRole('button', { name: '💾 Guardar' }).click();
  await expect(page.locator('.estado-guardado')).toHaveClass(/ok/);
  // finalizar sem preencher: o servidor recusa com a lista de obrigatórios
  page.once('dialog', (d) => d.accept());
  await page.getByRole('button', { name: /Descarregar/ }).click();
  await page.getByRole('button', { name: 'Finalizar', exact: true }).click();
  await expect(page.locator('.toast.erro')).toContainText('obrigatórios');
  const id = await page.evaluate(() => window.__sgiEditor.ctx.recordId);
  expect((await admin.call('GET', '/records/' + id)).body.status).toBe('rascunho');
  // preencher e finalizar; cancelar a confirmação não finaliza
  await page.evaluate(() => window.__sgiEditor.host.fillTest('T04'));
  await page.getByRole('button', { name: /Descarregar/ }).click();
  await page.getByRole('button', { name: 'Cancelar' }).click();
  expect((await admin.call('GET', '/records/' + id)).body.status).toBe('rascunho');
  await page.getByRole('button', { name: /Descarregar/ }).click();
  await page.getByRole('button', { name: 'Finalizar', exact: true }).click();
  await expect(page.locator('.cartao.ok')).toContainText('Registo final bloqueado', { timeout: 30000 });
  const rec = (await admin.call('GET', '/records/' + id)).body;
  expect(rec.status).toBe('final');
  expect(rec.final_sha256).toMatch(/^[a-f0-9]{64}$/);
  expect(rec.archive.categoria).toBe('Formulários');
  expect(rec.archive.tipo).toContain('FORM-007');
  expect(rec.archive.ano).toBe(new Date().getFullYear());
  const v = await admin.call('GET', `/records/${id}/verify`);
  expect(v.body.ok).toBe(true);
  // o ficheiro final contém os valores fixados e nenhum script
  const fin = await admin.call('GET', `/records/${id}/final`);
  const html = fin.body.toString();
  expect(html).toContain('REGISTO FINAL');
  expect(html).toContain('VT04-');
  expect(html).not.toMatch(/<script/i);
  expect(fin.headers['content-security-policy']).toContain('sandbox');
  gravarEstado({ finalT04: id });
  await admin.dispose();
});

test('T05 Registo final bloqueado na interface e no servidor/API', async ({ page }) => {
  const { finalT04 } = lerEstado();
  const admin = await sessao(CONTAS.admin);
  expect((await admin.call('PUT', '/records/' + finalT04, { data: { fields: { k0: 'x' } }, lock_version: 99 })).body.code).toBe('registo_final_bloqueado');
  const r = await admin.call('PUT', '/records/' + finalT04, { data: { fields: { k0: 'x' } }, lock_version: (await admin.call('GET', '/records/' + finalT04)).body.lock_version });
  expect(r.status).toBe(409);
  expect(r.body.code).toBe('registo_final_bloqueado');
  expect((await admin.call('DELETE', '/records/' + finalT04)).status).toBe(409);
  expect((await admin.call('POST', '/records/' + finalT04 + '/finalize', { final_html: '<p>x</p>', lock_version: 1, confirm: true })).status).toBe(409);
  // anexar a um final também é bloqueado
  const anexo = await admin.call('POST', '/attachments', undefined, { multipart: { entity_type: 'record', entity_id: String(finalT04), file: { name: 'a.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n%%EOF') } } });
  expect(anexo.status).toBe(409);
  await entrarUI(page, CONTAS.admin);
  await page.goto(URL_BASE + '#/registos/' + finalT04);
  await expect(page.locator('.cartao.ok')).toContainText('Registo final bloqueado');
  await expect(page.getByRole('button', { name: '💾 Guardar' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: /Eliminar/ })).toHaveCount(0);
  await admin.dispose();
});

test('T06 Aprovação prévia: exige aprovador com perfil suficiente, segregação de funções e só então finaliza', async ({ page }) => {
  const admin = await sessao(CONTAS.admin);
  const tpl = await idTemplate(admin, 'FORM-017');
  await admin.call('PUT', '/templates/' + tpl.id, { requires_approval: true, approver_role: 'coordenador' });
  const op = await sessao(CONTAS.operario);
  const c = await op.call('POST', '/records', { template_id: tpl.id, title: 'Avaliação de fornecedor (teste T06)', data: { fields: { k0: 'Fornecedor de teste' }, count: 1 } });
  const f = await op.call('POST', `/records/${c.body.id}/finalize`, { final_html: '<html><body><p>Fornecedor de teste</p></body></html>', lock_version: 1, confirm: true });
  expect(f.status).toBe(200);
  expect(f.body.status).toBe('pendente_aprovacao');
  // em aprovação não pode ser alterado
  expect((await op.call('PUT', '/records/' + c.body.id, { data: { fields: {} }, lock_version: f.body.lock_version })).body.code).toBe('registo_em_aprovacao');
  // supervisor não tem perfil suficiente
  const sup = await sessao(CONTAS.supervisor);
  const lista = (await sup.call('GET', '/approvals')).body.approvals;
  const ap = lista.find((a) => a.record_id === c.body.id);
  expect(ap.can_decide).toBe(false);
  expect((await sup.call('POST', '/approvals/' + ap.id, { decision: 'aprovar' })).status).toBe(403);
  // coordenador aprova pela interface
  await entrarUI(page, CONTAS.coordenador);
  await page.goto(URL_BASE + '#/aprovacoes');
  const cartao = page.locator('.lista-cartoes li', { hasText: 'teste T06' });
  await cartao.getByRole('button', { name: 'Aprovar e finalizar' }).click();
  await page.getByRole('button', { name: 'Continuar' }).click();
  await expect(page.locator('.toast.ok')).toContainText('Aprovado');
  const rec = (await admin.call('GET', '/records/' + c.body.id)).body;
  expect(rec.status).toBe('final');
  expect(rec.approvals[0].status).toBe('aprovado');
  const html = (await admin.call('GET', `/records/${c.body.id}/final`)).body.toString();
  expect(html).toContain('Aprovado por');
  await admin.call('PUT', '/templates/' + tpl.id, { requires_approval: false });
  await Promise.all([admin.dispose(), op.dispose(), sup.dispose()]);
});

test('T07 Correção de registo final = nova versão vinculada (motivo, autor, data); o original não muda', async ({ page }) => {
  const { finalT04 } = lerEstado();
  const admin = await sessao(CONTAS.admin);
  const antes = (await admin.call('GET', '/records/' + finalT04)).body;
  await entrarUI(page, CONTAS.coordenador);
  await page.goto(URL_BASE + '#/registos/' + finalT04);
  await page.getByRole('button', { name: /Corrigir/ }).click();
  await page.locator('.modal textarea').fill('Correção do número de registo (teste T07)');
  await page.getByRole('button', { name: 'Continuar' }).click();
  await esperarEditor(page);
  const novoId = await page.evaluate(() => window.__sgiEditor.ctx.recordId);
  expect(novoId).not.toBe(finalT04);
  const novo = (await admin.call('GET', '/records/' + novoId)).body;
  expect(novo.parent_record_id).toBe(finalT04);
  expect(novo.version_no).toBe(2);
  expect(novo.correction_reason).toContain('teste T07');
  expect(novo.status).toBe('rascunho');
  expect(novo.data.fields).toEqual(antes.data.fields);
  // o modelo original (versão usada) continua disponível
  expect(novo.template_version_id).toBe(antes.template_version_id);
  const depois = (await admin.call('GET', '/records/' + finalT04)).body;
  expect(depois.final_sha256).toBe(antes.final_sha256);
  expect((await admin.call('GET', `/records/${finalT04}/verify`)).body.ok).toBe(true);
  const vers = (await admin.call('GET', `/records/${novoId}/versions`)).body.versions;
  expect(vers.map((v) => v.id)).toEqual([finalT04, novoId]);
  await admin.dispose();
});

test('T08 "Baixar para o computador" não cria registo nem altera o original', async ({ page }) => {
  const { finalT04, tplForm030 } = lerEstado();
  const admin = await sessao(CONTAS.admin);
  const total = (await admin.call('GET', '/records?limit=1')).body.total;
  const sha = (await admin.call('GET', '/records/' + finalT04)).body.final_sha256;
  await entrarUI(page, CONTAS.admin);
  await page.goto(URL_BASE + '#/registos/' + finalT04);
  const [d1] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: /Baixar para o computador/ }).click()]);
  expect(d1.suggestedFilename()).toMatch(/REG-\d{6}-v1\.html/);
  await page.goto(URL_BASE + '#/documentos/' + tplForm030);
  const [d2] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: /Baixar para o computador/ }).click()]);
  expect(d2.suggestedFilename()).toContain('FORM-030');
  expect((await admin.call('GET', '/records?limit=1')).body.total).toBe(total);
  expect((await admin.call('GET', '/records/' + finalT04)).body.final_sha256).toBe(sha);
  await admin.dispose();
});
