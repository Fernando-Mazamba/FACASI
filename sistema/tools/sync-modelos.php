<?php
/**
 * Ferramenta LOCAL (não vai para o servidor): copia os HTML documentais da FACASI deste repositório
 * para sgi/modelos/facasi/, gera o catálogo e reescreve referências a imagens/PDF para marcadores:
 *   {{ORG_LOGO}}            → logótipo da organização
 *   {{ORG_ASSET:nome.ext}}  → ficheiro de apoio da organização (copiado para modelos/facasi/ativos/)
 * Remove o polyfill.io (CDN comprometido em 2024).
 *
 * Uso: php sistema/tools/sync-modelos.php
 */
declare(strict_types=1);

$repo = realpath(__DIR__ . '/../..');
$dest = realpath(__DIR__ . '/../sgi') . '/modelos/facasi';
$groups = [
    'PDF-01' => 'documentos',
    'Excel-trinormas' => 'formularios',
    'html' => 'manual',
];
$logoNames = ['Logotipo/logo.png', 'logo/logo.png', 'PDF-01/logo/logo.png', 'Excel-trinormas/logo/logo.png', 'PDF-01/logo/logo.avif'];

function rrmdir(string $d): void
{
    if (!is_dir($d)) {
        return;
    }
    foreach (scandir($d) as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $p = "$d/$f";
        is_dir($p) ? rrmdir($p) : unlink($p);
    }
    rmdir($d);
}

function norm_path(string $p): string
{
    $parts = [];
    foreach (explode('/', str_replace('\\', '/', $p)) as $seg) {
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $seg;
    }
    return implode('/', $parts);
}

function asset_name(string $rel): string
{
    $n = preg_replace('/[^A-Za-z0-9._-]+/', '-', str_replace('/', '__', $rel));
    return trim($n, '-');
}

function category_for(string $code, string $group): array
{
    if ($group === 'manual') {
        return ['Manual do SGI', 'Página do manual'];
    }
    $map = [
        'FORM' => ['Formulários', 'Formulário'],
        'PRO' => ['Procedimentos', 'Procedimento'],
        'PCS' => ['Processos', 'Ficha de processo'],
        'PSC' => ['Processos', 'Ficha de processo'],
        'DOC' => ['Documentos', 'Documento'],
        'MAN' => ['Manuais', 'Manual'],
        'OC' => ['Objetivos', 'Objetivos'],
        'DES' => ['Descrições de cargo', 'Descrição de cargo'],
        'REG' => ['Regulamentos', 'Regulamento'],
        'FICHAS' => ['Fichas', 'Ficha técnica'],
    ];
    $p = strtoupper((string)preg_replace('/[^A-Za-z].*$/', '', $code));
    return $map[$p] ?? ['Documentos', 'Documento'];
}

rrmdir($dest);
mkdir("$dest/ativos", 0755, true);
foreach ($groups as $g) {
    mkdir("$dest/$g", 0755, true);
}

$catalog = [];
$usedCodes = [];
$assetsCopied = [];
$report = ['polyfill_removidos' => 0, 'ativos' => 0, 'refs_em_falta' => []];

foreach ($groups as $srcDir => $group) {
    $files = glob("$repo/$srcDir/*.html");
    sort($files);
    foreach ($files as $file) {
        $base = basename($file);
        if ($group === 'manual' && $base === 'Calendario.html') {
            // Substituído pelo calendário do SGI no servidor (o antigo guardava só no navegador).
            continue;
        }
        $html = file_get_contents($file);
        $srcRel = "$srcDir/$base";

        // polyfill.io: remover
        $html = preg_replace('#<script[^>]+polyfill\.io[^>]*>\s*</script>\s*#i', '', $html, -1, $n);
        $report['polyfill_removidos'] += $n;

        // Reescrever src/href relativos para imagens e PDFs
        $html = preg_replace_callback('#\b(src|href)=(["\'])([^"\']+)\2#i', function ($m) use ($repo, $srcDir, $logoNames, $dest, &$assetsCopied, &$report) {
            $url = html_entity_decode($m[3]);
            if (preg_match('#^(?:[a-z]+:|//|\#|\{\{)#i', $url)) {
                return $m[0];
            }
            $path = rawurldecode(preg_replace('/[?#].*$/', '', $url));
            if (!preg_match('/\.(png|jpe?g|gif|svg|webp|avif|pdf)$/i', $path)) {
                return $m[0];
            }
            $rel = norm_path("$srcDir/$path");
            if (in_array($rel, $logoNames, true)) {
                return $m[1] . '=' . $m[2] . '{{ORG_LOGO}}' . $m[2];
            }
            $abs = "$repo/$rel";
            if (!is_file($abs) && is_file("$abs.png")) {
                // ficheiro do repositório gravado com extensão dupla (ex.: reconhecimento-iso-45001.png.png)
                $rel .= '.png';
                $abs .= '.png';
            }
            if (!is_file($abs)) {
                $report['refs_em_falta'][] = "$srcDir: $url";
                return $m[0];
            }
            if (filesize($abs) > 15 * 1024 * 1024) {
                $report['refs_em_falta'][] = "$srcDir: $url (>15MB, não incluído)";
                return $m[0];
            }
            $name = asset_name($rel);
            if (!isset($assetsCopied[$name])) {
                copy($abs, "$dest/ativos/$name");
                $assetsCopied[$name] = filesize($abs);
                $report['ativos']++;
            }
            return $m[1] . '=' . $m[2] . '{{ORG_ASSET:' . $name . '}}' . $m[2];
        }, $html);

        preg_match('#<title>(.*?)</title>#is', $html, $tm);
        $title = trim(html_entity_decode(strip_tags($tm[1] ?? $base), ENT_QUOTES, 'UTF-8'));
        $title = preg_replace('/\s+/', ' ', $title);

        if ($group === 'manual') {
            $code = 'MSGI-' . strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', pathinfo($base, PATHINFO_FILENAME)));
        } elseif (preg_match('/^([A-Z]+(?:-RRHH)?)[\s-]*(\d+)/i', $base, $cm)) {
            $code = strtoupper($cm[1]) . '-' . str_pad($cm[2], 3, '0', STR_PAD_LEFT);
        } else {
            $code = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', pathinfo($base, PATHINFO_FILENAME)));
        }
        $code = substr(trim($code, '-'), 0, 60);
        $orig = $code;
        $i = 2;
        while (isset($usedCodes[$code])) {
            $code = $orig . '-' . chr(ord('A') + $i - 2);
            $i++;
        }
        $usedCodes[$code] = true;

        // contagem de campos no HTML: só elementos reais (sem <script> nem <style>, onde aparecem seletores e código)
        $semScripts = preg_replace('#<(script|style)\b.*?</\1>#is', '', $html) ?? $html;
        $inputs = preg_match_all('#<(input|select|textarea)\b#i', $semScripts);
        $buttonsInputs = preg_match_all('#<input\b[^>]*\btype=["\']?(button|submit|reset|hidden|file|image)#i', $semScripts);
        $editables = preg_match_all('#<[a-z][a-z0-9]*\b[^>]*(\sclass=["\'][^"\']*\beditable\b|\scontenteditable=["\']?(true|["\'\s>]))#i', $semScripts);
        $fields = max(0, $inputs - $buttonsInputs) + $editables;
        [$cat, $type] = category_for($code, $group);

        file_put_contents("$dest/$group/$base", $html);
        $catalog[] = [
            'code' => $code,
            'title' => $title,
            'category' => $cat,
            'doc_type' => $type,
            'group' => $group,
            'file' => "$group/$base",
            'source' => $srcRel,
            'fields_estimate' => $fields,
            'is_form' => $fields > 0,
            'sha256' => hash('sha256', $html),
        ];
    }
}

copy("$repo/Logotipo/logo.png", "$dest/logo.png");
file_put_contents("$dest/catalogo.json", json_encode([
    'organizacao' => 'FACASI',
    'gerado_em' => date('c'),
    'total' => count($catalog),
    'modelos' => $catalog,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

fwrite(STDOUT, sprintf("Modelos: %d | Ativos: %d (%.1f MB) | polyfill.io removidos: %d\n",
    count($catalog), $report['ativos'], array_sum($assetsCopied) / 1048576, $report['polyfill_removidos']));
if ($report['refs_em_falta']) {
    fwrite(STDOUT, "Referências sem ficheiro no repositório:\n  " . implode("\n  ", array_unique($report['refs_em_falta'])) . "\n");
}
