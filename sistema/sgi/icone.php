<?php
/** Ícone da aplicação: logótipo da organização (com margem) ou ícone neutro do SGI. */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
$size = in_array((int)($_GET['s'] ?? 192), [180, 192, 512], true) ? (int)$_GET['s'] : 192;
$mask = !empty($_GET['m']);
$neutral = SGI_ROOT . '/app/img/' . ($size === 180 ? 'apple-touch-icon.png' : ($mask ? 'icone-512-maskable.png' : "icone-$size.png"));
$org = Brand::org();
header('Cache-Control: public, max-age=86400');
header('Content-Type: image/png');
if (!$org || empty($org['logo_path']) || !function_exists('imagecreatefromstring')) {
    readfile(is_file($neutral) ? $neutral : SGI_ROOT . '/app/img/icone-192.png');
    exit;
}
try {
    $src = @imagecreatefromstring(Storage::get((string)$org['logo_path']));
    if (!$src) {
        throw new RuntimeException('logo');
    }
    $dst = imagecreatetruecolor($size, $size);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    $pad = (int)round($size * ($mask ? 0.18 : 0.06));
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(($size - 2 * $pad) / $w, ($size - 2 * $pad) / $h);
    $nw = (int)round($w * $scale);
    $nh = (int)round($h * $scale);
    imagecopyresampled($dst, $src, (int)(($size - $nw) / 2), (int)(($size - $nh) / 2), 0, 0, $nw, $nh, $w, $h);
    imagepng($dst);
} catch (Throwable $e) {
    readfile($neutral);
}
