<?php
/** Casca da aplicação (SPA/PWA). O conteúdo é carregado pela API; esta página é guardada para uso sem ligação. */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
if (!sgi_is_installed()) {
    header('Location: ' . sgi_base_path() . '/instalar/');
    exit;
}
sgi_security_headers();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; media-src 'self' blob:; connect-src 'self'; frame-src 'self' blob:; worker-src 'self'; manifest-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
$base = sgi_base_path();
$v = rawurlencode(SGI_VERSION . '-' . substr(md5((string)@filemtime(__DIR__ . '/app/js/main.js') . (string)@filemtime(__DIR__ . '/app/css/app.css')), 0, 6));
?><!DOCTYPE html>
<html lang="pt-AO" data-base="<?= h($base) ?>" data-version="<?= h(SGI_VERSION) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#1e3a8a">
<meta name="description" content="Sistema de Gestão Integrado ISO 9001 · 14001 · 45001">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="SGI">
<title>SGI</title>
<link rel="manifest" href="<?= h($base) ?>/manifest.php">
<link rel="icon" href="<?= h($base) ?>/app/img/icone-sgi.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= h($base) ?>/icone.php?s=180">
<link rel="stylesheet" href="<?= h($base) ?>/app/css/app.css?v=<?= $v ?>">
</head>
<body>
<a class="skip" href="#conteudo">Saltar para o conteúdo</a>
<header class="topo" id="topo" hidden>
  <button class="icon-btn menu-btn" id="btn-menu" aria-label="Menu" aria-expanded="false">☰</button>
  <a class="marca" href="#/inicio"><img id="logo-org" src="<?= h($base) ?>/app/img/icone-sgi.svg" alt="" width="28" height="28"><span id="nome-org">SGI</span></a>
  <span class="estado-rede" id="estado-rede" role="status" aria-live="polite"></span>
  <a class="icon-btn" href="#/sincronizar" id="btn-sync" title="Pendentes de sincronizar" hidden>⟳<span class="badge" id="badge-sync"></span></a>
  <a class="icon-btn" href="#/notificacoes" id="btn-notif" title="Notificações">🔔<span class="badge" id="badge-notif" hidden></span></a>
</header>
<nav class="menu" id="menu" hidden aria-label="Menu principal"></nav>
<main id="conteudo" tabindex="-1"><div class="carregando">A carregar…</div></main>
<nav class="barra-inferior" id="barra-inferior" hidden aria-label="Navegação rápida"></nav>
<div id="toasts" class="toasts" role="status" aria-live="polite"></div>
<noscript><p style="padding:16px">O SGI precisa de JavaScript ativo.</p></noscript>
<script type="module" src="<?= h($base) ?>/app/js/main.js?v=<?= $v ?>"></script>
</body>
</html>
