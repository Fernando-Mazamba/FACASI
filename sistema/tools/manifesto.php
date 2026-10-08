<?php
/**
 * Ferramenta LOCAL: gera sgi/PACOTE.json (lista de ficheiros, SHA-256 e parte do ZIP onde cada um vai).
 * Uso: php tools/manifesto.php <pasta-sgi> <limite-bytes-por-parte>
 */
$dir = rtrim($argv[1], '/');
$limite = (int)($argv[2] ?? 20 * 1048576);
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile()) {
        $rel = substr(str_replace('\\', '/', $f->getPathname()), strlen($dir) + 1);
        if ($rel === 'PACOTE.json') continue;
        $files[$rel] = $f->getSize();
    }
}
ksort($files);
// parte 1: tudo exceto os ficheiros de apoio grandes da biblioteca; partes seguintes: ficheiros de apoio
$partes = [1 => 0];
$mapa = [];
$n = 2;
$tamanho = 0;
foreach ($files as $rel => $size) {
    if (strpos($rel, 'modelos/facasi/ativos/') === 0) {
        if ($tamanho > 0 && $tamanho + $size > $limite) {
            $n++;
            $tamanho = 0;
        }
        $mapa[$rel] = $n;
        $tamanho += $size;
        $partes[$n] = ($partes[$n] ?? 0) + $size;
    } else {
        $mapa[$rel] = 1;
        $partes[1] += $size;
    }
}
$out = ['produto' => 'SGI', 'versao' => trim((string)file_get_contents("$dir/VERSION")), 'gerado_em' => date('c'), 'total_partes' => count($partes), 'ficheiros' => []];
foreach ($files as $rel => $size) {
    $out['ficheiros'][$rel] = ['sha256' => hash_file('sha256', "$dir/$rel"), 'bytes' => $size, 'parte' => $mapa[$rel]];
}
file_put_contents("$dir/PACOTE.json", json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
foreach ($partes as $p => $b) {
    echo "$p\t$b\n";
}
