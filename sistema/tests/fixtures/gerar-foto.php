<?php
// Gera uma fotografia JPEG de teste (2400×1800, com ruído) para simular a câmara do telemóvel.
$w = 2400; $h = 1800;
$im = imagecreatetruecolor($w, $h);
for ($y = 0; $y < $h; $y += 6) {
    for ($x = 0; $x < $w; $x += 6) {
        $c = imagecolorallocate($im, ($x * 7 + $y) % 256, ($y * 3 + random_int(0, 60)) % 256, (($x + $y) / 3 + random_int(0, 90)) % 256);
        imagefilledrectangle($im, $x, $y, $x + 5, $y + 5, $c);
    }
}
imagestring($im, 5, 40, 40, 'SGI - fotografia de teste (camara simulada)', imagecolorallocate($im, 255, 255, 255));
imagejpeg($im, $argv[1], 92);
echo filesize($argv[1]), " bytes\n";
