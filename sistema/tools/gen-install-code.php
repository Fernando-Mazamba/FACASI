<?php
/**
 * Ferramenta LOCAL: gera um novo CÓDIGO DE INSTALAÇÃO. Grava apenas o hash em sgi/instalar/codigo.hash
 * e escreve o código em claro no ficheiro indicado (entregue separadamente do pacote).
 * Uso: php tools/gen-install-code.php [ficheiro-saida]
 */
$alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
$parts = [];
for ($p = 0; $p < 4; $p++) {
    $s = '';
    for ($i = 0; $i < 4; $i++) {
        $s .= $alpha[random_int(0, strlen($alpha) - 1)];
    }
    $parts[] = $s;
}
$code = implode('-', $parts);
$hash = password_hash(str_replace('-', '', $code) === $code ? $code : preg_replace('/\s+/', '', strtoupper($code)), PASSWORD_DEFAULT);
file_put_contents(__DIR__ . '/../sgi/instalar/codigo.hash', $hash . "\n");
$out = $argv[1] ?? null;
if ($out) {
    file_put_contents($out, "CÓDIGO DE INSTALAÇÃO DO SGI\n\n    $code\n\nGerado em " . date('c') . ".\nIntroduza-o no assistente de instalação (https://SEU-DOMINIO/sgi/instalar/).\nGuarde-o em local seguro e não o envie junto com o pacote.\n");
}
echo $code, "\n";
