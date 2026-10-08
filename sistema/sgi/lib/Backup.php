<?php
declare(strict_types=1);

/**
 * Cópias de segurança completas (BD + ficheiros) em ZIP, criadas e restauradas pelo navegador.
 * O manifesto guarda contagens e hashes por tabela (sobre as colunas existentes) e SHA-256 de cada ficheiro,
 * o que permite provar que uma atualização não perdeu dados.
 */
class Backup
{
    public static function dir(): string
    {
        $d = sgi_storage() . '/backups';
        Storage::protectDir($d);
        return $d;
    }

    /** @return array<int,string> */
    public static function tables(): array
    {
        return array_map(function ($r) {
            return (string)array_values($r)[0];
        }, sgi_db()->all('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\''));
    }

    /** @return array<int,string> */
    public static function columns(string $table): array
    {
        return array_column(sgi_db()->all('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$table]), 'COLUMN_NAME');
    }

    /** Hash determinístico das linhas de uma tabela considerando apenas as colunas indicadas. */
    public static function tableHash(string $table, array $columns): array
    {
        $db = sgi_db();
        $existing = self::columns($table);
        $cols = array_values(array_intersect($columns, $existing));
        if (!$cols) {
            return ['count' => 0, 'hash' => ''];
        }
        $order = in_array('id', $existing, true) ? 'id' : $cols[0];
        $ctx = hash_init('sha256');
        $count = 0;
        $st = $db->q('SELECT `' . implode('`,`', $cols) . '` FROM `' . $table . '` ORDER BY `' . $order . '`');
        while ($row = $st->fetch(PDO::FETCH_NUM)) {
            hash_update($ctx, json_encode(array_map(function ($v) {
                return $v === null ? null : (string)$v;
            }, $row), JSON_UNESCAPED_UNICODE) . "\n");
            $count++;
        }
        return ['count' => $count, 'hash' => hash_final($ctx)];
    }

    /** @return array<string,mixed> */
    public static function snapshot(): array
    {
        $tables = [];
        foreach (self::tables() as $t) {
            if (in_array($t, ['login_attempts', 'idempotency_keys'], true)) {
                continue;
            }
            $cols = self::columns($t);
            $tables[$t] = ['columns' => $cols] + self::tableHash($t, $cols);
        }
        return $tables;
    }

    /** @return array<string,string> rel => sha256 */
    public static function fileList(): array
    {
        $root = sgi_storage();
        $out = [];
        if (!is_dir($root)) {
            return $out;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            if (!$f->isFile()) {
                continue;
            }
            $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($root))), '/');
            if (preg_match('#^(backups|sessions|tmp|atualizacoes)/#', $rel) || preg_match('#(^|/)(\.htaccess|index\.html)$#', $rel)) {
                continue;
            }
            $out[$rel] = hash_file('sha256', $f->getPathname());
        }
        ksort($out);
        return $out;
    }

    /** Cria uma cópia completa. @return array<string,mixed> */
    public static function create(string $reason, ?int $userId): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('A extensão PHP "zip" é necessária para cópias de segurança. Ative-a no cPanel (Selecionar versão do PHP → Extensões).');
        }
        @set_time_limit(600);
        $db = sgi_db();
        $dir = self::dir();
        $name = 'sgi-copia-' . date('Ymd-His') . '-' . safe_name($reason, 20) . '-' . substr(random_token(4), 0, 6) . '.zip';
        $zipPath = $dir . '/' . $name;
        $sqlTmp = $dir . '/.dump-' . random_token(6) . '.sql';
        $fh = fopen($sqlTmp, 'wb');
        fwrite($fh, "-- SGI cópia de segurança\n-- Versão: " . SGI_VERSION . "\n-- Data: " . now() . "\nSET FOREIGN_KEY_CHECKS=0;\n");
        foreach (self::tables() as $t) {
            $create = $db->one('SHOW CREATE TABLE `' . $t . '`');
            $ddl = (string)array_values($create)[1];
            fwrite($fh, "\nDROP TABLE IF EXISTS `$t`;\n" . $ddl . ";\n");
            if (in_array($t, ['login_attempts', 'idempotency_keys'], true)) {
                continue;
            }
            $st = $db->q('SELECT * FROM `' . $t . '`');
            $batch = [];
            $cols = null;
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                if ($cols === null) {
                    $cols = '`' . implode('`,`', array_keys($row)) . '`';
                }
                $batch[] = '(' . implode(',', array_map(function ($v) use ($db) {
                    return $v === null ? 'NULL' : $db->pdo->quote((string)$v);
                }, array_values($row))) . ')';
                if (count($batch) >= 200) {
                    fwrite($fh, "INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                fwrite($fh, "INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $batch) . ";\n");
            }
        }
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);

        $files = self::fileList();
        $manifest = [
            'formato' => 'sgi-backup-1',
            'versao_sistema' => SGI_VERSION,
            'versao_esquema' => (new Migrator($db))->schemaVersion(),
            'criado_em' => now(),
            'motivo' => $reason,
            'tabelas' => self::snapshot(),
            'ficheiros' => $files,
            'sha256_bd' => hash_file('sha256', $sqlTmp),
        ];
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível criar o ficheiro ZIP da cópia.');
        }
        $zip->addFile($sqlTmp, 'bd.sql');
        $zip->addFromString('manifesto.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        foreach (array_keys($files) as $rel) {
            $zip->addFile(sgi_storage() . '/' . $rel, 'ficheiros/' . $rel);
        }
        $zip->close();
        @unlink($sqlTmp);
        $sha = hash_file('sha256', $zipPath);
        $id = $db->insert('backups', ['file_name' => $name, 'size_bytes' => filesize($zipPath), 'sha256' => $sha, 'reason' => $reason, 'created_by' => $userId]);
        setting_set('last_backup_at', now());
        setting_set('last_backup_file', $name);
        audit(null, $userId, 'copia_criada', 'backup', $id, ['ficheiro' => $name]);
        return ['id' => $id, 'file' => $name, 'size' => filesize($zipPath), 'sha256' => $sha, 'manifest' => $manifest];
    }

    public static function pathFor(string $name): string
    {
        if (!preg_match('/^sgi-copia-[A-Za-z0-9._-]+\.zip$/', $name)) {
            throw new HttpError(400, 'Nome de cópia inválido.', 'nome_invalido');
        }
        $p = self::dir() . '/' . $name;
        if (!is_file($p)) {
            throw new HttpError(404, 'Cópia não encontrada.', 'nao_encontrado');
        }
        return $p;
    }

    /** Restaura uma cópia completa (substitui BD e ficheiros). @return array<string,mixed> */
    public static function restore(string $zipPath, ?int $userId): array
    {
        @set_time_limit(900);
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new HttpError(422, 'O ficheiro não é um ZIP válido.', 'zip_invalido');
        }
        $manifest = json_decode((string)$zip->getFromName('manifesto.json'), true);
        $sql = $zip->getFromName('bd.sql');
        if (!is_array($manifest) || ($manifest['formato'] ?? '') !== 'sgi-backup-1' || $sql === false) {
            $zip->close();
            throw new HttpError(422, 'O ZIP não é uma cópia de segurança do SGI.', 'copia_invalida');
        }
        if (!hash_equals((string)$manifest['sha256_bd'], hash('sha256', $sql))) {
            $zip->close();
            throw new HttpError(422, 'A cópia está corrompida (hash da base de dados não confere).', 'copia_corrompida');
        }
        // Validar ficheiros antes de tocar em qualquer coisa
        foreach ((array)$manifest['ficheiros'] as $rel => $sha) {
            if (strpos((string)$rel, '..') !== false) {
                $zip->close();
                throw new HttpError(422, 'Caminho inválido na cópia.', 'copia_invalida');
            }
            $c = $zip->getFromName('ficheiros/' . $rel);
            if ($c === false || !hash_equals((string)$sha, hash('sha256', $c))) {
                $zip->close();
                throw new HttpError(422, 'A cópia está incompleta ou corrompida (ficheiro ' . $rel . ').', 'copia_corrompida');
            }
        }
        // Cópia de segurança do estado atual antes de restaurar
        $pre = self::create('antes-restauro', $userId);
        $db = sgi_db();
        $db->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (self::tables() as $t) {
            if ($t !== 'backups') {
                $db->exec('DROP TABLE IF EXISTS `' . $t . '`');
            }
        }
        $backupsRows = $db->all('SELECT * FROM backups');
        foreach (Db::splitSql($sql) as $stmt) {
            if (preg_match('/^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO) `backups`/', $stmt)) {
                continue; // o registo de cópias deste servidor mantém-se
            }
            $db->pdo->exec($stmt);
        }
        $db->exec('SET FOREIGN_KEY_CHECKS=1');
        unset($backupsRows);
        foreach ((array)$manifest['ficheiros'] as $rel => $sha) {
            Storage::put((string)$rel, (string)$zip->getFromName('ficheiros/' . $rel));
        }
        $zip->close();
        audit(null, $userId, 'copia_restaurada', 'backup', null, ['copia_previa' => $pre['file']]);
        return ['restaurado' => true, 'copia_previa' => $pre['file'], 'versao_esquema' => $manifest['versao_esquema']];
    }

    /** Compara o estado atual com um manifesto (contagens + hashes das colunas antigas + ficheiros). */
    public static function compare(array $manifest): array
    {
        $now = [];
        $problems = [];
        foreach ((array)$manifest['tabelas'] as $t => $info) {
            if (!sgi_db()->tableExists($t)) {
                $problems[] = "Tabela em falta: $t";
                continue;
            }
            $cur = self::tableHash($t, (array)$info['columns']);
            $now[$t] = $cur;
            if ($t === 'settings' || $t === 'schema_migrations' || $t === 'backups' || $t === 'audit_log' || $t === 'notifications') {
                // Tabelas de sistema crescem durante a atualização: exige-se só que nada desapareça.
                if ($cur['count'] < (int)$info['count']) {
                    $problems[] = "Linhas perdidas em $t ({$info['count']} → {$cur['count']})";
                }
                continue;
            }
            if ($cur['count'] !== (int)$info['count'] || $cur['hash'] !== $info['hash']) {
                $problems[] = "Diferença em $t: {$info['count']} → {$cur['count']} linhas" . ($cur['hash'] !== $info['hash'] ? ' (hash diferente)' : '');
            }
        }
        $files = self::fileList();
        foreach ((array)$manifest['ficheiros'] as $rel => $sha) {
            if (!isset($files[$rel])) {
                $problems[] = "Ficheiro em falta: $rel";
            } elseif ($files[$rel] !== $sha) {
                $problems[] = "Ficheiro alterado: $rel";
            }
        }
        return ['ok' => !$problems, 'problemas' => $problems, 'tabelas' => $now];
    }

    /** Exportação dos dados de UMA organização (JSON + ficheiros) — para o administrador da organização. */
    public static function exportOrg(int $orgId, int $userId): string
    {
        $db = sgi_db();
        $tmp = sgi_storage() . '/tmp';
        Storage::protectDir($tmp);
        $path = $tmp . '/exportacao-org' . $orgId . '-' . date('Ymd-His') . '-' . substr(random_token(4), 0, 6) . '.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $data = ['organizacao' => $db->one('SELECT * FROM organizations WHERE id = ?', [$orgId]), 'exportado_em' => now()];
        foreach (self::tables() as $t) {
            if (in_array($t, ['organizations', 'login_attempts', 'idempotency_keys', 'app_tokens'], true)) {
                continue;
            }
            if (in_array('org_id', self::columns($t), true)) {
                $rows = $db->all('SELECT * FROM `' . $t . '` WHERE org_id = ?', [$orgId]);
                if ($t === 'users') {
                    foreach ($rows as &$r) {
                        unset($r['password_hash']);
                    }
                    unset($r);
                }
                $data[$t] = $rows;
            }
        }
        $zip->addFromString('dados.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $prefix = Storage::orgPrefix($orgId) . '/';
        foreach (self::fileList() as $rel => $sha) {
            if (strpos($rel, $prefix) === 0) {
                $zip->addFile(sgi_storage() . '/' . $rel, 'ficheiros/' . substr($rel, strlen($prefix)));
            }
        }
        $zip->close();
        audit($orgId, $userId, 'exportacao_org', 'organization', $orgId);
        return $path;
    }
}
