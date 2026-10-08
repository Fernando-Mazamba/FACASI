<?php
declare(strict_types=1);

return function (Router $r) {
    Crud::register($r, 'workers', 'workers');
    Crud::register($r, 'positions', 'positions');
    Crud::register($r, 'suppliers', 'suppliers');
    Crud::register($r, 'assets', 'assets');
    Crud::register($r, 'trainings', 'trainings');
    Crud::register($r, 'nonconformities', 'nonconformities');
    Crud::register($r, 'actions', 'actions');
    Crud::register($r, 'events', 'calendar_events');
    Crud::register($r, 'indicators', 'indicators');

    // ---- Calendário (com recorrências) ----
    $r->add('GET', '/calendar', function () {
        $org = Auth::requireOrg();
        Auth::require('calendar.view');
        $from = opt_date(['f' => qstr('from', date('Y-m-01'))], 'f');
        $to = opt_date(['t' => qstr('to', date('Y-m-t', strtotime('+2 months')))], 't');
        $rows = sgi_db()->all("SELECT e.*, u.name AS owner_name FROM calendar_events e LEFT JOIN users u ON u.id = e.owner_user_id
            WHERE e.org_id = ? AND e.status <> 'cancelado' AND (e.recurrence <> 'nenhuma' OR (e.start_date <= ? AND COALESCE(e.end_date, e.start_date) >= ?))", [$org, $to, $from]);
        // Vencimentos derivados (não duplicados na tabela): ações, NC, verificações de equipamentos, capacitações
        $derived = [];
        foreach (sgi_db()->all("SELECT id, title, due_date FROM actions WHERE org_id = ? AND status IN ('pendente','em_curso') AND due_date BETWEEN ? AND ?", [$org, $from, $to]) as $a) {
            $derived[] = ['id' => 'acao-' . $a['id'], 'title' => 'Prazo da ação: ' . $a['title'], 'event_type' => 'vencimento', 'occurrence_date' => $a['due_date'], 'occurrence_end' => $a['due_date'], 'link' => '#/acoes/' . $a['id'], 'derived' => true];
        }
        foreach (sgi_db()->all("SELECT id, code, name, next_check_date FROM assets WHERE org_id = ? AND status = 'ativo' AND next_check_date BETWEEN ? AND ?", [$org, $from, $to]) as $a) {
            $derived[] = ['id' => 'eq-' . $a['id'], 'title' => 'Verificação: ' . $a['code'] . ' ' . $a['name'], 'event_type' => 'manutencao', 'occurrence_date' => $a['next_check_date'], 'occurrence_end' => $a['next_check_date'], 'link' => '#/equipamentos/' . $a['id'], 'derived' => true];
        }
        foreach (sgi_db()->all("SELECT id, title, planned_date FROM trainings WHERE org_id = ? AND status = 'planeada' AND planned_date BETWEEN ? AND ?", [$org, $from, $to]) as $t) {
            $derived[] = ['id' => 'cap-' . $t['id'], 'title' => 'Capacitação: ' . $t['title'], 'event_type' => 'capacitacao', 'occurrence_date' => $t['planned_date'], 'occurrence_end' => $t['planned_date'], 'link' => '#/capacitacoes', 'derived' => true];
        }
        $events = array_merge(Calendar::expand($rows, (string)$from, (string)$to), $derived);
        usort($events, function ($a, $b) {
            return strcmp((string)$a['occurrence_date'], (string)$b['occurrence_date']);
        });
        return ['from' => $from, 'to' => $to, 'events' => $events];
    });

    $r->add('POST', '/calendar/import-legacy', function () {
        $org = Auth::requireOrg();
        Auth::require('calendar.manage');
        $b = body();
        $list = $b['activities'] ?? null;
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            $list = json_decode((string)file_get_contents($_FILES['file']['tmp_name']), true);
        }
        if (is_array($list) && isset($list['activities'])) {
            $list = $list['activities'];
        }
        if (!is_array($list)) {
            throw new HttpError(422, 'Ficheiro inválido. Use o JSON obtido em "Descarregar → Descarregar como JSON" no calendário antigo.', 'json_invalido');
        }
        $map = ['auditoria' => 'auditoria', 'revision' => 'revisao', 'capacitação' => 'capacitacao', 'capacitacao' => 'capacitacao'];
        $st = ['planned' => 'planeado', 'done' => 'realizado', 'rescheduled' => 'planeado'];
        $n = 0;
        $skipped = 0;
        foreach ($list as $a) {
            if (!is_array($a) || empty($a['date']) || empty($a['activity']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$a['date'])) {
                $skipped++;
                continue;
            }
            $desc = [];
            if (!empty($a['norms'])) {
                $desc[] = 'Normas: ' . implode(', ', array_map('strval', (array)$a['norms']));
            }
            foreach (['process' => 'Processo', 'clause' => 'Cláusula', 'responsible' => 'Responsável (texto do calendário antigo)', 'observations' => 'Observações'] as $k => $l) {
                if (!empty($a[$k])) {
                    $desc[] = $l . ': ' . (string)$a[$k];
                }
            }
            $desc[] = 'Importado do calendário antigo (guardado só no navegador) em ' . now() . '.';
            $exists = sgi_db()->val('SELECT id FROM calendar_events WHERE org_id = ? AND start_date = ? AND title = ?', [$org, $a['date'], mb_substr((string)$a['activity'], 0, 255)]);
            if ($exists) {
                $skipped++;
                continue;
            }
            sgi_db()->insert('calendar_events', [
                'org_id' => $org, 'title' => mb_substr((string)$a['activity'], 0, 255),
                'event_type' => $map[(string)($a['process'] ?? '')] ?? 'outro', 'start_date' => $a['date'],
                'description' => implode("\n", $desc), 'status' => $st[(string)($a['status'] ?? '')] ?? 'planeado', 'created_by' => Auth::id(),
            ]);
            $n++;
        }
        audit($org, Auth::id(), 'calendario_antigo_importado', null, null, ['importados' => $n, 'ignorados' => $skipped]);
        return ['importados' => $n, 'ignorados' => $skipped];
    });

    // ---- QR de equipamentos/extintores/locais ----
    $r->add('GET', '/qr/{token}', function ($p) {
        $org = Auth::requireOrg();
        $tok = (string)$p['token'];
        if (!preg_match('/^[a-f0-9]{32}$/', $tok)) {
            throw new HttpError(404, 'Código QR não reconhecido.', 'qr_desconhecido');
        }
        // Isolamento: só procura na organização do utilizador. QR de outra organização ⇒ "não encontrado".
        $a = sgi_db()->one('SELECT a.*, t.code AS form_code, t.title AS form_title FROM assets a LEFT JOIN templates t ON t.id = a.form_template_id AND t.org_id = a.org_id
            WHERE a.qr_token = ? AND a.org_id = ?', [$tok, $org]);
        if (!$a) {
            audit($org, Auth::id(), 'qr_nao_reconhecido', null, null, ['token_prefixo' => substr($tok, 0, 6)]);
            throw new HttpError(404, 'Código QR não reconhecido nesta organização.', 'qr_desconhecido');
        }
        return ['asset' => $a];
    });

    $r->add('POST', '/assets/{id}/regenerate-qr', function ($p) {
        $org = Auth::requireOrg();
        Auth::require('assets.manage');
        $n = sgi_db()->update('assets', ['qr_token' => random_token(16)], 'id = ? AND org_id = ?', [(int)$p['id'], $org]);
        if (!$n) {
            throw new HttpError(404, 'Equipamento não encontrado.', 'nao_encontrado');
        }
        audit($org, Auth::id(), 'qr_regenerado', 'assets', (int)$p['id']);
        return ['ok' => true];
    });

    // ---- Participantes de capacitações ----
    $r->add('GET', '/trainings/{id}/participants', function ($p) {
        $org = Auth::requireOrg();
        Auth::require('catalog.view');
        if (!sgi_db()->val('SELECT id FROM trainings WHERE id = ? AND org_id = ?', [(int)$p['id'], $org])) {
            throw new HttpError(404, 'Capacitação não encontrada.', 'nao_encontrado');
        }
        return ['participants' => sgi_db()->all('SELECT tp.*, w.name FROM training_participants tp JOIN workers w ON w.id = tp.worker_id WHERE tp.training_id = ? AND tp.org_id = ? ORDER BY w.name', [(int)$p['id'], $org])];
    });

    $r->add('PUT', '/trainings/{id}/participants', function ($p) {
        $org = Auth::requireOrg();
        Auth::require('catalog.manage');
        $tid = (int)$p['id'];
        if (!sgi_db()->val('SELECT id FROM trainings WHERE id = ? AND org_id = ?', [$tid, $org])) {
            throw new HttpError(404, 'Capacitação não encontrada.', 'nao_encontrado');
        }
        $list = (array)(body()['participants'] ?? []);
        sgi_db()->tx(function () use ($list, $tid, $org) {
            sgi_db()->q('DELETE FROM training_participants WHERE training_id = ? AND org_id = ?', [$tid, $org]);
            foreach ($list as $x) {
                $wid = (int)($x['worker_id'] ?? 0);
                if (!sgi_db()->val('SELECT id FROM workers WHERE id = ? AND org_id = ?', [$wid, $org])) {
                    continue;
                }
                sgi_db()->insert('training_participants', ['org_id' => $org, 'training_id' => $tid, 'worker_id' => $wid, 'attended' => !empty($x['attended']) ? 1 : 0, 'result' => mb_substr((string)($x['result'] ?? ''), 0, 120) ?: null]);
            }
        });
        return ['ok' => true];
    });

    // ---- Valores de indicadores ----
    $r->add('GET', '/indicators/{id}/values', function ($p) {
        $org = Auth::requireOrg();
        Auth::require('reports.view');
        return ['values' => sgi_db()->all('SELECT v.*, u.name AS author FROM indicator_values v LEFT JOIN users u ON u.id = v.created_by WHERE v.indicator_id = ? AND v.org_id = ? ORDER BY period', [(int)$p['id'], $org])];
    });

    $r->add('POST', '/indicators/{id}/values', function ($p) {
        $org = Auth::requireOrg();
        Auth::require('indicators.manage');
        $iid = (int)$p['id'];
        if (!sgi_db()->val('SELECT id FROM indicators WHERE id = ? AND org_id = ?', [$iid, $org])) {
            throw new HttpError(404, 'Indicador não encontrado.', 'nao_encontrado');
        }
        $b = body();
        $period = (string)($b['period'] ?? '');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new HttpError(422, 'Período inválido (use AAAA-MM).', 'periodo_invalido');
        }
        $v = str_replace(',', '.', (string)($b['value'] ?? ''));
        if (!is_numeric($v)) {
            throw new HttpError(422, 'Valor numérico obrigatório (medição real; não estimar).', 'valor_invalido');
        }
        sgi_db()->q('INSERT INTO indicator_values (org_id, indicator_id, period, value, note, created_by) VALUES (?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE value = VALUES(value), note = VALUES(note), created_by = VALUES(created_by)', [$org, $iid, $period, (float)$v, opt_str($b, 'note', 500), Auth::id()]);
        audit($org, Auth::id(), 'indicador_medido', 'indicators', $iid, ['periodo' => $period, 'valor' => (float)$v]);
        return ['ok' => true];
    });

    // ---- Painel por perfil ----
    $r->add('GET', '/dashboard', function () {
        $org = Auth::requireOrg();
        $db = sgi_db();
        $uid = Auth::id();
        $u = Auth::user();
        $today = date('Y-m-d');
        $in30 = date('Y-m-d', strtotime('+30 days'));
        $d = ['role' => $u['role'], 'role_label' => Auth::ROLES[$u['role']]];
        $d['meus_rascunhos'] = (int)$db->val("SELECT COUNT(*) FROM records WHERE org_id = ? AND created_by = ? AND status = 'rascunho'", [$org, $uid]);
        $d['minhas_acoes'] = $db->all("SELECT id, title, due_date, status FROM actions WHERE org_id = ? AND owner_user_id = ? AND status IN ('pendente','em_curso') ORDER BY due_date IS NULL, due_date LIMIT 20", [$org, $uid]);
        $d['leituras_pendentes'] = $db->all("SELECT t.id, t.code, t.title, tv.version_no FROM templates t JOIN template_versions tv ON tv.id = t.current_version_id
            LEFT JOIN read_confirmations rc ON rc.template_version_id = tv.id AND rc.user_id = ?
            WHERE t.org_id = ? AND t.status = 'vigente' AND t.category IN ('Procedimentos','Manuais','Regulamentos','Documentos') AND tv.version_no > 1 AND rc.id IS NULL ORDER BY t.code LIMIT 30", [$uid, $org]);
        $d['proximos_eventos'] = array_slice(Calendar::expand($db->all("SELECT id, title, event_type, start_date, end_date, recurrence, status FROM calendar_events WHERE org_id = ? AND status = 'planeado'", [$org]), $today, $in30), 0, 15);
        $d['notificacoes_nao_lidas'] = (int)$db->val('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$uid]);
        if (Auth::can('approvals.decide')) {
            $rank = Auth::ROLE_RANK[$u['role']] ?? 0;
            $roles = array_keys(array_filter(Auth::ROLE_RANK, function ($x) use ($rank) {
                return $x <= $rank;
            }));
            $in = implode(',', array_fill(0, count($roles), '?'));
            $d['aprovacoes_pendentes'] = (int)$db->val("SELECT COUNT(*) FROM record_approvals WHERE org_id = ? AND status = 'pendente' AND requested_by <> ? AND approver_role IN ($in)", array_merge([$org, $uid], $roles));
        }
        $d['equipamentos_a_verificar'] = $db->all("SELECT id, code, name, next_check_date FROM assets WHERE org_id = ? AND status = 'ativo' AND next_check_date IS NOT NULL AND next_check_date <= ? ORDER BY next_check_date LIMIT 20", [$org, $in30]);
        if (in_array($u['role'], ['admin', 'direcao', 'coordenador', 'supervisor'], true)) {
            $d['nc_abertas'] = (int)$db->val("SELECT COUNT(*) FROM nonconformities WHERE org_id = ? AND status <> 'fechada'", [$org]);
            $d['acoes_atrasadas'] = (int)$db->val("SELECT COUNT(*) FROM actions WHERE org_id = ? AND status IN ('pendente','em_curso') AND due_date < ?", [$org, $today]);
            $d['acoes_abertas'] = (int)$db->val("SELECT COUNT(*) FROM actions WHERE org_id = ? AND status IN ('pendente','em_curso')", [$org]);
            $d['registos_mes'] = (int)$db->val("SELECT COUNT(*) FROM records WHERE org_id = ? AND status = 'final' AND finalized_at >= ?", [$org, date('Y-m-01')]);
            $d['rascunhos_equipa'] = (int)$db->val("SELECT COUNT(*) FROM records WHERE org_id = ? AND status = 'rascunho'", [$org]);
        }
        if (in_array($u['role'], ['admin', 'direcao', 'coordenador'], true)) {
            $d['indicadores'] = [];
            foreach ($db->all('SELECT * FROM indicators WHERE org_id = ? ORDER BY name', [$org]) as $i) {
                $last = $db->one('SELECT period, value FROM indicator_values WHERE indicator_id = ? ORDER BY period DESC LIMIT 1', [$i['id']]);
                $ok = null;
                if ($last && $i['target'] !== null) {
                    $ok = $i['direction'] === 'maior' ? (float)$last['value'] >= (float)$i['target'] : (float)$last['value'] <= (float)$i['target'];
                }
                $d['indicadores'][] = ['id' => (int)$i['id'], 'name' => $i['name'], 'unit' => $i['unit'], 'target' => $i['target'], 'last' => $last, 'cumpre' => $ok];
            }
            $d['capacitacoes_planeadas'] = (int)$db->val("SELECT COUNT(*) FROM trainings WHERE org_id = ? AND status = 'planeada'", [$org]);
            $d['fornecedores_em_avaliacao'] = (int)$db->val("SELECT COUNT(*) FROM suppliers WHERE org_id = ? AND status = 'em_avaliacao'", [$org]);
            $d['documentos_vigentes'] = (int)$db->val("SELECT COUNT(*) FROM templates WHERE org_id = ? AND status = 'vigente'", [$org]);
        }
        return $d;
    });
};
