<?php
declare(strict_types=1);

function temp_password(): string
{
    $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz';
    $s = '';
    for ($i = 0; $i < 8; $i++) {
        $s .= $alpha[random_int(0, strlen($alpha) - 1)];
    }
    return $s . random_int(10, 99) . '-' . strtoupper(substr(random_token(2), 0, 3));
}

return function (Router $r) {
    $r->add('GET', '/org', function () {
        $org = Auth::requireOrg();
        $o = sgi_db()->one('SELECT id, slug, name, legal_name, tax_id, address, logo_path, status, created_at FROM organizations WHERE id = ?', [$org]);
        $o['has_logo'] = !empty($o['logo_path']);
        unset($o['logo_path']);
        $o['ambito'] = Coverage::scope($org);
        return ['org' => $o];
    });

    $r->add('PUT', '/org', function () {
        $org = Auth::requireOrg();
        Auth::require('org.settings');
        $b = body();
        sgi_db()->update('organizations', [
            'name' => req_str($b, 'name', 'Nome'),
            'legal_name' => opt_str($b, 'legal_name'),
            'tax_id' => opt_str($b, 'tax_id', 60),
            'address' => opt_str($b, 'address'),
        ], 'id = ?', [$org]);
        if (isset($b['normas'])) {
            $normas = array_values(array_intersect(['9001', '14001', '45001'], array_map('strval', (array)$b['normas'])));
            if (!$normas) {
                throw new HttpError(422, 'Escolha pelo menos uma norma do âmbito do sistema de gestão.', 'normas');
            }
            if (count($normas) < 3 && trim((string)($b['justificacao'] ?? '')) === '') {
                throw new HttpError(422, 'Indique a justificação para as normas fora do âmbito.', 'justificacao');
            }
            setting_set('org_normas_' . $org, json_encode(['normas' => $normas, 'justificacao' => mb_substr(trim((string)($b['justificacao'] ?? '')), 0, 500)], JSON_UNESCAPED_UNICODE));
        }
        audit($org, Auth::id(), 'organizacao_atualizada', 'organization', $org);
        return ['ok' => true];
    });

    $r->add('POST', '/org/logo', function () {
        $org = Auth::requireOrg();
        Auth::require('org.settings');
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || $f['size'] > 2097152) {
            throw new HttpError(422, 'Envie uma imagem PNG, JPEG ou WebP até 2 MB.', 'logo_invalido');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime] ?? null;
        if (!$ext || !@getimagesize($f['tmp_name'])) {
            throw new HttpError(415, 'Formato de imagem não suportado (use PNG, JPEG ou WebP).', 'logo_invalido');
        }
        $rel = Storage::orgPrefix($org) . '/ativos/logo-' . date('YmdHis') . '.' . $ext;
        Storage::put($rel, (string)file_get_contents($f['tmp_name']));
        sgi_db()->update('organizations', ['logo_path' => $rel], 'id = ?', [$org]);
        audit($org, Auth::id(), 'logotipo_alterado', 'organization', $org);
        return ['ok' => true];
    });

    $r->add('GET', '/users', function () {
        $org = Auth::requireOrg();
        if (Auth::can('users.manage')) {
            $rows = sgi_db()->all('SELECT id, name, email, role, status, last_login_at, must_change_password, created_at, locked_until FROM users WHERE org_id = ? ORDER BY name', [$org]);
        } else {
            $rows = sgi_db()->all("SELECT id, name, role FROM users WHERE org_id = ? AND status = 'ativo' ORDER BY name", [$org]);
        }
        foreach ($rows as &$u) {
            $u['id'] = (int)$u['id'];
            $u['role_label'] = Auth::ROLES[$u['role']] ?? $u['role'];
        }
        return ['users' => $rows, 'roles' => array_diff_key(Auth::ROLES, ['plataforma' => 1])];
    });

    $r->add('POST', '/users', function () {
        $org = Auth::requireOrg();
        Auth::require('users.manage');
        $b = body();
        $email = mb_strtolower(req_str($b, 'email', 'Correio eletrónico', 190));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpError(422, 'Correio eletrónico inválido.', 'email_invalido');
        }
        if (sgi_db()->val('SELECT id FROM users WHERE email = ?', [$email])) {
            throw new HttpError(409, 'Já existe um utilizador com este correio eletrónico.', 'email_existente');
        }
        $role = opt_enum($b, 'role', ['admin', 'direcao', 'coordenador', 'supervisor', 'operario'], 'operario');
        $pw = temp_password();
        $id = sgi_db()->insert('users', [
            'org_id' => $org, 'email' => $email, 'name' => req_str($b, 'name', 'Nome', 190),
            'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'role' => $role, 'must_change_password' => 1,
        ]);
        audit($org, Auth::id(), 'utilizador_criado', 'user', $id, ['perfil' => $role]);
        return ['id' => $id, 'temp_password' => $pw, 'aviso' => 'Palavra-passe temporária mostrada uma única vez. O utilizador terá de a alterar no primeiro acesso.', '__status' => 201];
    });

    $r->add('PUT', '/users/{id}', function ($p) {
        $org = Auth::requireOrg();
        Auth::require('users.manage');
        $u = sgi_db()->one('SELECT * FROM users WHERE id = ? AND org_id = ?', [(int)$p['id'], $org]);
        if (!$u) {
            throw new HttpError(404, 'Utilizador não encontrado.', 'nao_encontrado');
        }
        $b = body();
        $row = [];
        if (isset($b['name'])) {
            $row['name'] = req_str($b, 'name', 'Nome', 190);
        }
        if (isset($b['role'])) {
            $row['role'] = opt_enum($b, 'role', ['admin', 'direcao', 'coordenador', 'supervisor', 'operario']);
        }
        if (isset($b['status'])) {
            $row['status'] = opt_enum($b, 'status', ['ativo', 'inativo']);
        }
        if ((int)$u['id'] === Auth::id() && ((isset($row['role']) && $row['role'] !== 'admin') || ($row['status'] ?? 'ativo') !== 'ativo')) {
            throw new HttpError(422, 'Não pode retirar a si próprio o perfil de administrador nem desativar a sua conta.', 'auto_alteracao');
        }
        if (isset($b['unlock'])) {
            $row['locked_until'] = null;
            $row['failed_attempts'] = 0;
        }
        if ($row) {
            sgi_db()->update('users', $row, 'id = ?', [$u['id']]);
            if (($row['status'] ?? '') === 'inativo') {
                Tokens::revokeAllForUser((int)$u['id'], Auth::id(), 'utilizador_desativado');
            }
            audit($org, Auth::id(), 'utilizador_atualizado', 'user', (int)$u['id'], $row);
        }
        return ['ok' => true];
    });

    $r->add('POST', '/users/{id}/reset-password', function ($p) {
        $org = Auth::requireOrg();
        Auth::require('users.manage');
        $u = sgi_db()->one('SELECT * FROM users WHERE id = ? AND org_id = ?', [(int)$p['id'], $org]);
        if (!$u) {
            throw new HttpError(404, 'Utilizador não encontrado.', 'nao_encontrado');
        }
        $pw = temp_password();
        sgi_db()->update('users', ['password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'must_change_password' => 1, 'locked_until' => null, 'failed_attempts' => 0], 'id = ?', [$u['id']]);
        Tokens::revokeAllForUser((int)$u['id'], Auth::id(), 'palavra_passe_redefinida_admin');
        audit($org, Auth::id(), 'palavra_passe_redefinida_admin', 'user', (int)$u['id']);
        return ['temp_password' => $pw];
    });

    $r->add('GET', '/org/export', function () {
        $org = Auth::requireOrg();
        Auth::require('org.export');
        $path = Backup::exportOrg($org, (int)Auth::id());
        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Cache-Control: no-store');
        readfile($path);
        @unlink($path);
        return null;
    });

    $r->add('GET', '/audit', function () {
        $org = Auth::requireOrg();
        Auth::require('users.manage');
        $rows = sgi_db()->all('SELECT a.id, a.action, a.entity, a.entity_id, a.details, a.ip, a.created_at, u.name AS user_name
            FROM audit_log a LEFT JOIN users u ON u.id = a.user_id WHERE a.org_id = ? ORDER BY a.id DESC LIMIT 300', [$org]);
        return ['audit' => $rows];
    });

    // ---- Notificações ----
    $r->add('GET', '/notifications', function () {
        $rows = sgi_db()->all('SELECT id, title, body, link, read_at, created_at FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 100', [Auth::id()]);
        $unread = (int)sgi_db()->val('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [Auth::id()]);
        return ['notifications' => $rows, 'unread' => $unread];
    });

    $r->add('POST', '/notifications/{id}/read', function ($p) {
        sgi_db()->q('UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL', [(int)$p['id'], Auth::id()]);
        return ['ok' => true];
    });

    $r->add('POST', '/notifications/read-all', function () {
        sgi_db()->q('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', [Auth::id()]);
        return ['ok' => true];
    });

    // ---- Web Push ----
    $r->add('POST', '/push/subscribe', function () {
        if (!WebPush::enabled()) {
            throw new HttpError(409, 'As notificações push estão desligadas neste servidor. As notificações internas continuam ativas.', 'push_desligado');
        }
        $b = body();
        $ep = (string)($b['endpoint'] ?? '');
        if (!preg_match('#^https://[^\s]{10,690}$#', $ep)) {
            throw new HttpError(422, 'Subscrição inválida.', 'push_invalido');
        }
        $keys = (array)($b['keys'] ?? []);
        sgi_db()->q('INSERT INTO push_subscriptions (org_id, user_id, device_id, endpoint, endpoint_hash, p256dh, auth) VALUES (?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), org_id = VALUES(org_id), device_id = VALUES(device_id), p256dh = VALUES(p256dh), auth = VALUES(auth)',
            [Auth::orgId(), Auth::id(), Auth::deviceId(), $ep, hash('sha256', $ep), mb_substr((string)($keys['p256dh'] ?? ''), 0, 255), mb_substr((string)($keys['auth'] ?? ''), 0, 255)]);
        return ['ok' => true];
    });

    $r->add('POST', '/push/unsubscribe', function () {
        $ep = (string)(body()['endpoint'] ?? '');
        sgi_db()->q('DELETE FROM push_subscriptions WHERE endpoint_hash = ? AND user_id = ?', [hash('sha256', $ep), Auth::id()]);
        return ['ok' => true];
    });

    $r->add('POST', '/push/test', function () {
        $n = Notify::users(Auth::orgId(), [(int)Auth::id()], 'Teste de notificação', 'Se está a ler isto, as notificações funcionam neste dispositivo.', '#/notificacoes');
        return ['ok' => $n > 0];
    });
};
