// Recursos do telemóvel: câmara (com compressão no aparelho), assinatura no ecrã, QR, geolocalização e push.
import { BASE, api, state } from './api.js';
import { el, modal, toast } from './ui.js';

// ---------- Câmara ----------
/** Abre a câmara (input capture) e devolve um File; compatível com Android, iOS e computador. */
export function escolherFoto() {
  return new Promise((resolve) => {
    const inp = el('input', { type: 'file', accept: 'image/*', capture: 'environment', style: { display: 'none' } });
    inp.addEventListener('change', () => { resolve(inp.files && inp.files[0] ? inp.files[0] : null); inp.remove(); });
    document.body.appendChild(inp);
    inp.click();
  });
}

/** Comprime no aparelho: máx. 1600 px no lado maior, JPEG 0,8. Remove metadados (a imagem é redesenhada). */
export async function comprimirImagem(file, maxLado = 1600, qualidade = 0.8) {
  if (!file || !/^image\//.test(file.type)) throw new Error('O ficheiro não é uma imagem.');
  let bmp;
  try {
    bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
  } catch (e) {
    bmp = await new Promise((resolve, reject) => {
      const img = new Image();
      img.onload = () => resolve(img);
      img.onerror = () => reject(new Error('Imagem inválida.'));
      img.src = URL.createObjectURL(file);
    });
  }
  const w = bmp.width, h = bmp.height;
  const esc = Math.min(1, maxLado / Math.max(w, h));
  const c = document.createElement('canvas');
  c.width = Math.round(w * esc);
  c.height = Math.round(h * esc);
  c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
  const blob = await new Promise((r) => c.toBlob(r, 'image/jpeg', qualidade));
  return { blob, width: c.width, height: c.height, original: file.size, comprimido: blob.size };
}

// ---------- Assinatura no ecrã ----------
/** Recolhe um traço de assinatura. Registo de conformidade visual — NÃO é assinatura digital certificada. */
export async function recolherAssinatura() {
  const canvas = el('canvas', { class: 'assinatura-canvas', width: 600, height: 240, 'aria-label': 'Área para assinar com o dedo ou caneta' });
  const ctx = canvas.getContext('2d');
  ctx.fillStyle = '#fff';
  ctx.fillRect(0, 0, canvas.width, canvas.height);
  ctx.lineWidth = 2.5; ctx.lineCap = 'round'; ctx.strokeStyle = '#0f172a';
  let desenhou = false, ativo = false;
  const pos = (e) => { const r = canvas.getBoundingClientRect(); return [(e.clientX - r.left) * canvas.width / r.width, (e.clientY - r.top) * canvas.height / r.height]; };
  canvas.addEventListener('pointerdown', (e) => { ativo = true; canvas.setPointerCapture(e.pointerId); const [x, y] = pos(e); ctx.beginPath(); ctx.moveTo(x, y); });
  canvas.addEventListener('pointermove', (e) => { if (!ativo) return; const [x, y] = pos(e); ctx.lineTo(x, y); ctx.stroke(); desenhou = true; });
  canvas.addEventListener('pointerup', () => { ativo = false; });
  canvas.style.touchAction = 'none';
  const nome = el('input', { type: 'text', placeholder: 'Nome de quem assina (obrigatório)' });
  const limpar = el('button', { class: 'btn sec', type: 'button', onclick: () => { ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height); desenhou = false; } }, 'Limpar');
  const aviso = el('p', { class: 'aviso' }, 'A assinatura no ecrã é apenas um registo visual de conformidade. Não é uma assinatura digital certificada nem identifica legalmente o signatário.');
  const err = el('p', { class: 'erro-campo', hidden: true });
  const r = await modal('Assinatura no ecrã', [aviso, canvas, limpar, nome, err], [
    { label: 'Cancelar', value: null, class: 'sec' },
    { label: 'Guardar assinatura', value: 'ok', validate: () => {
      if (!desenhou) { err.textContent = 'Desenhe a assinatura.'; err.hidden = false; return false; }
      if (!nome.value.trim()) { err.textContent = 'Indique o nome de quem assina.'; err.hidden = false; return false; }
      return true;
    } },
  ]);
  if (r !== 'ok') return null;
  const blob = await new Promise((res) => canvas.toBlob(res, 'image/png'));
  return { blob, nome: nome.value.trim() };
}

// ---------- Geolocalização (opcional, com consentimento) ----------
export async function pedirLocalizacao() {
  let consent = null;
  try { consent = localStorage.getItem('sgi_geo_consent'); } catch (e) {}
  if (consent !== 'sim') {
    const ok = await modal('Registar localização?', [
      el('p', null, 'O SGI pode guardar a localização GPS aproximada deste registo de campo (ex.: inspeção, incidente).'),
      el('p', null, 'É opcional. A localização só é recolhida quando carregar neste botão e fica associada apenas a este registo. Pode retirar o consentimento no Perfil.'),
    ], [{ label: 'Não autorizo', value: false, class: 'sec' }, { label: 'Autorizo', value: true }]);
    if (!ok) return null;
    try { localStorage.setItem('sgi_geo_consent', 'sim'); } catch (e) {}
  }
  if (!('geolocation' in navigator)) { toast('Este dispositivo não disponibiliza localização.', 'aviso'); return null; }
  return new Promise((resolve) => {
    navigator.geolocation.getCurrentPosition(
      (p) => resolve({ lat: +p.coords.latitude.toFixed(7), lng: +p.coords.longitude.toFixed(7), accuracy: Math.round(p.coords.accuracy) }),
      (e) => { toast('Não foi possível obter a localização: ' + (e.code === 1 ? 'permissão recusada.' : 'sinal indisponível.'), 'aviso'); resolve(null); },
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 });
  });
}

export function retirarConsentimentoGeo() {
  try { localStorage.removeItem('sgi_geo_consent'); } catch (e) {}
}

// ---------- QR ----------
function loadScript(src) {
  return new Promise((resolve, reject) => {
    const s = el('script', { src });
    s.onload = resolve;
    s.onerror = () => reject(new Error('Não foi possível carregar ' + src));
    document.head.appendChild(s);
  });
}

export function urlQr(token) {
  return location.origin + BASE + '/#/qr/' + token;
}

/** Gera um SVG do código QR (biblioteca local qrcode-generator, MIT). */
export async function qrSvg(texto, cell = 4) {
  if (!window.qrcode) await loadScript(BASE + '/app/js/vendor/qrcode.js');
  const q = window.qrcode(0, 'M');
  q.addData(texto);
  q.make();
  return q.createSvgTag({ cellSize: cell, margin: 2, scalable: true });
}

/** Extrai o token de um texto lido (URL do SGI ou só o token). */
export function tokenDoTexto(txt) {
  const m = String(txt || '').match(/#\/qr\/([a-f0-9]{32})/) || String(txt || '').match(/^([a-f0-9]{32})$/);
  return m ? m[1] : null;
}

async function decodeCanvas(canvas) {
  if ('BarcodeDetector' in window) {
    try {
      const det = new window.BarcodeDetector({ formats: ['qr_code'] });
      const r = await det.detect(canvas);
      if (r && r[0]) return r[0].rawValue;
    } catch (e) { /* usa jsQR */ }
  }
  if (!window.jsQR) await loadScript(BASE + '/app/js/vendor/jsQR.js');
  const ctx = canvas.getContext('2d', { willReadFrequently: true });
  const img = ctx.getImageData(0, 0, canvas.width, canvas.height);
  const r = window.jsQR(img.data, img.width, img.height, { inversionAttempts: 'attemptBoth' });
  return r ? r.data : null;
}

/** Lê QR a partir de uma fotografia/ficheiro de imagem. */
export async function lerQrDeImagem(file) {
  const bmp = await createImageBitmap(file);
  const c = document.createElement('canvas');
  const esc = Math.min(1, 1200 / Math.max(bmp.width, bmp.height));
  c.width = Math.round(bmp.width * esc); c.height = Math.round(bmp.height * esc);
  c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
  return decodeCanvas(c);
}

/** Leitura contínua pela câmara. Devolve função para parar. */
export async function iniciarLeitorQr(video, onResult) {
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) throw new Error('Este navegador não permite usar a câmara. Use "Ler de uma fotografia".');
  const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
  video.srcObject = stream;
  video.setAttribute('playsinline', '');
  await video.play();
  const c = document.createElement('canvas');
  let parar = false;
  const loop = async () => {
    if (parar) return;
    if (video.readyState >= 2) {
      c.width = video.videoWidth; c.height = video.videoHeight;
      c.getContext('2d', { willReadFrequently: true }).drawImage(video, 0, 0);
      const txt = await decodeCanvas(c).catch(() => null);
      if (txt) { onResult(txt); return; }
    }
    setTimeout(loop, 250);
  };
  loop();
  return () => { parar = true; stream.getTracks().forEach((t) => t.stop()); video.srcObject = null; };
}

// ---------- Notificações push ----------
function b64ToUint8(b64) {
  const pad = '='.repeat((4 - (b64.length % 4)) % 4);
  const raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
  return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)));
}

export function pushSuportado() {
  return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

export async function estadoPush() {
  if (!pushSuportado()) return 'nao_suportado';
  if (!state.me || !state.me.push_key) return 'servidor_desligado';
  const reg = await navigator.serviceWorker.ready;
  const sub = await reg.pushManager.getSubscription();
  return sub ? 'ativo' : (Notification.permission === 'denied' ? 'bloqueado' : 'inativo');
}

export async function ativarPush() {
  if (!pushSuportado()) throw new Error('Este dispositivo/navegador não suporta notificações push. As notificações internas continuam disponíveis no sino.');
  if (!state.me.push_key) throw new Error('As notificações push estão desligadas no servidor.');
  const perm = await Notification.requestPermission();
  if (perm !== 'granted') throw new Error('Permissão de notificações recusada no dispositivo.');
  const reg = await navigator.serviceWorker.ready;
  const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToUint8(state.me.push_key) });
  await api('POST', '/push/subscribe', sub.toJSON());
  return true;
}

export async function desativarPush() {
  if (!pushSuportado()) return;
  const reg = await navigator.serviceWorker.ready;
  const sub = await reg.pushManager.getSubscription();
  if (sub) {
    await api('POST', '/push/unsubscribe', { endpoint: sub.endpoint }).catch(() => {});
    await sub.unsubscribe();
  }
}
