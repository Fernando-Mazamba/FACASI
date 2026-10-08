// Resumo legível dos relatórios JSON do Playwright e dos ficheiros de ida e volta.
import fs from 'node:fs';
import path from 'node:path';
const [nome, dir] = process.argv.slice(2);
const linhas = [`Passagem: ${nome} — ${new Date().toISOString()}`];
function percorrer(suite, out) {
  for (const s of suite.suites || []) percorrer(s, out);
  for (const sp of suite.specs || []) {
    for (const t of sp.tests || []) {
      const r = t.results[t.results.length - 1] || {};
      out.push({ titulo: sp.title, estado: r.status || (t.status === 'skipped' ? 'skipped' : 'desconhecido'), duracao: r.duration || 0, erro: r.error ? String(r.error.message || '').split('\n')[0] : '' });
    }
  }
}
for (const tipo of ['aceitacao', 'ida-volta']) {
  const f = path.join(dir, `resultado-${nome}-${tipo}.json`);
  if (!fs.existsSync(f)) continue;
  const j = JSON.parse(fs.readFileSync(f, 'utf8'));
  const out = [];
  for (const s of j.suites || []) percorrer(s, out);
  linhas.push(`\n[${tipo}]`);
  for (const o of out) linhas.push(`${o.estado === 'passed' ? 'PASSOU ' : o.estado === 'skipped' ? 'IGNORADO' : 'FALHOU '} ${o.titulo} (${Math.round(o.duracao / 1000)} s)${o.erro ? ' — ' + o.erro : ''}`);
  const c = (e) => out.filter((o) => o.estado === e).length;
  linhas.push(`Total: ${out.length} · passou ${c('passed')} · falhou ${out.length - c('passed') - c('skipped')} · ignorado ${c('skipped')}`);
}
const idv = path.join(dir, nome);
if (fs.existsSync(idv)) {
  for (const larg of [390, 1280]) {
    const files = fs.readdirSync(idv).filter((f) => f.startsWith(`ida-volta-${larg}-`));
    if (!files.length) continue;
    const all = files.flatMap((f) => JSON.parse(fs.readFileSync(path.join(idv, f), 'utf8')));
    const forms = all.filter((x) => x.campos > 0);
    linhas.push(`\nIda e volta ${larg}px: ${all.filter((x) => x.ok).length}/${all.length} documentos OK (${forms.filter((x) => x.ok).length}/${forms.length} com campos preenchidos e reabertos; ${all.length - forms.length} sem campos, só consulta)`);
    for (const x of all.filter((y) => !y.ok)) linhas.push(`   ✗ ${x.code}: ${x.detalhe}`);
  }
}
console.log(linhas.join('\n'));
