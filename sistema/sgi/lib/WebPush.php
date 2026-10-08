<?php
declare(strict_types=1);

/**
 * Web Push com VAPID (RFC 8292), sem conteúdo cifrado: o servidor envia apenas um "toque";
 * o service worker vai buscar as notificações pendentes à API. Assim não é preciso ECDH/AES no PHP
 * e nenhum dado do SGI passa pelos serviços de push (Google/Apple/Mozilla).
 */
class WebPush
{
    public static function supported(): bool
    {
        return function_exists('openssl_pkey_new') && function_exists('curl_init') && in_array('prime256v1', openssl_get_curve_names() ?: [], true);
    }

    /** Gera e guarda o par de chaves VAPID (na instalação/atualização). */
    public static function ensureKeys(): ?array
    {
        if (!self::supported()) {
            return null;
        }
        $cur = json_decode((string)setting_get('vapid', ''), true);
        if (is_array($cur) && !empty($cur['public'])) {
            return $cur;
        }
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if (!$key) {
            return null;
        }
        openssl_pkey_export($key, $pem);
        $d = openssl_pkey_get_details($key);
        $pub = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $v = ['public' => b64url($pub), 'private_enc' => secret_encrypt($pem), 'created_at' => now()];
        setting_set('vapid', json_encode($v));
        return $v;
    }

    public static function publicKey(): ?string
    {
        $v = json_decode((string)setting_get('vapid', ''), true);
        return is_array($v) ? ($v['public'] ?? null) : null;
    }

    public static function enabled(): bool
    {
        return setting_get('push_enabled', '1') === '1' && self::publicKey() !== null && self::supported();
    }

    private static function jwt(string $aud): string
    {
        $v = json_decode((string)setting_get('vapid', ''), true);
        $pem = secret_decrypt((string)($v['private_enc'] ?? ''));
        $contact = setting_get('push_contact', '') ?: 'mailto:sgi@localhost';
        $h = b64url(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $p = b64url(json_encode(['aud' => $aud, 'exp' => time() + 43200, 'sub' => $contact]));
        $data = "$h.$p";
        if (!openssl_sign($data, $der, $pem, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Falha a assinar VAPID.');
        }
        return $data . '.' . b64url(self::derToRaw($der));
    }

    private static function derToRaw(string $der): string
    {
        $pos = 2;
        if (ord($der[1]) & 0x80) {
            $pos += ord($der[1]) & 0x7f;
        }
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $pos++; // 0x02
            $len = ord($der[$pos++]);
            $int = substr($der, $pos, $len);
            $pos += $len;
            $int = ltrim($int, "\0");
            $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
        }
        return $out;
    }

    /** @return bool true se entregue; remove subscrições expiradas (404/410). */
    public static function tickle(array $sub): bool
    {
        $endpoint = (string)$sub['endpoint'];
        $u = parse_url($endpoint);
        if (!$u || ($u['scheme'] ?? '') !== 'https' || empty($u['host'])) {
            return false;
        }
        $aud = 'https://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'TTL: 86400',
                'Urgency: normal',
                'Content-Length: 0',
                'Authorization: vapid t=' . self::jwt($aud) . ', k=' . self::publicKey(),
            ],
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $db = sgi_db();
        if ($code === 404 || $code === 410) {
            $db->q('DELETE FROM push_subscriptions WHERE id = ?', [$sub['id']]);
            return false;
        }
        if ($code >= 200 && $code < 300) {
            $db->update('push_subscriptions', ['last_ok_at' => now(), 'last_error' => null], 'id = ?', [$sub['id']]);
            return true;
        }
        $db->update('push_subscriptions', ['last_error' => mb_substr("HTTP $code $err", 0, 255)], 'id = ?', [$sub['id']]);
        return false;
    }
}
