// Ferramenta LOCAL: gera os PNG do ícone neutro do SGI a partir do SVG (usa o Chromium do Playwright).
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
const dir = path.resolve(path.dirname(new URL(import.meta.url).pathname), '../sgi/app/img');
const svg = fs.readFileSync(path.join(dir, 'icone-sgi.svg'), 'utf8');
const browser = await chromium.launch();
const page = await browser.newPage();
for (const [size, maskable] of [[192, false], [512, false], [512, true], [180, false]]) {
  await page.setViewportSize({ width: size, height: size });
  const pad = maskable ? Math.round(size * 0.1) : 0;
  await page.setContent(`<html><body style="margin:0;background:${maskable ? '#1e3a8a' : 'transparent'}"><div style="padding:${pad}px">${svg.replace('<svg ', `<svg width="${size - 2 * pad}" height="${size - 2 * pad}" `)}</div></body></html>`);
  const name = size === 180 ? 'apple-touch-icon.png' : `icone-${size}${maskable ? '-maskable' : ''}.png`;
  await page.screenshot({ path: path.join(dir, name), omitBackground: !maskable, clip: { x: 0, y: 0, width: size, height: size } });
  console.log('gerado', name);
}
await browser.close();
