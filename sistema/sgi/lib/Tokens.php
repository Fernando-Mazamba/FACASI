<?php
declare(strict_types=1);

/**
 * Tokens da aplicação: acesso (15 min) + renovação (30 dias, rotativo), por dispositivo.
 * Os tokens nunca são guardados em claro: apenas SHA-256.
 * Revogar um dispositivo invalida imediatamente todos os seus tokens (cada pedido consulta a BD).
 * Reutilização de um token de renovação já substituído ⇒ o dispositivo é revogado (deteção de roubo).
 */
class Tokens
{
    public const ACCESS_TTL = 900;
    public const REFRESH_TTL = 2592000;

    /** @return array<string,mixed> */
    public static function issueForNewDevice(array $user, string $deviceName): array
    {
        $db = sgi_db();
        $deviceId = $db->insert('devices', [
            'org_id' => $user['org_id'],
            'user_id' => $user['id'],
            'name' => mb_substr(trim($deviceName) !== '' ? trim($deviceName) : 'Dispositivo', 0, 120),
            'user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'ip' => client_ip(),
            'last_seen_at' => now(),
        ]);
        audit($user['org_id'] !== null ? (int)$user['org_id'] : null, (int)$user['id'], 'dispositivo_ligado', 'device', $deviceId, ['nome' => $deviceName]);
        return self::issuePair($deviceId, (int)$user['id']) + ['device_id' => $deviceId];
    }

    /** @return array<string,mixed> */
    private static function issuePair(int $deviceId, int $userId): array
    {
        $db = sgi_db();
        $access = random_token(32);
        $refresh = random_token(32);
        $db->insert('app_tokens', [
            'device_id' => $deviceId, 'user_id' => $userId, 'token_type' => 'access',
            'token_hash' => hash('sha256', $access), 'expires_at' => date('Y-m-d H:i:s', time() + self::ACCESS_TTL),
        ]);
        $rid = $db->insert('app_tokens', [
            'device_id' => $deviceId, 'user_id' => $userId, 'token_type' => 'refresh',
            'token_hash' => hash('sha256', $refresh), 'expires_at' => date('Y-m-d H:i:s', time() + self::REFRESH_TTL),
        ]);
        return [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TTL,
            'refresh_id' => $rid,
        ];
    }

    /** @return array{user:array<string,mixed>,device_id:int}|null */
    public static function authenticate(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $db = sgi_db();
        $t = $db->one("SELECT t.*, d.revoked_at AS dev_revoked, d.last_seen_at FROM app_tokens t JOIN devices d ON d.id = t.device_id
            WHERE t.token_hash = ? AND t.token_type = 'access'", [hash('sha256', $token)]);
        if (!$t || $t['revoked_at'] !== null || $t['dev_revoked'] !== null || strtotime((string)$t['expires_at']) < time()) {
            return null;
        }
        $u = Auth::loadActive((int)$t['user_id']);
        if (!$u) {
            return null;
        }
        if ($t['last_seen_at'] === null || strtotime((string)$t['last_seen_at']) < time() - 60) {
            $db->update('devices', ['last_seen_at' => now(), 'ip' => client_ip()], 'id = ?', [$t['device_id']]);
        }
        return ['user' => $u, 'device_id' => (int)$t['device_id']];
    }

    /** @return array<string,mixed> */
    public static function refresh(string $refresh): array
    {
        $db = sgi_db();
        $err = new HttpError(401, 'Sessão da aplicação expirada ou revogada. Inicie sessão novamente.', 'refresh_invalido');
        if (!preg_match('/^[a-f0-9]{64}$/', $refresh)) {
            throw $err;
        }
        return $db->tx(function () use ($db, $refresh, $err) {
            $t = $db->one("SELECT t.*, d.revoked_at AS dev_revoked FROM app_tokens t JOIN devices d ON d.id = t.device_id
                WHERE t.token_hash = ? AND t.token_type = 'refresh' FOR UPDATE", [hash('sha256', $refresh)]);
            if (!$t || $t['dev_revoked'] !== null) {
                throw $err;
            }
            if ($t['revoked_at'] !== null) {
                // Reutilização de token antigo: possível roubo → revoga o dispositivo inteiro.
                self::revokeDevice((int)$t['device_id'], null, 'reutilizacao_refresh');
                throw $err;
            }
            if (strtotime((string)$t['expires_at']) < time()) {
                throw $err;
            }
            $u = Auth::loadActive((int)$t['user_id']);
            if (!$u) {
                throw $err;
            }
            $pair = self::issuePair((int)$t['device_id'], (int)$t['user_id']);
            $db->update('app_tokens', ['revoked_at' => now(), 'replaced_by' => $pair['refresh_id']], 'id = ?', [$t['id']]);
            $db->update('devices', ['last_seen_at' => now()], 'id = ?', [$t['device_id']]);
            return $pair + ['device_id' => (int)$t['device_id'], 'user' => $u];
        });
    }

    public static function revokeDevice(int $deviceId, ?int $byUser, string $reason = 'manual'): void
    {
        $db = sgi_db();
        $d = $db->one('SELECT * FROM devices WHERE id = ?', [$deviceId]);
        if (!$d) {
            return;
        }
        $db->update('devices', ['revoked_at' => now()], 'id = ? AND revoked_at IS NULL', [$deviceId]);
        $db->update('app_tokens', ['revoked_at' => now()], 'device_id = ? AND revoked_at IS NULL', [$deviceId]);
        $db->q('DELETE FROM push_subscriptions WHERE device_id = ?', [$deviceId]);
        audit($d['org_id'] !== null ? (int)$d['org_id'] : null, $byUser, 'dispositivo_revogado', 'device', $deviceId, ['motivo' => $reason]);
    }

    public static function revokeAllForUser(int $userId, ?int $byUser, string $reason): void
    {
        foreach (sgi_db()->all('SELECT id FROM devices WHERE user_id = ? AND revoked_at IS NULL', [$userId]) as $d) {
            self::revokeDevice((int)$d['id'], $byUser, $reason);
        }
    }
}
