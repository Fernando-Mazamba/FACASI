// T02 — Utilizadores por perfil, sessão + CSRF, bloqueio por tentativas (conta e organização), separação da plataforma.
import { test, expect } from '@playwright/test';
import { URL_BASE, CONTAS, sessao, gravarEstado } from './helpers.js';

test('T02 perfis, CSRF obrigatório e bloqueio por tentativas', async ({ request }) => {
  const admin = await sessao(CONTAS.admin);
  const ids = {};
  for (const [role, conta] of [['coordenador', CONTAS.coordenador], ['supervisor', CONTAS.supervisor], ['operario', CONTAS.operario], ['direcao', { email: 'direcao@facasi.teste', password: 'Direcao12345' }]]) {
    const r = await admin.call('POST', '/users', { name: `Utilizador ${role} (teste)`, email: conta.email, role });
    expect(r.status, JSON.stringify(r.body)).toBe(201);
    expect(r.body.temp_password).toBeTruthy();
    ids[role] = r.body.id;
    // primeiro acesso com palavra-passe temporária obriga a alterá-la
    const tmp = await sessao({ email: conta.email, password: r.body.temp_password });
    expect(tmp.me.user.must_change_password).toBe(true);
    const ch = await tmp.call('POST', '/auth/password', { current: r.body.temp_password, new: conta.password });
    expect(ch.status).toBe(200);
    await tmp.dispose();
  }
  // CSRF: pedido que altera dados sem o cabeçalho é recusado
  const semCsrf = await admin.ctx.post('api.php/users', { data: { name: 'x', email: 'x@x.ao' } });
  expect(semCsrf.status()).toBe(403);
  expect((await semCsrf.json()).code).toBe('csrf');
  // palavra-passe fraca recusada
  const op = await sessao(CONTAS.operario);
  expect((await op.call('POST', '/auth/password', { current: CONTAS.operario.password, new: 'curta' })).status).toBe(422);
  // operário não gere utilizadores
  expect((await op.call('POST', '/users', { name: 'y', email: 'y@y.ao' })).status).toBe(403);

  // bloqueio da conta após 5 tentativas falhadas
  const vitima = 'direcao@facasi.teste';
  for (let i = 0; i < 5; i++) {
    const r = await request.post(URL_BASE + 'api.php/auth/login', { data: { email: vitima, password: 'errada' + i } });
    expect(r.status()).toBe(401);
  }
  const bloq = await request.post(URL_BASE + 'api.php/auth/login', { data: { email: vitima, password: 'Direcao12345' } });
  expect(bloq.status()).toBe(423);
  // o administrador desbloqueia
  expect((await admin.call('PUT', '/users/' + ids.direcao, { unlock: 1 })).status).toBe(200);
  expect((await request.post(URL_BASE + 'api.php/auth/login', { data: { email: vitima, password: 'Direcao12345' } })).status()).toBe(200);

  // a administração da plataforma não acede a documentos das organizações
  const plat = await sessao(CONTAS.plataforma);
  const docs = await plat.call('GET', '/templates');
  expect(docs.status).toBe(403);
  expect(docs.body.code).toBe('plataforma_sem_acesso');
  expect((await plat.call('GET', '/records')).status).toBe(403);
  expect((await plat.call('GET', '/records/1/final')).status).toBe(403);
  // e a organização não acede à plataforma
  expect((await admin.call('GET', '/platform/status')).status).toBe(403);
  gravarEstado({ users: ids });
  await Promise.all([admin.dispose(), op.dispose(), plat.dispose()]);
});
