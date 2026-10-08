// T01 — Instalação pelo assistente web com código de instalação (sem senha fixa; primeiro administrador criado com segurança).
import { test, expect } from '@playwright/test';
import { URL_BASE, CONTAS, gravarEstado } from './helpers.js';

test('T01 instalação pelo navegador: rejeita código errado, instala com o código certo e bloqueia o assistente', async ({ page, request }) => {
  const env = process.env;
  await page.goto(URL_BASE);
  await expect(page).toHaveURL(/instalar\/$/);
  const preencher = async (codigo) => {
    await page.fill('[name=install_code]', codigo);
    await page.fill('[name=db_host]', '127.0.0.1');
    await page.fill('[name=db_port]', '3306');
    await page.fill('[name=db_name]', env.SGI_DB);
    await page.fill('[name=db_user]', env.SGI_DB_USER);
    await page.fill('[name=db_pass]', env.SGI_DB_PASS);
    await page.fill('[name=storage_path]', env.SGI_STORAGE);
    await page.fill('[name=pa_name]', 'Administrador da Plataforma (teste)');
    await page.fill('[name=pa_email]', CONTAS.plataforma.email);
    await page.fill('[name=pa_pass]', CONTAS.plataforma.password);
    await page.fill('[name=pa_pass2]', CONTAS.plataforma.password);
    await page.fill('[name=org_name]', 'FACASI');
    await page.fill('[name=oa_name]', 'Administrador FACASI (teste)');
    await page.fill('[name=oa_email]', CONTAS.admin.email);
    await page.fill('[name=oa_pass]', CONTAS.admin.password);
    await page.fill('[name=oa_pass2]', CONTAS.admin.password);
    await page.check('[name=import_facasi]');
    await page.getByRole('button', { name: 'Instalar' }).click();
  };
  await preencher('AAAA-BBBB-CCCC-DDDD');
  await expect(page.locator('.card.err')).toContainText('Código de instalação incorreto');
  // nenhuma configuração foi criada
  expect((await request.get(URL_BASE + 'api.php/auth/me')).status()).toBe(503);

  await preencher(env.SGI_INSTALL_CODE);
  await expect(page.locator('.card.ok h2')).toHaveText('Instalação concluída', { timeout: 120000 });
  await expect(page.locator('.card.ok')).toContainText('Documentos da FACASI importados: 165');

  // assistente bloqueado após instalação
  await page.goto(URL_BASE + 'instalar/');
  await expect(page.locator('.card.ok h2')).toHaveText('O SGI já está instalado');
  // ficheiros sensíveis não acessíveis por HTTP (no Apache, pelo .htaccess; no php -S, o teste verifica que não devolvem conteúdo)
  for (const f of ['config.php', 'instalar/codigo.hash', 'lib/Auth.php', 'migrations/1.0.0/001_nucleo.sql']) {
    const r = await request.get(URL_BASE + f);
    const txt = await r.text();
    expect(txt.includes('db_pass') || txt.includes('$2y$') || txt.includes('class Auth') || txt.includes('CREATE TABLE'), f).toBeFalsy();
  }
  gravarEstado({ instalado: true });
});
