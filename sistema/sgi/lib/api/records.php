<?php
declare(strict_types=1);

return function (Router $r) {
    $r->add('GET', '/records', function () {
        $org = Auth::requireOrg();
        Auth::require('records.create');
        $where = ['r.org_id = ?'];
        $p = [$org];
        if (!Auth::can('records.view_all') || qstr('mine') === '1') {
            $where[] = 'r.created_by = ?';
            $p[] = Auth::id();
        }
        $st = qstr('status');
        if (in_array($st, ['rascunho', 'pendente_aprovacao', 'final'], true)) {
            $where[] = 'r.status = ?';
            $p[] = $st;
        }
        if (qint('template_id')) {
            $where[] = 'r.template_id = ?';
            $p[] = qint('template_id');
        }
        if (qint('asset_id')) {
            $where[] = 'r.asset_id = ?';
            $p[] = qint('asset_id');
        }
        if (qstr('categoria') !== '') {
            $where[] = 'r.archive_category = ?';
            $p[] = qstr('categoria');
        }
        if (qstr('tipo') !== '') {
            $where[] = 'r.archive_type = ?';
            $p[] = qstr('tipo');
        }
        if (qint('ano')) {
            $where[] = 'r.archive_year = ?';
            $p[] = qint('ano');
        }
        if (qint('mes')) {
            $where[] = 'r.archive_month = ?';
            $p[] = qint('mes');
        }
        if (qstr('q') !== '') {
            $where[] = '(r.title LIKE ? OR t.code LIKE ? OR r.id = ?)';
            $q = qstr('q');
            $p[] = "%$q%";
            $p[] = "%$q%";
            $p[] = (int)preg_replace('/\D/', '', $q);
        }
        $limit = max(1, min(200, qint('limit', 50)));
        $offset = max(0, qint('offset'));
        $rows = sgi_db()->all('SELECT r.*, t.code AS template_code, t.title AS template_title, t.category, t.requires_approval, tv.version_no AS template_version_no,
                uc.name AS created_by_name, uf.name AS finalized_by_name
            FROM records r JOIN templates t ON t.id = r.template_id JOIN template_versions tv ON tv.id = r.template_version_id
            LEFT JOIN users uc ON uc.id = r.created_by LEFT JOIN users uf ON uf.id = r.finalized_by
            WHERE ' . implode(' AND ', $where) . " ORDER BY r.updated_at DESC, r.id DESC LIMIT $limit OFFSET $offset", $p);
        $total = (int)sgi_db()->val('SELECT COUNT(*) FROM records r JOIN templates t ON t.id = r.template_id WHERE ' . implode(' AND ', $where), $p);
        return ['records' => array_map(function ($x) {
            return Records::publicRecord($x, false);
        }, $rows), 'total' => $total];
    });

    $r->add('POST', '/records', function () {
        $org = Auth::requireOrg();
        Auth::require('records.create');
        $rec = Records::create($org, body());
        return $rec + ['__status' => empty($rec['duplicado']) ? 201 : 200];
    });

    $r->add('GET', '/records/{id}', function ($p) {
        $org = Auth::requireOrg();
        $rec = Records::load($org, (int)$p['id']);
        Records::assertCanView($rec);
        $out = Records::publicRecord($rec, true);
        $out['can_edit'] = $rec['status'] === 'rascunho' && ((int)$rec['created_by'] === Auth::id() || Auth::can('records.manage_all'));
        $out['approvals'] = sgi_db()->all('SELECT a.id, a.status, a.approver_role, a.comment, a.created_at, a.decided_at, u.name AS decided_by_name, q.name AS requested_by_name
            FROM record_approvals a LEFT JOIN users u ON u.id = a.decided_by LEFT JOIN users q ON q.id = a.requested_by WHERE a.record_id = ? ORDER BY a.id', [$rec['id']]);
        return $out;
    });

    $r->add('PUT', '/records/{id}', function ($p) {
        $org = Auth::requireOrg();
        return Records::update($org, (int)$p['id'], body());
    });

    $r->add('DELETE', '/records/{id}', function ($p) {
        $org = Auth::requireOrg();
        Records::delete($org, (int)$p['id']);
        return ['ok' => true];
    });

    $r->add('POST', '/records/{id}/finalize', function ($p) {
        $org = Auth::requireOrg();
        return Records::finalize($org, (int)$p['id'], body());
    });

    $r->add('POST', '/records/{id}/correct', function ($p) {
        $org = Auth::requireOrg();
        Auth::require('records.create');
        return Records::correct($org, (int)$p['id'], (string)(body()['reason'] ?? '')) + ['__status' => 201];
    });

    $r->add('GET', '/records/{id}/final', function ($p) {
        $org = Auth::requireOrg();
        $rec = Records::load($org, (int)$p['id']);
        Records::assertCanView($rec);
        if ($rec['status'] !== 'final') {
            throw new HttpError(409, 'O registo ainda não foi finalizado.', 'nao_final');
        }
        Storage::assertOrg((string)$rec['final_path'], $org);
        $dl = qstr('download') === '1';
        if ($dl) {
            audit($org, Auth::id(), 'registo_baixado', 'record', (int)$rec['id']);
        }
        Storage::send((string)$rec['final_path'], 'text/html; charset=utf-8', basename((string)$rec['final_path']), $dl);
        return null;
    });

    $r->add('GET', '/records/{id}/verify', function ($p) {
        $org = Auth::requireOrg();
        $rec = Records::load($org, (int)$p['id']);
        Records::assertCanView($rec);
        return Records::verify($org, $rec);
    });

    $r->add('GET', '/records/{id}/versions', function ($p) {
        $org = Auth::requireOrg();
        $rec = Records::load($org, (int)$p['id']);
        Records::assertCanView($rec);
        // sobe até à raiz e desce por todas as correções
        $root = $rec;
        $guard = 0;
        while ($root['parent_record_id'] && $guard++ < 50) {
            $root = sgi_db()->one('SELECT * FROM records WHERE id = ? AND org_id = ?', [$root['parent_record_id'], $org]) ?: $root;
            if (!$root['parent_record_id']) {
                break;
            }
        }
        $chain = [];
        $queue = [(int)$root['id']];
        while ($queue && count($chain) < 100) {
            $id = array_shift($queue);
            $x = sgi_db()->one('SELECT r.id, r.status, r.version_no, r.correction_reason, r.final_sha256, r.finalized_at, r.created_at, r.parent_record_id, u.name AS author
                FROM records r LEFT JOIN users u ON u.id = r.created_by WHERE r.id = ? AND r.org_id = ?', [$id, $org]);
            if ($x) {
                $chain[] = $x;
                foreach (sgi_db()->all('SELECT id FROM records WHERE parent_record_id = ? AND org_id = ?', [$id, $org]) as $c) {
                    $queue[] = (int)$c['id'];
                }
            }
        }
        return ['versions' => $chain];
    });

    $r->add('GET', '/archive', function () {
        $org = Auth::requireOrg();
        Auth::require('records.create');
        $extra = '';
        $p = [$org];
        if (!Auth::can('records.view_all')) {
            $extra = ' AND created_by = ?';
            $p[] = Auth::id();
        }
        $rows = sgi_db()->all("SELECT archive_category AS categoria, archive_type AS tipo, archive_year AS ano, archive_month AS mes, COUNT(*) AS n
            FROM records WHERE org_id = ? AND status = 'final'$extra GROUP BY archive_category, archive_type, archive_year, archive_month
            ORDER BY archive_category, archive_type, archive_year DESC, archive_month DESC", $p);
        return ['tree' => $rows];
    });

    // ---- Aprovações ----
    $r->add('GET', '/approvals', function () {
        $org = Auth::requireOrg();
        Auth::require('approvals.decide');
        $st = qstr('status', 'pendente');
        $rows = sgi_db()->all('SELECT a.*, r.title, r.id AS record_id, t.code AS template_code, u.name AS requested_by_name
            FROM record_approvals a JOIN records r ON r.id = a.record_id JOIN templates t ON t.id = r.template_id
            LEFT JOIN users u ON u.id = a.requested_by WHERE a.org_id = ? AND a.status = ? ORDER BY a.created_at DESC LIMIT 200', [$org, $st]);
        $myRank = Auth::ROLE_RANK[Auth::user()['role']] ?? 0;
        foreach ($rows as &$a) {
            $a['can_decide'] = $a['status'] === 'pendente' && $myRank >= (Auth::ROLE_RANK[$a['approver_role']] ?? 3) && (int)$a['requested_by'] !== Auth::id();
        }
        return ['approvals' => $rows];
    });

    $r->add('POST', '/approvals/{id}', function ($p) {
        $org = Auth::requireOrg();
        Auth::require('approvals.decide');
        $b = body();
        $d = (string)($b['decision'] ?? '');
        if (!in_array($d, ['aprovar', 'rejeitar'], true)) {
            throw new HttpError(422, 'Decisão inválida.', 'decisao');
        }
        return Records::decideApproval($org, (int)$p['id'], $d === 'aprovar', trim((string)($b['comment'] ?? '')));
    });
};
