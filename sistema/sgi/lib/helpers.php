<?php
declare(strict_types=1);

class HttpError extends Exception
{
    /** @var int */
    public $status;
    /** @var string */
    public $codeName;
    /** @var array<string,mixed> */
    public $extra;

    /** @param array<string,mixed> $extra */
    public function __construct(int $status, string $message, string $codeName = 'erro', array $extra = [])
    {
        parent::__construct($message);
        $this->status = $status;
        $this->codeName = $codeName;
        $this->extra = $extra;
    }
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function random_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 64);
}

/** @param mixed $data */
function json_out($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Nome de ficheiro/pasta seguro (sem separadores, sem caracteres de controlo). */
function safe_name(string $s, int $max = 80): string
{
    $s = trim($s);
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($t !== false) {
            $s = $t;
        }
    }
    $s = preg_replace('/[^A-Za-z0-9._-]+/', '-', $s) ?? '';
    $s = trim($s, '-.');
    if ($s === '') {
        $s = 'x';
    }
    return substr($s, 0, $max);
}

function ensure_dir(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Não foi possível criar a pasta ' . $dir);
    }
}

function setting_get(string $key, ?string $default = null): ?string
{
    $v = sgi_db()->val('SELECT svalue FROM settings WHERE skey = ?', [$key]);
    return $v === null ? $default : (string)$v;
}

function setting_set(string $key, ?string $value): void
{
    sgi_db()->q('INSERT INTO settings (skey, svalue, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue), updated_at = NOW()', [$key, $value]);
}

/** Cifra simétrica (para segredos guardados na BD, ex.: palavra-passe SMTP). */
function secret_encrypt(string $plain): string
{
    $key = hash('sha256', (string)sgi_config()['app_key'], true);
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return 'v1:' . base64_encode($iv . $tag . $ct);
}

function secret_decrypt(?string $enc): string
{
    if ($enc === null || $enc === '' || strpos($enc, 'v1:') !== 0) {
        return '';
    }
    $raw = base64_decode(substr($enc, 3), true);
    if ($raw === false || strlen($raw) < 29) {
        return '';
    }
    $key = hash('sha256', (string)sgi_config()['app_key'], true);
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $p === false ? '' : $p;
}

function b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function b64url_dec(string $s): string
{
    return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
}

function audit(?int $orgId, ?int $userId, string $action, ?string $entity = null, ?int $entityId = null, $details = null): void
{
    sgi_db()->insert('audit_log', [
        'org_id' => $orgId,
        'user_id' => $userId,
        'action' => $action,
        'entity' => $entity,
        'entity_id' => $entityId,
        'details' => $details === null ? null : (is_string($details) ? $details : json_encode($details, JSON_UNESCAPED_UNICODE)),
        'ip' => client_ip(),
    ]);
}
