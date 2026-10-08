<?php
/**
 * Assistente de instalação (navegador, sem Terminal).
 * Protegido pelo CÓDIGO DE INSTALAÇÃO gerado no empacotamento: o pacote contém apenas o hash (codigo.hash).
 * Depois de instalado, este assistente fica bloqueado (existe config.php).
 */
declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
// Durante a instalação, os erros são mostrados como página e não como JSON.
set_exception_handler(null);

$step = 'form';
$errors = [];
$result = null;

if (sgi_is_installed()) {
    Wizard::head('Instalação do SGI');
    echo '<div class="card ok"><h2>O SGI já está instalado</h2><p>Por segurança, o assistente de instalação está bloqueado. '
        . 'Para instalar uma versão nova, use o <a href="' . h(sgi_base_path()) . '/atualizar/">assistente de atualização</a>.</p>'
        . '<p><a class="btn" href="' . h(sgi_base_path()) . '/">Abrir o SGI</a></p></div>';
    Wizard::foot();
    exit;
}

$hashFile = __DIR__ . '/codigo.hash';
$defaults = [
    'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '',
    'storage_path' => dirname(SGI_ROOT, 2) !== '' && is_writable(dirname(SGI_ROOT, 2)) ? dirname(SGI_ROOT, 2) . '/sgi-dados' : SGI_ROOT . '/storage',
    'pa_name' => '', 'pa_email' => '', 'org_name' => 'FACASI', 'org_legal' => '', 'org_tax' => '',
    'oa_name' => '', 'oa_email' => '', 'import_facasi' => '1', 'push' => '1',
];
$v = array_merge($defaults, array_intersect_key($_POST, $defaults));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v['import_facasi'] = isset($_POST['import_facasi']) ? '1' : '0';
    $v['push'] = isset($_POST['push']) ? '1' : '0';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Wizard::checkCsrf();
        Wizard::throttleCheck('install');
        if (!is_file($hashFile)) {
            throw new RuntimeException('Pacote sem código de instalação (instalar/codigo.hash). Use o pacote oficial.');
        }
        $code = preg_replace('/\s+/', '', strtoupper((string)($_POST['install_code'] ?? '')));
        if (!password_verify($code, trim((string)file_get_contents($hashFile)))) {
            Wizard::throttleFail('install');
            throw new RuntimeException('Código de instalação incorreto.');
        }
        if (!Wizard::requirementsOk()) {
            throw new RuntimeException('O servidor não cumpre os requisitos mínimos (ver lista acima).');
        }
        foreach (['db_name' => 'Nome da base de dados', 'db_user' => 'Utilizador da base de dados', 'pa_name' => 'Nome do administrador da plataforma', 'pa_email' => 'Correio do administrador da plataforma', 'org_name' => 'Nome da organização', 'oa_name' => 'Nome do administrador da organização', 'oa_email' => 'Correio do administrador da organização'] as $k => $l) {
            if (trim((string)$v[$k]) === '') {
                $errors[] = "Preencha: $l.";
            }
        }
        foreach (['pa' => 'administrador da plataforma', 'oa' => 'administrador da organização'] as $p => $l) {
            if (!filter_var((string)$v[$p . '_email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Correio inválido do $l.";
            }
            $pw = (string)($_POST[$p . '_pass'] ?? '');
            if ($pw !== (string)($_POST[$p . '_pass2'] ?? '')) {
                $errors[] = "As palavras-passe do $l não coincidem.";
            }
            try {
                Auth::validatePassword($pw);
            } catch (HttpError $e) {
                $errors[] = $e->getMessage() . " ($l)";
            }
        }
        if (mb_strtolower(trim((string)$v['pa_email'])) === mb_strtolower(trim((string)$v['oa_email']))) {
            $errors[] = 'O administrador da plataforma e o da organização têm de ser contas diferentes (separação de acessos).';
        }
        if ($errors) {
            throw new RuntimeException('Corrija os campos indicados.');
        }
        // Ligação à BD
        try {
            $db = new Db((string)$v['db_host'], (int)$v['db_port'], (string)$v['db_name'], (string)$v['db_user'], (string)($_POST['db_pass'] ?? ''));
        } catch (PDOException $e) {
            throw new RuntimeException('Não foi possível ligar à base de dados: verifique o nome, o utilizador, a palavra-passe e se o utilizador tem "Todos os privilégios" nessa base (cPanel → Bases de dados MySQL). Detalhe: ' . $e->getMessage());
        }
        if ($db->tableExists('organizations') || $db->tableExists('schema_migrations')) {
            throw new RuntimeException('A base de dados indicada já contém um SGI. Use uma base vazia ou o assistente de atualização.');
        }
        // Armazenamento
        $storage = rtrim(str_replace('\\', '/', trim((string)$v['storage_path'])), '/');
        if ($storage === '' || strpos($storage, '..') !== false) {
            throw new RuntimeException('Pasta de dados inválida.');
        }
        ensure_dir($storage);
        if (!is_writable($storage)) {
            throw new RuntimeException('Sem permissão de escrita na pasta de dados: ' . $storage);
        }
        Storage::protectDir($storage);
        foreach (['sessions', 'backups', 'tmp'] as $d) {
            Storage::protectDir($storage . '/' . $d);
        }
        $appKey = random_token(32);
        $cfg = [
            'db_host' => (string)$v['db_host'], 'db_port' => (int)$v['db_port'], 'db_name' => (string)$v['db_name'],
            'db_user' => (string)$v['db_user'], 'db_pass' => (string)($_POST['db_pass'] ?? ''),
            'storage_path' => $storage, 'app_key' => $appKey, 'debug' => false,
        ];
        $cfgPhp = "<?php\n// Configuração do SGI — gerada pelo assistente de instalação em " . date('Y-m-d H:i:s') . ".\n// NÃO partilhe este ficheiro. Contém a palavra-passe da base de dados e a chave da aplicação.\nreturn " . var_export($cfg, true) . ";\n";

        // Migrações e dados iniciais (a configuração só é gravada no fim, para não bloquear uma nova tentativa)
        $mig = new Migrator($db);
        $applied = $mig->migrate();
        // As funções auxiliares usam sgi_db()/sgi_config(): escrever config temporária em memória não é possível,
        // por isso gravamos o config agora e removemo-lo em caso de falha.
        file_put_contents(sgi_config_path(), $cfgPhp, LOCK_EX);
        @chmod(sgi_config_path(), 0640);
        try {
            sgi_config(true);
            $db2 = sgi_db(true);
            $now = now();
            $paId = $db2->insert('users', [
                'org_id' => null, 'email' => mb_strtolower(trim((string)$v['pa_email'])), 'name' => trim((string)$v['pa_name']),
                'password_hash' => password_hash((string)$_POST['pa_pass'], PASSWORD_DEFAULT), 'role' => 'plataforma',
            ]);
            $isFacasi = $v['import_facasi'] === '1';
            $orgId = $db2->insert('organizations', [
                'slug' => strtolower(safe_name((string)$v['org_name'], 60)), 'name' => trim((string)$v['org_name']),
                'legal_name' => trim((string)$v['org_legal']) ?: null, 'tax_id' => trim((string)$v['org_tax']) ?: null,
                'is_library_source' => $isFacasi ? 1 : 0,
            ]);
            $oaId = $db2->insert('users', [
                'org_id' => $orgId, 'email' => mb_strtolower(trim((string)$v['oa_email'])), 'name' => trim((string)$v['oa_name']),
                'password_hash' => password_hash((string)$_POST['oa_pass'], PASSWORD_DEFAULT), 'role' => 'admin',
            ]);
            $imported = 0;
            if ($isFacasi) {
                $imported = Templates::importOriginals($orgId, $oaId);
                // Âmbito fornecido pela FACASI (relatório de validação do SGI original): ISO 9001 e ISO 45001.
                setting_set('org_normas_' . $orgId, json_encode(['normas' => ['9001', '45001'], 'justificacao' => 'Âmbito contratado pela FACASI: ISO 9001:2015 e ISO 45001:2018 (relatório de validação do SGI original; conteúdos exclusivos da ISO 14001 foram retirados). Alterável em Organização.'], JSON_UNESCAPED_UNICODE));
            }
            setting_set('installed_at', $now);
            setting_set('installed_version', SGI_VERSION);
            setting_set('push_enabled', $v['push'] === '1' ? '1' : '0');
            $vapid = $v['push'] === '1' ? WebPush::ensureKeys() : null;
            setting_set('push_contact', 'mailto:' . mb_strtolower(trim((string)$v['pa_email'])));
            audit(null, $paId, 'instalacao', null, null, ['versao' => SGI_VERSION, 'migracoes' => count($applied), 'modelos' => $imported]);
            $result = ['applied' => $applied, 'imported' => $imported, 'org' => $v['org_name'], 'storage' => $storage, 'push' => (bool)$vapid];
            $step = 'done';
            @file_put_contents(__DIR__ . '/.instalado', $now);
        } catch (Throwable $e) {
            @unlink(sgi_config_path());
            throw $e;
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

Wizard::head('Instalação do SGI');
if ($step === 'done') {
    echo '<div class="card ok"><h2>Instalação concluída</h2><ul>'
        . '<li>Migrações aplicadas: ' . count($result['applied']) . '</li>'
        . '<li>Organização criada: <strong>' . h((string)$result['org']) . '</strong></li>'
        . '<li>Documentos da FACASI importados: ' . (int)$result['imported'] . '</li>'
        . '<li>Pasta de dados: <code>' . h((string)$result['storage']) . '</code></li>'
        . '<li>Notificações push (VAPID): ' . ($result['push'] ? 'chaves geradas' : 'desligadas/não suportadas — mantêm-se as notificações internas') . '</li></ul>'
        . '<p><strong>Próximos passos:</strong> 1) entre como administrador da plataforma e faça a primeira cópia de segurança; '
        . '2) entre como administrador da organização e crie os utilizadores; 3) configure o correio SMTP (opcional).</p>'
        . '<p><a class="btn" href="' . h(sgi_base_path()) . '/">Abrir o SGI</a></p></div>';
    Wizard::foot();
    exit;
}

echo '<div class="card"><h2>1. Requisitos do servidor</h2>';
Wizard::renderRequirements();
echo '</div>';
if ($errors) {
    echo '<div class="card err"><h2>Não foi possível instalar</h2><ul>';
    foreach ($errors as $e) {
        echo '<li>' . h($e) . '</li>';
    }
    echo '</ul></div>';
}
$catalog = Templates::catalog();
?>
<form method="post" class="card" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= h(Wizard::csrf()) ?>">
  <h2>2. Código de instalação</h2>
  <p class="hint">Código entregue com o pacote (ficheiro separado <em>CODIGO-INSTALACAO.txt</em>). O pacote contém apenas o seu hash.</p>
  <label>Código de instalação<input name="install_code" required autocomplete="off" placeholder="XXXX-XXXX-XXXX-XXXX"></label>

  <h2>3. Base de dados MySQL / MariaDB</h2>
  <p class="hint">No cPanel: <em>Bases de dados MySQL</em> → criar base, criar utilizador e adicionar o utilizador à base com <em>Todos os privilégios</em>. Os nomes incluem o prefixo da conta (ex.: <code>conta_sgi</code>).</p>
  <div class="grid">
    <label>Servidor<input name="db_host" value="<?= h($v['db_host']) ?>" required></label>
    <label>Porta<input name="db_port" value="<?= h($v['db_port']) ?>" inputmode="numeric" required></label>
    <label>Nome da base<input name="db_name" value="<?= h($v['db_name']) ?>" required></label>
    <label>Utilizador<input name="db_user" value="<?= h($v['db_user']) ?>" required></label>
    <label>Palavra-passe<input type="password" name="db_pass" autocomplete="new-password"></label>
  </div>

  <h2>4. Pasta de dados (ficheiros)</h2>
  <p class="hint">Recomendado: fora de <code>public_html</code>. Se não for possível, use a pasta <code>storage</code> do SGI (protegida por .htaccess).</p>
  <label>Caminho completo<input name="storage_path" value="<?= h($v['storage_path']) ?>" required></label>

  <h2>5. Administrador da plataforma</h2>
  <p class="hint">Gere organizações, cópias, atualizações e correio. <strong>Não tem acesso aos documentos das organizações.</strong></p>
  <div class="grid">
    <label>Nome<input name="pa_name" value="<?= h($v['pa_name']) ?>" required></label>
    <label>Correio eletrónico<input type="email" name="pa_email" value="<?= h($v['pa_email']) ?>" required></label>
    <label>Palavra-passe (mín. 10, letras e números)<input type="password" name="pa_pass" autocomplete="new-password" required minlength="10"></label>
    <label>Repetir palavra-passe<input type="password" name="pa_pass2" autocomplete="new-password" required minlength="10"></label>
  </div>

  <h2>6. Primeira organização</h2>
  <div class="grid">
    <label>Nome<input name="org_name" value="<?= h($v['org_name']) ?>" required></label>
    <label>Denominação legal (opcional)<input name="org_legal" value="<?= h($v['org_legal']) ?>"></label>
    <label>NIF (opcional — não inventar)<input name="org_tax" value="<?= h($v['org_tax']) ?>"></label>
  </div>
  <label class="chk"><input type="checkbox" name="import_facasi" value="1" <?= $v['import_facasi'] === '1' ? 'checked' : '' ?> <?= $catalog ? '' : 'disabled' ?>>
    Importar para esta organização os <?= $catalog ? (int)$catalog['total'] : 0 ?> documentos originais da FACASI (apenas se esta organização <strong>for</strong> a FACASI)</label>
  <div class="grid">
    <label>Nome do administrador da organização<input name="oa_name" value="<?= h($v['oa_name']) ?>" required></label>
    <label>Correio eletrónico<input type="email" name="oa_email" value="<?= h($v['oa_email']) ?>" required></label>
    <label>Palavra-passe<input type="password" name="oa_pass" autocomplete="new-password" required minlength="10"></label>
    <label>Repetir palavra-passe<input type="password" name="oa_pass2" autocomplete="new-password" required minlength="10"></label>
  </div>

  <h2>7. Notificações</h2>
  <label class="chk"><input type="checkbox" name="push" value="1" <?= $v['push'] === '1' ? 'checked' : '' ?>> Gerar chaves VAPID e permitir notificações push (pode desligar depois)</label>

  <p><button class="btn" type="submit">Instalar</button></p>
</form>
<?php
Wizard::foot();
