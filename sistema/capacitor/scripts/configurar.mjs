// Define o endereço do servidor do SGI usado pela aplicação nativa.
// Uso: npm run configurar -- https://o-seu-dominio/sgi/
import fs from 'node:fs';
const url = process.argv[2];
if (!url || !/^https:\/\/[^/]+\/.*$/.test(url)) {
  console.error('Indique o endereço completo do SGI com https, por exemplo: npm run configurar -- https://textesgi.sistemaiso.net/sgi/');
  process.exit(1);
}
const u = new URL(url.endsWith('/') ? url : url + '/');
const cfg = JSON.parse(fs.readFileSync('capacitor.config.json', 'utf8'));
cfg.server.url = u.href;
cfg.server.allowNavigation = [u.host];
fs.writeFileSync('capacitor.config.json', JSON.stringify(cfg, null, 2) + '\n');
console.log('Servidor configurado:', u.href);
console.log('Agora execute: npx cap sync');
