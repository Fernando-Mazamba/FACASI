<?php
declare(strict_types=1);

/**
 * Registos: Guardar = rascunho no MySQL; Descarregar = finalizar e arquivar (SHA-256, bloqueio).
 * Toda a lógica de bloqueio é aplicada aqui (servidor), independentemente da interface.
 */
class Records
{
    public const MAX_DATA = 4194304;   // 4 MB de dados de rascunho
    public const MAX_FINAL = 8388608;  // 8 MB de HTML final

    public static function load(int $orgId, int $id): array
    {
        $r = sgi_db()->one('SELECT r.*, t.code AS template_code, t.title AS template_title, t.category, t.doc_type,
                t.requires_approval, t.approver_role, t.required_fields, tv.version_no AS template_version_no,
                uc.name AS created_by_name, uf.name AS finalized_by_name
            FROM records r
            JOIN templates t ON t.id = r.template_id
            JOIN template_versions tv ON tv.id = r.template_version_id
            LEFT JOIN users uc ON uc.id = r.created_by
            LEFT JOIN users uf ON uf.id = r.finalized_by
            WHERE r.id = ? AND r.org_id = ?', [$id, $orgId]);
        if (!$r) {
            throw new HttpError(404, 'Registo não encontrado.', 'nao_encontrado');
        }
        return $r;
    }

    public static function assertCanView(array $r): void
    {
        if (!Auth::can('records.view_all') && (int)$r['created_by'] !== Auth::id()) {
            throw new HttpError(404, 'Registo não encontrado.', 'nao_encontrado');
        }
    }

    public static function assertCanEdit(array $r): void
    {
        self::assertCanView($r);
        if ($r['status'] === 'final') {
            throw new HttpError(409, 'Registo final: não pode ser alterado nem eliminado. Use "Corrigir" para criar uma nova versão vinculada.', 'registo_final_bloqueado');
        }
        if ($r['status'] === 'pendente_aprovacao') {
            throw new HttpError(409, 'Registo em aprovação: não pode ser alterado até à decisão do aprovador.', 'registo_em_aprovacao');
        }
        if ((int)$r['created_by'] !== Auth::id() && !Auth::can('records.manage_all')) {
            throw new HttpError(403, 'Só o autor ou o coordenador do SGI podem alterar este rascunho.', 'sem_permissao');
        }
    }

    /** @param mixed $data */
    public static function normalizeData($data): string
    {
        if (!is_array($data)) {
            throw new HttpError(422, 'Dados do formulário inválidos.', 'dados_invalidos');
        }
        $fields = $data['fields'] ?? [];
        if (!is_array($fields)) {
            throw new HttpError(422, 'Dados do formulário inválidos.', 'dados_invalidos');
        }
        $clean = [
            'fields' => array_map(function ($v) {
                return is_scalar($v) || $v === null ? (string)$v : json_encode($v);
            }, $fields),
            'count' => (int)($data['count'] ?? count($fields)),
            'required' => array_values(array_filter(array_map('strval', (array)($data['required'] ?? [])))),
            'labels' => is_array($data['labels'] ?? null) ? array_map('strval', $data['labels']) : [],
            'dom' => isset($data['dom']) && is_string($data['dom']) ? $data['dom'] : null,
        ];
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
        if ($json === false || strlen($json) > self::MAX_DATA) {
            throw new HttpError(413, 'O formulário é demasiado grande (máx. 4 MB). Anexe fotografias como anexos.', 'dados_grandes');
        }
        return $json;
    }

    /** @param array<string,mixed> $in */
    public static function create(int $orgId, array $in): array
    {
        $db = sgi_db();
        $t = $db->one("SELECT * FROM templates WHERE id = ? AND org_id = ? AND status = 'vigente'", [(int)($in['template_id'] ?? 0), $orgId]);
        if (!$t || !$t['current_version_id']) {
            throw new HttpError(404, 'Modelo não encontrado ou não vigente.', 'modelo_nao_encontrado');
        }
        $title = trim((string)($in['title'] ?? '')) ?: ($t['code'] . ' — ' . $t['title']);
        $clientUuid = isset($in['client_uuid']) ? substr(preg_replace('/[^A-Za-z0-9-]/', '', (string)$in['client_uuid']) ?? '', 0, 64) : null;
        if ($clientUuid) {
            $ex = $db->one('SELECT id FROM records WHERE org_id = ? AND client_uuid = ?', [$orgId, $clientUuid]);
            if ($ex) {
                // Mesmo rascunho já sincronizado (sem duplicar).
                return self::publicRecord(self::load($orgId, (int)$ex['id']), true) + ['duplicado' => true];
            }
        }
        $assetId = null;
        if (!empty($in['asset_id'])) {
            $assetId = (int)$db->val('SELECT id FROM assets WHERE id = ? AND org_id = ?', [(int)$in['asset_id'], $orgId]) ?: null;
        }
        $row = [
            'org_id' => $orgId,
            'template_id' => (int)$t['id'],
            'template_version_id' => (int)$t['current_version_id'],
            'title' => mb_substr($title, 0, 255),
            'status' => 'rascunho',
            'data_json' => self::normalizeData($in['data'] ?? ['fields' => []]),
            'lock_version' => 1,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
            'client_uuid' => $clientUuid ?: null,
            'asset_id' => $assetId,
        ];
        $row += self::geo($in);
        $id = $db->insert('records', $row);
        audit($orgId, Auth::id(), 'registo_criado', 'record', $id, ['modelo' => $t['code']]);
        return self::publicRecord(self::load($orgId, $id), true);
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    private static function geo(array $in): array
    {
        $g = $in['geo'] ?? null;
        if (!is_array($g) || !isset($g['lat'], $g['lng'])) {
            return [];
        }
        $lat = (float)$g['lat'];
        $lng = (float)$g['lng'];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return [];
        }
        return ['geo_lat' => $lat, 'geo_lng' => $lng, 'geo_accuracy' => isset($g['accuracy']) ? min(99999999, (float)$g['accuracy']) : null];
    }

    /** Atualiza rascunho com controlo de concorrência otimista (lock_version). */
    public static function update(int $orgId, int $id, array $in): array
    {
        $db = sgi_db();
        return $db->tx(function () use ($db, $orgId, $id, $in) {
            $r = $db->one('SELECT * FROM records WHERE id = ? AND org_id = ? FOR UPDATE', [$id, $orgId]);
            if (!$r) {
                throw new HttpError(404, 'Registo não encontrado.', 'nao_encontrado');
            }
            self::assertCanEdit($r);
            $expected = (int)($in['lock_version'] ?? 0);
            if ($expected !== (int)$r['lock_version']) {
                $cur = self::publicRecord(self::load($orgId, $id), true);
                throw new HttpError(409, 'Conflito: este rascunho foi alterado por outra pessoa ou noutro dispositivo depois da sua cópia. Escolha como resolver.', 'conflito', ['servidor' => $cur]);
            }
            $row = ['lock_version' => (int)$r['lock_version'] + 1, 'updated_by' => Auth::id(), 'updated_at' => now()];
            if (array_key_exists('data', $in)) {
                $row['data_json'] = self::normalizeData($in['data']);
            }
            if (isset($in['title']) && trim((string)$in['title']) !== '') {
                $row['title'] = mb_substr(trim((string)$in['title']), 0, 255);
            }
            $row += self::geo($in);
            $db->update('records', $row, 'id = ? AND lock_version = ?', [$id, $r['lock_version']]);
            audit($orgId, Auth::id(), 'rascunho_guardado', 'record', $id, ['lock_version' => $row['lock_version']]);
            return self::publicRecord(self::load($orgId, $id), true);
        });
    }

    public static function delete(int $orgId, int $id): void
    {
        $db = sgi_db();
        $r = self::load($orgId, $id);
        self::assertCanEdit($r);
        $db->tx(function () use ($db, $id, $orgId) {
            $db->q("DELETE FROM records WHERE id = ? AND org_id = ? AND status = 'rascunho'", [$id, $orgId]);
            $db->q("DELETE FROM attachments WHERE org_id = ? AND entity_type = 'record' AND entity_id = ?", [$orgId, $id]);
        });
        audit($orgId, Auth::id(), 'rascunho_eliminado', 'record', $id);
    }

    /** @return array<int,string> campos obrigatórios por preencher (rótulos) */
    public static function missingRequired(array $r): array
    {
        $data = json_decode((string)$r['data_json'], true) ?: [];
        $fields = (array)($data['fields'] ?? []);
        $labels = (array)($data['labels'] ?? []);
        $req = array_merge((array)($data['required'] ?? []), (array)(json_decode((string)($r['required_fields'] ?? '[]'), true) ?: []));
        $missing = [];
        foreach (array_unique($req) as $k) {
            if (trim(strip_tags((string)($fields[$k] ?? ''))) === '') {
                $missing[] = (string)($labels[$k] ?? $k);
            }
        }
        $any = false;
        foreach ($fields as $v) {
            if (trim(strip_tags((string)$v)) !== '') {
                $any = true;
                break;
            }
        }
        if (!$any && empty($data['dom'])) {
            $missing[] = 'Pelo menos um campo do formulário';
        }
        return $missing;
    }

    /**
     * Descarregar = finalizar. Requer ligação ao servidor (não existe finalização offline).
     * @param array<string,mixed> $in {final_html, lock_version, confirm}
     */
    public static function finalize(int $orgId, int $id, array $in): array
    {
        $db = sgi_db();
        if (empty($in['confirm'])) {
            throw new HttpError(422, 'É necessária a confirmação explícita para finalizar.', 'confirmacao_obrigatoria');
        }
        $html = (string)($in['final_html'] ?? '');
        if ($html === '' || strlen($html) > self::MAX_FINAL) {
            throw new HttpError(422, 'Representação final em falta ou demasiado grande.', 'final_invalido');
        }
        return $db->tx(function () use ($db, $orgId, $id, $in, $html) {
            $r = $db->one('SELECT r.*, t.requires_approval, t.approver_role, t.required_fields FROM records r JOIN templates t ON t.id = r.template_id
                WHERE r.id = ? AND r.org_id = ? FOR UPDATE', [$id, $orgId]);
            if (!$r) {
                throw new HttpError(404, 'Registo não encontrado.', 'nao_encontrado');
            }
            self::assertCanEdit($r);
            if ((int)($in['lock_version'] ?? 0) !== (int)$r['lock_version']) {
                throw new HttpError(409, 'O rascunho mudou desde que o abriu. Guarde/resolva o conflito antes de finalizar.', 'conflito',
                    ['servidor' => self::publicRecord(self::load($orgId, $id), true)]);
            }
            $missing = self::missingRequired($r);
            if ($missing) {
                throw new HttpError(422, 'Preencha os campos obrigatórios antes de finalizar: ' . implode(', ', $missing), 'obrigatorios', ['campos' => $missing]);
            }
            if ((int)$r['requires_approval'] === 1) {
                $already = $db->one("SELECT * FROM record_approvals WHERE record_id = ? AND status = 'aprovado' ORDER BY id DESC LIMIT 1", [$id]);
                if (!$already) {
                    $db->update('records', [
                        'status' => 'pendente_aprovacao', 'pending_final_html' => $html,
                        'lock_version' => (int)$r['lock_version'] + 1, 'updated_at' => now(),
                    ], 'id = ?', [$id]);
                    $aid = $db->insert('record_approvals', [
                        'org_id' => $orgId, 'record_id' => $id, 'approver_role' => $r['approver_role'] ?: 'coordenador',
                        'requested_by' => Auth::id(),
                    ]);
                    audit($orgId, Auth::id(), 'aprovacao_pedida', 'record', $id, ['aprovacao' => $aid]);
                    Notify::approvers($orgId, (string)($r['approver_role'] ?: 'coordenador'), Auth::id(),
                        'Aprovação pendente: ' . $r['title'], 'Um registo aguarda a sua aprovação antes de ser finalizado.', '#/aprovacoes');
                    return self::publicRecord(self::load($orgId, $id), false) + ['mensagem' => 'Enviado para aprovação prévia. Será finalizado e arquivado quando for aprovado.'];
                }
            }
            self::doFinalize($orgId, $r, $html, null);
            return self::publicRecord(self::load($orgId, $id), false) + ['mensagem' => 'Registo finalizado e arquivado no SGI.'];
        });
    }

    /** Gera o ficheiro final (sanitizado, com metadados), calcula SHA-256 e bloqueia. Chamar dentro de transação. */
    public static function doFinalize(int $orgId, array $r, string $html, ?array $approval): void
    {
        $db = sgi_db();
        $org = $db->one('SELECT * FROM organizations WHERE id = ?', [$orgId]);
        $t = $db->one('SELECT t.*, tv.version_no FROM templates t JOIN template_versions tv ON tv.id = ? WHERE t.id = ?', [$r['template_version_id'], $r['template_id']]);
        $author = $db->val('SELECT name FROM users WHERE id = ?', [$r['created_by']]);
        $finisher = Auth::user();
        $ts = now();
        $y = (int)date('Y');
        $mo = (int)date('n');
        $clean = HtmlSanitizer::clean($html);
        $meta = '<div id="sgi-registo-final" style="font:13px/1.45 Arial,sans-serif;border:2px solid #1e3a8a;background:#eff6ff;color:#0f172a;padding:10px 14px;margin:0 0 12px">'
            . '<strong>REGISTO FINAL ' . h(sprintf('REG-%06d', $r['id'])) . ' · versão ' . (int)$r['version_no'] . '</strong><br>'
            . 'Organização: ' . h((string)$org['name']) . ' · Modelo: ' . h((string)$t['code']) . ' — ' . h((string)$t['title']) . ' (versão ' . (int)$t['version_no'] . ')<br>'
            . 'Elaborado por: ' . h((string)$author) . ' · Finalizado por: ' . h((string)$finisher['name']) . ' em ' . h($ts)
            . ($approval ? '<br>Aprovado por: ' . h((string)$approval['by']) . ' em ' . h((string)$approval['at']) . ($approval['comment'] ? ' — «' . h((string)$approval['comment']) . '»' : '') : '')
            . ((int)$r['version_no'] > 1 ? '<br>Correção do registo ' . h(sprintf('REG-%06d', $r['parent_record_id'])) . '. Motivo: ' . h((string)$r['correction_reason']) : '')
            . self::attachmentsMeta($orgId, (int)$r['id'])
            . '<br><em>Integridade: o SHA-256 deste ficheiro foi registado no SGI no momento da finalização. '
            . 'Não é uma assinatura digital certificada.</em></div>';
        $final = preg_replace('/<body([^>]*)>/i', '<body$1>' . $meta, $clean, 1, $cnt);
        if (!$cnt) {
            $final = $meta . $clean;
        }
        $cat = safe_name((string)$t['category'], 50);
        $type = safe_name($t['code'] . '-' . $t['doc_type'], 70);
        $rel = sprintf('%s/registos/%s/%s/%04d/%02d/REG-%06d-v%d.html', Storage::orgPrefix($orgId), $cat, $type, $y, $mo, $r['id'], $r['version_no']);
        if (Storage::exists($rel)) {
            throw new HttpError(409, 'Já existe um ficheiro final para este registo.', 'final_existente');
        }
        Storage::put($rel, (string)$final);
        $hash = hash_file('sha256', Storage::abs($rel));
        $db->update('records', [
            'status' => 'final', 'final_path' => $rel, 'final_sha256' => $hash, 'final_size' => filesize(Storage::abs($rel)),
            'pending_final_html' => null, 'archive_category' => (string)$t['category'], 'archive_type' => $t['code'] . ' — ' . $t['doc_type'],
            'archive_year' => $y, 'archive_month' => $mo, 'finalized_by' => Auth::id(), 'finalized_at' => $ts,
            'lock_version' => (int)$r['lock_version'] + 1, 'updated_at' => $ts,
        ], 'id = ?', [$r['id']]);
        audit($orgId, Auth::id(), 'registo_finalizado', 'record', (int)$r['id'], ['sha256' => $hash, 'ficheiro' => $rel]);
    }

    /** Lista de anexos (com SHA-256) incluída na representação final. */
    private static function attachmentsMeta(int $orgId, int $recordId): string
    {
        $rows = sgi_db()->all("SELECT id, kind, original_name, sha256, geo_lat, geo_lng, created_at FROM attachments WHERE org_id = ? AND entity_type = 'record' AND entity_id = ? ORDER BY id", [$orgId, $recordId]);
        if (!$rows) {
            return '';
        }
        $kinds = ['foto' => 'Fotografia', 'assinatura' => 'Assinatura no ecrã (conformidade visual)', 'ficheiro' => 'Ficheiro'];
        $li = '';
        foreach ($rows as $a) {
            $li .= '<li>' . h($kinds[$a['kind']] ?? $a['kind']) . ' #' . (int)$a['id'] . ' — ' . h((string)$a['original_name']) . ' — ' . h((string)$a['created_at'])
                . ($a['geo_lat'] !== null ? ' — GPS ' . h(sprintf('%.5f, %.5f', $a['geo_lat'], $a['geo_lng'])) : '')
                . '<br><code style="font-size:11px">SHA-256 ' . h((string)$a['sha256']) . '</code></li>';
        }
        return '<br>Anexos/evidências guardados no SGI:<ul style="margin:4px 0 0 18px;padding:0">' . $li . '</ul>';
    }

    public static function decideApproval(int $orgId, int $approvalId, bool $approve, string $comment): array
    {
        $db = sgi_db();
        return $db->tx(function () use ($db, $orgId, $approvalId, $approve, $comment) {
            $a = $db->one("SELECT * FROM record_approvals WHERE id = ? AND org_id = ? FOR UPDATE", [$approvalId, $orgId]);
            if (!$a || $a['status'] !== 'pendente') {
                throw new HttpError(404, 'Pedido de aprovação não encontrado ou já decidido.', 'nao_encontrado');
            }
            $me = Auth::user();
            $need = Auth::ROLE_RANK[$a['approver_role']] ?? 3;
            if ((Auth::ROLE_RANK[$me['role']] ?? 0) < $need) {
                throw new HttpError(403, 'Este registo exige aprovação de um perfil superior (' . (Auth::ROLES[$a['approver_role']] ?? $a['approver_role']) . ').', 'sem_permissao');
            }
            if ((int)$a['requested_by'] === Auth::id()) {
                throw new HttpError(403, 'Não pode aprovar um registo que submeteu (segregação de funções).', 'auto_aprovacao');
            }
            $r = $db->one('SELECT * FROM records WHERE id = ? AND org_id = ? FOR UPDATE', [$a['record_id'], $orgId]);
            if (!$r || $r['status'] !== 'pendente_aprovacao') {
                throw new HttpError(409, 'O registo já não está pendente de aprovação.', 'estado_invalido');
            }
            if (!$approve && trim($comment) === '') {
                throw new HttpError(422, 'Indique o motivo da rejeição.', 'motivo_obrigatorio');
            }
            $db->update('record_approvals', [
                'status' => $approve ? 'aprovado' : 'rejeitado', 'decided_by' => Auth::id(), 'decided_at' => now(),
                'comment' => mb_substr($comment, 0, 1000),
            ], 'id = ?', [$approvalId]);
            if ($approve) {
                self::doFinalize($orgId, $r, (string)$r['pending_final_html'], ['by' => $me['name'], 'at' => now(), 'comment' => $comment]);
                Notify::users($orgId, [(int)$r['created_by']], 'Registo aprovado e finalizado', $r['title'], '#/registos/' . $r['id']);
            } else {
                $db->update('records', ['status' => 'rascunho', 'pending_final_html' => null, 'lock_version' => (int)$r['lock_version'] + 1, 'updated_at' => now()], 'id = ?', [$r['id']]);
                Notify::users($orgId, [(int)$r['created_by']], 'Registo devolvido para correção', $r['title'] . ' — ' . $comment, '#/registos/' . $r['id']);
            }
            audit($orgId, Auth::id(), $approve ? 'aprovacao_aprovada' : 'aprovacao_rejeitada', 'record', (int)$r['id'], ['comentario' => $comment]);
            return self::publicRecord(self::load($orgId, (int)$r['id']), false);
        });
    }

    /** Correção de um registo final ⇒ nova versão vinculada (rascunho), mantendo o original intacto. */
    public static function correct(int $orgId, int $id, string $reason): array
    {
        $db = sgi_db();
        $r = self::load($orgId, $id);
        self::assertCanView($r);
        if ($r['status'] !== 'final') {
            throw new HttpError(409, 'Só registos finais podem ser corrigidos por nova versão.', 'estado_invalido');
        }
        if (trim($reason) === '') {
            throw new HttpError(422, 'Indique o motivo da correção.', 'motivo_obrigatorio');
        }
        $open = $db->val("SELECT id FROM records WHERE parent_record_id = ? AND status <> 'final'", [$id]);
        if ($open) {
            throw new HttpError(409, 'Já existe uma correção em curso para este registo (REG-' . sprintf('%06d', $open) . ').', 'correcao_existente', ['id' => (int)$open]);
        }
        $nid = $db->insert('records', [
            'org_id' => $orgId, 'template_id' => $r['template_id'], 'template_version_id' => $r['template_version_id'],
            'title' => $r['title'], 'status' => 'rascunho', 'data_json' => $r['data_json'], 'lock_version' => 1,
            'parent_record_id' => $id, 'version_no' => (int)$r['version_no'] + 1,
            'correction_reason' => mb_substr(trim($reason), 0, 500), 'created_by' => Auth::id(), 'updated_by' => Auth::id(),
            'asset_id' => $r['asset_id'] ?? null,
        ]);
        audit($orgId, Auth::id(), 'correcao_criada', 'record', $nid, ['original' => $id, 'motivo' => $reason]);
        return self::publicRecord(self::load($orgId, $nid), true);
    }

    public static function verify(int $orgId, array $r): array
    {
        if ($r['status'] !== 'final' || !$r['final_path']) {
            return ['ok' => false, 'motivo' => 'Registo não finalizado'];
        }
        Storage::assertOrg((string)$r['final_path'], $orgId);
        $abs = Storage::abs((string)$r['final_path']);
        if (!is_file($abs)) {
            return ['ok' => false, 'motivo' => 'Ficheiro final em falta no armazenamento'];
        }
        $h = hash_file('sha256', $abs);
        return ['ok' => hash_equals((string)$r['final_sha256'], $h), 'sha256_registado' => $r['final_sha256'], 'sha256_atual' => $h];
    }

    public static function publicRecord(array $r, bool $withData): array
    {
        $out = [
            'id' => (int)$r['id'],
            'ref' => sprintf('REG-%06d', $r['id']),
            'title' => $r['title'],
            'status' => $r['status'],
            'template_id' => (int)$r['template_id'],
            'template_code' => $r['template_code'] ?? null,
            'template_title' => $r['template_title'] ?? null,
            'template_version_id' => (int)$r['template_version_id'],
            'template_version_no' => isset($r['template_version_no']) ? (int)$r['template_version_no'] : null,
            'category' => $r['category'] ?? null,
            'lock_version' => (int)$r['lock_version'],
            'version_no' => (int)$r['version_no'],
            'parent_record_id' => $r['parent_record_id'] !== null ? (int)$r['parent_record_id'] : null,
            'correction_reason' => $r['correction_reason'],
            'requires_approval' => isset($r['requires_approval']) ? (bool)$r['requires_approval'] : null,
            'final_sha256' => $r['final_sha256'],
            'final_size' => $r['final_size'] !== null ? (int)$r['final_size'] : null,
            'archive' => $r['status'] === 'final' ? [
                'categoria' => $r['archive_category'], 'tipo' => $r['archive_type'],
                'ano' => (int)$r['archive_year'], 'mes' => (int)$r['archive_month'],
            ] : null,
            'created_by' => (int)$r['created_by'],
            'created_by_name' => $r['created_by_name'] ?? null,
            'finalized_by_name' => $r['finalized_by_name'] ?? null,
            'created_at' => $r['created_at'],
            'updated_at' => $r['updated_at'],
            'finalized_at' => $r['finalized_at'],
            'client_uuid' => $r['client_uuid'] ?? null,
            'asset_id' => isset($r['asset_id']) && $r['asset_id'] !== null ? (int)$r['asset_id'] : null,
            'geo' => isset($r['geo_lat']) && $r['geo_lat'] !== null ? ['lat' => (float)$r['geo_lat'], 'lng' => (float)$r['geo_lng'], 'accuracy' => $r['geo_accuracy'] !== null ? (float)$r['geo_accuracy'] : null] : null,
        ];
        if ($withData) {
            $out['data'] = json_decode((string)$r['data_json'], true);
        }
        return $out;
    }
}
