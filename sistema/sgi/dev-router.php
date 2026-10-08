<?php
/**
 * Router para o servidor embutido do PHP (apenas desenvolvimento/testes):
 *   php -S 127.0.0.1:8080 -t <pasta-que-contém-sgi> sgi/dev-router.php
 * Reproduz as proteções do .htaccess (o servidor embutido não lê .htaccess).
 */
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
if (preg_match('#/(lib|migrations|modelos|storage)(/|$)|/config\.php$|/codigo\.hash$|/\.instalado$|/VERSION$|/\.user\.ini$|/\.htaccess$|/dev-router\.php$#', $path)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Acesso negado.';
    return true;
}
if (substr($path, -12) === '.webmanifest') {
    header('Content-Type: application/manifest+json');
}
return false;
