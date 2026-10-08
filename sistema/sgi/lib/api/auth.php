<?php
declare(strict_types=1);

return function (Router $r) {
    $mePayload = function (): array {
        $u = Auth::user();
        $org = null;
        if ($u && $u['org_id'] !== null) {
            $o = sgi_db()->one('SELECT id, name, slug, legal_name, tax_id, logo_path FROM organizations WHERE id = ?', [$u['org_id']]);
            $org = ['id' => (int)$o['id'], 'name' => $o['name'], 'slug' => $o['slug'], 'has_logo' => !empty($o['logo_path'])];
        }
        $out = [
            'user' => $u ? Auth::publicUser($u) : null,
            'org' => $org,
            'mode' => Auth::mode(),
            'device_id' => Auth::deviceId(),
            'version' => SGI_VERSION,
            'push_key' => WebPush::enabled() ? WebPush::publicKey() : null,
            'smtp' => Mailer::enabled(),
        ];
        if (Auth::mode() === 'sessao') {
            $out['csrf'] = Auth::csrfToken();
        }
        return $out;
    };

    $r->add('GET', '/auth/me', function () use ($mePayload) {
        return $mePayload();
    }, ['public' => true, 'maintenance' => true]);

    $r->add('POST', '/auth/login', function () use ($mePayload) {
        $b = body();
        $u = Auth::verifyCredentials((string)($b['email'] ?? ''), (string)($b['password'] ?? ''));
        Auth::loginSession($u);
        audit($u['org_id'] !== null ? (int)$u['org_id'] : null, (int)$u['id'], 'login_sessao');
        return $mePayload();
    }, ['public' => true, 'csrf' => false, 'maintenance' => true]);

    $r->add('POST', '/auth/logout', function () {
        if (Auth::mode() === 'sessao') {
            Auth::logoutSession();
        }
        return ['ok' => true];
    }, ['public' => true, 'csrf' => false, 'maintenance' => true]);

    // ---- Tokens da aplicação ----
    $r->add('POST', '/auth/token', function () {
        $b = body();
        $u = Auth::verifyCredentials((string)($b['email'] ?? ''), (string)($b['password'] ?? ''));
        $res = Tokens::issueForNewDevice($u, (string)($b['device_name'] ?? 'Aplicação SGI'));
        unset($res['refresh_id']);
        Auth::setUser($u, 'token');
        return $res + ['user' => Auth::publicUser($u)];
    }, ['public' => true, 'csrf' => false]);

    $r->add('POST', '/auth/refresh', function () {
        $res = Tokens::refresh((string)(body()['refresh_token'] ?? ''));
        $u = $res['user'];
        unset($res['user'], $res['refresh_id']);
        return $res + ['user' => Auth::publicUser($u)];
    }, ['public' => true, 'csrf' => false]);

    $r->add('POST', '/auth/token/revoke', function () {
        if (Auth::mode() !== 'token') {
            throw new HttpError(400, 'Esta operação aplica-se apenas à sessão da aplicação.', 'modo');
        }
        Tokens::revokeDevice((int)Auth::deviceId(), Auth::id(), 'terminar_sessao_app');
        return ['ok' => true];
    });

    $r->add('GET', '/auth/devices', function () {
        $uid = Auth::id();
        if (qint('user_id') && qint('user_id') !== $uid) {
            Auth::require('users.manage');
            $target = sgi_db()->one('SELECT id FROM users WHERE id = ? AND org_id = ?', [qint('user_id'), Auth::requireOrg()]);
            if (!$target) {
                throw new HttpError(404, 'Utilizador não encontrado.', 'nao_encontrado');
            }
            $uid = (int)$target['id'];
        }
        $rows = sgi_db()->all('SELECT id, name, user_agent, ip, created_at, last_seen_at, revoked_at FROM devices WHERE user_id = ? ORDER BY revoked_at IS NULL DESC, last_seen_at DESC', [$uid]);
        foreach ($rows as &$d) {
            $d['id'] = (int)$d['id'];
            $d['current'] = Auth::deviceId() === $d['id'];
            $d['active'] = $d['revoked_at'] === null;
        }
        return ['devices' => $rows];
    });

    $r->add('DELETE', '/auth/devices/{id}', function ($p) {
        $d = sgi_db()->one('SELECT * FROM devices WHERE id = ?', [(int)$p['id']]);
        if (!$d) {
            throw new HttpError(404, 'Dispositivo não encontrado.', 'nao_encontrado');
        }
        if ((int)$d['user_id'] !== Auth::id()) {
            Auth::require('users.manage');
            if ((int)$d['org_id'] !== Auth::requireOrg()) {
                throw new HttpError(404, 'Dispositivo não encontrado.', 'nao_encontrado');
            }
        }
        Tokens::revokeDevice((int)$d['id'], Auth::id(), 'encerrado_pelo_utilizador');
        return ['ok' => true];
    });

    // ---- Palavra-passe ----
    $r->add('POST', '/auth/password', function () {
        $b = body();
        $u = sgi_db()->one('SELECT * FROM users WHERE id = ?', [Auth::id()]);
        if (!password_verify((string)($b['current'] ?? ''), (string)$u['password_hash'])) {
            throw new HttpError(422, 'A palavra-passe atual não está correta.', 'palavra_passe_atual');
        }
        $new = (string)($b['new'] ?? '');
        Auth::validatePassword($new);
        sgi_db()->update('users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT), 'must_change_password' => 0], 'id = ?', [$u['id']]);
        if (sgi_db()->tableExists('devices')) {
            // termina as sessões noutros dispositivos
            foreach (sgi_db()->all('SELECT id FROM devices WHERE user_id = ? AND revoked_at IS NULL', [$u['id']]) as $d) {
                if ((int)$d['id'] !== Auth::deviceId()) {
                    Tokens::revokeDevice((int)$d['id'], (int)$u['id'], 'mudanca_palavra_passe');
                }
            }
        }
        $fresh = Auth::loadActive((int)$u['id']);
        if (Auth::mode() === 'sessao' && $fresh) {
            Auth::loginSession($fresh);
        }
        audit($u['org_id'] !== null ? (int)$u['org_id'] : null, (int)$u['id'], 'palavra_passe_alterada');
        return ['ok' => true, 'csrf' => Auth::mode() === 'sessao' ? Auth::csrfToken() : null];
    }, ['maintenance' => true]);

    $r->add('POST', '/auth/forgot', function () {
        $email = mb_strtolower(trim((string)(body()['email'] ?? '')));
        $generic = ['ok' => true, 'mensagem' => 'Se o endereço estiver registado e o correio estiver configurado, receberá uma ligação para redefinir a palavra-passe.'];
        if (!Mailer::enabled()) {
            return $generic + ['smtp' => false, 'mensagem' => 'O envio de correio não está configurado neste servidor. Peça ao administrador da sua organização para redefinir a palavra-passe.'];
        }
        $ipCount = (int)sgi_db()->val('SELECT COUNT(*) FROM password_resets pr JOIN users u ON u.id = pr.user_id WHERE u.email = ? AND pr.created_at > ?', [$email, date('Y-m-d H:i:s', time() - 3600)]);
        $u = sgi_db()->one("SELECT * FROM users WHERE email = ? AND status = 'ativo'", [$email]);
        if ($u && $ipCount < 3) {
            $tok = random_token(32);
            sgi_db()->insert('password_resets', ['user_id' => $u['id'], 'token_hash' => hash('sha256', $tok), 'expires_at' => date('Y-m-d H:i:s', time() + 3600)]);
            $link = (sgi_is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . sgi_base_path() . '/#/redefinir/' . $tok;
            try {
                Mailer::send((string)$u['email'], '[SGI] Redefinir palavra-passe', "Foi pedido um novo acesso ao SGI.\n\nAbra esta ligação (válida durante 1 hora):\n$link\n\nSe não foi você, ignore esta mensagem.\n");
            } catch (Throwable $e) {
                error_log('[SGI mail] ' . $e->getMessage());
            }
        }
        return $generic + ['smtp' => true];
    }, ['public' => true, 'csrf' => false]);

    $r->add('POST', '/auth/reset', function () {
        $b = body();
        $tok = (string)($b['token'] ?? '');
        $row = sgi_db()->one('SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()', [hash('sha256', $tok)]);
        if (!$row) {
            throw new HttpError(422, 'Ligação inválida ou expirada. Peça uma nova.', 'token_invalido');
        }
        $pw = (string)($b['password'] ?? '');
        Auth::validatePassword($pw);
        sgi_db()->update('users', ['password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'must_change_password' => 0, 'failed_attempts' => 0, 'locked_until' => null], 'id = ?', [$row['user_id']]);
        sgi_db()->update('password_resets', ['used_at' => now()], 'id = ?', [$row['id']]);
        Tokens::revokeAllForUser((int)$row['user_id'], null, 'redefinicao_palavra_passe');
        audit(null, (int)$row['user_id'], 'palavra_passe_redefinida');
        return ['ok' => true];
    }, ['public' => true, 'csrf' => false]);

    $r->add('PUT', '/me/prefs', function () {
        $b = body();
        sgi_db()->update('users', [
            'notify_email' => !empty($b['notify_email']) ? 1 : 0,
            'notify_push' => !empty($b['notify_push']) ? 1 : 0,
        ], 'id = ?', [Auth::id()]);
        return ['ok' => true];
    });

    $r->add('GET', '/me/prefs', function () {
        $u = sgi_db()->one('SELECT notify_email, notify_push FROM users WHERE id = ?', [Auth::id()]);
        return ['notify_email' => (bool)$u['notify_email'], 'notify_push' => (bool)$u['notify_push']];
    });
};
