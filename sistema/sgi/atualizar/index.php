<?php
/**
 * Assistente de atualização (navegador, sem Terminal). Apenas para o administrador da plataforma.
 * 1) cópia de segurança automática (BD + ficheiros), 2) migrações pendentes, 3) verificação e relatório.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
set_exception_handler(null);

if (!sgi_is_installed()) {
    header('Location: ' . sgi_base_path() . '/instalar/');
    exit;
}

$errors = [];
$report = null;
$db = sgi_db();
$mig = new Migrator($db);
sgi_start_session();

// Sessão própria do assistente (funciona mesmo com a API em manutenção).
$user = null;
if (!empty($_SESSION['uid'])) {
    $u = Auth::loadActive((int)$_SESSION['uid']);
    if ($u && $u['role'] === 'plataforma' && (int)($_SESSION['pwv'] ?? 0) === Auth::pwVersion($u)) {
        $user = $u;
        Auth::setUser($u, 'sessao');
    }
}

$action = (string)($_POST['acao'] ?? '');
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Wizard::checkCsrf();
    }
    if ($action === 'entrar') {
        $u = Auth::verifyCredentials((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''));
        if ($u['role'] !== 'plataforma') {
            throw new RuntimeException('Apenas o administrador da plataforma pode atualizar o sistema.');
        }
        Auth::loginSession($u);
        $user = $u;
    } elseif ($action === 'sair') {
        Auth::logoutSession();
        $user = null;
    } elseif ($action === 'atualizar' && $user) {
        @set_time_limit(1200);
        @ignore_user_abort(true);
        $t0 = microtime(true);
        $from = $mig->schemaVersion();
        $pending = $mig->pending();
        // 1) cópia de segurança completa
        $bk = Backup::create('antes-atualizacao', (int)$user['id']);
        $manifest = $bk['manifest'];
        // 2) migrações
        $applied = [];
        $migError = null;
        try {
            $applied = $mig->migrate();
        } catch (Throwable $e) {
            $migError = $e->getMessage();
        }
        // 3) tarefas posteriores
        if (!$migError) {
            if (setting_get('push_enabled', '1') === '1') {
                WebPush::ensureKeys();
            }
            setting_set('updated_at', now());
            setting_set('installed_version', SGI_VERSION);
        }
        // 4) verificação: nada perdido (contagens e hashes das colunas existentes antes; SHA-256 de cada ficheiro)
        $cmp = Backup::compare($manifest);
        $finals = $db->all("SELECT id, final_path, final_sha256 FROM records WHERE status = 'final'");
        $finalOk = 0;
        $finalBad = [];
        foreach ($finals as $f) {
            $abs = Storage::abs((string)$f['final_path']);
            if (is_file($abs) && hash_equals((string)$f['final_sha256'], hash_file('sha256', $abs))) {
                $finalOk++;
            } else {
                $finalBad[] = sprintf('REG-%06d', $f['id']);
            }
        }
        $report = [
            'data' => now(), 'por' => $user['name'], 'de_esquema' => $from, 'para_esquema' => $mig->schemaVersion(), 'versao_pacote' => SGI_VERSION,
            'copia' => ['ficheiro' => $bk['file'], 'tamanho' => $bk['size'], 'sha256' => $bk['sha256']],
            'migracoes_pendentes' => array_map(function ($m) {
                return $m['version'] . '/' . $m['file'];
            }, $pending),
            'migracoes_aplicadas' => $applied, 'erro_migracao' => $migError,
            'verificacao' => ['ok' => $cmp['ok'] && !$finalBad && !$migError, 'problemas' => $cmp['problemas'], 'finais_ok' => $finalOk, 'finais_com_problema' => $finalBad],
            'tabelas_antes' => array_map(function ($t) {
                return ['linhas' => $t['count'], 'hash' => $t['hash']];
            }, $manifest['tabelas']),
            'tabelas_depois' => array_map(function ($t) {
                return ['linhas' => $t['count'], 'hash' => $t['hash']];
            }, $cmp['tabelas']),
            'ficheiros_verificados' => count($manifest['ficheiros']),
            'duracao_s' => round(microtime(true) - $t0, 2),
        ];
        $dir = sgi_storage() . '/atualizacoes';
        Storage::protectDir($dir);
        $name = 'relatorio-atualizacao-' . date('Ymd-His') . '.json';
        file_put_contents($dir . '/' . $name, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        setting_set('last_update_report', $name);
        audit(null, (int)$user['id'], 'atualizacao', null, null, ['de' => $from, 'para' => $report['para_esquema'], 'ok' => $report['verificacao']['ok']]);
    } elseif ($action === 'restaurar' && $user) {
        if (($_POST['confirm'] ?? '') !== 'RESTAURAR') {
            throw new RuntimeException('Escreva RESTAURAR para confirmar.');
        }
        $res = Backup::restore(Backup::pathFor((string)($_POST['copia'] ?? '')), (int)$user['id']);
        $errors[] = 'Cópia restaurada. Esquema atual: ' . $mig->schemaVersion() . '. (Foi criada a cópia prévia ' . $res['copia_previa'] . '.)';
    } elseif ($action === 'relatorio' && $user) {
        $name = (string)setting_get('last_update_report', '');
        $p = sgi_storage() . '/atualizacoes/' . basename($name);
        if ($name !== '' && is_file($p)) {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . basename($name) . '"');
            readfile($p);
            exit;
        }
    } elseif ($action === 'copia' && $user) {
        $p = Backup::pathFor((string)($_POST['copia'] ?? ''));
        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize($p));
        header('Content-Disposition: attachment; filename="' . basename($p) . '"');
        readfile($p);
        exit;
    }
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

Wizard::head('Atualização do SGI');
$csrf = Wizard::csrf();
if ($errors) {
    echo '<div class="card ' . ($report ? '' : 'err') . '"><ul>';
    foreach ($errors as $e) {
        echo '<li>' . h($e) . '</li>';
    }
    echo '</ul></div>';
}

if (!$user) {
    echo '<form method="post" class="card"><h2>Entrar como administrador da plataforma</h2><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="acao" value="entrar">'
        . '<label>Correio eletrónico<input type="email" name="email" required autocomplete="username"></label>'
        . '<label>Palavra-passe<input type="password" name="password" required autocomplete="current-password"></label>'
        . '<p><button class="btn" type="submit">Entrar</button></p></form>';
    Wizard::foot();
    exit;
}

if ($report) {
    $ok = $report['verificacao']['ok'];
    echo '<div class="card ' . ($ok ? 'ok' : 'err') . '" id="relatorio"><h2>' . ($ok ? 'Atualização concluída sem perda de dados' : 'Atualização com problemas') . '</h2>'
        . '<p>Esquema: <strong>' . h($report['de_esquema']) . '</strong> → <strong>' . h($report['para_esquema']) . '</strong> · pacote ' . h($report['versao_pacote']) . ' · ' . h((string)$report['duracao_s']) . ' s</p>'
        . '<p>Cópia prévia: <code>' . h($report['copia']['ficheiro']) . '</code> (SHA-256 ' . h(substr($report['copia']['sha256'], 0, 16)) . '…)</p>'
        . '<p>Migrações aplicadas: ' . count($report['migracoes_aplicadas']) . ($report['migracoes_aplicadas'] ? ' — ' . h(implode(', ', $report['migracoes_aplicadas'])) : '') . '</p>';
    if ($report['erro_migracao']) {
        echo '<p><strong>Erro na migração:</strong> ' . h($report['erro_migracao']) . '<br>Pode restaurar a cópia prévia abaixo e contactar o suporte.</p>';
    }
    echo '<p>Verificação: <span data-teste="verificacao">' . ($ok ? 'OK' : 'FALHOU') . '</span> · versões finais com SHA-256 confirmado: ' . (int)$report['verificacao']['finais_ok']
        . ' · ficheiros verificados: ' . (int)$report['ficheiros_verificados'] . '</p>';
    if ($report['verificacao']['problemas'] || $report['verificacao']['finais_com_problema']) {
        echo '<ul>';
        foreach (array_merge($report['verificacao']['problemas'], $report['verificacao']['finais_com_problema']) as $p) {
            echo '<li>' . h($p) . '</li>';
        }
        echo '</ul>';
    }
    echo '<div class="tw"><table class="req"><thead><tr><th>Tabela</th><th>Linhas antes</th><th>Linhas depois</th><th>Hash (colunas antigas)</th></tr></thead><tbody>';
    foreach ($report['tabelas_antes'] as $t => $a) {
        $d = $report['tabelas_depois'][$t] ?? ['linhas' => '—', 'hash' => ''];
        echo '<tr><td>' . h($t) . '</td><td>' . (int)$a['linhas'] . '</td><td>' . h((string)$d['linhas']) . '</td><td>' . ($a['hash'] === $d['hash'] ? 'igual' : 'diferente') . '</td></tr>';
    }
    echo '</tbody></table></div>'
        . '<form method="post" class="inline"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="acao" value="relatorio"><button class="btn sec">Descarregar relatório (JSON)</button></form> '
        . '<a class="btn" href="' . h(sgi_base_path()) . '/">Abrir o SGI</a></div>';
}

$pending = $mig->pending();
echo '<div class="card"><h2>Estado</h2><p>Sessão: ' . h($user['name']) . ' (administrador da plataforma)</p>'
    . '<p>Versão do pacote instalado nos ficheiros: <strong>' . h(SGI_VERSION) . '</strong><br>Versão do esquema da base de dados: <strong data-teste="esquema">' . h($mig->schemaVersion()) . '</strong></p>';
Wizard::renderRequirements();
if ($pending) {
    echo '<h3>Migrações pendentes (' . count($pending) . ')</h3><ul>';
    foreach ($pending as $m) {
        echo '<li><code>' . h($m['version'] . '/' . $m['file']) . '</code></li>';
    }
    echo '</ul><form method="post"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="acao" value="atualizar">'
        . '<p class="hint">Antes de aplicar, o assistente cria automaticamente uma cópia de segurança completa (base de dados + ficheiros). '
        . 'Não feche a página até ver o relatório.</p><p><button class="btn" type="submit" data-teste="atualizar">Atualizar agora</button></p></form>';
} else {
    echo '<p data-teste="em-dia">✅ A base de dados está atualizada. Não há migrações pendentes.</p>';
}
echo '</div>';

$backups = $db->all('SELECT file_name, size_bytes, created_at, reason FROM backups ORDER BY id DESC LIMIT 10');
if ($backups) {
    echo '<div class="card"><h2>Cópias de segurança recentes</h2><div class="tw"><table class="req"><tbody>';
    foreach ($backups as $b) {
        echo '<tr><td><code>' . h($b['file_name']) . '</code></td><td>' . h($b['reason']) . '</td><td>' . h($b['created_at']) . '</td><td>' . round($b['size_bytes'] / 1048576, 2) . ' MB</td><td>'
            . '<form method="post" class="inline"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="acao" value="copia"><input type="hidden" name="copia" value="' . h($b['file_name']) . '"><button class="btn sec">Descarregar</button></form></td></tr>';
    }
    echo '</tbody></table></div><details><summary>Restaurar uma cópia (substitui os dados atuais)</summary>'
        . '<form method="post"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="acao" value="restaurar">'
        . '<label>Cópia<select name="copia">';
    foreach ($backups as $b) {
        echo '<option>' . h($b['file_name']) . '</option>';
    }
    echo '</select></label><label>Escreva RESTAURAR para confirmar<input name="confirm" autocomplete="off"></label><button class="btn danger">Restaurar</button></form></details></div>';
}
echo '<form method="post" class="card"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="acao" value="sair"><button class="btn sec">Terminar sessão</button></form>';
Wizard::foot();
