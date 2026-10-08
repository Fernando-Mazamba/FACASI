<?php
declare(strict_types=1);

/** Notificações internas (sempre) + Web Push (opcional) + correio SMTP (opcional). */
class Notify
{
    /** @param array<int,int> $userIds */
    public static function users(?int $orgId, array $userIds, string $title, string $body = '', string $link = ''): int
    {
        $db = sgi_db();
        $n = 0;
        foreach (array_unique(array_filter($userIds)) as $uid) {
            $u = $db->one("SELECT * FROM users WHERE id = ? AND status = 'ativo'", [$uid]);
            if (!$u || ($orgId !== null && (int)$u['org_id'] !== $orgId)) {
                continue; // nunca notificar fora da organização
            }
            $db->insert('notifications', [
                'org_id' => $orgId, 'user_id' => $uid, 'title' => mb_substr($title, 0, 255),
                'body' => mb_substr($body, 0, 1000), 'link' => mb_substr($link, 0, 255),
            ]);
            $n++;
            self::deliverExternal($u, $title, $body);
        }
        return $n;
    }

    public static function approvers(int $orgId, string $role, ?int $exceptUser, string $title, string $body, string $link): int
    {
        $need = Auth::ROLE_RANK[$role] ?? 3;
        $roles = array_keys(array_filter(Auth::ROLE_RANK, function ($r) use ($need) {
            return $r >= $need;
        }));
        $roles = array_values(array_diff($roles, ['operario']));
        if (!$roles) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($roles), '?'));
        $ids = array_map('intval', array_column(sgi_db()->all("SELECT id FROM users WHERE org_id = ? AND status = 'ativo' AND role IN ($in)", array_merge([$orgId], $roles)), 'id'));
        $ids = array_values(array_diff($ids, [(int)$exceptUser]));
        return self::users($orgId, $ids, $title, $body, $link);
    }

    private static function deliverExternal(array $u, string $title, string $body): void
    {
        try {
            if (!empty($u['notify_push']) && WebPush::enabled() && sgi_db()->tableExists('push_subscriptions')) {
                foreach (sgi_db()->all('SELECT * FROM push_subscriptions WHERE user_id = ?', [$u['id']]) as $s) {
                    WebPush::tickle($s);
                }
            }
        } catch (Throwable $e) {
            error_log('[SGI push] ' . $e->getMessage());
        }
        try {
            if (!empty($u['notify_email']) && Mailer::enabled()) {
                Mailer::send((string)$u['email'], '[SGI] ' . $title, $title . "\n\n" . $body . "\n\nAbra o SGI para ver os detalhes.\n");
            }
        } catch (Throwable $e) {
            error_log('[SGI mail] ' . $e->getMessage());
        }
    }
}
