<?php
/**
 * Arranque comum: constantes, autoload, configuração, ligação à BD e sessão.
 * Compatível com PHP 7.4 – 8.4.
 */
declare(strict_types=1);

if (PHP_VERSION_ID < 70400) {
    http_response_code(500);
    exit('O SGI requer PHP 7.4 ou superior.');
}

define('SGI_ROOT', dirname(__DIR__));
define('SGI_VERSION', trim((string)@file_get_contents(SGI_ROOT . '/VERSION')) ?: '0.0.0');

spl_autoload_register(function ($class) {
    $f = __DIR__ . '/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($f)) {
        require $f;
    }
});

require_once __DIR__ . '/helpers.php';

date_default_timezone_set('Africa/Luanda');
mb_internal_encoding('UTF-8');
ini_set('default_charset', 'UTF-8');

/** Caminho base público (ex.: "/sgi"), calculado a partir do script executado. */
function sgi_base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
    $root = str_replace('\\', '/', SGI_ROOT);
    $real = str_replace('\\', '/', (string)realpath($script));
    $rel = '';
    if ($real !== '' && strpos($real, $root) === 0) {
        $rel = substr($real, strlen($root));
    }
    $name = $_SERVER['SCRIPT_NAME'] ?? '';
    if ($rel !== '' && substr($name, -strlen($rel)) === $rel) {
        $base = substr($name, 0, -strlen($rel));
    } else {
        $base = rtrim(str_replace('\\', '/', dirname($name)), '/');
    }
    $base = rtrim($base, '/');
    return $base;
}

function sgi_config_path(): string
{
    return SGI_ROOT . '/config.php';
}

function sgi_is_installed(): bool
{
    return is_file(sgi_config_path());
}

/** @return array<string,mixed> */
function sgi_config(bool $reload = false): array
{
    static $cfg = null;
    if ($cfg === null || $reload) {
        $cfg = sgi_is_installed() ? (array)require sgi_config_path() : [];
        $cfg += [
            'db_host' => 'localhost', 'db_port' => 3306, 'db_name' => '', 'db_user' => '', 'db_pass' => '',
            'storage_path' => SGI_ROOT . '/storage',
            'app_key' => '',
            'debug' => false,
            'force_https' => false,
        ];
    }
    return $cfg;
}

function sgi_storage(): string
{
    return rtrim((string)sgi_config()['storage_path'], '/');
}

function sgi_db(bool $reset = false): Db
{
    static $db = null;
    if ($db === null || $reset) {
        $c = sgi_config();
        $db = new Db((string)$c['db_host'], (int)$c['db_port'], (string)$c['db_name'], (string)$c['db_user'], (string)$c['db_pass']);
    }
    return $db;
}

function sgi_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
        return true;
    }
    return (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;
}

function sgi_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $path = sgi_base_path() . '/';
    session_name('SGISESS');
    $params = [
        'lifetime' => 0,
        'path' => $path,
        'secure' => sgi_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    session_set_cookie_params($params);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', '28800');
    // Sessões guardadas dentro do storage do SGI quando possível (hospedagem partilhada).
    $sdir = sgi_storage() . '/sessions';
    if (is_dir($sdir) && is_writable($sdir)) {
        session_save_path($sdir);
    }
    session_start();
}

function sgi_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header('Permissions-Policy: camera=(self), geolocation=(self), microphone=()');
}

set_exception_handler(function (Throwable $e) {
    error_log('[SGI] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    $msg = 'Erro interno do servidor.';
    if (!empty(sgi_config()['debug'])) {
        $msg .= ' ' . $e->getMessage();
    }
    echo json_encode(['error' => $msg, 'code' => 'erro_interno'], JSON_UNESCAPED_UNICODE);
});
