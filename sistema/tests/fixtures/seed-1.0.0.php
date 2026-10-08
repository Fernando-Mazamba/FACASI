<?php
/**
 * Cria uma instalação com o ESQUEMA 1.0.0 e dados de teste (para o teste 23: atualização 1.0.0 → 1.1.0).
 * Os nomes são claramente fictícios ("… de Teste"). Não representa dados reais da FACASI.
 * Uso: php seed-1.0.0.php <pasta-sgi> <bd> <utilizador-bd> <senha-bd> <pasta-dados>
 */
declare(strict_types=1);
[$_, $dir, $dbName, $dbUser, $dbPass, $storage] = $argv + [null, null, null, null, null, null];
if (!$storage) {
    fwrite(STDERR, "parâmetros em falta\n");
    exit(1);
}
$cfg = ['db_host' => '127.0.0.1', 'db_port' => 3306, 'db_name' => $dbName, 'db_user' => $dbUser, 'db_pass' => $dbPass, 'storage_path' => $storage, 'app_key' => bin2hex(random_bytes(32)), 'debug' => true];
file_put_contents("$dir/config.php", "<?php\nreturn " . var_export($cfg, true) . ";\n");
$_SERVER['SCRIPT_FILENAME'] = "$dir/cron.php";
$_SERVER['SCRIPT_NAME'] = '/sgi/cron.php';
require "$dir/lib/bootstrap.php";
foreach (['', '/sessions', '/backups', '/tmp'] as $d) {
    Storage::protectDir($storage . $d);
}
$db = sgi_db();
$applied = (new Migrator($db))->migrate('1.0.0');
$pw = fn($p) => password_hash($p, PASSWORD_DEFAULT);
$db->insert('settings', ['skey' => 'installed_at', 'svalue' => '2026-01-15 09:00:00']);
$db->insert('settings', ['skey' => 'installed_version', 'svalue' => '1.0.0']);
$pa = $db->insert('users', ['org_id' => null, 'email' => 'plataforma@upd.teste', 'name' => 'Plataforma de Teste', 'password_hash' => $pw('Plataforma123'), 'role' => 'plataforma']);
$org = $db->insert('organizations', ['slug' => 'facasi', 'name' => 'FACASI', 'is_library_source' => 1]);
$adm = $db->insert('users', ['org_id' => $org, 'email' => 'admin@upd.teste', 'name' => 'Administrador de Teste', 'password_hash' => $pw('AdminTeste123'), 'role' => 'admin']);
$op = $db->insert('users', ['org_id' => $org, 'email' => 'operario@upd.teste', 'name' => 'Operário de Teste', 'password_hash' => $pw('Operario123'), 'role' => 'operario']);
$n = Templates::importOriginals($org, $adm);
$tpls = $db->all("SELECT id, code, category, doc_type, current_version_id FROM templates WHERE org_id = ? AND is_form = 1 ORDER BY id LIMIT 12", [$org]);
$records = 0;
$finals = 0;
foreach ($tpls as $i => $t) {
    foreach ([0, 1] as $j) {
        $data = json_encode(['fields' => ['k0' => "Valor de teste $i-$j", 'k1' => 'Observação de teste'], 'count' => 2, 'required' => [], 'labels' => ['k0' => 'Campo 1', 'k1' => 'Campo 2'], 'dom' => null], JSON_UNESCAPED_UNICODE);
        $rid = $db->insert('records', [
            'org_id' => $org, 'template_id' => $t['id'], 'template_version_id' => $t['current_version_id'], 'title' => "{$t['code']} — teste $i-$j",
            'status' => 'rascunho', 'data_json' => $data, 'lock_version' => 1 + $j, 'created_by' => $j ? $op : $adm, 'updated_by' => $adm,
        ]);
        $records++;
        if ($j === 0) {
            $y = 2026;
            $m = 3 + ($i % 6);
            $rel = sprintf('org_%d/registos/%s/%s/%04d/%02d/REG-%06d-v1.html', $org, safe_name($t['category'], 50), safe_name($t['code'] . '-' . $t['doc_type'], 70), $y, $m, $rid);
            $html = "<!DOCTYPE html><html><body><div id=\"sgi-registo-final\">REGISTO FINAL REG-" . sprintf('%06d', $rid) . " (dados de teste da versão 1.0.0)</div><p>Valor de teste $i-$j</p></body></html>";
            Storage::put($rel, $html);
            $db->update('records', ['status' => 'final', 'final_path' => $rel, 'final_sha256' => hash('sha256', $html), 'final_size' => strlen($html),
                'archive_category' => $t['category'], 'archive_type' => $t['code'] . ' — ' . $t['doc_type'], 'archive_year' => $y, 'archive_month' => $m,
                'finalized_by' => $adm, 'finalized_at' => sprintf('%04d-%02d-10 10:00:00', $y, $m)], 'id = ?', [$rid]);
            $finals++;
            // anexo (fotografia de teste)
            $img = imagecreatetruecolor(64, 48);
            imagefill($img, 0, 0, imagecolorallocate($img, 30 + $i * 10, 80, 160));
            ob_start();
            imagejpeg($img, null, 80);
            $jpg = (string)ob_get_clean();
            $arel = sprintf('org_%d/anexos/record/%d/foto-%d.jpg', $org, $rid, $rid);
            Storage::put($arel, $jpg);
            $db->insert('attachments', ['org_id' => $org, 'entity_type' => 'record', 'entity_id' => $rid, 'kind' => 'foto', 'original_name' => "foto-teste-$rid.jpg", 'mime' => 'image/jpeg',
                'size_bytes' => strlen($jpg), 'sha256' => hash('sha256', $jpg), 'file_path' => $arel, 'created_by' => $op]);
        }
    }
}
for ($k = 1; $k <= 5; $k++) {
    $db->insert('workers', ['org_id' => $org, 'name' => "Trabalhador de Teste $k", 'id_number' => "TESTE-$k", 'department' => 'Operações']);
    $db->insert('suppliers', ['org_id' => $org, 'name' => "Fornecedor de Teste $k", 'status' => 'em_avaliacao']);
    $nc = $db->insert('nonconformities', ['org_id' => $org, 'code' => "NC-TESTE-$k", 'title' => "Não conformidade de teste $k", 'created_by' => $adm, 'detected_at' => '2026-02-0' . $k]);
    $db->insert('actions', ['org_id' => $org, 'nc_id' => $nc, 'title' => "Ação de teste $k", 'owner_user_id' => $op, 'due_date' => '2026-12-0' . $k, 'created_by' => $adm]);
    $db->insert('trainings', ['org_id' => $org, 'title' => "Capacitação de teste $k", 'planned_date' => '2026-11-1' . $k, 'created_by' => $adm]);
    $db->insert('notifications', ['org_id' => $org, 'user_id' => $op, 'title' => "Notificação de teste $k"]);
}
$db->insert('positions', ['org_id' => $org, 'name' => 'Cargo de Teste']);
echo json_encode(['migracoes' => $applied, 'modelos' => $n, 'registos' => $records, 'finais' => $finals], JSON_UNESCAPED_UNICODE), "\n";
