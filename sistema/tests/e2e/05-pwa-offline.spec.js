// T15–T18 — PWA instalável, abertura sem ligação, rascunho offline + sincronização, conflito e finalização offline bloqueada.
import { test, expect, devices } from '@playwright/test';
import { URL_BASE, CONTAS, sessao, esperarEditor, idTemplate, gravarEstado, lerEstado } from './helpers.js';

test.describe.configure({ mode: 'serial' });
const MOBILE = { ...devices['Pixel 7'] };

async function abrirApp(browser, conta) {
  const ctx = await browser.newContext({ ...MOBILE, serviceWorkers: 'allow' });
  // A aplicação instalada usa tokens (modo app); no Chromium de testes simula-se o modo "standalone".
  await ctx.addInitScript(() => { try { localStorage.setItem('sgi_modo', 'token'); } catch (e) {} });
  const page = await ctx.newPage();
  await page.goto(URL_BASE);
  await page.locator('input[type=email]').fill(conta.email);
  await page.locator('input[type=password]').fill(conta.password);
  await page.getByRole('button', { name: 'Entrar' }).click();
  await expect(page.locator('.kpis')).toBeVisible({ timeout: 20000 });
  await page.evaluate(async () => { await navigator.serviceWorker.ready; });
  if (!(await page.evaluate(() => !!navigator.serviceWorker.controller))) await page.reload();
  await expect(page.locator('.kpis')).toBeVisible();
  await page.waitForFunction(() => !!navigator.serviceWorker.controller);
  return { ctx, page };
}

async function ficarOffline(ctx, page, v) {
  await ctx.setOffline(v);
  await page.evaluate((on) => window.dispatchEvent(new Event(on ? 'offline' : 'online')), v);
}

test('T15 PWA instalável (Chromium com emulação móvel) e ecrã inicial abre sem ligação', async ({ browser, request }) => {
  const man = await (await request.get(URL_BASE + 'manifest.php')).json();
  expect(man.display).toBe('standalone');
  expect(man.start_url).toContain('/sgi/');
  expect(man.icons.some((i) => i.sizes === '192x192')).toBe(true);
  expect(man.icons.some((i) => i.sizes === '512x512')).toBe(true);
  const ic = await request.get(man.icons[0].src.replace(/^\/sgi\//, URL_BASE));
  expect(ic.headers()['content-type']).toBe('image/png');
  const { ctx, page } = await abrirApp(browser, CONTAS.operario);
  const cdp = await ctx.newCDPSession(page);
  const inst = await cdp.send('Page.getInstallabilityErrors');
  expect(inst.installabilityErrors, JSON.stringify(inst.installabilityErrors)).toEqual([]);
  const manifestCdp = await cdp.send('Page.getAppManifest');
  expect(manifestCdp.errors).toEqual([]);
  // sem ligação: recarregar abre o ecrã inicial a partir da cache
  await ficarOffline(ctx, page, true);
  await page.reload();
  await expect(page.locator('#estado-rede')).toHaveText('Sem ligação');
  await expect(page.locator('.kpis')).toBeVisible();
  await expect(page.locator('main')).toContainText('Sem ligação: painel da última consulta');
  await page.screenshot({ path: 'results/T15-offline-390.png' });
  await ctx.close();
});

test('T16 Rascunho preenchido sem internet fica "pendente de sincronizar" e sincroniza sem duplicar', async ({ browser }) => {
  const admin = await sessao(CONTAS.admin);
  const tpl = await idTemplate(admin, 'FORM-030');
  const { ctx, page } = await abrirApp(browser, CONTAS.operario);
  await page.goto(URL_BASE + '#/documentos/' + tpl.id);
  await page.getByRole('button', { name: /Disponível offline/ }).click();
  await expect(page.locator('.toast.ok')).toContainText('Disponível offline');
  await ficarOffline(ctx, page, true);
  await page.goto(URL_BASE + '#/registos/novo/' + tpl.id);
  await esperarEditor(page);
  await expect(page.locator('.meta')).toContainText('cópia offline');
  await page.locator('.titulo-registo').fill('Registo offline T16');
  await page.evaluate(() => window.__sgiEditor.host.fillTest('T16'));
  await page.getByRole('button', { name: '💾 Guardar' }).click();
  await expect(page.locator('.estado-guardado')).toContainText('APENAS neste dispositivo');
  await expect(page.locator('#btn-sync')).toBeVisible();
  const uuid = await page.evaluate(() => window.__sgiEditor.ctx.localId);
  let lista = (await admin.call('GET', '/records?q=' + encodeURIComponent('Registo offline T16'))).body;
  expect(lista.total).toBe(0);
  // volta a ligação → sincroniza
  await ficarOffline(ctx, page, false);
  await expect(page.locator('.toast.ok').filter({ hasText: 'sincronizado' })).toBeVisible({ timeout: 30000 });
  await expect(page.locator('#btn-sync')).toBeHidden();
  lista = (await admin.call('GET', '/records?q=' + encodeURIComponent('Registo offline T16'))).body;
  expect(lista.total).toBe(1);
  const rec = lista.records[0];
  expect(rec.client_uuid).toBe(uuid);
  // reenvio do mesmo rascunho (resposta perdida, nova tentativa) não duplica
  const op = await sessao(CONTAS.operario);
  const dup = await op.call('POST', '/records', { template_id: tpl.id, title: 'Registo offline T16', client_uuid: uuid, data: { fields: { k0: 'x' } } }, { headers: { 'Idempotency-Key': 'outra-chave-' + Date.now() } });
  expect(dup.body.duplicado).toBe(true);
  expect(dup.body.id).toBe(rec.id);
  const idem = 'repetir-T16-' + Date.now();
  const a1 = await op.call('POST', '/records', { template_id: tpl.id, title: 'Idem T16', data: { fields: {} } }, { headers: { 'Idempotency-Key': idem } });
  const a2 = await op.call('POST', '/records', { template_id: tpl.id, title: 'Idem T16', data: { fields: {} } }, { headers: { 'Idempotency-Key': idem } });
  expect(a2.headers['idempotent-replay']).toBe('true');
  expect(a2.body.id).toBe(a1.body.id);
  expect((await admin.call('GET', '/records?q=' + encodeURIComponent('Idem T16'))).body.total).toBe(1);
  expect((await admin.call('GET', '/records?q=' + encodeURIComponent('Registo offline T16'))).body.total).toBe(1);
  const full = (await admin.call('GET', '/records/' + rec.id)).body;
  expect(full.data.fields.k2).toBe('VT16-2');
  await Promise.all([admin.dispose(), op.dispose(), ctx.close()]);
});

test('T17 Conflito: duas pessoas editam o mesmo rascunho, uma sem ligação → ecrã de resolução, nada se perde', async ({ browser }) => {
  const admin = await sessao(CONTAS.admin);
  const tpl = await idTemplate(admin, 'FORM-030');
  const op = await sessao(CONTAS.operario);
  const c = await op.call('POST', '/records', { template_id: tpl.id, title: 'Rascunho partilhado T17', data: { fields: { k2: 'original' }, count: 1 } });
  const { ctx, page } = await abrirApp(browser, CONTAS.operario);
  await page.goto(URL_BASE + '#/registos/' + c.body.id);
  await esperarEditor(page);
  await ficarOffline(ctx, page, true);
  await page.evaluate(() => window.__sgiEditor.host.fillTest('B'));
  await page.getByRole('button', { name: '💾 Guardar' }).click();
  await expect(page.locator('.estado-guardado')).toContainText('APENAS neste dispositivo');
  // entretanto, o coordenador altera o mesmo rascunho no servidor
  const coord = await sessao(CONTAS.coordenador);
  const cur = (await coord.call('GET', '/records/' + c.body.id)).body;
  const upd = await coord.call('PUT', '/records/' + c.body.id, { data: { fields: { ...cur.data.fields, k2: 'Versão do coordenador' }, count: cur.data.count }, lock_version: cur.lock_version });
  expect(upd.status).toBe(200);
  // o operário volta a ter ligação
  await ficarOffline(ctx, page, false);
  await expect(page.locator('.toast').filter({ hasText: 'conflito' })).toBeVisible({ timeout: 30000 });
  // o servidor manteve a versão do coordenador (sem sobrescrita silenciosa)
  expect((await admin.call('GET', '/records/' + c.body.id)).body.data.fields.k2).toBe('Versão do coordenador');
  await page.goto(URL_BASE + '#/sincronizar');
  await expect(page.locator('main')).toContainText('Conflito por resolver');
  await page.getByRole('link', { name: 'Resolver' }).click();
  await expect(page.locator('main')).toContainText('Resolver conflito');
  await expect(page.locator('main')).toContainText('Versão do coordenador');
  await expect(page.locator('main')).toContainText('VB-2');
  await page.screenshot({ path: 'results/T17-conflito-390.png', fullPage: true });
  await page.getByRole('button', { name: 'Guardar a minha como novo rascunho' }).click();
  await expect(page.locator('.toast.ok')).toContainText('novo rascunho');
  const copia = (await admin.call('GET', '/records?q=' + encodeURIComponent('cópia do conflito'))).body.records[0];
  expect((await admin.call('GET', '/records/' + copia.id)).body.data.fields.k2).toBe('VB-2');
  expect((await admin.call('GET', '/records/' + c.body.id)).body.data.fields.k2).toBe('Versão do coordenador');
  await Promise.all([admin.dispose(), op.dispose(), coord.dispose(), ctx.close()]);
});

test('T18 Finalizar sem ligação é bloqueado com mensagem clara; com ligação finaliza e bloqueia no servidor', async ({ browser }) => {
  const admin = await sessao(CONTAS.admin);
  const tpl = await idTemplate(admin, 'FORM-030');
  const op = await sessao(CONTAS.operario);
  const c = await op.call('POST', '/records', { template_id: tpl.id, title: 'Finalizar T18', data: { fields: { k2: 'dados T18' }, count: 1 } });
  const { ctx, page } = await abrirApp(browser, CONTAS.operario);
  await page.goto(URL_BASE + '#/registos/' + c.body.id);
  await esperarEditor(page);
  await ficarOffline(ctx, page, true);
  const btn = page.getByRole('button', { name: /Descarregar/ });
  await expect(btn).toBeDisabled({ timeout: 5000 });
  await expect(page.locator('.motivo-offline')).toHaveText('Finalizar exige ligação ao servidor.');
  await expect(btn).toHaveAttribute('title', /Sem ligação/);
  await btn.evaluate((b) => { b.disabled = false; b.click(); });
  await expect(page.locator('.modal')).toContainText('Finalizar exige ligação');
  await page.getByRole('button', { name: 'Entendi' }).click();
  expect((await admin.call('GET', '/records/' + c.body.id)).body.status).toBe('rascunho');
  // aprovar também exige ligação
  await page.goto(URL_BASE + '#/aprovacoes');
  await expect(page.locator('main')).toContainText('Sem ligação');
  // com ligação
  await ficarOffline(ctx, page, false);
  await page.goto(URL_BASE + '#/registos/' + c.body.id);
  await esperarEditor(page);
  await expect(btn).toBeEnabled();
  await btn.click();
  await page.getByRole('button', { name: 'Finalizar', exact: true }).click();
  await expect(page.locator('.cartao.ok')).toContainText('Registo final bloqueado', { timeout: 30000 });
  const fin = (await admin.call('GET', '/records/' + c.body.id)).body;
  expect(fin.status).toBe('final');
  expect((await op.call('PUT', '/records/' + c.body.id, { data: { fields: {} }, lock_version: fin.lock_version })).body.code).toBe('registo_final_bloqueado');
  gravarEstado({ finalT18: c.body.id });
  await Promise.all([admin.dispose(), op.dispose(), ctx.close()]);
});
