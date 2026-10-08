<?php
/**
 * Moldura isolada para mostrar/preencher modelos HTML.
 * É carregada num iframe com sandbox SEM allow-same-origin (origem opaca): os scripts dos modelos
 * não acedem à sessão, aos tokens nem aos dados guardados no dispositivo.
 * O conteúdo chega por postMessage e só é aceite da própria origem do SGI.
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
$origin = (sgi_is_https() ? 'https' : 'http') . '://' . preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; script-src 'unsafe-inline' https://cdn.tailwindcss.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; style-src 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com https://cdn.jsdelivr.net; font-src https://cdnjs.cloudflare.com https://fonts.gstatic.com https://cdn.jsdelivr.net data:; img-src data: blob: $origin https:; media-src data: blob:; connect-src https://cdn.jsdelivr.net; form-action 'none'; base-uri 'none'; frame-ancestors 'self'");
?><!DOCTYPE html><html lang="pt-AO"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Modelo</title></head>
<body style="font:14px Arial,sans-serif;color:#475569;padding:16px">A preparar o documento…
<script>
(function () {
  var ORIGEM = <?= json_encode($origin) ?>;
  window.addEventListener('message', function (ev) {
    if (ev.origin !== ORIGEM || ev.source !== window.parent || !ev.data || ev.data.t !== 'carregar') return;
    document.open();
    document.write(String(ev.data.html));
    document.close();
  });
  window.parent.postMessage({ t: 'quadro-pronto' }, ORIGEM);
})();
</script>
</body></html>
