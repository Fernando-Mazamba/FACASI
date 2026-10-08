<?php
declare(strict_types=1);

/**
 * Cliente SMTP mínimo (sem dependências): SSL (465), STARTTLS (587) ou sem cifra (apenas redes internas).
 * Configurado pela interface da plataforma; a palavra-passe fica cifrada na BD (AES-256-GCM com app_key).
 */
class Mailer
{
    /** @return array<string,mixed> */
    public static function config(): array
    {
        $c = json_decode((string)setting_get('smtp', '{}'), true) ?: [];
        $c += ['enabled' => false, 'host' => '', 'port' => 587, 'security' => 'tls', 'user' => '', 'pass_enc' => '', 'from_email' => '', 'from_name' => 'SGI'];
        return $c;
    }

    public static function enabled(): bool
    {
        $c = self::config();
        return !empty($c['enabled']) && $c['host'] !== '' && $c['from_email'] !== '';
    }

    /** @throws RuntimeException */
    public static function send(string $to, string $subject, string $textBody, ?array $cfg = null): void
    {
        $c = $cfg ?? self::config();
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Destinatário inválido.');
        }
        $host = (string)$c['host'];
        $port = (int)$c['port'];
        $sec = (string)$c['security'];
        $remote = ($sec === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new RuntimeException("Não foi possível ligar ao servidor SMTP ($errstr).");
        }
        stream_set_timeout($fp, 20);
        $read = function () use ($fp): string {
            $data = '';
            while (($line = fgets($fp, 1024)) !== false) {
                $data .= $line;
                if (strlen($line) < 4 || $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };
        $cmd = function (string $c, array $ok) use ($fp, $read): string {
            if ($c !== '') {
                fwrite($fp, $c . "\r\n");
            }
            $r = $read();
            if (!in_array((int)substr($r, 0, 3), $ok, true)) {
                throw new RuntimeException('SMTP: resposta inesperada: ' . trim($r));
            }
            return $r;
        };
        try {
            $cmd('', [220]);
            $ehloName = preg_replace('/[^A-Za-z0-9.-]/', '', (string)($_SERVER['SERVER_NAME'] ?? 'localhost')) ?: 'localhost';
            $cmd('EHLO ' . $ehloName, [250]);
            if ($sec === 'tls') {
                $cmd('STARTTLS', [220]);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0))) {
                    throw new RuntimeException('SMTP: falha ao iniciar TLS.');
                }
                $cmd('EHLO ' . $ehloName, [250]);
            }
            if ((string)$c['user'] !== '') {
                $pass = isset($c['pass']) ? (string)$c['pass'] : secret_decrypt((string)$c['pass_enc']);
                $cmd('AUTH LOGIN', [334]);
                $cmd(base64_encode((string)$c['user']), [334]);
                $cmd(base64_encode($pass), [235]);
            }
            $from = (string)$c['from_email'];
            $cmd('MAIL FROM:<' . $from . '>', [250]);
            $cmd('RCPT TO:<' . $to . '>', [250, 251]);
            $cmd('DATA', [354]);
            $enc = function (string $s): string {
                return '=?UTF-8?B?' . base64_encode($s) . '?=';
            };
            $headers = [
                'Date: ' . date('r'),
                'From: ' . $enc((string)$c['from_name']) . ' <' . $from . '>',
                'To: <' . $to . '>',
                'Subject: ' . $enc($subject),
                'Message-ID: <' . random_token(12) . '@' . (strpos($from, '@') ? substr($from, strpos($from, '@') + 1) : 'sgi') . '>',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: base64',
                'Auto-Submitted: auto-generated',
            ];
            $body = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($textBody));
            $cmd($body . "\r\n.", [250]);
            $cmd('QUIT', [221]);
        } finally {
            fclose($fp);
        }
    }
}
