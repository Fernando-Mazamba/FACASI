<?php
declare(strict_types=1);

/** Definição das importações: coluna → [campo, obrigatório, tipo]. Cabeçalhos aceitam sinónimos. */
const IMPORT_DEFS = [
    'workers' => [
        'label' => 'Trabalhadores', 'table' => 'workers',
        'cols' => [
            'nome' => ['name', true, 'str'], 'numero_identificacao' => ['id_number', false, 'str'], 'cargo' => ['position_id', false, 'position'],
            'departamento' => ['department', false, 'str'], 'email' => ['email', false, 'email'], 'telefone' => ['phone', false, 'str'],
            'data_admissao' => ['hired_at', false, 'date'], 'estado' => ['status', false, 'enum:ativo,inativo'],
        ],
        'aliases' => ['nome_completo' => 'nome', 'bi' => 'numero_identificacao', 'n_bi' => 'numero_identificacao', 'documento' => 'numero_identificacao', 'correio' => 'email', 'correio_eletronico' => 'email', 'admissao' => 'data_admissao', 'funcao' => 'cargo'],
        'unique' => 'id_number',
    ],
    'positions' => [
        'label' => 'Cargos', 'table' => 'positions',
        'cols' => ['codigo' => ['code', false, 'str'], 'nome' => ['name', true, 'str'], 'departamento' => ['department', false, 'str'], 'descricao' => ['description', false, 'str']],
        'aliases' => ['cargo' => 'nome', 'designacao' => 'nome'],
        'unique' => 'name',
    ],
    'assets' => [
        'label' => 'Equipamentos', 'table' => 'assets',
        'cols' => [
            'tipo' => ['kind', true, 'enum:equipamento,extintor,local'], 'codigo' => ['code', true, 'str'], 'nome' => ['name', true, 'str'],
            'localizacao' => ['location', false, 'str'], 'marca' => ['brand', false, 'str'], 'numero_serie' => ['serial_no', false, 'str'],
            'proxima_verificacao' => ['next_check_date', false, 'date'], 'notas' => ['notes', false, 'str'],
        ],
        'aliases' => ['local' => 'localizacao', 'n_serie' => 'numero_serie', 'serie' => 'numero_serie', 'designacao' => 'nome', 'descricao' => 'nome', 'observacoes' => 'notas'],
        'unique' => 'code',
    ],
    'suppliers' => [
        'label' => 'Fornecedores', 'table' => 'suppliers',
        'cols' => [
            'nome' => ['name', true, 'str'], 'nif' => ['tax_id', false, 'str'], 'categoria' => ['category', false, 'str'], 'contacto' => ['contact', false, 'str'],
            'email' => ['email', false, 'email'], 'telefone' => ['phone', false, 'str'], 'estado' => ['status', false, 'enum:aprovado,em_avaliacao,suspenso'],
        ],
        'aliases' => ['fornecedor' => 'nome', 'razao_social' => 'nome', 'correio' => 'email', 'contribuinte' => 'nif'],
        'unique' => 'tax_id',
    ],
];

return function (Router $r) {
    $r->add('GET', '/import/template/{entity}', function ($p) {
        Auth::require('import');
        $def = IMPORT_DEFS[$p['entity']] ?? null;
        if (!$def) {
            throw new HttpError(404, 'Importação desconhecida.', 'importacao');
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="modelo-importacao-' . $p['entity'] . '.csv"');
        echo "\xEF\xBB\xBF" . implode(';', array_keys($def['cols'])) . "\n";
        return null;
    });

    $r->add('POST', '/import/preview', function () {
        $org = Auth::requireOrg();
        Auth::require('import');
        $entity = (string)(body()['entity'] ?? '');
        $def = IMPORT_DEFS[$entity] ?? null;
        if (!$def) {
            throw new HttpError(422, 'Escolha o que pretende importar.', 'importacao');
        }
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || $f['size'] > 5242880) {
            throw new HttpError(422, 'Envie um ficheiro .csv ou .xlsx até 5 MB.', 'sem_ficheiro');
        }
        $rows = Sheet::read($f['tmp_name'], (string)$f['name']);
        if (count($rows) < 2) {
            throw new HttpError(422, 'A folha não tem linhas de dados (a primeira linha deve ter os cabeçalhos).', 'folha_vazia');
        }
        if (count($rows) > 5001) {
            throw new HttpError(422, 'Máximo de 5000 linhas por importação.', 'folha_grande');
        }
        $headers = array_map(function ($h) use ($def) {
            $k = Sheet::key((string)$h);
            return $def['aliases'][$k] ?? $k;
        }, array_shift($rows));
        $unknown = array_values(array_filter($headers, function ($h) use ($def) {
            return $h !== '' && !isset($def['cols'][$h]);
        }));
        foreach ($def['cols'] as $col => [$field, $req]) {
            if ($req && !in_array($col, $headers, true)) {
                throw new HttpError(422, "Falta a coluna obrigatória «{$col}». Descarregue o modelo de importação.", 'coluna_em_falta', ['colunas_esperadas' => array_keys($def['cols'])]);
            }
        }
        $db = sgi_db();
        $positions = [];
        foreach ($db->all('SELECT id, name FROM positions WHERE org_id = ?', [$org]) as $pp) {
            $positions[mb_strtolower(trim($pp['name']))] = (int)$pp['id'];
        }
        $out = [];
        $seen = [];
        $ok = 0;
        foreach ($rows as $i => $row) {
            if (!array_filter($row, function ($v) {
                return trim((string)$v) !== '';
            })) {
                continue;
            }
            $item = [];
            $errors = [];
            $warnings = [];
            foreach ($headers as $ci => $col) {
                if (!isset($def['cols'][$col])) {
                    continue;
                }
                [$field, $req, $type] = $def['cols'][$col];
                $v = trim((string)($row[$ci] ?? ''));
                if ($v === '') {
                    if ($req) {
                        $errors[] = "«{$col}» é obrigatório";
                    }
                    continue;
                }
                if ($type === 'date') {
                    $d = Sheet::date($v);
                    if (!$d) {
                        $errors[] = "data inválida em «{$col}» ($v)";
                    } else {
                        $item[$field] = $d;
                    }
                } elseif ($type === 'email') {
                    if (!filter_var($v, FILTER_VALIDATE_EMAIL)) {
                        $errors[] = "correio inválido ($v)";
                    } else {
                        $item[$field] = mb_strtolower($v);
                    }
                } elseif (strpos($type, 'enum:') === 0) {
                    $opts = explode(',', substr($type, 5));
                    $k = Sheet::key($v);
                    if (!in_array($k, $opts, true)) {
                        $errors[] = "«{$col}» deve ser: " . implode(', ', $opts);
                    } else {
                        $item[$field] = $k;
                    }
                } elseif ($type === 'position') {
                    $pid = $positions[mb_strtolower($v)] ?? null;
                    if ($pid) {
                        $item[$field] = $pid;
                    } else {
                        $warnings[] = "cargo «{$v}» não existe no catálogo (fica em branco; importe primeiro os cargos)";
                    }
                } else {
                    $item[$field] = mb_substr($v, 0, 190);
                }
            }
            $u = $def['unique'];
            if (!$errors && !empty($item[$u])) {
                $key = mb_strtolower((string)$item[$u]);
                if (isset($seen[$key])) {
                    $errors[] = 'duplicado dentro do ficheiro (linha ' . $seen[$key] . ')';
                } elseif ($db->val("SELECT id FROM `{$def['table']}` WHERE org_id = ? AND `$u` = ?", [$org, $item[$u]])) {
                    $errors[] = 'já existe no SGI (não será importado)';
                }
                $seen[$key] = $i + 2;
            }
            if (!$errors) {
                $ok++;
            }
            $out[] = ['linha' => $i + 2, 'dados' => $item, 'erros' => $errors, 'avisos' => $warnings];
        }
        $bid = $db->insert('import_batches', [
            'org_id' => $org, 'entity' => $entity, 'file_name' => mb_substr((string)$f['name'], 0, 255),
            'rows_total' => count($out), 'rows_ok' => $ok, 'rows_error' => count($out) - $ok,
            'payload' => json_encode($out, JSON_UNESCAPED_UNICODE), 'created_by' => Auth::id(),
        ]);
        return ['batch_id' => $bid, 'entity' => $entity, 'label' => $def['label'], 'total' => count($out), 'ok' => $ok, 'erros' => count($out) - $ok, 'colunas_ignoradas' => $unknown, 'linhas' => array_slice($out, 0, 1000)];
    });

    $r->add('POST', '/import/{id}/apply', function ($p) {
        $org = Auth::requireOrg();
        Auth::require('import');
        $db = sgi_db();
        $b = $db->one("SELECT * FROM import_batches WHERE id = ? AND org_id = ? AND status = 'previa'", [(int)$p['id'], $org]);
        if (!$b) {
            throw new HttpError(404, 'Pré-visualização não encontrada ou já aplicada.', 'nao_encontrado');
        }
        $def = IMPORT_DEFS[$b['entity']];
        $rows = json_decode((string)$b['payload'], true);
        $n = 0;
        $db->tx(function () use ($db, $rows, $def, $org, &$n, $b) {
            foreach ($rows as $row) {
                if ($row['erros']) {
                    continue;
                }
                $item = $row['dados'] + ['org_id' => $org];
                if ($def['table'] === 'assets') {
                    $item['qr_token'] = random_token(16);
                }
                $u = $def['unique'];
                if (!empty($item[$u]) && $db->val("SELECT id FROM `{$def['table']}` WHERE org_id = ? AND `$u` = ?", [$org, $item[$u]])) {
                    continue; // criado entretanto
                }
                $db->insert($def['table'], $item);
                $n++;
            }
            $db->update('import_batches', ['status' => 'aplicado', 'applied_at' => now()], 'id = ?', [$b['id']]);
        });
        audit($org, Auth::id(), 'importacao_aplicada', $def['table'], (int)$b['id'], ['importados' => $n]);
        return ['importados' => $n];
    });

    $r->add('POST', '/import/{id}/cancel', function ($p) {
        $org = Auth::requireOrg();
        Auth::require('import');
        sgi_db()->q("UPDATE import_batches SET status = 'cancelado' WHERE id = ? AND org_id = ? AND status = 'previa'", [(int)$p['id'], $org]);
        return ['ok' => true];
    });
};
