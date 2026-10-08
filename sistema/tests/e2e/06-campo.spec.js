// T19–T21 — Fotografia (câmara simulada), assinatura e localização; QR por organização; revogação imediata de token.
import { test, expect, devices } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { URL_BASE, CONTAS, sessao, app, entrarUI, esperarEditor, idTemplate, gravarEstado, lerEstado } from './helpers.js';

test.describe.configure({ mode: 'serial' });
const FOTO = path.resolve('fixtures/foto-teste.jpg');

function dimensoes(buf) {
  const f = path.resolve('results/tmp-img.bin');
  fs.writeFileSync(f, buf);
  const out = execFileSync('php', ['-r', '$i=getimagesize($argv[1]); echo $i[0]."x".$i[1]." ".$i["mime"];', f]).toString();
  return out;
}

test('T19 Fotografia pela câmara (ficheiro simulado) comprimida no aparelho, validada e protegida por organização; assinatura e GPS', async ({ browser }) => {
  const admin = await sessao(CONTAS.admin);
  const tpl = await idTemplate(admin, 'FORM-030');
  const ctx = await browser.newContext({ ...devices['Pixel 7'], permissions: ['geolocation'], geolocation: { latitude: -8.8383, longitude: 13.2344, accuracy: 12 } });
  const page = await ctx.newPage();
  await entrarUI(page, CONTAS.operario);
  await page.goto(URL_BASE + '#/registos/novo/' + tpl.id);
  await esperarEditor(page);
  await page.evaluate(() => window.__sgiEditor.host.fillTest('T19'));
  // localização com consentimento explícito
  await page.getByRole('button', { name: '📍 Localização' }).click();
  await expect(page.locator('.modal')).toContainText('opcional');
  await page.getByRole('button', { name: 'Autorizo', exact: true }).click();
  await expect(page.locator('.geo-info')).toContainText('-8.8383');
  // fotografia
  const original = fs.statSync(FOTO).size;
  const [fc] = await Promise.all([page.waitForEvent('filechooser'), page.getByRole('button', { name: '📷 Foto' }).click()]);
  await fc.setFiles(FOTO);
  await page.locator('.modal input').fill('EPI incompleto na zona de carga (teste T19)');
  await page.getByRole('button', { name: 'Continuar' }).click();
  await expect(page.locator('.toast').filter({ hasText: 'Anexo guardado no servidor' })).toBeVisible({ timeout: 30000 });
  // assinatura no ecrã
  await page.getByRole('button', { name: '✍ Assinatura' }).click();
  await expect(page.locator('.modal')).toContainText(/não é uma assinatura digital certificada/i);
  const cv = page.locator('canvas.assinatura-canvas');
  const b = await cv.boundingBox();
  await page.mouse.move(b.x + 20, b.y + 60);
  await page.mouse.down();
  for (let i = 0; i < 12; i++) await page.mouse.move(b.x + 30 + i * 20, b.y + 60 + (i % 2 ? 30 : -10));
  await page.mouse.up();
  await page.locator('.modal input[type=text]').fill('Operário de teste');
  await page.getByRole('button', { name: 'Guardar assinatura' }).click();
  await expect(page.locator('.toast').filter({ hasText: 'Anexo guardado no servidor' }).last()).toBeVisible();
  await page.getByRole('button', { name: '💾 Guardar' }).click();
  await expect(page.locator('.estado-guardado')).toHaveClass(/ok/);
  const id = await page.evaluate(() => window.__sgiEditor.ctx.recordId);
  const rec = (await admin.call('GET', '/records/' + id)).body;
  expect(rec.geo.lat).toBeCloseTo(-8.8383, 3);
  const ats = (await admin.call('GET', `/attachments?entity_type=record&entity_id=${id}`)).body.attachments;
  const foto = ats.find((a) => a.kind === 'foto');
  const ass = ats.find((a) => a.kind === 'assinatura');
  expect(foto.mime).toBe('image/jpeg');
  expect(+foto.size_bytes).toBeLessThan(original);
  expect(+foto.geo_lat).toBeCloseTo(-8.8383, 3);
  expect(ass.mime).toBe('image/png');
  const fileRes = await admin.call('GET', `/attachments/${foto.id}/file`);
  const dims = dimensoes(fileRes.body);
  const [w, h] = dims.split(' ')[0].split('x').map(Number);
  expect(Math.max(w, h)).toBeLessThanOrEqual(1600);
  // validação no servidor: ficheiro que não é imagem é recusado
  const op = await sessao(CONTAS.operario);
  const falso = await op.call('POST', '/attachments', undefined, { multipart: { entity_type: 'record', entity_id: String(id), file: { name: 'foto.jpg', mimeType: 'image/jpeg', buffer: Buffer.from('<?php echo 1; ?> não é imagem') } } });
  expect(falso.status).toBe(415);
  // isolamento: a organização Beta não acede ao anexo
  const beta = await sessao(CONTAS.beta);
  expect((await beta.call('GET', `/attachments/${foto.id}/file`)).status).toBe(404);
  expect((await beta.call('GET', `/attachments?entity_type=record&entity_id=${id}`)).status).toBe(404);
  // finalizar inclui os anexos com SHA-256 na representação final
  await page.getByRole('button', { name: /Descarregar/ }).click();
  await page.getByRole('button', { name: 'Finalizar', exact: true }).click();
  await expect(page.locator('.cartao.ok')).toContainText('Registo final bloqueado', { timeout: 30000 });
  const html = (await admin.call('GET', `/records/${id}/final`)).body.toString();
  expect(html).toContain(foto.sha256);
  expect(html).toContain('Assinatura no ecrã (conformidade visual)');
  // depois de final, não se removem anexos
  expect((await op.call('DELETE', '/attachments/' + foto.id)).status).toBe(409);
  await Promise.all([admin.dispose(), op.dispose(), beta.dispose(), ctx.close()]);
});

test('T20 QR do equipamento abre a ficha certa; QR de outra organização não abre nada', async ({ browser }) => {
  const admin = await sessao(CONTAS.admin);
  const tpl = await idTemplate(admin, 'FICHAS-EXTINTORES');
  const a = (await admin.call('POST', '/assets', { kind: 'extintor', code: 'EXT-T20', name: 'Extintor PQS 6 kg (teste)', location: 'Armazém (teste)', form_template_id: tpl.id, next_check_date: '2026-12-01' })).body;
  const beta = await sessao(CONTAS.beta);
  const ab = (await beta.call('POST', '/assets', { kind: 'equipamento', code: 'EQ-BETA', name: 'Equipamento da Beta' })).body;
  const ctx = await browser.newContext({ ...devices['Pixel 7'] });
  const page = await ctx.newPage();
  await entrarUI(page, CONTAS.operario);
  // 1) leitura com a câmara do telemóvel (URL do QR)
  await page.goto(URL_BASE + '#/qr/' + a.item.qr_token);
  await expect(page).toHaveURL(new RegExp('#/equipamentos/' + a.id + '$'));
  await expect(page.locator('main')).toContainText('EXT-T20');
  await expect(page.getByRole('link', { name: /Preencher formulário de inspeção/ })).toBeVisible();
  // 2) leitura dentro da aplicação a partir de uma fotografia da etiqueta
  const png = await page.locator('.qr-box svg').screenshot();
  const f = path.resolve('results/qr-T20.png');
  fs.writeFileSync(f, png);
  await page.goto(URL_BASE + '#/qr');
  await page.locator('input[type=file]').setInputFiles(f);
  await expect(page).toHaveURL(new RegExp('#/equipamentos/' + a.id + '$'), { timeout: 15000 });
  // 3) etiqueta da organização Beta lida por utilizador da FACASI
  await page.goto(URL_BASE + '#/qr/' + ab.item.qr_token);
  await expect(page.locator('main')).toContainText('não reconhecido');
  await expect(page.locator('main')).not.toContainText('EQ-BETA');
  const op = await sessao(CONTAS.operario);
  expect((await op.call('GET', '/qr/' + ab.item.qr_token)).status).toBe(404);
  expect((await op.call('GET', '/assets/' + ab.id)).status).toBe(404);
  // formulário associado abre com o equipamento ligado
  await page.goto(URL_BASE + '#/equipamentos/' + a.id);
  await page.getByRole('link', { name: /Preencher formulário de inspeção/ }).click();
  await esperarEditor(page);
  await page.getByRole('button', { name: '💾 Guardar' }).click();
  await expect(page.locator('.estado-guardado')).toHaveClass(/ok/);
  const rid = await page.evaluate(() => window.__sgiEditor.ctx.recordId);
  expect((await admin.call('GET', '/records/' + rid)).body.asset_id).toBe(a.id);
  gravarEstado({ assetT20: a.id });
  await Promise.all([admin.dispose(), beta.dispose(), op.dispose(), ctx.close()]);
});

test('T21 Token da aplicação revogado deixa de funcionar imediatamente', async ({ page }) => {
  const cli = await app(CONTAS.operario, URL_BASE, 'Telemóvel de teste T21');
  expect((await cli.call('GET', '/records')).status).toBe(200);
  const me = (await cli.call('GET', '/auth/me')).body;
  expect(me.mode).toBe('token');
  // renovação funciona antes da revogação (e roda o token de renovação)
  const ref = await cli.ctx.post('api.php/auth/refresh', { data: { refresh_token: cli.tok.refresh_token } });
  expect(ref.status()).toBe(200);
  const novo = await ref.json();
  expect(novo.refresh_token).not.toBe(cli.tok.refresh_token);
  expect((await cli.call('GET', '/records', undefined, novo.access_token)).status).toBe(200);
  // o utilizador encerra o dispositivo em Perfil → Dispositivos ligados
  await entrarUI(page, CONTAS.operario);
  await page.goto(URL_BASE + '#/perfil');
  const linha = page.locator('tr', { hasText: 'Telemóvel de teste T21' });
  await linha.getByRole('button', { name: 'Encerrar' }).click();
  await page.getByRole('button', { name: 'Encerrar', exact: true }).last().click();
  await expect(page.locator('.toast.ok')).toContainText('Dispositivo encerrado');
  // imediatamente: acesso e renovação recusados
  expect((await cli.call('GET', '/records', undefined, novo.access_token)).status).toBe(401);
  const ref2 = await cli.ctx.post('api.php/auth/refresh', { data: { refresh_token: novo.refresh_token } });
  expect(ref2.status()).toBe(401);
  // reutilizar um token de renovação antigo também é recusado
  expect((await cli.ctx.post('api.php/auth/refresh', { data: { refresh_token: cli.tok.refresh_token } })).status()).toBe(401);
  await cli.dispose();
});
