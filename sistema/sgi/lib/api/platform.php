<?php
declare(strict_types=1);

/** Estado do sistema (sem conteúdo de documentos das organizações). @return array<string,mixed> */
function system_status(): array
{
    $db = sgi_db();
    $mig = new Migrator($db);
    $exts = [];
    foreach (['pdo_mysql', 'openssl', 'mbstring', 'json', 'fileinfo', 'zip', 'gd', 'curl', 'dom', 'simplexml', 'iconv', 'intl'] as $e) {
        $exts[$e] = extension_loaded($e);
    }
    $storage = sgi_storage();
    $size = 0;
    $files = 0;
    if (is_dir($storage)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storage, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile()) {
                $size += $f->getSize();
                $files++;
            }
        }
    }
    $dbSize = (int)$db->val('SELECT COALESCE(SUM(data_length + index_length),0) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
    $integrity = json_decode((string)setting_get('integrity_last', ''), true);
    return [
        'versao_sistema' => SGI_VERSION,
        'versao_esquema' => $mig->schemaVersion(),
        'migracoes_pendentes' => array_map(function ($m) {
            return $m['version'] . '/' . $m['file'];
        }, $mig->pending()),
        'php' => PHP_VERSION,
        'php_sapi' => PHP_SAPI,
        'servidor_web' => (string)($_SERVER['SERVER_SOFTWARE'] ?? ''),
        'mysql' => (string)$db->val('SELECT VERSION()'),
        'extensoes' => $exts,
        'limites' => ['upload_max_filesize' => ini_get('upload_max_filesize'), 'post_max_size' => ini_get('post_max_size'), 'memory_limit' => ini_get('memory_limit'), 'max_execution_time' => ini_get('max_execution_time')],
        'disco_livre_bytes' => @disk_free_space($storage) ?: null,
        'disco_total_bytes' => @disk_total_space($storage) ?: null,
        'armazenamento_bytes' => $size,
        'armazenamento_ficheiros' => $files,
        'bd_bytes' => $dbSize,
        'storage_fora_publico' => strpos(realpath($storage) ?: $storage, realpath(SGI_ROOT) ?: SGI_ROOT) !== 0,
        'https' => sgi_is_https(),
        'ultima_copia' => setting_get('last_backup_at'),
        'ultima_copia_ficheiro' => setting_get('last_backup_file'),
        'ultima_tarefa_diaria' => setting_get('last_cron_at'),
        'resultado_tarefa_diaria' => json_decode((string)setting_get('last_cron_result', 'null'), true),
        'integridade' => $integrity,
        'smtp_ativo' => Mailer::enabled(),
        'push_ativo' => WebPush::enabled(),
        'push_suportado' => WebPush::supported(),
        'organizacoes' => (int)$db->val('SELECT COUNT(*) FROM organizations'),
        'utilizadores' => (int)$db->val("SELECT COUNT(*) FROM users WHERE status = 'ativo'"),
        'registos_finais' => (int)$db->val("SELECT COUNT(*) FROM records WHERE status = 'final'"),
        'instalado_em' => setting_get('installed_at'),
        'atualizado_em' => setting_get('updated_at'),
    ];
}

/** Verifica o SHA-256 de todas as versões finais (não lê nem devolve conteúdo). */
function integrity_check(): array
{
    $db = sgi_db();
    $ok = 0;
    $bad = [];
    foreach ($db->all("SELECT id, org_id, final_path, final_sha256 FROM records WHERE status = 'final'") as $r) {
        $abs = Storage::abs((string)$r['final_path']);
        if (!is_file($abs)) {
            $bad[] = ['registo' => sprintf('REG-%06d', $r['id']), 'org_id' => (int)$r['org_id'], 'problema' => 'ficheiro em falta'];
        } elseif (!hash_equals((string)$r['final_sha256'], hash_file('sha256', $abs))) {
            $bad[] = ['registo' => sprintf('REG-%06d', $r['id']), 'org_id' => (int)$r['org_id'], 'problema' => 'hash não confere (ficheiro alterado)'];
        } else {
            $ok++;
        }
    }
    $res = ['verificado_em' => now(), 'ok' => $ok, 'falhas' => count($bad), 'detalhe' => array_slice($bad, 0, 200)];
    setting_set('integrity_last', json_encode($res, JSON_UNESCAPED_UNICODE));
    return $res;
}

return function (Router $r) {
    $plat = function () {
        Auth::require('platform');
    };

    $r->add('GET', '/platform/status', function () use ($plat) {
        $plat();
        return system_status();
    }, ['maintenance' => true]);

    $r->add('POST', '/platform/integrity', function () use ($plat) {
        $plat();
        $res = integrity_check();
        audit(null, Auth::id(), 'integridade_verificada', null, null, ['ok' => $res['ok'], 'falhas' => $res['falhas']]);
        return $res;
    });

    $r->add('POST', '/platform/cron', function () use ($plat) {
        $plat();
        return Cron::run('manual');
    });

    // ---- Organizações ----
    $r->add('GET', '/platform/orgs', function () use ($plat) {
        $plat();
        $rows = sgi_db()->all('SELECT o.id, o.slug, o.name, o.status, o.is_library_source, o.created_at,
            (SELECT COUNT(*) FROM users u WHERE u.org_id = o.id) AS users,
            (SELECT COUNT(*) FROM templates t WHERE t.org_id = o.id) AS templates,
            (SELECT COUNT(*) FROM records r WHERE r.org_id = o.id) AS records
            FROM organizations o ORDER BY o.name');
        return ['orgs' => $rows];
    });

    $r->add('POST', '/platform/orgs', function () use ($plat) {
        $plat();
        $b = body();
        $name = req_str($b, 'name', 'Nome da organização', 190);
        $slug = strtolower(safe_name((string)($b['slug'] ?? $name), 60));
        if (sgi_db()->val('SELECT id FROM organizations WHERE slug = ?', [$slug])) {
            throw new HttpError(409, 'Já existe uma organização com este identificador.', 'duplicado');
        }
        $email = mb_strtolower(req_str($b, 'admin_email', 'Correio do administrador', 190));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || sgi_db()->val('SELECT id FROM users WHERE email = ?', [$email])) {
            throw new HttpError(422, 'Correio do administrador inválido ou já utilizado.', 'email_invalido');
        }
        $pw = temp_password();
        $res = sgi_db()->tx(function () use ($name, $slug, $email, $pw, $b) {
            $oid = sgi_db()->insert('organizations', ['slug' => $slug, 'name' => $name, 'legal_name' => opt_str($b, 'legal_name'), 'tax_id' => opt_str($b, 'tax_id', 60)]);
            $uid = sgi_db()->insert('users', [
                'org_id' => $oid, 'email' => $email, 'name' => req_str($b, 'admin_name', 'Nome do administrador', 190),
                'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'role' => 'admin', 'must_change_password' => 1,
            ]);
            return [$oid, $uid];
        });
        Storage::protectDir(sgi_storage());
        $provisioned = 0;
        if (!empty($b['library_ids']) && is_array($b['library_ids'])) {
            $provisioned = Templates::provisionFromLibrary($res[0], $b['library_ids'], (int)Auth::id());
        }
        audit(null, Auth::id(), 'organizacao_criada', 'organization', $res[0], ['modelos' => $provisioned]);
        return ['id' => $res[0], 'admin_temp_password' => $pw, 'modelos_entregues' => $provisioned, 'aviso' => 'A palavra-passe temporária é mostrada uma única vez.', '__status' => 201];
    });

    $r->add('PUT', '/platform/orgs/{id}', function ($p) use ($plat) {
        $plat();
        $b = body();
        $st = opt_enum($b, 'status', ['ativa', 'suspensa']);
        if ($st) {
            sgi_db()->update('organizations', ['status' => $st], 'id = ?', [(int)$p['id']]);
            if ($st === 'suspensa') {
                foreach (sgi_db()->all('SELECT id FROM users WHERE org_id = ?', [(int)$p['id']]) as $u) {
                    Tokens::revokeAllForUser((int)$u['id'], Auth::id(), 'organizacao_suspensa');
                }
            }
            audit(null, Auth::id(), 'organizacao_estado', 'organization', (int)$p['id'], ['estado' => $st]);
        }
        return ['ok' => true];
    });

    $r->add('POST', '/platform/orgs/{id}/provision', function ($p) use ($plat) {
        $plat();
        $oid = (int)$p['id'];
        $org = sgi_db()->one('SELECT * FROM organizations WHERE id = ?', [$oid]);
        if (!$org) {
            throw new HttpError(404, 'Organização não encontrada.', 'nao_encontrado');
        }
        if ((int)$org['is_library_source'] === 1) {
            throw new HttpError(422, 'A organização de origem já tem os seus documentos originais.', 'origem');
        }
        $n = Templates::provisionFromLibrary($oid, (array)(body()['library_ids'] ?? []), (int)Auth::id());
        audit(null, Auth::id(), 'modelos_entregues', 'organization', $oid, ['n' => $n]);
        return ['entregues' => $n];
    });

    $r->add('POST', '/platform/orgs/{id}/admin-reset', function ($p) use ($plat) {
        $plat();
        $u = sgi_db()->one("SELECT * FROM users WHERE org_id = ? AND role = 'admin' AND id = ?", [(int)$p['id'], (int)(body()['user_id'] ?? 0)]);
        if (!$u) {
            throw new HttpError(404, 'Administrador não encontrado nesta organização.', 'nao_encontrado');
        }
        $pw = temp_password();
        sgi_db()->update('users', ['password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'must_change_password' => 1, 'locked_until' => null, 'failed_attempts' => 0, 'status' => 'ativo'], 'id = ?', [$u['id']]);
        Tokens::revokeAllForUser((int)$u['id'], Auth::id(), 'reposicao_plataforma');
        audit(null, Auth::id(), 'admin_org_reposto', 'user', (int)$u['id']);
        return ['temp_password' => $pw];
    });

    $r->add('GET', '/platform/orgs/{id}/admins', function ($p) use ($plat) {
        $plat();
        return ['admins' => sgi_db()->all("SELECT id, name, email, status, last_login_at FROM users WHERE org_id = ? AND role = 'admin'", [(int)$p['id']])];
    });

    // ---- Biblioteca neutralizada ----
    $r->add('GET', '/platform/library', function () use ($plat) {
        $plat();
        $auth = json_decode((string)setting_get('library_authorization', 'null'), true);
        $rows = sgi_db()->all('SELECT l.*, u.name AS reviewed_by_name FROM library_templates l LEFT JOIN users u ON u.id = l.reviewed_by ORDER BY l.category, l.code');
        foreach ($rows as &$x) {
            $x['scan_findings'] = json_decode((string)$x['scan_findings'], true) ?: [];
        }
        return ['autorizacao' => $auth, 'items' => $rows];
    });

    $r->add('POST', '/platform/library/rebuild', function () use ($plat) {
        $plat();
        $auth = json_decode((string)setting_get('library_authorization', 'null'), true);
        if (!$auth) {
            throw new HttpError(409, 'Sem autorização expressa da organização de origem para usar os seus modelos como biblioteca. Registe a autorização primeiro.', 'sem_autorizacao');
        }
        return ['novos' => Templates::buildLibrary()];
    });

    $r->add('POST', '/platform/library/authorization', function () use ($plat) {
        $plat();
        $b = body();
        $who = req_str($b, 'authorized_by', 'Nome de quem autoriza (pela organização de origem)', 190);
        $ref = req_str($b, 'reference', 'Referência do documento de autorização', 255);
        setting_set('library_authorization', json_encode(['autorizado_por' => $who, 'referencia' => $ref, 'registado_por' => Auth::user()['name'], 'em' => now()], JSON_UNESCAPED_UNICODE));
        audit(null, Auth::id(), 'biblioteca_autorizada', null, null, ['por' => $who, 'ref' => $ref]);
        return ['ok' => true];
    });

    $r->add('GET', '/platform/library/{id}/preview', function ($p) use ($plat) {
        $plat();
        $l = sgi_db()->one('SELECT * FROM library_templates WHERE id = ?', [(int)$p['id']]);
        if (!$l) {
            throw new HttpError(404, 'Modelo não encontrado.', 'nao_encontrado');
        }
        // Mostra o modelo NEUTRALIZADO (não contém dados de nenhuma organização cliente).
        return ['html' => str_replace(['{{ORG_NAME}}', '{{ORG_NIF}}', '{{ORG_LOGO}}'], ['[NOME DA ORGANIZAÇÃO]', '[NIF]', sgi_base_path() . '/app/img/icone-sgi.svg'], Storage::get((string)$l['neutral_path']))];
    });

    $r->add('POST', '/platform/library/{id}/review', function ($p) use ($plat) {
        $plat();
        $st = opt_enum(body(), 'status', ['aprovado', 'rejeitado', 'pendente_revisao']);
        $l = sgi_db()->one('SELECT * FROM library_templates WHERE id = ?', [(int)$p['id']]);
        if (!$l || !$st) {
            throw new HttpError(404, 'Modelo não encontrado.', 'nao_encontrado');
        }
        if ($st === 'aprovado' && preg_match('/FACASI|\{\{ORG_ASSET:/i', Storage::get((string)$l['neutral_path']))) {
            throw new HttpError(422, 'Este modelo ainda contém identificadores ou ficheiros da organização de origem e não pode ser aprovado.', 'nao_neutralizado');
        }
        sgi_db()->update('library_templates', ['status' => $st, 'reviewed_by' => Auth::id(), 'reviewed_at' => now()], 'id = ?', [$l['id']]);
        audit(null, Auth::id(), 'biblioteca_revisao', 'library', (int)$l['id'], ['estado' => $st]);
        return ['ok' => true];
    });

    // ---- Correio SMTP ----
    $r->add('GET', '/platform/smtp', function () use ($plat) {
        $plat();
        $c = Mailer::config();
        $c['has_password'] = $c['pass_enc'] !== '';
        unset($c['pass_enc']);
        return $c;
    });

    $r->add('PUT', '/platform/smtp', function () use ($plat) {
        $plat();
        $b = body();
        $cur = Mailer::config();
        $sec = opt_enum($b, 'security', ['tls', 'ssl', 'nenhuma'], 'tls');
        $c = [
            'enabled' => !empty($b['enabled']),
            'host' => (string)opt_str($b, 'host', 190),
            'port' => max(1, min(65535, (int)($b['port'] ?? 587))),
            'security' => $sec,
            'user' => (string)opt_str($b, 'user', 190),
            'pass_enc' => isset($b['password']) && $b['password'] !== '' ? secret_encrypt((string)$b['password']) : $cur['pass_enc'],
            'from_email' => (string)opt_str($b, 'from_email', 190),
            'from_name' => (string)(opt_str($b, 'from_name', 120) ?? 'SGI'),
        ];
        if ($c['enabled'] && (!$c['host'] || !filter_var($c['from_email'], FILTER_VALIDATE_EMAIL))) {
            throw new HttpError(422, 'Indique o servidor SMTP e um remetente válido.', 'smtp_invalido');
        }
        setting_set('smtp', json_encode($c));
        audit(null, Auth::id(), 'smtp_configurado', null, null, ['host' => $c['host'], 'ativo' => $c['enabled']]);
        return ['ok' => true];
    });

    $r->add('POST', '/platform/smtp/test', function () use ($plat) {
        $plat();
        $to = (string)(body()['to'] ?? '');
        try {
            Mailer::send($to, '[SGI] Teste de correio', "Mensagem de teste enviada pelo SGI em " . now() . ".\n");
        } catch (Throwable $e) {
            throw new HttpError(502, 'Falha no envio: ' . $e->getMessage(), 'smtp_falha');
        }
        return ['ok' => true, 'mensagem' => 'Mensagem enviada. Verifique a caixa de correio (e o spam).'];
    });

    // ---- Push ----
    $r->add('GET', '/platform/push', function () use ($plat) {
        $plat();
        return ['suportado' => WebPush::supported(), 'ativo' => WebPush::enabled(), 'chave_publica' => WebPush::publicKey(), 'contacto' => setting_get('push_contact', ''),
            'subscricoes' => (int)sgi_db()->val('SELECT COUNT(*) FROM push_subscriptions')];
    });

    $r->add('PUT', '/platform/push', function () use ($plat) {
        $plat();
        $b = body();
        if (!empty($b['enabled'])) {
            if (!WebPush::ensureKeys()) {
                throw new HttpError(409, 'Este servidor não suporta Web Push (falta OpenSSL com curva P-256 ou cURL). As notificações internas continuam ativas.', 'push_nao_suportado');
            }
        }
        setting_set('push_enabled', !empty($b['enabled']) ? '1' : '0');
        $contact = (string)($b['contact'] ?? '');
        if ($contact !== '') {
            if (!preg_match('#^(mailto:[^@\s]+@[^@\s]+|https://\S+)$#', $contact)) {
                throw new HttpError(422, 'Contacto VAPID deve ser "mailto:endereco@dominio" ou um URL https.', 'contacto_invalido');
            }
            setting_set('push_contact', $contact);
        }
        return ['ok' => true];
    });

    // ---- Cópias de segurança ----
    $r->add('GET', '/platform/backups', function () use ($plat) {
        $plat();
        $rows = sgi_db()->all('SELECT b.*, u.name AS created_by_name FROM backups b LEFT JOIN users u ON u.id = b.created_by ORDER BY b.id DESC LIMIT 100');
        foreach ($rows as &$b) {
            $b['exists'] = is_file(Backup::dir() . '/' . $b['file_name']);
        }
        return ['backups' => $rows];
    }, ['maintenance' => true]);

    $r->add('POST', '/platform/backups', function () use ($plat) {
        $plat();
        $res = Backup::create('manual', Auth::id());
        unset($res['manifest']);
        return $res;
    }, ['maintenance' => true]);

    $r->add('GET', '/platform/backups/{name}', function ($p) use ($plat) {
        $plat();
        $path = Backup::pathFor((string)$p['name']);
        audit(null, Auth::id(), 'copia_descarregada', 'backup', null, ['ficheiro' => basename($path)]);
        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Cache-Control: no-store');
        readfile($path);
        return null;
    }, ['maintenance' => true]);

    $r->add('DELETE', '/platform/backups/{name}', function ($p) use ($plat) {
        $plat();
        $path = Backup::pathFor((string)$p['name']);
        unlink($path);
        sgi_db()->q('DELETE FROM backups WHERE file_name = ?', [basename($path)]);
        audit(null, Auth::id(), 'copia_eliminada', 'backup', null, ['ficheiro' => basename($path)]);
        return ['ok' => true];
    }, ['maintenance' => true]);

    $r->add('POST', '/platform/backups/restore', function () use ($plat) {
        $plat();
        $b = body();
        if (($b['confirm'] ?? '') !== 'RESTAURAR') {
            throw new HttpError(422, 'Escreva RESTAURAR para confirmar. O estado atual será substituído (é feita uma cópia prévia automática).', 'confirmacao');
        }
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            $tmp = Backup::dir() . '/sgi-copia-carregada-' . date('Ymd-His') . '.zip';
            move_uploaded_file($_FILES['file']['tmp_name'], $tmp);
            $path = $tmp;
        } else {
            $path = Backup::pathFor((string)($b['name'] ?? ''));
        }
        return Backup::restore($path, Auth::id());
    }, ['maintenance' => true]);

    $r->add('GET', '/platform/audit', function () use ($plat) {
        $plat();
        // Auditoria da plataforma (ações sem organização) — não inclui atividade dos documentos das organizações.
        return ['audit' => sgi_db()->all('SELECT a.id, a.action, a.entity, a.entity_id, a.details, a.ip, a.created_at, u.name AS user_name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id WHERE a.org_id IS NULL ORDER BY a.id DESC LIMIT 300')];
    });
};
