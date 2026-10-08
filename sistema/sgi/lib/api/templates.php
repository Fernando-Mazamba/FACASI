<?php
declare(strict_types=1);

/** Assinatura de URL de ficheiros da organização (para imagens/PDF dentro de modelos em iframe isolado). */
function signed_asset_url(int $orgId, string $kind, string $name, int $ttl = 86400): string
{
    $exp = time() + $ttl;
    $sig = hash_hmac('sha256', "$kind|$orgId|$name|$exp", (string)sgi_config()['app_key']);
    $path = $kind === 'logo' ? '/api.php/org/logo' : '/api.php/org/ativo/' . rawurlencode($name);
    return sgi_base_path() . $path . '?o=' . $orgId . '&e=' . $exp . '&s=' . $sig;
}

function verify_asset_signature(string $kind, string $name): ?int
{
    $o = qint('o');
    $e = qint('e');
    $s = qstr('s');
    if (!$o || $e < time() || $s === '') {
        return null;
    }
    $exp = hash_hmac('sha256', "$kind|$o|$name|$e", (string)sgi_config()['app_key']);
    return hash_equals($exp, $s) ? $o : null;
}

/** HTML do modelo pronto para o cliente: marcadores resolvidos, imagens embutidas (funciona offline). */
function render_for_client(array $org, string $html): string
{
    $orgId = (int)$org['id'];
    $inline = function (string $abs, string $fallbackUrl): string {
        if (is_file($abs) && filesize($abs) <= 1572864) {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($abs) ?: 'application/octet-stream';
            if (strpos($mime, 'image/') === 0) {
                return 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($abs));
            }
        }
        return $fallbackUrl;
    };
    if (!empty($org['logo_path'])) {
        $logo = $inline(Storage::abs((string)$org['logo_path']), signed_asset_url($orgId, 'logo', 'logo'));
    } else {
        $logo = sgi_base_path() . '/app/img/icone-sgi.svg';
    }
    $html = str_replace('{{ORG_LOGO}}', $logo, $html);
    $html = preg_replace_callback('/\{\{ORG_ASSET:([A-Za-z0-9._-]+)\}\}/', function ($m) use ($orgId, $inline) {
        $rel = Storage::orgPrefix($orgId) . '/ativos/' . $m[1];
        return $inline(Storage::abs($rel), signed_asset_url($orgId, 'ativo', $m[1]));
    }, $html) ?? $html;
    $html = str_replace('{{ORG_NAME}}', h((string)$org['name']), $html);
    $html = str_replace('{{ORG_NIF}}', h((string)($org['tax_id'] ?: '[NIF — pendente]')), $html);
    return $html;
}

return function (Router $r) {
    $db = function (): Db {
        return sgi_db();
    };

    $r->add('GET', '/templates', function () use ($db) {
        $org = Auth::requireOrg();
        Auth::require('docs.view');
        $where = ['t.org_id = ?'];
        $p = [$org];
        $status = qstr('status', 'vigente');
        if ($status !== 'todos') {
            $where[] = 't.status = ?';
            $p[] = $status === 'obsoleto' ? 'obsoleto' : 'vigente';
        }
        if (qstr('category') !== '') {
            $where[] = 't.category = ?';
            $p[] = qstr('category');
        }
        if (qstr('q') !== '') {
            $where[] = '(t.code LIKE ? OR t.title LIKE ?)';
            $p[] = '%' . qstr('q') . '%';
            $p[] = '%' . qstr('q') . '%';
        }
        if (qstr('forms') === '1') {
            $where[] = 't.is_form = 1';
        }
        $p[] = Auth::id();
        $rows = $db()->all('SELECT t.id, t.code, t.title, t.category, t.doc_type, t.is_form, t.requires_approval, t.approver_role, t.status, t.source_file,
                tv.id AS version_id, tv.version_no, tv.created_at AS version_date, tv.sha256,
                (SELECT rc.confirmed_at FROM read_confirmations rc WHERE rc.template_version_id = tv.id AND rc.user_id = ?) AS read_at
            FROM templates t JOIN template_versions tv ON tv.id = t.current_version_id
            WHERE ' . implode(' AND ', $where) . ' ORDER BY t.category, t.code', array_merge([$p[count($p) - 1]], array_slice($p, 0, -1)));
        foreach ($rows as &$t) {
            foreach (['id', 'version_id', 'version_no'] as $k) {
                $t[$k] = (int)$t[$k];
            }
            $t['is_form'] = (bool)$t['is_form'];
            $t['requires_approval'] = (bool)$t['requires_approval'];
        }
        $cats = $db()->all("SELECT category, COUNT(*) n FROM templates WHERE org_id = ? AND status = 'vigente' GROUP BY category ORDER BY category", [$org]);
        return ['templates' => $rows, 'categories' => $cats];
    });

    $r->add('GET', '/templates/{id}', function ($p) use ($db) {
        $org = Auth::requireOrg();
        Auth::require('docs.view');
        $t = $db()->one('SELECT * FROM templates WHERE id = ? AND org_id = ?', [(int)$p['id'], $org]);
        if (!$t) {
            throw new HttpError(404, 'Documento não encontrado.', 'nao_encontrado');
        }
        $versions = $db()->all('SELECT tv.id, tv.version_no, tv.sha256, tv.reason, tv.status, tv.created_at, u.name AS author,
                (SELECT COUNT(*) FROM read_confirmations rc WHERE rc.template_version_id = tv.id) AS reads
            FROM template_versions tv LEFT JOIN users u ON u.id = tv.created_by WHERE tv.template_id = ? ORDER BY tv.version_no DESC', [$t['id']]);
        $myRead = $db()->val('SELECT confirmed_at FROM read_confirmations WHERE template_version_id = ? AND user_id = ?', [$t['current_version_id'], Auth::id()]);
        return ['template' => [
            'id' => (int)$t['id'], 'code' => $t['code'], 'title' => $t['title'], 'category' => $t['category'], 'doc_type' => $t['doc_type'],
            'is_form' => (bool)$t['is_form'], 'requires_approval' => (bool)$t['requires_approval'], 'approver_role' => $t['approver_role'],
            'required_fields' => json_decode((string)($t['required_fields'] ?? '[]'), true) ?: [], 'status' => $t['status'],
            'current_version_id' => (int)$t['current_version_id'], 'my_read_at' => $myRead,
        ], 'versions' => $versions];
    });

    $r->add('GET', '/templates/{id}/render', function ($p) use ($db) {
        $org = Auth::requireOrg();
        Auth::require('docs.view');
        $t = $db()->one('SELECT * FROM templates WHERE id = ? AND org_id = ?', [(int)$p['id'], $org]);
        if (!$t) {
            throw new HttpError(404, 'Documento não encontrado.', 'nao_encontrado');
        }
        $vid = qint('version_id', (int)$t['current_version_id']);
        $v = $db()->one('SELECT * FROM template_versions WHERE id = ? AND template_id = ? AND org_id = ?', [$vid, $t['id'], $org]);
        if (!$v) {
            throw new HttpError(404, 'Versão não encontrada.', 'nao_encontrado');
        }
        Storage::assertOrg((string)$v['file_path'], $org);
        $o = $db()->one('SELECT * FROM organizations WHERE id = ?', [$org]);
        $html = render_for_client($o, Storage::get((string)$v['file_path']));
        return [
            'template_id' => (int)$t['id'], 'code' => $t['code'], 'title' => $t['title'], 'version_id' => (int)$v['id'],
            'version_no' => (int)$v['version_no'], 'version_status' => $v['status'], 'version_date' => $v['created_at'],
            'sha256' => $v['sha256'], 'trusted' => (bool)$v['trusted'], 'html' => $html,
            'copied_at' => now(),
        ];
    });

    $r->add('PUT', '/templates/{id}', function ($p) use ($db) {
        $org = Auth::requireOrg();
        Auth::require('docs.manage');
        $t = $db()->one('SELECT * FROM templates WHERE id = ? AND org_id = ?', [(int)$p['id'], $org]);
        if (!$t) {
            throw new HttpError(404, 'Documento não encontrado.', 'nao_encontrado');
        }
        $b = body();
        $row = [];
        if (isset($b['title'])) {
            $row['title'] = req_str($b, 'title', 'Título');
        }
        if (isset($b['requires_approval'])) {
            $row['requires_approval'] = !empty($b['requires_approval']) ? 1 : 0;
        }
        if (array_key_exists('approver_role', $b)) {
            $row['approver_role'] = opt_enum($b, 'approver_role', ['supervisor', 'coordenador', 'direcao', 'admin'], 'coordenador');
        }
        if (isset($b['required_fields'])) {
            $rf = array_values(array_filter(array_map('strval', (array)$b['required_fields']), function ($k) {
                return (bool)preg_match('/^k\d{1,5}$/', $k);
            }));
            $row['required_fields'] = json_encode($rf);
        }
        if (isset($b['status'])) {
            $row['status'] = opt_enum($b, 'status', ['vigente', 'obsoleto']);
        }
        if ($row) {
            $db()->update('templates', $row, 'id = ?', [$t['id']]);
            audit($org, Auth::id(), 'documento_configurado', 'template', (int)$t['id'], $row);
        }
        return ['ok' => true];
    });

    $r->add('POST', '/templates/{id}/versions', function ($p) {
        $org = Auth::requireOrg();
        Auth::require('docs.manage');
        $b = body();
        $html = (string)($b['html'] ?? '');
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            if ($_FILES['file']['size'] > 5 * 1048576) {
                throw new HttpError(413, 'Ficheiro demasiado grande (máx. 5 MB).', 'ficheiro_grande');
            }
            $html = (string)file_get_contents($_FILES['file']['tmp_name']);
        }
        if (!preg_match('/<(html|body|div|table|p)\b/i', $html)) {
            throw new HttpError(422, 'Envie um ficheiro HTML (.html). Documentos Word não são aceites como versão controlada.', 'html_invalido');
        }
        $res = Templates::newVersion($org, (int)$p['id'], $html, (string)($b['reason'] ?? ''), (int)Auth::id());
        // Avisar utilizadores para confirmar a leitura da nova versão
        $ids = array_map('intval', array_column(sgi_db()->all("SELECT id FROM users WHERE org_id = ? AND status = 'ativo' AND id <> ?", [$org, Auth::id()]), 'id'));
        $t = sgi_db()->one('SELECT code, title FROM templates WHERE id = ?', [(int)$p['id']]);
        Notify::users($org, $ids, 'Nova versão: ' . $t['code'], $t['title'] . ' — confirme a leitura.', '#/documentos/' . (int)$p['id']);
        return $res;
    });

    $r->add('POST', '/templates/{id}/read', function ($p) use ($db) {
        $org = Auth::requireOrg();
        Auth::require('docs.view');
        $t = $db()->one('SELECT * FROM templates WHERE id = ? AND org_id = ?', [(int)$p['id'], $org]);
        if (!$t) {
            throw new HttpError(404, 'Documento não encontrado.', 'nao_encontrado');
        }
        $db()->q('INSERT IGNORE INTO read_confirmations (org_id, template_version_id, user_id) VALUES (?, ?, ?)', [$org, $t['current_version_id'], Auth::id()]);
        audit($org, Auth::id(), 'leitura_confirmada', 'template', (int)$t['id'], ['versao' => (int)$t['current_version_id']]);
        return ['ok' => true, 'confirmed_at' => $db()->val('SELECT confirmed_at FROM read_confirmations WHERE template_version_id = ? AND user_id = ?', [$t['current_version_id'], Auth::id()])];
    });

    $r->add('GET', '/templates/{id}/reads', function ($p) use ($db) {
        $org = Auth::requireOrg();
        Auth::require('docs.manage');
        $t = $db()->one('SELECT * FROM templates WHERE id = ? AND org_id = ?', [(int)$p['id'], $org]);
        if (!$t) {
            throw new HttpError(404, 'Documento não encontrado.', 'nao_encontrado');
        }
        $rows = $db()->all("SELECT u.id, u.name, u.role, rc.confirmed_at FROM users u
            LEFT JOIN read_confirmations rc ON rc.user_id = u.id AND rc.template_version_id = ?
            WHERE u.org_id = ? AND u.status = 'ativo' ORDER BY rc.confirmed_at IS NULL DESC, u.name", [$t['current_version_id'], $org]);
        return ['reads' => $rows];
    });

    // Ficheiros da organização (logótipo e ativos) — por sessão/token ou por URL assinada.
    $r->add('GET', '/org/logo', function () use ($db) {
        $org = verify_asset_signature('logo', 'logo');
        if ($org === null) {
            Auth::require('docs.view');
            $org = Auth::requireOrg();
        }
        $o = $db()->one('SELECT logo_path FROM organizations WHERE id = ?', [$org]);
        if (!$o || !$o['logo_path']) {
            header('Content-Type: image/svg+xml');
            readfile(SGI_ROOT . '/app/img/icone-sgi.svg');
            return null;
        }
        Storage::assertOrg((string)$o['logo_path'], $org);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file(Storage::abs((string)$o['logo_path'])) ?: 'image/png';
        Storage::send((string)$o['logo_path'], $mime, 'logo', false);
        return null;
    }, ['public' => true]);

    $r->add('GET', '/org/ativo/{name}', function ($p) {
        $name = rawurldecode((string)$p['name']);
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
            throw new HttpError(404, 'Ficheiro não encontrado.', 'nao_encontrado');
        }
        $org = verify_asset_signature('ativo', $name);
        if ($org === null) {
            Auth::require('docs.view');
            $org = Auth::requireOrg();
        }
        $rel = Storage::orgPrefix($org) . '/ativos/' . $name;
        $mime = Storage::exists($rel) ? ((new finfo(FILEINFO_MIME_TYPE))->file(Storage::abs($rel)) ?: 'application/octet-stream') : 'application/octet-stream';
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml', 'image/avif', 'application/pdf'], true)) {
            $mime = 'application/octet-stream';
        }
        Storage::send($rel, $mime, $name, false);
        return null;
    }, ['public' => true]);
};
