<?php
declare(strict_types=1);

/**
 * Matriz de cobertura ISO 9001:2015 / ISO 14001:2015 / ISO 45001:2018 (estrutura de alto nível, cláusulas 4–10).
 * O mapeamento cláusula → código de documento é uma PROPOSTA PARA VALIDAÇÃO pelo coordenador do SGI.
 * Nenhuma cobertura é declarada sem documento vigente existente no sistema; a evidência conta registos finais reais.
 */
class Coverage
{
    /** [cláusula, título, normas aplicáveis, códigos (prefixos) propostos] */
    public const CLAUSES = [
        ['4.1', 'Compreender a organização e o seu contexto', ['9001', '14001', '45001'], ['DOC-002']],
        ['4.2', 'Necessidades e expectativas das partes interessadas', ['9001', '14001', '45001'], ['DOC-003']],
        ['4.3', 'Âmbito do sistema de gestão', ['9001', '14001', '45001'], ['DOC-001']],
        ['4.4', 'Sistema de gestão e respetivos processos', ['9001', '14001', '45001'], ['DOC-004', 'PSC-', 'PCS-', 'FORM-039']],
        ['5.1', 'Liderança e compromisso', ['9001', '14001', '45001'], ['PRO-012', 'PSC-001']],
        ['5.2', 'Política', ['9001', '14001', '45001'], ['DOC-005', 'DOC-007', 'DOC-008', 'DOC-012']],
        ['5.3', 'Funções, responsabilidades e autoridades', ['9001', '14001', '45001'], ['DES-', 'DOC-011']],
        ['5.4', 'Consulta e participação dos trabalhadores', ['45001'], ['PRO-023', 'FORM-033', 'FORM-036', 'REG-001']],
        ['6.1', 'Ações para tratar riscos e oportunidades', ['9001', '14001', '45001'], ['PRO-019', 'PRO-007', 'DOC-009', 'DOC-024', 'DOC-019', 'FORM-037']],
        ['6.1.2 (14001)', 'Aspetos ambientais', ['14001'], ['ASP-AMB']],
        ['6.1.2 (45001)', 'Identificação de perigos e avaliação de riscos de SST', ['45001'], ['PRO-019', 'FORM-035', 'MAN-005']],
        ['6.1.3', 'Requisitos legais e outros requisitos (obrigações de conformidade)', ['14001', '45001'], ['PRO-022']],
        ['6.2', 'Objetivos e planeamento para os atingir', ['9001', '14001', '45001'], ['OC-001', 'OC-003', 'DOC-013', 'DOC-017']],
        ['6.3', 'Planeamento das alterações', ['9001'], ['PRO-024', 'FORM-026']],
        ['7.1', 'Recursos (incl. infraestrutura e recursos de monitorização e medição)', ['9001', '14001', '45001'], ['PSC-002', 'FORM-004', 'FORM-032']],
        ['7.2', 'Competência', ['9001', '14001', '45001'], ['PRO-009', 'FORM-RRHH-001', 'FORM-RRHH-002', 'FORM-RRHH-003', 'FORM-024', 'FORM-014']],
        ['7.3', 'Consciencialização', ['9001', '14001', '45001'], ['FORM-021']],
        ['7.4', 'Comunicação', ['9001', '14001', '45001'], ['PRO-010', 'FORM-002']],
        ['7.5', 'Informação documentada', ['9001', '14001', '45001'], ['PRO-001', 'PRO-002', 'FORM-025', 'FORM-027']],
        ['8.1', 'Planeamento e controlo operacional', ['9001', '14001', '45001'], ['PRO-031', 'MAN-005', 'PRO-020', 'FORM-028', 'FORM-029']],
        ['8.1 (14001)', 'Perspetiva de ciclo de vida', ['14001'], ['CICLO-VIDA']],
        ['8.2 (14001/45001)', 'Preparação e resposta a emergências', ['14001', '45001'], ['MAN-004', 'PRO-015', 'DOC-014', 'FICHAS-EXTINTORES']],
        ['8.2 (9001)', 'Requisitos para produtos e serviços', ['9001'], ['PRO-008', 'PCS-006', 'FORM-001', 'FORM-022']],
        ['8.3', 'Design e desenvolvimento', ['9001'], ['PSC-007']],
        ['8.4', 'Controlo de processos, produtos e serviços externos', ['9001', '45001'], ['PRO-016', 'FORM-017', 'FORM-018', 'PSC-011']],
        ['8.5', 'Produção e prestação do serviço', ['9001'], ['PRO-031', 'PRO-026', 'FORM-043', 'PRO-030', 'DOC-010']],
        ['8.6', 'Libertação de produtos e serviços', ['9001'], ['FORM-023']],
        ['8.7', 'Controlo de saídas não conformes', ['9001'], ['PRO-003']],
        ['9.1', 'Monitorização, medição, análise e avaliação', ['9001', '14001', '45001'], ['DOC-013', 'FORM-022', 'PRO-021']],
        ['9.1.2', 'Avaliação da conformidade', ['14001', '45001'], ['PRO-022']],
        ['9.2', 'Auditoria interna', ['9001', '14001', '45001'], ['PRO-004', 'FORM-010', 'FORM-011', 'FORM-015']],
        ['9.3', 'Revisão pela direção', ['9001', '14001', '45001'], ['FORM-012', 'FORM-020']],
        ['10.2', 'Incidentes, não conformidades e ações corretivas', ['9001', '14001', '45001'], ['PRO-005', 'FORM-007', 'FORM-008', 'FORM-030']],
        ['10.3', 'Melhoria contínua', ['9001', '14001', '45001'], ['PCS-005', 'PRO-006']],
    ];

    /** Normas do âmbito da organização (definidas pelo administrador). @return array{normas:array<int,string>,justificacao:string} */
    public static function scope(int $orgId): array
    {
        $v = json_decode((string)setting_get('org_normas_' . $orgId, ''), true);
        if (!is_array($v) || empty($v['normas'])) {
            return ['normas' => ['9001', '14001', '45001'], 'justificacao' => ''];
        }
        return ['normas' => array_values(array_intersect(['9001', '14001', '45001'], (array)$v['normas'])), 'justificacao' => (string)($v['justificacao'] ?? '')];
    }

    /** @return array<int,array<string,mixed>> */
    public static function matrix(int $orgId): array
    {
        $db = sgi_db();
        $scope = self::scope($orgId);
        $templates = $db->all("SELECT t.id, t.code, t.title FROM templates t WHERE t.org_id = ? AND t.status = 'vigente'", [$orgId]);
        $since = date('Y-m-d', strtotime('-12 months'));
        $evid = [];
        foreach ($db->all("SELECT template_id, COUNT(*) n FROM records WHERE org_id = ? AND status = 'final' AND finalized_at >= ? GROUP BY template_id", [$orgId, $since]) as $e) {
            $evid[(int)$e['template_id']] = (int)$e['n'];
        }
        $out = [];
        foreach (self::CLAUSES as [$cl, $title, $norms, $codes]) {
            $docs = [];
            $records = 0;
            foreach ($templates as $t) {
                foreach ($codes as $c) {
                    if ($t['code'] === $c || (substr($c, -1) === '-' && strpos($t['code'], $c) === 0) || strpos($t['code'], $c . '-') === 0) {
                        $docs[] = $t['code'];
                        $records += $evid[(int)$t['id']] ?? 0;
                        break;
                    }
                }
            }
            if (!array_intersect($norms, $scope['normas'])) {
                $estado = 'nao_aplicavel';
                $nota = 'Não aplicável: fora do âmbito definido pela organização (ISO ' . implode(', ISO ', $scope['normas']) . ').' . ($scope['justificacao'] !== '' ? ' Justificação: ' . $scope['justificacao'] : '');
            } elseif (!$docs) {
                $estado = 'pendente';
                $nota = 'Sem documento vigente no SGI para esta cláusula. Necessário elaborar ou indicar o documento existente.';
            } elseif ($records > 0) {
                $estado = 'documentado_com_evidencia';
                $nota = "$records registo(s) final(is) nos últimos 12 meses.";
            } else {
                $estado = 'documentado_sem_evidencia';
                $nota = 'Documento vigente, mas sem registos finais no SGI nos últimos 12 meses (pode haver evidência fora do sistema).';
            }
            $out[] = ['clausula' => $cl, 'titulo' => $title, 'normas' => $norms, 'documentos' => array_values(array_unique($docs)), 'registos_12m' => $records, 'estado' => $estado, 'nota' => $nota];
        }
        return $out;
    }
}
