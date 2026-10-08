<?php
declare(strict_types=1);

/** @param array<int,array<string,mixed>> $rows @param array<string,string> $cols */
function report_output(string $title, string $subtitle, array $cols, array $rows, string $format, string $slug): ?array
{
    if ($format === 'json') {
        return ['title' => $title, 'subtitle' => $subtitle, 'columns' => $cols, 'rows' => $rows];
    }
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $slug . '-' . date('Ymd') . '.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM para Excel
        fputcsv($out, array_values($cols), ';');
        foreach ($rows as $r) {
            $line = [];
            foreach (array_keys($cols) as $k) {
                $v = $r[$k] ?? '';
                $v = is_array($v) ? implode(', ', $v) : (string)$v;
                // Evita injeção de fórmulas em folhas de cálculo
                if ($v !== '' && strpos('=+-@', $v[0]) !== false && !is_numeric($v)) {
                    $v = "'" . $v;
                }
                $line[] = $v;
            }
            fputcsv($out, $line, ';');
        }
        fclose($out);
        return null;
    }
    $org = sgi_db()->one('SELECT name FROM organizations WHERE id = ?', [Auth::orgId()]);
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:");
    echo '<!DOCTYPE html><html lang="pt-AO"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($title) . '</title>';
    echo '<style>body{font:13px/1.45 Arial,Helvetica,sans-serif;color:#0f172a;margin:24px}h1{font-size:20px;margin:0 0 4px}p.s{color:#475569;margin:0 0 14px}'
        . '.w{overflow-x:auto}table{border-collapse:collapse;width:100%}th,td{border:1px solid #cbd5e1;padding:5px 7px;text-align:left;vertical-align:top}'
        . 'th{background:#1e3a8a;color:#fff}tr:nth-child(even) td{background:#f1f5f9}footer{margin-top:16px;color:#64748b;font-size:11px}'
        . '@media print{body{margin:8mm}th{background:#ddd!important;color:#000!important;-webkit-print-color-adjust:exact}}</style></head><body>';
    echo '<h1>' . h($title) . '</h1><p class="s">' . h((string)$org['name']) . ' · ' . h($subtitle) . ' · Gerado em ' . h(now()) . ' por ' . h((string)Auth::user()['name']) . '</p>';
    echo '<div class="w"><table><thead><tr>';
    foreach ($cols as $c) {
        echo '<th>' . h($c) . '</th>';
    }
    echo '</tr></thead><tbody>';
    if (!$rows) {
        echo '<tr><td colspan="' . count($cols) . '">Sem dados no período selecionado.</td></tr>';
    }
    foreach ($rows as $r) {
        echo '<tr>';
        foreach (array_keys($cols) as $k) {
            $v = $r[$k] ?? '';
            echo '<td>' . h(is_array($v) ? implode(', ', $v) : (string)$v) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div><footer>Relatório gerado pelo SGI a partir dos dados registados no sistema. Valores em falta não são estimados.</footer></body></html>';
    return null;
}

return function (Router $r) {
    $r->add('GET', '/reports/{type}', function ($p) {
        $org = Auth::requireOrg();
        Auth::require('reports.view');
        $db = sgi_db();
        $fmt = in_array(qstr('format'), ['csv', 'json', 'html'], true) ? qstr('format') : 'html';
        $from = qstr('from') !== '' ? (string)opt_date(['f' => qstr('from')], 'f') : date('Y-01-01');
        $to = qstr('to') !== '' ? (string)opt_date(['t' => qstr('to')], 't') : date('Y-12-31');
        $period = "Período: $from a $to";
        switch ($p['type']) {
            case 'indicadores':
                $rows = $db->all('SELECT i.name, i.standard, i.unit, i.target, i.direction, v.period, v.value, v.note
                    FROM indicators i LEFT JOIN indicator_values v ON v.indicator_id = i.id AND v.period BETWEEN ? AND ?
                    WHERE i.org_id = ? ORDER BY i.name, v.period', [substr($from, 0, 7), substr($to, 0, 7), $org]);
                foreach ($rows as &$x) {
                    $x['cumpre'] = ($x['value'] === null || $x['target'] === null) ? 'sem dados' : ((($x['direction'] === 'maior') ? (float)$x['value'] >= (float)$x['target'] : (float)$x['value'] <= (float)$x['target']) ? 'sim' : 'não');
                }
                return report_output('Relatório de indicadores', $period, ['name' => 'Indicador', 'standard' => 'Norma', 'period' => 'Período', 'value' => 'Valor', 'unit' => 'Unidade', 'target' => 'Meta', 'cumpre' => 'Cumpre', 'note' => 'Nota'], $rows, $fmt, 'indicadores');
            case 'nc-acoes':
                $rows = $db->all("SELECT n.code, n.title, n.nc_type, n.standard, n.severity, n.status, n.detected_at, n.due_date, u.name AS owner,
                        (SELECT COUNT(*) FROM actions a WHERE a.nc_id = n.id) AS n_acoes,
                        (SELECT COUNT(*) FROM actions a WHERE a.nc_id = n.id AND a.status IN ('pendente','em_curso') AND a.due_date < CURDATE()) AS acoes_atrasadas,
                        (SELECT GROUP_CONCAT(CONCAT(a.title, ' [', a.status, ']') SEPARATOR ' | ') FROM actions a WHERE a.nc_id = n.id) AS acoes
                    FROM nonconformities n LEFT JOIN users u ON u.id = n.owner_user_id
                    WHERE n.org_id = ? AND (n.detected_at BETWEEN ? AND ? OR n.detected_at IS NULL) ORDER BY n.detected_at DESC, n.id DESC", [$org, $from, $to]);
                return report_output('Não conformidades e ações', $period, ['code' => 'Código', 'title' => 'Título', 'nc_type' => 'Tipo', 'standard' => 'Norma', 'severity' => 'Gravidade', 'status' => 'Estado', 'detected_at' => 'Deteção', 'due_date' => 'Prazo', 'owner' => 'Responsável', 'n_acoes' => 'N.º ações', 'acoes_atrasadas' => 'Ações atrasadas', 'acoes' => 'Ações'], $rows, $fmt, 'nc-acoes');
            case 'capacitacao':
                $rows = $db->all("SELECT t.title, t.topic, t.provider, t.planned_date, t.hours, t.status,
                        (SELECT COUNT(*) FROM training_participants tp WHERE tp.training_id = t.id) AS inscritos,
                        (SELECT COUNT(*) FROM training_participants tp WHERE tp.training_id = t.id AND tp.attended = 1) AS presentes, t.efficacy_note
                    FROM trainings t WHERE t.org_id = ? AND (t.planned_date BETWEEN ? AND ? OR t.planned_date IS NULL) ORDER BY t.planned_date", [$org, $from, $to]);
                return report_output('Relatório de capacitação', $period, ['title' => 'Capacitação', 'topic' => 'Tema', 'provider' => 'Formador/entidade', 'planned_date' => 'Data', 'hours' => 'Horas', 'status' => 'Estado', 'inscritos' => 'Inscritos', 'presentes' => 'Presentes', 'efficacy_note' => 'Eficácia'], $rows, $fmt, 'capacitacao');
            case 'fornecedores':
                $rows = $db->all('SELECT name, tax_id, category, contact, email, phone, status, last_evaluation, score FROM suppliers WHERE org_id = ? ORDER BY name', [$org]);
                return report_output('Relatório de fornecedores', 'Situação atual', ['name' => 'Fornecedor', 'tax_id' => 'NIF', 'category' => 'Categoria', 'contact' => 'Contacto', 'email' => 'Correio', 'phone' => 'Telefone', 'status' => 'Estado', 'last_evaluation' => 'Última avaliação', 'score' => 'Pontuação'], $rows, $fmt, 'fornecedores');
            case 'equipamentos':
                $rows = $db->all("SELECT a.kind, a.code, a.name, a.location, a.brand, a.serial_no, a.next_check_date, a.status,
                        CASE WHEN a.next_check_date IS NULL THEN 'sem data' WHEN a.next_check_date < CURDATE() THEN 'vencida' WHEN a.next_check_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'próxima' ELSE 'em dia' END AS situacao,
                        (SELECT COUNT(*) FROM records r WHERE r.asset_id = a.id AND r.status = 'final') AS registos
                    FROM assets a WHERE a.org_id = ? ORDER BY a.kind, a.code", [$org]);
                return report_output('Equipamentos, extintores e locais', 'Situação atual', ['kind' => 'Tipo', 'code' => 'Código', 'name' => 'Nome', 'location' => 'Localização', 'brand' => 'Marca', 'serial_no' => 'N.º série', 'next_check_date' => 'Próxima verificação', 'situacao' => 'Situação', 'registos' => 'Registos finais', 'status' => 'Estado'], $rows, $fmt, 'equipamentos');
            case 'cobertura':
                $rows = Coverage::matrix($org);
                $labels = ['pendente' => 'PENDENTE', 'nao_aplicavel' => 'Não aplicável (justificado)', 'documentado_sem_evidencia' => 'Documentado (sem evidência no SGI)', 'documentado_com_evidencia' => 'Documentado com evidência'];
                foreach ($rows as &$x) {
                    $x['normas'] = implode(', ', array_map(function ($n) {
                        return 'ISO ' . $n;
                    }, $x['normas']));
                    $x['estado_txt'] = $labels[$x['estado']];
                }
                return report_output('Matriz de cobertura normativa (proposta para validação)', 'ISO 9001:2015 · ISO 14001:2015 · ISO 45001:2018 — registos dos últimos 12 meses', ['clausula' => 'Cláusula', 'titulo' => 'Requisito', 'normas' => 'Normas', 'documentos' => 'Documentos vigentes', 'registos_12m' => 'Registos 12m', 'estado_txt' => 'Estado', 'nota' => 'Observação'], $rows, $fmt, 'cobertura');
            case 'registos':
                $rows = $db->all("SELECT CONCAT('REG-', LPAD(r.id, 6, '0')) AS ref, r.title, t.code, r.status, r.version_no, r.created_at, r.finalized_at, u.name AS autor, r.final_sha256
                    FROM records r JOIN templates t ON t.id = r.template_id LEFT JOIN users u ON u.id = r.created_by
                    WHERE r.org_id = ? AND DATE(r.created_at) BETWEEN ? AND ? ORDER BY r.id DESC", [$org, $from, $to]);
                return report_output('Registos do SGI', $period, ['ref' => 'Referência', 'title' => 'Título', 'code' => 'Modelo', 'status' => 'Estado', 'version_no' => 'Versão', 'autor' => 'Autor', 'created_at' => 'Criado', 'finalized_at' => 'Finalizado', 'final_sha256' => 'SHA-256'], $rows, $fmt, 'registos');
        }
        throw new HttpError(404, 'Relatório desconhecido.', 'relatorio');
    });
};
