<?php
declare(strict_types=1);

const ATT_TYPES = ['record', 'nc', 'action', 'asset', 'training', 'event'];
const ATT_MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
const ATT_MAX = 10485760;

/** Verifica que a entidade pertence à organização e pode receber/perder anexos. */
function attachment_entity_check(int $org, string $type, int $id, bool $forWrite): void
{
    $db = sgi_db();
    $map = ['nc' => 'nonconformities', 'action' => 'actions', 'asset' => 'assets', 'training' => 'trainings', 'event' => 'calendar_events'];
    if ($type === 'record') {
        $rec = Records::load($org, $id);
        if ($forWrite) {
            Records::assertCanEdit($rec);
        } else {
            Records::assertCanView($rec);
        }
        return;
    }
    if (!isset($map[$type])) {
        throw new HttpError(422, 'Tipo de entidade inválido.', 'entidade');
    }
    if (!$db->val('SELECT id FROM `' . $map[$type] . '` WHERE id = ? AND org_id = ?', [$id, $org])) {
        throw new HttpError(404, 'Elemento não encontrado.', 'nao_encontrado');
    }
    if ($forWrite) {
        $perm = ['nc' => 'nc.manage', 'action' => 'nc.manage', 'asset' => 'assets.manage', 'training' => 'catalog.manage', 'event' => 'calendar.manage'][$type];
        // operários podem anexar evidências a ações/NC que lhes estão atribuídas
        if (!Auth::can($perm)) {
            $owner = in_array($type, ['nc', 'action'], true) ? $db->val('SELECT owner_user_id FROM `' . $map[$type] . '` WHERE id = ?', [$id]) : null;
            if ((int)$owner !== Auth::id()) {
                throw new HttpError(403, 'Não tem permissão para anexar ficheiros a este elemento.', 'sem_permissao');
            }
        }
    }
}

return function (Router $r) {
    $r->add('POST', '/attachments', function () {
        $org = Auth::requireOrg();
        $b = body();
        $type = (string)($b['entity_type'] ?? '');
        $eid = (int)($b['entity_id'] ?? 0);
        if (!in_array($type, ATT_TYPES, true) || $eid <= 0) {
            throw new HttpError(422, 'Indique a que elemento pertence o anexo.', 'entidade');
        }
        attachment_entity_check($org, $type, $eid, true);
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
            throw new HttpError(422, $err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE ? 'Ficheiro maior do que o permitido pelo servidor.' : 'Nenhum ficheiro recebido.', 'sem_ficheiro');
        }
        if ($f['size'] > ATT_MAX) {
            throw new HttpError(413, 'Ficheiro demasiado grande (máx. 10 MB).', 'ficheiro_grande');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
        if (!isset(ATT_MIMES[$mime])) {
            throw new HttpError(415, 'Tipo de ficheiro não permitido. Use fotografia (JPEG/PNG/WebP) ou PDF.', 'tipo_invalido');
        }
        $content = (string)file_get_contents($f['tmp_name']);
        $kind = in_array($b['kind'] ?? '', ['foto', 'assinatura', 'ficheiro'], true) ? (string)$b['kind'] : (strpos($mime, 'image/') === 0 ? 'foto' : 'ficheiro');
        if (strpos($mime, 'image/') === 0) {
            $info = @getimagesizefromstring($content);
            if (!$info || $info[0] < 1 || $info[0] > 12000 || $info[1] > 12000) {
                throw new HttpError(415, 'A imagem é inválida ou está corrompida.', 'imagem_invalida');
            }
            // Recodificação JPEG: remove metadados EXIF (privacidade) e normaliza o tamanho.
            if ($mime === 'image/jpeg' && function_exists('imagecreatefromstring') && $info[0] * $info[1] <= 40000000) {
                $img = @imagecreatefromstring($content);
                if ($img) {
                    $w = imagesx($img);
                    $hgt = imagesy($img);
                    $max = 2560;
                    if ($w > $max || $hgt > $max) {
                        $scale = $max / max($w, $hgt);
                        $dst = imagecreatetruecolor((int)($w * $scale), (int)($hgt * $scale));
                        imagecopyresampled($dst, $img, 0, 0, 0, 0, (int)($w * $scale), (int)($hgt * $scale), $w, $hgt);
                        imagedestroy($img);
                        $img = $dst;
                    }
                    ob_start();
                    imagejpeg($img, null, 85);
                    $content = (string)ob_get_clean();
                    imagedestroy($img);
                }
            }
        } elseif (strncmp($content, '%PDF-', 5) !== 0) {
            throw new HttpError(415, 'O PDF é inválido.', 'pdf_invalido');
        }
        $sha = hash('sha256', $content);
        $rel = sprintf('%s/anexos/%s/%d/%s-%s.%s', Storage::orgPrefix($org), $type, $eid, date('Ymd-His'), substr($sha, 0, 12), ATT_MIMES[$mime]);
        Storage::put($rel, $content);
        $orig = mb_substr(basename((string)$f['name']), 0, 200) ?: ('anexo.' . ATT_MIMES[$mime]);
        $row = [
            'org_id' => $org, 'entity_type' => $type, 'entity_id' => $eid, 'kind' => $kind,
            'original_name' => $orig, 'mime' => $mime, 'size_bytes' => strlen($content), 'sha256' => $sha,
            'file_path' => $rel, 'caption' => opt_str($b, 'caption', 500), 'created_by' => Auth::id(),
        ];
        if (isset($b['lat'], $b['lng']) && is_numeric($b['lat']) && is_numeric($b['lng']) && abs((float)$b['lat']) <= 90 && abs((float)$b['lng']) <= 180) {
            $row['geo_lat'] = (float)$b['lat'];
            $row['geo_lng'] = (float)$b['lng'];
        }
        $id = sgi_db()->insert('attachments', $row);
        audit($org, Auth::id(), 'anexo_adicionado', $type, $eid, ['anexo' => $id, 'sha256' => $sha]);
        return ['id' => $id, 'sha256' => $sha, 'size' => strlen($content), 'mime' => $mime, 'kind' => $kind, '__status' => 201];
    });

    $r->add('GET', '/attachments', function () {
        $org = Auth::requireOrg();
        $type = qstr('entity_type');
        $eid = qint('entity_id');
        attachment_entity_check($org, $type, $eid, false);
        $rows = sgi_db()->all('SELECT a.id, a.kind, a.original_name, a.mime, a.size_bytes, a.sha256, a.caption, a.created_at, a.geo_lat, a.geo_lng, u.name AS author
            FROM attachments a LEFT JOIN users u ON u.id = a.created_by WHERE a.org_id = ? AND a.entity_type = ? AND a.entity_id = ? ORDER BY a.id', [$org, $type, $eid]);
        return ['attachments' => $rows];
    });

    $r->add('GET', '/attachments/{id}/file', function ($p) {
        $org = Auth::requireOrg();
        $a = sgi_db()->one('SELECT * FROM attachments WHERE id = ? AND org_id = ?', [(int)$p['id'], $org]);
        if (!$a) {
            throw new HttpError(404, 'Anexo não encontrado.', 'nao_encontrado');
        }
        attachment_entity_check($org, (string)$a['entity_type'], (int)$a['entity_id'], false);
        Storage::assertOrg((string)$a['file_path'], $org);
        Storage::send((string)$a['file_path'], (string)$a['mime'], (string)$a['original_name'], qstr('download') === '1');
        return null;
    });

    $r->add('DELETE', '/attachments/{id}', function ($p) {
        $org = Auth::requireOrg();
        $a = sgi_db()->one('SELECT * FROM attachments WHERE id = ? AND org_id = ?', [(int)$p['id'], $org]);
        if (!$a) {
            throw new HttpError(404, 'Anexo não encontrado.', 'nao_encontrado');
        }
        attachment_entity_check($org, (string)$a['entity_type'], (int)$a['entity_id'], true);
        if ((int)$a['created_by'] !== Auth::id() && !Auth::can('records.manage_all')) {
            throw new HttpError(403, 'Só o autor do anexo ou o coordenador o podem remover.', 'sem_permissao');
        }
        sgi_db()->q('DELETE FROM attachments WHERE id = ?', [$a['id']]);
        @unlink(Storage::abs((string)$a['file_path']));
        audit($org, Auth::id(), 'anexo_removido', (string)$a['entity_type'], (int)$a['entity_id'], ['anexo' => (int)$a['id']]);
        return ['ok' => true];
    });
};
