// Ida e volta de TODOS os HTML da FACASI (preencher → guardar no MySQL → reabrir → valores iguais)
// em largura de computador (1280 px, teste de base) e de telemóvel (390 px, T22).
// Modelos sem campos: abrem-se e verifica-se que o conteúdo cabe no ecrã (sem perda de conteúdo).
import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { URL_BASE, CONTAS, sessao } from './helpers.js';

const CAT = JSON.parse(fs.readFileSync(path.resolve('../sgi/modelos/facasi/catalogo.json'), 'utf8')).modelos;
const LOTES = Number(process.env.SGI_LOTES || 8);
const LARGURAS = (process.env.SGI_LARGURAS || '390,1280').split(',').map(Number);

test.describe.configure({ mode: 'parallel' });

async function verificarModelo(page, base, t, largura) {
  const out = { code: t.code, largura, campos: 0, ok: false, detalhe: '' };
  // carga limpa da aplicação para cada documento (sem reaproveitar o editor anterior)
  await page.goto('about:blank');
  if (t.is_form) {
    await page.goto(base + '#/registos/novo/' + t.id);
    await page.waitForFunction(() => window.__sgiEditor && window.__sgiEditor.host, null, { timeout: 60000 });
    const filled = await page.evaluate((seed) => window.__sgiEditor.host.fillTest(seed), `${t.code}-${largura}`);
    out.campos = filled.count;
    if (filled.count === 0) { out.detalhe = 'classificado como formulário, mas sem campos em execução. '; }
    await page.locator('.titulo-registo').fill(`Ida e volta ${t.code} ${largura}px`);
    await page.getByRole('button', { name: '💾 Guardar' }).click();
    await expect(page.locator('.estado-guardado')).toHaveClass(/ok/, { timeout: 30000 });
    const id = await page.evaluate(() => window.__sgiEditor.ctx.recordId);
    // reabrir a partir do servidor (nova carga da página)
    await page.goto('about:blank');
    await page.goto(base + '#/registos/' + id);
    await page.waitForFunction((rid) => window.__sgiEditor && window.__sgiEditor.ctx.recordId === rid, id, { timeout: 60000 });
    const back = await page.evaluate(() => window.__sgiEditor.host.collect());
    const dif = Object.keys(filled.fields).filter((k) => (filled.fields[k] ?? '') !== (back.fields[k] ?? ''));
    if (back.count !== filled.count) out.detalhe += `n.º de campos ${filled.count} → ${back.count}. `;
    if (dif.length) out.detalhe += `campos diferentes: ${dif.slice(0, 5).map((k) => `${k} «${String(filled.fields[k]).slice(0, 30)}» → «${String(back.fields[k]).slice(0, 30)}»`).join('; ')}`;
    out.ok = !out.detalhe;
  } else {
    await page.goto(base + '#/documentos/' + t.id);
    await page.waitForFunction(() => window.__sgiVista && window.__sgiVista.info, null, { timeout: 60000 });
    out.campos_execucao = await page.evaluate(() => window.__sgiVista.info.count);
    out.ok = true;
    // coerência: documento de consulta não pode ter campos (senão deveria ser preenchível)
    if (out.campos_execucao > 0) { out.ok = false; out.detalhe = `classificado como consulta, mas tem ${out.campos_execucao} campo(s) em execução.`; }
  }
  // conteúdo dentro do ecrã: o documento não pode ultrapassar a largura (tabelas largas deslizam dentro do seu contentor)
  const frame = page.frames().find((f) => f !== page.mainFrame() && /quadro\.php/.test(f.url()));
  if (frame) {
    await frame.waitForLoadState('load').catch(() => {});
    const m = await frame.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth, txt: (document.body.innerText || '').length }));
    out.overflow = m.sw - m.cw;
    out.texto = m.txt;
    if (largura <= 400 && m.sw > m.cw + 2) { out.ok = false; out.detalhe += ` ultrapassa a largura do ecrã em ${m.sw - m.cw}px.`; }
    if (m.txt < 20) { out.ok = false; out.detalhe += ' documento vazio.'; }
  }
  return out;
}

for (const largura of LARGURAS) {
  for (let lote = 0; lote < LOTES; lote++) {
    test(`${largura === 390 ? 'T22' : 'Base'} ida e volta ${largura}px — lote ${lote + 1}/${LOTES}`, async ({ browser }) => {
      test.setTimeout(30 * 60 * 1000);
      const admin = await sessao(CONTAS.admin);
      const todos = (await admin.call('GET', '/templates?status=vigente')).body.templates;
      await admin.dispose();
      const meus = CAT.filter((_, i) => i % LOTES === lote).map((m) => {
        const t = todos.find((x) => x.code === m.code);
        return t ? { ...t, is_form: t.is_form } : { code: m.code, missing: true };
      });
      const ctx = await browser.newContext({ viewport: { width: largura, height: largura <= 400 ? 844 : 900 }, isMobile: largura <= 400, hasTouch: largura <= 400, deviceScaleFactor: 1 });
      const page = await ctx.newPage();
      await page.goto(URL_BASE);
      await page.locator('input[type=email]').fill(CONTAS.coordenador.email);
      await page.locator('input[type=password]').fill(CONTAS.coordenador.password);
      await page.getByRole('button', { name: 'Entrar' }).click();
      await expect(page.locator('#topo')).toBeVisible();
      const res = [];
      for (const t of meus) {
        if (t.missing) { res.push({ code: t.code, largura, ok: false, detalhe: 'modelo não existe no SGI' }); continue; }
        try {
          res.push(await verificarModelo(page, URL_BASE, t, largura));
        } catch (e) {
          res.push({ code: t.code, largura, ok: false, detalhe: 'erro: ' + String(e.message).split('\n')[0] });
        }
      }
      const dir = path.resolve(process.env.SGI_RESULTS || 'results');
      fs.mkdirSync(dir, { recursive: true });
      fs.writeFileSync(path.join(dir, `ida-volta-${largura}-lote${lote + 1}.json`), JSON.stringify(res, null, 1));
      await ctx.close();
      const falhas = res.filter((r) => !r.ok);
      expect(falhas, falhas.map((f) => `${f.code}: ${f.detalhe}`).join('\n')).toEqual([]);
    });
  }
}
