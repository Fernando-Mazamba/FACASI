<?php
declare(strict_types=1);

/**
 * Ficheiros por organização dentro de storage/: org_<id>/...
 * Os caminhos guardados na BD são sempre relativos ao storage e validados (sem "..").
 */
class Storage
{
    public static function orgPrefix(int $orgId): string
    {
        return 'org_' . $orgId;
    }

    public static function abs(string $rel): string
    {
        $rel = str_replace('\\', '/', $rel);
        if ($rel === '' || strpos($rel, '..') !== false || $rel[0] === '/' || strpos($rel, "\0") !== false) {
            throw new HttpError(400, 'Caminho de ficheiro inválido.', 'caminho_invalido');
        }
        return sgi_storage() . '/' . $rel;
    }

    /** Garante que o caminho pertence à organização indicada. */
    public static function assertOrg(string $rel, int $orgId): void
    {
        if (strpos($rel, self::orgPrefix($orgId) . '/') !== 0) {
            throw new HttpError(404, 'Ficheiro não encontrado.', 'nao_encontrado');
        }
    }

    public static function put(string $rel, string $content): void
    {
        $abs = self::abs($rel);
        ensure_dir(dirname($abs));
        $tmp = $abs . '.tmp' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new RuntimeException('Falha ao gravar ficheiro.');
        }
        rename($tmp, $abs);
    }

    public static function get(string $rel): string
    {
        $abs = self::abs($rel);
        if (!is_file($abs)) {
            throw new HttpError(404, 'Ficheiro não encontrado no armazenamento.', 'ficheiro_em_falta');
        }
        return (string)file_get_contents($abs);
    }

    public static function exists(string $rel): bool
    {
        return is_file(self::abs($rel));
    }

    public static function protectDir(string $dir): void
    {
        ensure_dir($dir);
        $ht = $dir . '/.htaccess';
        if (!is_file($ht)) {
            file_put_contents($ht, "# Acesso direto proibido: os ficheiros são servidos apenas pela API com controlo de organização.\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
        }
        if (!is_file($dir . '/index.html')) {
            file_put_contents($dir . '/index.html', '');
        }
    }

    /** Envia um ficheiro ao cliente. */
    public static function send(string $rel, string $mime, string $downloadName, bool $attachment): void
    {
        $abs = self::abs($rel);
        if (!is_file($abs)) {
            throw new HttpError(404, 'Ficheiro não encontrado no armazenamento.', 'ficheiro_em_falta');
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($abs));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        $fn = str_replace(['"', "\r", "\n"], '', $downloadName);
        header('Content-Disposition: ' . ($attachment ? 'attachment' : 'inline') . '; filename="' . safe_name($fn, 120) . '"; filename*=UTF-8\'\'' . rawurlencode($fn));
        if (stripos($mime, 'text/html') === 0 || stripos($mime, 'image/svg') === 0) {
            // HTML final ou SVG: nunca executar scripts.
            header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline' 'self' https:; font-src https: data:; frame-ancestors 'self'; sandbox");
        }
        readfile($abs);
    }
}
