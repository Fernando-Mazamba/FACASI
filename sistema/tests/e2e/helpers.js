// Utilitários partilhados pelos testes E2E do SGI.
import { request as pwRequest, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

export const URL_BASE = process.env.SGI_URL || 'http://127.0.0.1:8090/sgi/';
export const URL_UPD = process.env.SGI_UPD_URL || 'http://127.0.0.1:8091/sgi/';
export const ESTADO = path.resolve(process.env.SGI_STATE || 'results/estado.json');

export const CONTAS = {
  plataforma: { email: 'plataforma@teste.ao', password: 'Plataforma123' },
  admin: { email: 'admin@facasi.teste', password: 'AdminFacasi123' },
  coordenador: { email: 'coordenador@facasi.teste', password: 'Coordenador123' },
  supervisor: { email: 'supervisor@facasi.teste', password: 'Supervisor123' },
  operario: { email: 'operario@facasi.teste', password: 'Operario12345' },
  beta: { email: 'admin@beta.teste', password: 'AdminBeta12345' },
};

export function lerEstado() {
  try { return JSON.parse(fs.readFileSync(ESTADO, 'utf8')); } catch (e) { return {}; }
}
export function gravarEstado(patch) {
  const s = Object.assign(lerEstado(), patch);
  fs.mkdirSync(path.dirname(ESTADO), { recursive: true });
  fs.writeFileSync(ESTADO, JSON.stringify(s, null, 2));
  return s;
}

/** Cliente da API com sessão (cookie + CSRF) — como o navegador. */
export async function sessao(conta, base = URL_BASE) {
  const ctx = await pwRequest.newContext({ baseURL: base });
  const r = await ctx.post('api.php/auth/login', { data: conta });
  if (!r.ok()) throw new Error(`login ${conta.email}: ${r.status()} ${await r.text()}`);
  const me = await r.json();
  const call = async (method, p, data, extra = {}) => {
    const headers = Object.assign({ 'X-CSRF-Token': me.csrf }, extra.headers || {});
    const opts = { method, headers };
    if (data instanceof FormData || extra.multipart) opts.multipart = extra.multipart || data;
    else if (data !== undefined) opts.data = data;
    const res = await ctx.fetch('api.php' + p, opts);
    const ct = res.headers()['content-type'] || '';
    const body = ct.includes('json') ? await res.json() : await res.body();
    return { status: res.status(), body, headers: res.headers() };
  };
  return { ctx, me, call, dispose: () => ctx.dispose() };
}

/** Cliente da API com token Bearer (aplicação). */
export async function app(conta, base = URL_BASE, device = 'Teste automático') {
  const ctx = await pwRequest.newContext({ baseURL: base });
  const r = await ctx.post('api.php/auth/token', { data: { ...conta, device_name: device } });
  if (!r.ok()) throw new Error(`token ${conta.email}: ${r.status()} ${await r.text()}`);
  const tok = await r.json();
  const call = async (method, p, data, token = tok.access_token) => {
    const res = await ctx.fetch('api.php' + p, { method, data, headers: { Authorization: 'Bearer ' + token } });
    const ct = res.headers()['content-type'] || '';
    return { status: res.status(), body: ct.includes('json') ? await res.json() : await res.body() };
  };
  return { ctx, tok, call, dispose: () => ctx.dispose() };
}

export async function entrarUI(page, conta, url = URL_BASE) {
  await page.goto(url);
  await page.locator('input[type=email]').fill(conta.email);
  await page.locator('input[type=password]').fill(conta.password);
  await page.getByRole('button', { name: 'Entrar' }).click();
  await expect(page.locator('#topo')).toBeVisible();
  // espera que o primeiro ecrã esteja desenhado (evita corrida com a navegação seguinte)
  await expect(page.locator('form.login')).toHaveCount(0);
  await expect(page.locator('#conteudo .pagina-cabecalho, #conteudo .cartao').first()).toBeVisible({ timeout: 20000 });
}

export async function esperarEditor(page) {
  await page.waitForFunction(() => window.__sgiEditor && window.__sgiEditor.host, null, { timeout: 60000 });
}

export async function idTemplate(cli, code) {
  const r = await cli.call('GET', '/templates?q=' + encodeURIComponent(code));
  const t = r.body.templates.find((x) => x.code === code);
  if (!t) throw new Error('modelo não encontrado: ' + code);
  return t;
}

