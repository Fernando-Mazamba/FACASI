// T23 — Atualização pelo assistente web a partir do esquema 1.0.0 com dados de teste, sem perda (contagens e hashes iguais).
import { test, expect } from '@playwright/test';
import { URL_UPD, sessao } from './helpers.js';

const PLAT = { email: 'plataforma@upd.teste', password: 'Plataforma123' };
const ADMIN = { email: 'admin@upd.teste', password: 'AdminTeste123' };

test('T23 atualização 1.0.0 → 1.1.0 pelo navegador, com cópia automática e verificação', async ({ page, request }) => {
  // antes: a API indica atualização pendente e não serve dados
  const login = await request.post(URL_UPD + 'api.php/auth/login', { data: ADMIN });
  expect(login.status()).toBe(200);
  const r0 = await request.get(URL_UPD + 'api.php/records');
  expect(r0.status()).toBe(503);
  expect((await r0.json()).code).toBe('atualizacao_pendente');
  // a interface mostra o aviso
  await page.goto(URL_UPD + 'atualizar/');
  // um utilizador que não é da plataforma não pode atualizar
  await page.fill('[name=email]', ADMIN.email);
  await page.fill('[name=password]', ADMIN.password);
  await page.getByRole('button', { name: 'Entrar' }).click();
  await expect(page.locator('.card').first()).toContainText('Apenas o administrador da plataforma');
  await page.fill('[name=email]', PLAT.email);
  await page.fill('[name=password]', PLAT.password);
  await page.getByRole('button', { name: 'Entrar' }).click();
  await expect(page.locator('[data-teste=esquema]')).toHaveText('1.0.0');
  await expect(page.locator('main')).toContainText('1.1.0/001_app_movel.sql');
  await page.locator('[data-teste=atualizar]').click();
  await expect(page.locator('#relatorio h2')).toHaveText('Atualização concluída sem perda de dados', { timeout: 300000 });
  await expect(page.locator('[data-teste=verificacao]')).toHaveText('OK');
  await expect(page.locator('[data-teste=esquema]')).toHaveText('1.1.0');
  // todas as tabelas com contagem e hash iguais (colunas existentes antes da atualização)
  const linhas = await page.locator('#relatorio table tbody tr').allTextContents();
  expect(linhas.length).toBeGreaterThan(15);
  const sistema = ['settings', 'schema_migrations', 'backups', 'audit_log', 'notifications'];
  for (const l of linhas) {
    const tabela = l.split(/\d/)[0];
    if (!sistema.some((s) => l.startsWith(s))) expect(l, l).toContain('igual');
  }
  await page.screenshot({ path: 'results/T23-relatorio.png', fullPage: true });
  await expect(page.locator('#relatorio')).toContainText('versões finais com SHA-256 confirmado: 12');
  // depois: o sistema funciona com os dados antigos
  const adm = await sessao(ADMIN, URL_UPD);
  const lista = (await adm.call('GET', '/records?limit=200')).body;
  expect(lista.total).toBe(24);
  const finais = lista.records.filter((x) => x.status === 'final');
  expect(finais.length).toBe(12);
  for (const f of finais) expect((await adm.call('GET', `/records/${f.id}/verify`)).body.ok).toBe(true);
  const att = (await adm.call('GET', `/attachments?entity_type=record&entity_id=${finais[0].id}`)).body.attachments;
  expect(att.length).toBe(1);
  expect((await adm.call('GET', `/attachments/${att[0].id}/file`)).status).toBe(200);
  // funcionalidades novas da 1.1.0 disponíveis
  expect((await adm.call('GET', '/calendar')).status).toBe(200);
  const tok = await request.post(URL_UPD + 'api.php/auth/token', { data: { ...ADMIN, device_name: 'pós-atualização' } });
  expect(tok.status()).toBe(200);
  await page.goto(URL_UPD + 'atualizar/');
  await expect(page.locator('[data-teste=em-dia]')).toBeVisible();
  await adm.dispose();
});
