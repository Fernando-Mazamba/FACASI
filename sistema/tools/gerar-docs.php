<?php
/**
 * Ferramenta LOCAL: gera docs/INVENTARIO-DOCUMENTAL.html, docs/MATRIZ-COBERTURA.html e docs/RELATORIO-TESTES.html
 * a partir do catálogo real (sgi/modelos/facasi/catalogo.json), do mapeamento (sgi/lib/Coverage.php)
 * e dos relatórios JSON do Playwright (tests/results). Nada é escrito à mão: só o que existe.
 */
declare(strict_types=1);
$root = realpath(__DIR__ . '/..');
require $root . '/sgi/lib/Coverage.php';
$cat = json_decode((string)file_get_contents("$root/sgi/modelos/facasi/catalogo.json"), true);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$page = function (string $title, string $sub, string $body) use ($h) {
    return "<!DOCTYPE html>\n<html lang=\"pt-AO\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<title>" . $h($title) . "</title>\n<link rel=\"stylesheet\" href=\"estilo.css\">\n</head>\n<body>\n<div class=\"pagina\">\n<header class=\"capa\"><h1>" . $h($title) . "</h1><p>" . $h($sub) . "</p></header>\n<nav class=\"voltar\"><a href=\"index.html\">← Documentação</a></nav>\n<main>\n" . $body . "\n</main>\n<footer>Gerado automaticamente por tools/gerar-docs.php em " . date('Y-m-d H:i') . ".</footer>\n</div>\n</body>\n</html>\n";
};

// ---------------- INVENTÁRIO ----------------
$porCat = [];
foreach ($cat['modelos'] as $m) {
    $porCat[$m['category']][] = $m;
}
ksort($porCat);
$b = '<h2>Resumo</h2><table><tr><th>Categoria</th><th>Documentos</th><th>Preenchíveis (formulários)</th><th>Só consulta</th></tr>';
$tot = [0, 0];
foreach ($porCat as $c => $ms) {
    $f = count(array_filter($ms, fn($m) => $m['is_form']));
    $b .= '<tr><td>' . $h($c) . '</td><td>' . count($ms) . '</td><td>' . $f . '</td><td>' . (count($ms) - $f) . '</td></tr>';
    $tot[0] += count($ms);
    $tot[1] += $f;
}
$b .= '<tr><th>Total</th><th>' . $tot[0] . '</th><th>' . $tot[1] . '</th><th>' . ($tot[0] - $tot[1]) . '</th></tr></table>';
$b .= '<div class="nota"><strong>Classificação da informação:</strong> o conteúdo de cada documento (texto, campos, datas de emissão, «Elabora/Aprova», versões) é <span class="ok">fornecido</span> pela FACASI nos ficheiros HTML originais. '
    . 'A categoria, o código normalizado e a classificação «preenchível/consulta» são <span class="pend">propostas para validação</span> (geradas a partir do nome do ficheiro e da existência de campos). '
    . 'O antigo <code>Calendario.html</code> (guardava só no navegador) foi <span class="na">substituído</span> pelo calendário do SGI no servidor e não é um documento do inventário. '
    . 'Origem: pastas <code>PDF-01/</code> (documentos), <code>Excel-trinormas/</code> (formulários) e <code>html/</code> (manual) deste repositório.</div>';
$b .= '<h2>Documentos</h2>';
foreach ($porCat as $c => $ms) {
    $b .= '<h3>' . $h($c) . ' (' . count($ms) . ')</h3><div class="tw"><table><tr><th>Código</th><th>Título</th><th>Tipo</th><th>Campos (estimativa)</th><th>Uso no SGI</th><th>Ficheiro de origem</th></tr>';
    foreach ($ms as $m) {
        $b .= '<tr><td>' . $h($m['code']) . '</td><td>' . $h($m['title']) . '</td><td>' . $h($m['doc_type']) . '</td><td>' . (int)$m['fields_estimate'] . '</td><td>'
            . ($m['is_form'] ? '<span class="ok">Preencher → registo</span>' : 'Consulta / leitura') . '</td><td><code>' . $h($m['source']) . '</code></td></tr>';
    }
    $b .= '</table></div>';
}
// lacunas de numeração
$nums = [];
foreach ($cat['modelos'] as $m) {
    if (preg_match('/^(DES|DOC|FORM|PRO|PSC|PCS)-(\d{3})$/', $m['code'], $mm)) {
        $nums[$mm[1]][] = (int)$mm[2];
    }
}
$b .= '<h2>Lacunas na numeração <span class="falha">(pendente: confirmar com a FACASI)</span></h2><table><tr><th>Série</th><th>Números em falta entre o primeiro e o último</th></tr>';
foreach ($nums as $s => $ns) {
    $ns = array_unique($ns);
    sort($ns);
    $falta = array_diff(range(min($ns), max($ns)), $ns);
    $b .= '<tr><td>' . $s . '</td><td>' . ($falta ? implode(', ', array_map(fn($n) => sprintf('%s-%03d', $s, $n), $falta)) : '—') . '</td></tr>';
}
$b .= '</table><p>Alguns números podem corresponder a documentos retirados de propósito (ex.: legislação do Paraguai e conteúdos ISO 14001, segundo o relatório de validação original).</p>';
$b .= '<h2>Ficheiros de apoio (imagens e PDF referenciados pelos documentos)</h2><p>' . count(glob("$root/sgi/modelos/facasi/ativos/*")) . ' ficheiros copiados para o espaço da FACASI no SGI (certificados, relatórios de auditoria, manuais, mapa de processos, organigrama). Nunca são entregues a outras organizações.</p>';
file_put_contents("$root/docs/INVENTARIO-DOCUMENTAL.html", $page('Inventário documental', $cat['total'] . ' documentos HTML da FACASI integrados no SGI', $b));

// ---------------- MATRIZ ----------------
$codes = array_column($cat['modelos'], 'title', 'code');
$scope = ['9001', '45001'];
$b = '<div class="nota">Mapeamento cláusula → documento: <span class="pend">proposta para validação</span> pelo coordenador do SGI. Âmbito <span class="ok">fornecido</span>: ISO 9001:2015 e ISO 45001:2018 (relatório de validação do SGI original). '
    . 'No SGI, o relatório «Cobertura normativa» acrescenta a evidência real (registos finais dos últimos 12 meses) e respeita o âmbito definido em Organização.</div>';
$b .= '<div class="tw"><table><tr><th>Cláusula</th><th>Requisito</th><th>Normas</th><th>Documentos FACASI propostos</th><th>Situação</th></tr>';
foreach (Coverage::CLAUSES as [$cl, $tit, $normas, $pref]) {
    $docs = [];
    foreach ($codes as $code => $t) {
        foreach ($pref as $p) {
            if ($code === $p || (substr($p, -1) === '-' && strpos($code, $p) === 0) || strpos($code, $p . '-') === 0) {
                $docs[] = $code;
                break;
            }
        }
    }
    if (!array_intersect($normas, $scope)) {
        $sit = '<span class="na">Não aplicável</span> — fora do âmbito contratado (ISO 9001 + ISO 45001)';
    } elseif (!$docs) {
        $sit = '<span class="falha">Pendente</span> — sem documento';
    } else {
        $sit = '<span class="ok">Documentado</span> (evidência: ver relatório no SGI)';
    }
    $b .= '<tr><td>' . $h($cl) . '</td><td>' . $h($tit) . '</td><td>ISO ' . implode(', ISO ', $normas) . '</td><td>' . ($docs ? implode('<br>', array_map(fn($c) => '<strong>' . $h($c) . '</strong> ' . $h(mb_substr((string)$codes[$c], 0, 70)), $docs)) : '—') . '</td><td>' . $sit . '</td></tr>';
}
$b .= '</table></div>';
file_put_contents("$root/docs/MATRIZ-COBERTURA.html", $page('Matriz de cobertura normativa', 'ISO 9001:2015 · ISO 45001:2018 (ISO 14001:2015 como referência) — cláusulas 4 a 10', $b));

// ---------------- RELATÓRIO DE TESTES ----------------
$res = "$root/tests/results";
$walk = function (array $suite, array &$out) use (&$walk) {
    foreach ($suite['suites'] ?? [] as $s) {
        $walk($s, $out);
    }
    foreach ($suite['specs'] ?? [] as $sp) {
        foreach ($sp['tests'] ?? [] as $t) {
            $r = end($t['results']) ?: [];
            $out[] = ['titulo' => $sp['title'], 'estado' => $r['status'] ?? ($t['status'] ?? '?'), 'ms' => $r['duration'] ?? 0, 'erro' => isset($r['error']['message']) ? preg_replace('/\e\[[0-9;]*m/', '', strtok((string)$r['error']['message'], "\n")) : ''];
        }
    }
};
$b = '';
$passagens = ['php' => 'Servidor embutido do PHP 8.3 (php -S) em subpasta /sgi/', 'apache' => 'Apache 2.4 + mod_php 8.3 em subpasta /sgi/ com .htaccess'];
$resumo = [];
foreach ($passagens as $p => $desc) {
    $b .= '<h2>Passagem: ' . $h($desc) . '</h2>';
    foreach (['aceitacao' => 'Testes de aceitação', 'ida-volta' => 'Ida e volta de todos os HTML'] as $tipo => $lab) {
        $f = "$res/resultado-$p-$tipo.json";
        if (!is_file($f)) {
            $b .= '<p class="pend">' . $h($lab) . ': não executado.</p>';
            continue;
        }
        $j = json_decode((string)file_get_contents($f), true);
        $out = [];
        foreach ($j['suites'] ?? [] as $s) {
            $walk($s, $out);
        }
        $ok = count(array_filter($out, fn($o) => $o['estado'] === 'passed'));
        $sk = count(array_filter($out, fn($o) => $o['estado'] === 'skipped'));
        $resumo[$p][$tipo] = [$ok, count($out), $sk];
        $b .= '<h3>' . $h($lab) . ' — ' . $ok . ' de ' . (count($out) - $sk) . ' passaram' . ($sk ? " ($sk não aplicável nesta passagem)" : '') . '</h3>';
        $b .= '<p>Executado em ' . $h(substr((string)($j['stats']['startTime'] ?? ''), 0, 19)) . ' · duração ' . round(($j['stats']['duration'] ?? 0) / 1000) . ' s.</p>';
        $b .= '<div class="tw"><table><tr><th>Teste</th><th>Resultado</th><th>Duração</th></tr>';
        foreach ($out as $o) {
            $cls = $o['estado'] === 'passed' ? 'ok' : ($o['estado'] === 'skipped' ? 'na' : 'falha');
            $lab2 = ['passed' => 'Passou', 'skipped' => 'Não aplicável', 'failed' => 'Falhou', 'timedOut' => 'Falhou (tempo esgotado)'][$o['estado']] ?? $o['estado'];
            $b .= '<tr><td>' . $h($o['titulo']) . ($o['erro'] ? '<br><small class="falha">' . $h(mb_substr($o['erro'], 0, 300)) . '</small>' : '') . '</td><td class="' . $cls . '">' . $lab2 . '</td><td>' . round($o['ms'] / 1000, 1) . ' s</td></tr>';
        }
        $b .= '</table></div>';
    }
    $dir = "$res/$p";
    if (is_dir($dir)) {
        foreach ([390, 1280] as $w) {
            $all = [];
            foreach (glob("$dir/ida-volta-$w-*.json") as $f) {
                $all = array_merge($all, json_decode((string)file_get_contents($f), true));
            }
            if (!$all) {
                continue;
            }
            $forms = array_filter($all, fn($x) => !empty($x['campos']));
            $okF = count(array_filter($forms, fn($x) => $x['ok']));
            $okA = count(array_filter($all, fn($x) => $x['ok']));
            $campos = array_sum(array_map(fn($x) => (int)($x['campos'] ?? 0), $forms));
            $b .= "<p><strong>Ida e volta {$w} px:</strong> {$okA}/" . count($all) . " documentos OK — {$okF}/" . count($forms) . " formulários preenchidos ({$campos} campos no total), guardados no MySQL, reabertos e comparados campo a campo; " . (count($all) - count($forms)) . ' documentos de consulta abertos' . ($w === 390 ? ' e verificados sem ultrapassar a largura do ecrã' : '') . '.</p>';
            $falhas = array_filter($all, fn($x) => !$x['ok']);
            if ($falhas) {
                $b .= '<ul>' . implode('', array_map(fn($x) => '<li class="falha">' . $h($x['code'] . ': ' . $x['detalhe']) . '</li>', $falhas)) . '</ul>';
            }
        }
    }
}
$intro = '<div class="nota">Este relatório é gerado a partir dos ficheiros de resultados do Playwright (<code>tests/results/*.json</code>). Só aparece como «Passou» o que foi realmente executado neste ambiente. Comando: <code>bash sistema/tests/run-all.sh</code>.</div>'
    . '<h2>Ambiente de teste</h2><table><tr><th>Componente</th><th>Versão</th></tr>'
    . '<tr><td>Sistema</td><td>Ubuntu 24.04 (contentor)</td></tr><tr><td>PHP</td><td>' . PHP_VERSION . ' (CLI e mod_php)</td></tr>'
    . '<tr><td>Base de dados</td><td>' . $h(trim((string)@shell_exec("mysql -uroot -N -e 'select version()' 2>/dev/null")) ?: 'MariaDB') . '</td></tr>'
    . '<tr><td>Servidor web</td><td>' . $h(trim((string)@shell_exec("apache2 -v 2>/dev/null | head -1")) ?: 'Apache 2.4') . '</td></tr>'
    . '<tr><td>Navegador</td><td>Chromium (Playwright 1.56.1), emulação móvel Pixel 7 (412 px) e 390 px para a ida e volta</td></tr></table>'
    . '<h2>Lista dos testes</h2><table><tr><th>N.º</th><th>O que verifica</th></tr>'
    . implode('', array_map(fn($r) => '<tr><td>' . $r[0] . '</td><td>' . $h($r[1]) . '</td></tr>', [
        ['T01', 'Instalação pelo navegador com código (código errado recusado; assistente bloqueado após instalar; ficheiros internos não servidos)'],
        ['T02', 'Perfis, palavra-passe temporária, CSRF, bloqueio de conta, separação plataforma/organização'],
        ['T03', 'Guardar = rascunho no MySQL; confirmação só depois do servidor'],
        ['T04', 'Descarregar = obrigatórios, confirmação, final com SHA-256, arquivo Categoria/Tipo/Ano/Mês'],
        ['T05', 'Final bloqueado na interface e na API (alterar, eliminar, refinalizar, anexar)'],
        ['T06', 'Aprovação prévia: perfil mínimo, segregação de funções, finalização após aprovação'],
        ['T07', 'Correção = nova versão vinculada; original e SHA-256 inalterados'],
        ['T08', 'Baixar para o computador não cria registo nem altera o original'],
        ['T09', 'Isolamento entre organizações (registos, ficheiros, modelos, listas)'],
        ['T10', 'Plataforma: estado do sistema, sem acesso a documentos'],
        ['T11', 'Biblioteca: autorização expressa, neutralização, só modelos aprovados'],
        ['T12', 'Cópia de segurança pelo navegador, manifesto, restauro verificado'],
        ['T13', 'Nova versão de documento (sanitizada) e confirmação de leitura'],
        ['T14', 'Sem senha fixa, cookies, cabeçalhos, bloqueio por organização'],
        ['T15', 'PWA instalável (sem erros de instalabilidade) e abertura sem ligação'],
        ['T16', 'Rascunho sem ligação → pendente → sincronizado sem duplicar (client_uuid e Idempotency-Key)'],
        ['T17', 'Conflito com outra pessoa → ecrã de resolução, nenhuma perda'],
        ['T18', 'Finalizar sem ligação bloqueado com mensagem; com ligação finaliza e bloqueia no servidor'],
        ['T19', 'Fotografia (câmara simulada) comprimida, validada, isolada; assinatura; GPS com consentimento'],
        ['T20', 'QR abre a ficha certa; QR de outra organização não abre nada'],
        ['T21', 'Token revogado deixa de funcionar imediatamente (acesso e renovação)'],
        ['T22', 'Ida e volta de todos os HTML a 390 px (e de base a 1280 px)'],
        ['T23', 'Atualização 1.0.0 → 1.1.0 pelo assistente web, sem perda (contagens e hashes)'],
        ['T24', 'Apache + PHP 8.3 com .htaccess em subpasta'],
        ['T25–T30', 'Painel por perfil, calendário no servidor, relatórios HTML/CSV, importação CSV/XLSX, SMTP + recuperação de palavra-passe, integridade (deteção de ficheiro adulterado)'],
    ])) . '</table>';
file_put_contents("$root/docs/RELATORIO-TESTES.html", $page('Relatório de testes', 'Resultados reais da bateria E2E (Playwright)', $intro . $b));
echo "Documentos gerados.\n";
