<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$org = Brand::org();
$base = sgi_base_path();
$name = $org ? 'SGI ' . $org['name'] : 'SGI — Sistema de Gestão Integrado';
$short = $org ? mb_substr('SGI ' . $org['name'], 0, 12) : 'SGI';
$v = $org && $org['logo_path'] ? substr(md5((string)$org['logo_path']), 0, 8) : 'n';
$icons = [
    ['src' => "$base/icone.php?s=192&v=$v", 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
    ['src' => "$base/icone.php?s=512&v=$v", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
    ['src' => "$base/icone.php?s=512&m=1&v=$v", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
];
echo json_encode([
    'id' => "$base/",
    'name' => $name,
    'short_name' => $short,
    'description' => 'Sistema de Gestão Integrado ISO 9001 · 14001 · 45001 — registos, documentos, aprovações e trabalho de campo.',
    'lang' => 'pt-AO',
    'dir' => 'ltr',
    'start_url' => "$base/?origem=app",
    'scope' => "$base/",
    'display' => 'standalone',
    'orientation' => 'any',
    'background_color' => '#ffffff',
    'theme_color' => '#1e3a8a',
    'categories' => ['business', 'productivity'],
    'icons' => $icons,
    'shortcuts' => [
        ['name' => 'Pendências', 'url' => "$base/#/inicio"],
        ['name' => 'Ler código QR', 'url' => "$base/#/qr"],
        ['name' => 'Novo registo', 'url' => "$base/#/documentos?formularios=1"],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
