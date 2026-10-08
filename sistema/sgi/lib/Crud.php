<?php
declare(strict_types=1);

/**
 * CRUD genérico com isolamento por organização (todas as consultas filtram org_id).
 * Especificação de campos: tipo[:args][!]  (! = obrigatório)
 *   str:N | text | date | int | dec | enum:a,b,c | fk:tabela | user | bool
 */
class Crud
{
    public const SPECS = [
        'workers' => [
            'view' => 'catalog.view', 'manage' => 'catalog.manage', 'label' => 'Trabalhador', 'order' => 'name', 'search' => ['name', 'id_number', 'department'],
            'fields' => ['name' => 'str:190!', 'id_number' => 'str:60', 'position_id' => 'fk:positions', 'department' => 'str:120', 'email' => 'str:190', 'phone' => 'str:60', 'hired_at' => 'date', 'status' => 'enum:ativo,inativo'],
        ],
        'positions' => [
            'view' => 'catalog.view', 'manage' => 'catalog.manage', 'label' => 'Cargo', 'order' => 'name', 'search' => ['name', 'code', 'department'],
            'fields' => ['code' => 'str:40', 'name' => 'str:190!', 'department' => 'str:120', 'description' => 'text'],
        ],
        'suppliers' => [
            'view' => 'catalog.view', 'manage' => 'catalog.manage', 'label' => 'Fornecedor', 'order' => 'name', 'search' => ['name', 'tax_id', 'category'],
            'fields' => ['name' => 'str:190!', 'tax_id' => 'str:60', 'category' => 'str:120', 'contact' => 'str:190', 'email' => 'str:190', 'phone' => 'str:60', 'status' => 'enum:aprovado,em_avaliacao,suspenso', 'last_evaluation' => 'date', 'score' => 'dec'],
        ],
        'assets' => [
            'view' => 'assets.view', 'manage' => 'assets.manage', 'label' => 'Equipamento/local', 'order' => 'code', 'search' => ['code', 'name', 'location', 'serial_no'],
            'fields' => ['kind' => 'enum:equipamento,extintor,local!', 'code' => 'str:60!', 'name' => 'str:190!', 'location' => 'str:190', 'brand' => 'str:120', 'serial_no' => 'str:120', 'notes' => 'text', 'next_check_date' => 'date', 'form_template_id' => 'fk:templates', 'status' => 'enum:ativo,inativo'],
        ],
        'trainings' => [
            'view' => 'catalog.view', 'manage' => 'catalog.manage', 'label' => 'Capacitação', 'order' => 'planned_date DESC', 'search' => ['title', 'topic', 'provider'],
            'fields' => ['title' => 'str:255!', 'topic' => 'str:120', 'provider' => 'str:190', 'planned_date' => 'date', 'hours' => 'dec', 'status' => 'enum:planeada,realizada,cancelada', 'efficacy_note' => 'text'],
        ],
        'nonconformities' => [
            'view' => 'nc.view', 'manage' => 'nc.manage', 'label' => 'Não conformidade', 'order' => 'id DESC', 'search' => ['code', 'title', 'origin'],
            'fields' => ['code' => 'str:40!', 'title' => 'str:255!', 'description' => 'text', 'origin' => 'str:60', 'standard' => 'enum:ISO 9001,ISO 14001,ISO 45001,Legal,Outro', 'nc_type' => 'enum:nc,observacao,oportunidade,incidente,acidente', 'severity' => 'enum:baixa,media,alta', 'status' => 'enum:aberta,em_tratamento,fechada', 'root_cause' => 'text', 'detected_at' => 'date', 'due_date' => 'date', 'owner_user_id' => 'user', 'record_id' => 'fk:records'],
        ],
        'actions' => [
            'view' => 'nc.view', 'manage' => 'nc.manage', 'label' => 'Ação', 'order' => 'due_date IS NULL, due_date', 'search' => ['title', 'description'],
            'fields' => ['nc_id' => 'fk:nonconformities', 'title' => 'str:255!', 'description' => 'text', 'action_type' => 'enum:corretiva,preventiva,melhoria,imediata', 'owner_user_id' => 'user', 'due_date' => 'date', 'status' => 'enum:pendente,em_curso,concluida,cancelada', 'efficacy' => 'enum:nao_avaliada,eficaz,nao_eficaz'],
        ],
        'calendar_events' => [
            'view' => 'calendar.view', 'manage' => 'calendar.manage', 'label' => 'Evento', 'order' => 'start_date', 'search' => ['title', 'description'],
            'fields' => ['title' => 'str:255!', 'event_type' => 'enum:auditoria,capacitacao,manutencao,revisao,vencimento_legal,reuniao,outro!', 'start_date' => 'date!', 'end_date' => 'date', 'description' => 'text', 'owner_user_id' => 'user', 'status' => 'enum:planeado,realizado,cancelado', 'recurrence' => 'enum:nenhuma,mensal,trimestral,semestral,anual', 'reminder_days' => 'int'],
        ],
        'indicators' => [
            'view' => 'reports.view', 'manage' => 'indicators.manage', 'label' => 'Indicador', 'order' => 'name', 'search' => ['name'],
            'fields' => ['name' => 'str:190!', 'standard' => 'enum:ISO 9001,ISO 14001,ISO 45001,Geral', 'unit' => 'str:40', 'target' => 'dec', 'direction' => 'enum:maior,menor', 'frequency' => 'enum:mensal,trimestral,semestral,anual'],
        ],
    ];

    /** @param array<string,mixed> $in @return array<string,mixed> */
    public static function validate(string $table, array $in, int $org, bool $partial): array
    {
        $spec = self::SPECS[$table];
        $out = [];
        foreach ($spec['fields'] as $f => $def) {
            $required = substr($def, -1) === '!';
            $def = rtrim($def, '!');
            [$type, $arg] = array_pad(explode(':', $def, 2), 2, null);
            if (!array_key_exists($f, $in)) {
                if ($required && !$partial) {
                    throw new HttpError(422, 'Campo obrigatório em falta: ' . $f, 'campo_obrigatorio', ['campo' => $f]);
                }
                continue;
            }
            $v = $in[$f];
            if ($v === '' || $v === null) {
                if ($required) {
                    throw new HttpError(422, 'Campo obrigatório em falta: ' . $f, 'campo_obrigatorio', ['campo' => $f]);
                }
                $out[$f] = $type === 'enum' ? null : null;
                if ($type === 'enum') {
                    unset($out[$f]);
                }
                continue;
            }
            switch ($type) {
                case 'str':
                    $out[$f] = mb_substr(trim((string)$v), 0, (int)$arg);
                    break;
                case 'text':
                    $out[$f] = mb_substr((string)$v, 0, 20000);
                    break;
                case 'date':
                    $out[$f] = opt_date([$f => $v], $f);
                    break;
                case 'int':
                    if (!is_numeric($v)) {
                        throw new HttpError(422, "Número inválido em «{$f}».", 'valor_invalido', ['campo' => $f]);
                    }
                    $out[$f] = (int)$v;
                    break;
                case 'dec':
                    $v = str_replace(',', '.', (string)$v);
                    if (!is_numeric($v)) {
                        throw new HttpError(422, "Número inválido em «{$f}».", 'valor_invalido', ['campo' => $f]);
                    }
                    $out[$f] = (float)$v;
                    break;
                case 'enum':
                    $out[$f] = opt_enum([$f => $v], $f, explode(',', (string)$arg));
                    break;
                case 'fk':
                    $id = (int)$v;
                    if (!sgi_db()->val('SELECT id FROM `' . $arg . '` WHERE id = ? AND org_id = ?', [$id, $org])) {
                        throw new HttpError(422, "Referência inválida em «{$f}».", 'referencia_invalida', ['campo' => $f]);
                    }
                    $out[$f] = $id;
                    break;
                case 'user':
                    $id = (int)$v;
                    if (!sgi_db()->val('SELECT id FROM users WHERE id = ? AND org_id = ?', [$id, $org])) {
                        throw new HttpError(422, "Utilizador inválido em «{$f}».", 'referencia_invalida', ['campo' => $f]);
                    }
                    $out[$f] = $id;
                    break;
            }
        }
        return $out;
    }

    public static function register(Router $r, string $route, string $table): void
    {
        $spec = self::SPECS[$table];
        $r->add('GET', "/$route", function () use ($table, $spec) {
            $org = Auth::requireOrg();
            Auth::require($spec['view']);
            $where = ['org_id = ?'];
            $p = [$org];
            if (qstr('q') !== '') {
                $or = [];
                foreach ($spec['search'] as $c) {
                    $or[] = "`$c` LIKE ?";
                    $p[] = '%' . qstr('q') . '%';
                }
                $where[] = '(' . implode(' OR ', $or) . ')';
            }
            foreach ($spec['fields'] as $f => $def) {
                if (isset($_GET[$f]) && $_GET[$f] !== '' && (strpos($def, 'enum') === 0 || strpos($def, 'fk') === 0 || strpos($def, 'user') === 0)) {
                    $where[] = "`$f` = ?";
                    $p[] = (string)$_GET[$f];
                }
            }
            if ($table === 'calendar_events' && qstr('from') !== '' && qstr('to') !== '') {
                // eventos recorrentes são expandidos em /calendar; aqui filtra-se por intervalo simples
                $where[] = '(start_date <= ? AND COALESCE(end_date, start_date) >= ? OR recurrence <> \'nenhuma\')';
                $p[] = qstr('to');
                $p[] = qstr('from');
            }
            $limit = max(1, min(1000, qint('limit', 500)));
            $rows = sgi_db()->all("SELECT * FROM `$table` WHERE " . implode(' AND ', $where) . " ORDER BY {$spec['order']} LIMIT $limit", $p);
            return ['items' => $rows];
        });
        $r->add('GET', "/$route/{id}", function ($pp) use ($table, $spec) {
            $org = Auth::requireOrg();
            Auth::require($spec['view']);
            $row = sgi_db()->one("SELECT * FROM `$table` WHERE id = ? AND org_id = ?", [(int)$pp['id'], $org]);
            if (!$row) {
                throw new HttpError(404, $spec['label'] . ' não encontrado(a).', 'nao_encontrado');
            }
            return ['item' => $row];
        });
        $r->add('POST', "/$route", function () use ($table, $spec) {
            $org = Auth::requireOrg();
            Auth::require($spec['manage']);
            $row = self::validate($table, body(), $org, false);
            $row['org_id'] = $org;
            if (in_array($table, ['nonconformities', 'actions', 'calendar_events', 'trainings'], true)) {
                $row['created_by'] = Auth::id();
            }
            if ($table === 'assets') {
                $row['qr_token'] = random_token(16);
            }
            try {
                $id = sgi_db()->insert($table, $row);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    throw new HttpError(409, 'Já existe um registo com o mesmo código/identificação.', 'duplicado');
                }
                throw $e;
            }
            audit($org, Auth::id(), 'criado_' . $table, $table, $id);
            return ['id' => $id, 'item' => sgi_db()->one("SELECT * FROM `$table` WHERE id = ?", [$id]), '__status' => 201];
        });
        $r->add('PUT', "/$route/{id}", function ($pp) use ($table, $spec) {
            $org = Auth::requireOrg();
            $cur = sgi_db()->one("SELECT * FROM `$table` WHERE id = ? AND org_id = ?", [(int)$pp['id'], $org]);
            if (!$cur) {
                throw new HttpError(404, $spec['label'] . ' não encontrado(a).', 'nao_encontrado');
            }
            $in = body();
            if (!Auth::can($spec['manage'])) {
                // o responsável pode atualizar o estado da sua própria ação
                if ($table === 'actions' && (int)$cur['owner_user_id'] === Auth::id()) {
                    $in = array_intersect_key($in, ['status' => 1]);
                } else {
                    Auth::require($spec['manage']);
                }
            }
            $row = self::validate($table, $in, $org, true);
            if (isset($row['status'])) {
                if ($table === 'nonconformities') {
                    $row['closed_at'] = $row['status'] === 'fechada' ? ($cur['closed_at'] ?: now()) : null;
                } elseif ($table === 'actions') {
                    $row['closed_at'] = $row['status'] === 'concluida' ? ($cur['closed_at'] ?: now()) : null;
                }
            }
            if ($row) {
                try {
                    sgi_db()->update($table, $row, 'id = ? AND org_id = ?', [$cur['id'], $org]);
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        throw new HttpError(409, 'Já existe um registo com o mesmo código/identificação.', 'duplicado');
                    }
                    throw $e;
                }
                audit($org, Auth::id(), 'atualizado_' . $table, $table, (int)$cur['id']);
            }
            return ['ok' => true, 'item' => sgi_db()->one("SELECT * FROM `$table` WHERE id = ?", [$cur['id']])];
        });
        $r->add('DELETE', "/$route/{id}", function ($pp) use ($table, $spec) {
            $org = Auth::requireOrg();
            Auth::require($spec['manage']);
            $n = sgi_db()->q("DELETE FROM `$table` WHERE id = ? AND org_id = ?", [(int)$pp['id'], $org])->rowCount();
            if (!$n) {
                throw new HttpError(404, $spec['label'] . ' não encontrado(a).', 'nao_encontrado');
            }
            audit($org, Auth::id(), 'eliminado_' . $table, $table, (int)$pp['id']);
            return ['ok' => true];
        });
    }
}
