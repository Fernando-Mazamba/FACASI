// T24 — Apache + PHP (mod_php) com .htaccess em subpasta, como no cPanel. Executado apenas na passagem por Apache.
import { test, expect } from '@playwright/test';
import { URL_BASE, CONTAS, sessao, app } from './helpers.js';

test('T24 Apache em subpasta: .htaccess protege ficheiros internos, cabeçalho Authorization chega ao PHP, versão do PHP', async ({ request }) => {
  test.skip(process.env.SGI_SERVIDOR !== 'apache', 'Só na passagem por Apache');
  for (const p of ['config.php', 'VERSION', 'lib/Auth.php', 'lib/', 'migrations/1.0.0/001_nucleo.sql', 'modelos/facasi/catalogo.json', 'modelos/', 'instalar/codigo.hash', '.user.ini', 'storage/']) {
    const r = await request.get(URL_BASE + p);
    expect([403, 404], p + ' → ' + r.status()).toContain(r.status());
  }
  const idx = await request.get(URL_BASE + 'app/');
  expect(idx.status(), 'listagem de pastas desativada').not.toBe(200);
  const cli = await app(CONTAS.coordenador);
  expect((await cli.call('GET', '/auth/me')).body.mode).toBe('token');
  await cli.dispose();
  const plat = await sessao(CONTAS.plataforma);
  const st = (await plat.call('GET', '/platform/status')).body;
  expect(st.servidor_web).toMatch(/Apache/i);
  expect(st.php).toMatch(/^8\.[23]\./);
  expect(st.php_sapi).toMatch(/apache/);
  console.log('Servidor:', st.servidor_web, '| PHP', st.php, st.php_sapi, '| MySQL', st.mysql);
  const man = await request.get(URL_BASE + 'manifest.php');
  expect(man.headers()['content-type']).toContain('application/manifest+json');
  const sw = await request.get(URL_BASE + 'sw.js');
  expect(sw.headers()['content-type']).toMatch(/javascript/);
  await plat.dispose();
});
