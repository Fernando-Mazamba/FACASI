<?php
declare(strict_types=1);

/**
 * Tarefas diárias: lembretes do calendário, ações atrasadas, verificações de equipamentos, limpeza.
 * Pode correr por cron do cPanel (php cron.php) ou, sem cron, automaticamente após um pedido à API (1×/dia).
 */
class Cron
{
    private static $scheduled = false;

    public static function maybeRunAfterResponse(): void
    {
        if (self::$scheduled) {
            return;
        }
        $last = setting_get('last_cron_at');
        if ($last !== null && strtotime($last) > time() - 86400) {
            return;
        }
        self::$scheduled = true;
        register_shutdown_function(function () {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            try {
                self::run('automatico');
            } catch (Throwable $e) {
                error_log('[SGI cron] ' . $e->getMessage());
            }
        });
    }

    /** @return array<string,int> */
    public static function run(string $origin): array
    {
        $db = sgi_db();
        // evita execuções simultâneas
        $got = (int)$db->val("SELECT GET_LOCK('sgi_cron', 0)");
        if ($got !== 1) {
            return ['ignorado' => 1];
        }
        try {
            setting_set('last_cron_at', now());
            $stats = ['lembretes' => 0, 'acoes_atrasadas' => 0, 'equipamentos' => 0, 'limpeza' => 0];
            $today = date('Y-m-d');
            foreach ($db->all("SELECT id FROM organizations WHERE status = 'ativa'") as $o) {
                $org = (int)$o['id'];
                // Lembretes de eventos
                $events = $db->all("SELECT * FROM calendar_events WHERE org_id = ? AND status = 'planeado'", [$org]);
                $maxDays = 60;
                foreach (Calendar::expand($events, $today, date('Y-m-d', strtotime("+$maxDays days"))) as $e) {
                    $days = (int)((strtotime($e['occurrence_date']) - strtotime($today)) / 86400);
                    if ($days > (int)$e['reminder_days'] || ($e['last_reminder_for'] ?? null) === $e['occurrence_date']) {
                        continue;
                    }
                    $targets = $e['owner_user_id'] ? [(int)$e['owner_user_id']] : array_map('intval', array_column($db->all("SELECT id FROM users WHERE org_id = ? AND status = 'ativo' AND role IN ('admin','coordenador')", [$org]), 'id'));
                    Notify::users($org, $targets, 'Lembrete: ' . $e['title'], 'Data: ' . $e['occurrence_date'] . ($days === 0 ? ' (hoje)' : " (em $days dia(s))"), '#/calendario');
                    $db->update('calendar_events', ['last_reminder_for' => $e['occurrence_date']], 'id = ?', [$e['id']]);
                    $stats['lembretes']++;
                }
                // Ações atrasadas (1 aviso por dia ao responsável)
                foreach ($db->all("SELECT * FROM actions WHERE org_id = ? AND status IN ('pendente','em_curso') AND due_date < ? AND owner_user_id IS NOT NULL", [$org, $today]) as $a) {
                    Notify::users($org, [(int)$a['owner_user_id']], 'Ação atrasada: ' . $a['title'], 'Prazo: ' . $a['due_date'], '#/acoes');
                    $stats['acoes_atrasadas']++;
                }
                // Equipamentos com verificação vencida hoje ou em 7 dias
                $due = $db->all("SELECT * FROM assets WHERE org_id = ? AND status = 'ativo' AND next_check_date IN (?, ?)", [$org, $today, date('Y-m-d', strtotime('+7 days'))]);
                if ($due) {
                    $targets = array_map('intval', array_column($db->all("SELECT id FROM users WHERE org_id = ? AND status = 'ativo' AND role IN ('coordenador','supervisor')", [$org]), 'id'));
                    foreach ($due as $a) {
                        Notify::users($org, $targets, 'Verificação de ' . $a['code'], $a['name'] . ' — ' . $a['next_check_date'], '#/equipamentos/' . $a['id']);
                        $stats['equipamentos']++;
                    }
                }
            }
            // Limpeza
            $stats['limpeza'] += $db->q('DELETE FROM login_attempts WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 90 * 86400)])->rowCount();
            if ($db->tableExists('idempotency_keys')) {
                $stats['limpeza'] += $db->q('DELETE FROM idempotency_keys WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 30 * 86400)])->rowCount();
                $stats['limpeza'] += $db->q('DELETE FROM app_tokens WHERE expires_at < ?', [date('Y-m-d H:i:s', time() - 7 * 86400)])->rowCount();
            }
            $stats['limpeza'] += $db->q('DELETE FROM password_resets WHERE expires_at < ?', [date('Y-m-d H:i:s', time() - 7 * 86400)])->rowCount();
            $tmp = sgi_storage() . '/tmp';
            if (is_dir($tmp)) {
                foreach (glob($tmp . '/*') ?: [] as $f) {
                    if (is_file($f) && filemtime($f) < time() - 86400 && basename($f) !== 'index.html' && basename($f) !== '.htaccess') {
                        @unlink($f);
                    }
                }
            }
            setting_set('last_cron_result', json_encode($stats + ['origem' => $origin, 'concluido_em' => now()]));
            return $stats;
        } finally {
            $db->val("SELECT RELEASE_LOCK('sgi_cron')");
        }
    }
}

