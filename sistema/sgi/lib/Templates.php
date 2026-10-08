<?php
declare(strict_types=1);

/** Modelos/documentos controlados por organização, versões vigentes e biblioteca neutralizada. */
class Templates
{
    public static function libraryDir(): string
    {
        return SGI_ROOT . '/modelos/facasi';
    }

    /** @return array<string,mixed>|null */
    public static function catalog(): ?array
    {
        $f = self::libraryDir() . '/catalogo.json';
        return is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    }

    /**
     * Importa os documentos originais da FACASI para a organização indicada (a própria FACASI).
     * Os ficheiros de apoio (imagens/PDF) são copiados para storage/org_<id>/ativos/.
     */
    public static function importOriginals(int $orgId, ?int $userId): int
    {
        $cat = self::catalog();
        if (!$cat) {
            throw new RuntimeException('Catálogo de modelos da FACASI não encontrado (modelos/facasi/catalogo.json).');
        }
        $db = sgi_db();
        $n = 0;
        foreach ($cat['modelos'] as $m) {
            if ($db->val('SELECT id FROM templates WHERE org_id = ? AND code = ?', [$orgId, $m['code']])) {
                continue;
            }
            $html = (string)file_get_contents(self::libraryDir() . '/' . $m['file']);
            self::copyAssetsFor($orgId, $html);
            self::createTemplate($orgId, $m, $html, true, $userId, 'Versão inicial importada do SGI FACASI', null);
            $n++;
        }
        $logo = self::libraryDir() . '/logo.png';
        if (is_file($logo)) {
            $rel = Storage::orgPrefix($orgId) . '/ativos/logo.png';
            Storage::put($rel, (string)file_get_contents($logo));
            $db->update('organizations', ['logo_path' => $rel], 'id = ? AND logo_path IS NULL', [$orgId]);
        }
        return $n;
    }

    private static function copyAssetsFor(int $orgId, string $html): void
    {
        if (!preg_match_all('/\{\{ORG_ASSET:([A-Za-z0-9._-]+)\}\}/', $html, $mm)) {
            return;
        }
        foreach (array_unique($mm[1]) as $name) {
            $src = self::libraryDir() . '/ativos/' . $name;
            $rel = Storage::orgPrefix($orgId) . '/ativos/' . $name;
            if (is_file($src) && !Storage::exists($rel)) {
                ensure_dir(dirname(Storage::abs($rel)));
                copy($src, Storage::abs($rel));
            }
        }
    }

    /** @param array<string,mixed> $m */
    public static function createTemplate(int $orgId, array $m, string $html, bool $trusted, ?int $userId, string $reason, ?int $libraryId): int
    {
        $db = sgi_db();
        return $db->tx(function () use ($db, $orgId, $m, $html, $trusted, $userId, $reason, $libraryId) {
            $tid = $db->insert('templates', [
                'org_id' => $orgId,
                'code' => $m['code'],
                'title' => mb_substr((string)$m['title'], 0, 255),
                'category' => $m['category'],
                'doc_type' => $m['doc_type'],
                'is_form' => !empty($m['is_form']) ? 1 : 0,
                'source_file' => basename((string)($m['file'] ?? '')) ?: null,
                'library_id' => $libraryId,
                'requires_approval' => 0,
            ]);
            $vid = self::storeVersion($orgId, $tid, 1, $html, $trusted, $userId, $reason);
            $db->update('templates', ['current_version_id' => $vid], 'id = ?', [$tid]);
            return $tid;
        });
    }

    public static function storeVersion(int $orgId, int $tid, int $no, string $html, bool $trusted, ?int $userId, string $reason): int
    {
        $rel = Storage::orgPrefix($orgId) . '/modelos/' . $tid . '/v' . $no . '.html';
        Storage::put($rel, $html);
        return sgi_db()->insert('template_versions', [
            'org_id' => $orgId,
            'template_id' => $tid,
            'version_no' => $no,
            'file_path' => $rel,
            'sha256' => hash('sha256', $html),
            'reason' => mb_substr($reason, 0, 500),
            'trusted' => $trusted ? 1 : 0,
            'status' => 'vigente',
            'created_by' => $userId,
        ]);
    }

    /** Nova versão de um documento controlado (o conteúdo enviado é sempre sanitizado). */
    public static function newVersion(int $orgId, int $tid, string $html, string $reason, int $userId): array
    {
        $db = sgi_db();
        $t = $db->one('SELECT * FROM templates WHERE id = ? AND org_id = ?', [$tid, $orgId]);
        if (!$t) {
            throw new HttpError(404, 'Documento não encontrado.', 'nao_encontrado');
        }
        if (trim($reason) === '') {
            throw new HttpError(422, 'Indique o motivo da nova versão.', 'motivo_obrigatorio');
        }
        $clean = HtmlSanitizer::clean($html);
        if (strlen($clean) < 50) {
            throw new HttpError(422, 'O ficheiro HTML enviado está vazio ou é inválido.', 'html_invalido');
        }
        return $db->tx(function () use ($db, $t, $tid, $orgId, $clean, $reason, $userId) {
            $no = (int)$db->val('SELECT MAX(version_no) FROM template_versions WHERE template_id = ?', [$tid]) + 1;
            $db->update('template_versions', ['status' => 'obsoleta'], 'template_id = ?', [$tid]);
            $vid = self::storeVersion($orgId, $tid, $no, $clean, false, $userId, $reason);
            $db->update('templates', ['current_version_id' => $vid], 'id = ?', [$tid]);
            audit($orgId, $userId, 'documento_nova_versao', 'template', $tid, ['versao' => $no, 'motivo' => $reason]);
            return ['version_id' => $vid, 'version_no' => $no];
        });
    }

    /** Substitui marcadores e devolve o HTML pronto a mostrar. */
    public static function render(string $html, array $org): string
    {
        $base = sgi_base_path();
        $logo = !empty($org['logo_path']) ? $base . '/api.php/org/logo?v=' . substr(md5((string)$org['logo_path']), 0, 8) : $base . '/app/img/icone-sgi.svg';
        $html = str_replace('{{ORG_LOGO}}', h($logo), $html);
        $html = preg_replace_callback('/\{\{ORG_ASSET:([A-Za-z0-9._-]+)\}\}/', function ($m) use ($base) {
            return h($base . '/api.php/org/ativo/' . $m[1]);
        }, $html) ?? $html;
        $html = str_replace('{{ORG_NAME}}', h((string)$org['name']), $html);
        $html = str_replace('{{ORG_NIF}}', h((string)($org['tax_id'] ?: '[NIF — pendente]')), $html);
        return $html;
    }

    // ---------------- Biblioteca neutralizada ----------------

    /** @return array{0:string,1:array<int,string>} [html neutralizado, achados para revisão] */
    public static function neutralize(string $html): array
    {
        $html = preg_replace('/FACASI(?:\s*[-–]\s*Com[ée]rcio Geral e Presta[çc][ãa]o de Servi[çc]os(?:,?\s*Lda\.?)?|\s+Oil\s*(?:&amp;|&)\s*Gas\s+Inspection)?/u', '{{ORG_NAME}}', $html) ?? $html;
        $html = preg_replace('/(NIF\s*:?\s*)\d{9,14}/u', '$1{{ORG_NIF}}', $html) ?? $html;
        $html = preg_replace('/[A-Za-z0-9._%+-]+@facasi\.[a-z.]+/i', '[correio@organizacao]', $html) ?? $html;
        $findings = [];
        $checks = [
            'Referência a ficheiro próprio da FACASI (imagem/PDF)' => '/\{\{ORG_ASSET:/',
            'Endereço de correio eletrónico' => '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[a-z]{2,}/',
            'Número de telefone' => '/\+?244[\s0-9]{7,}/',
            'Localização geográfica específica (Luanda, Cabinda, Soyo…)' => '/\b(Luanda|Cabinda|Soyo|Benguela|Lobito)\b/i',
            'Setor específico (petróleo e gás)' => '/oil\s*(&amp;|&)\s*gas|petr[óo]le/i',
            'Certificado/declaração de certificação' => '/certificad[oa]s?\s+(ISO|pela)|certifica[çc][ãa]o\s+obtida/i',
            'Script com armazenamento local (localStorage)' => '/localStorage/',
        ];
        foreach ($checks as $label => $re) {
            if (preg_match_all($re, $html, $mm)) {
                $findings[] = $label . ' (' . count($mm[0]) . ')';
            }
        }
        return [$html, $findings];
    }

    /** Gera/atualiza a biblioteca neutralizada a partir do catálogo (apenas com autorização expressa da FACASI). */
    public static function buildLibrary(): int
    {
        $cat = self::catalog();
        if (!$cat) {
            return 0;
        }
        $db = sgi_db();
        $dir = sgi_storage() . '/biblioteca';
        Storage::protectDir($dir);
        $n = 0;
        foreach ($cat['modelos'] as $m) {
            if ($m['group'] === 'manual') {
                continue; // páginas do manual são específicas da FACASI
            }
            [$neutral, $findings] = self::neutralize((string)file_get_contents(self::libraryDir() . '/' . $m['file']));
            $rel = 'biblioteca/' . safe_name($m['code']) . '.html';
            Storage::put($rel, $neutral);
            $ex = $db->one('SELECT id, status FROM library_templates WHERE source_file = ?', [$m['file']]);
            $row = [
                'code' => $m['code'], 'title' => mb_substr((string)$m['title'], 0, 255), 'category' => $m['category'],
                'doc_type' => $m['doc_type'], 'neutral_path' => $rel, 'scan_findings' => json_encode($findings, JSON_UNESCAPED_UNICODE),
            ];
            if ($ex) {
                $db->update('library_templates', $row, 'id = ?', [$ex['id']]);
            } else {
                $db->insert('library_templates', $row + ['source_file' => $m['file'], 'status' => 'pendente_revisao']);
                $n++;
            }
        }
        return $n;
    }

    /** Entrega a uma nova organização apenas os modelos aprovados e neutralizados. */
    public static function provisionFromLibrary(int $orgId, array $libraryIds, int $byUser): int
    {
        $db = sgi_db();
        $n = 0;
        foreach ($libraryIds as $lid) {
            $l = $db->one("SELECT * FROM library_templates WHERE id = ? AND status = 'aprovado'", [(int)$lid]);
            if (!$l || $db->val('SELECT id FROM templates WHERE org_id = ? AND code = ?', [$orgId, $l['code']])) {
                continue;
            }
            $html = Storage::get((string)$l['neutral_path']);
            // Garantia final: nenhum identificador da FACASI pode passar.
            if (preg_match('/FACASI|\{\{ORG_ASSET:/i', $html)) {
                continue;
            }
            $fields = preg_match_all('#<(input|select|textarea)\b|class=["\'][^"\']*\beditable\b#i', $html);
            self::createTemplate($orgId, [
                'code' => $l['code'], 'title' => $l['title'], 'category' => $l['category'], 'doc_type' => $l['doc_type'],
                'is_form' => $fields > 0, 'file' => $l['source_file'],
            ], $html, true, $byUser, 'Modelo da biblioteca (neutralizado e aprovado)', (int)$l['id']);
            $n++;
        }
        return $n;
    }
}
