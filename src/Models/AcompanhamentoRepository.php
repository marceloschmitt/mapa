<?php
declare(strict_types=1);

namespace Mapa\Models;

use Mapa\Core\Database;
use PDO;

/**
 * Acompanhamento dos alunos com alarme crítico (acompanhamento_alunos, gerado
 * por python/gerar_efeito_contatos.py): a lista "Precisam de nova abordagem" da
 * tela Eficácia dos alarmes. Também a comparação de duas proporções com margem.
 */
class AcompanhamentoRepository
{
    public const TIPOS = ['faltas_3semanas', 'faltas_4dias', 'percentual_baixo'];

    /** Situação do aluno na lista: tipo do 1º alarme crítico; 'sem_risco' = contatado sem alarme crítico. */
    public const ROTULOS_SITUACAO = [
        'percentual_baixo' => 'Frequência abaixo de 50%',
        'faltas_3semanas' => '3 semanas sem aparecer',
        'faltas_4dias' => 'Dias seguidos de falta',
        'sem_risco' => 'Sem alarme crítico',
    ];

    /** Dias depois do último contato para o aluno entrar na lista de nova abordagem. */
    public const DIAS_NOVA_ABORDAGEM = 14;

    /** Mínimo de aulas desde o último contato para julgar se voltou (o mesmo do retorno). */
    public const MIN_AULAS_DESDE = 3;

    /** Presença (%) desde o último contato: abaixo de 75 não voltou; abaixo de 50 é prioridade alta. */
    public const PRESENCA_VOLTOU = 75;
    public const PRESENCA_ALTA = 50;

    public const POR_PAGINA = [25, 50, 100];

    /** Abaixo disto, um grupo é "poucos alunos": sem selo e sem comparação. */
    public const MIN_ALUNOS = 20;

    /** z de 95% (duas proporções). */
    private const Z = 1.96;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /** @return array<string, mixed>|null */
    public function ultimaExecucao(): ?array
    {
        $row = $this->db->query(
            'SELECT id, coleta_id, periodo, data_inicio, data_corte, total_alunos, executado_em
             FROM acompanhamento_execucoes ORDER BY id DESC LIMIT 1'
        )->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Compara a proporção de um grupo com a do outro (ex.: voltou entre os
     * contatados x na comparação). Diferença em pontos percentuais e margem de 95%; selo:
     * 'melhor' (diferença toda acima de zero), 'pior', 'incerto' (margem inclui
     * zero) ou 'poucos' (algum grupo abaixo de MIN_ALUNOS).
     *
     * @return array{p1: float|null, p2: float|null, diferenca: float|null, margem: float|null, selo: string}
     */
    public static function comparar(int $n1, int $k1, int $n2, int $k2): array
    {
        $p1 = $n1 > 0 ? 100.0 * $k1 / $n1 : null;
        $p2 = $n2 > 0 ? 100.0 * $k2 / $n2 : null;
        if ($n1 < self::MIN_ALUNOS || $n2 < self::MIN_ALUNOS || $p1 === null || $p2 === null) {
            return ['p1' => $p1, 'p2' => $p2, 'diferenca' => null, 'margem' => null, 'selo' => 'poucos'];
        }

        $diferenca = $p1 - $p2;
        $margem = self::Z * 100 * sqrt(
            ($p1 / 100) * (1 - $p1 / 100) / $n1 + ($p2 / 100) * (1 - $p2 / 100) / $n2
        );
        $selo = 'incerto';
        if ($diferenca - $margem > 0) {
            $selo = 'melhor';
        } elseif ($diferenca + $margem < 0) {
            $selo = 'pior';
        }

        return ['p1' => $p1, 'p2' => $p2, 'diferenca' => $diferenca, 'margem' => $margem, 'selo' => $selo];
    }

    /**
     * Precisam de nova abordagem: contatados há DIAS_NOVA_ABORDAGEM dias ou
     * mais (até a data dos dados) que não voltaram a frequentar desde o último
     * contato (presença abaixo de 75%, com aulas suficientes para medir) e não
     * saíram. Quem recebeu contato depois da análise sai da lista na hora.
     * Agrupada por curso; dentro dele, prioridade alta (presença abaixo de 50%)
     * primeiro, ingressantes e sumiço repentino na frente.
     *
     * Filtros extras: situacao (tipo ou 'sem_risco'), prioridade ('alta' ou
     * 'media'), contatos (1, 2 ou 3 = três ou mais). Os contadores usam todos
     * os filtros menos situação e prioridade.
     *
     * @param array<string, mixed> $filtros
     * @return array{linhas: list<array<string, mixed>>, total: int, por_situacao: array<string, int>, por_prioridade: array<string, int>}
     */
    public function novaAbordagem(
        int $execucaoId,
        string $dataCorte,
        array $filtros = [],
        int $pagina = 1,
        ?int $porPagina = 25
    ): array {
        [$sqlBase, $params] = $this->filtros($filtros, 'a');
        $params['execucao'] = $execucaoId;
        $params['limite'] = (new \DateTimeImmutable($dataCorte))
            ->modify('-' . self::DIAS_NOVA_ABORDAGEM . ' days')->format('Y-m-d');
        $params['min_aulas'] = self::MIN_AULAS_DESDE;
        $params['voltou'] = self::PRESENCA_VOLTOU;

        $contatos = (int)($filtros['contatos'] ?? 0);
        if ($contatos >= 1 && $contatos <= 2) {
            $sqlBase .= ' AND a.total_contatos = :contatos';
            $params['contatos'] = $contatos;
        } elseif ($contatos >= 3) {
            $sqlBase .= ' AND a.total_contatos >= 3';
        }

        $presenca = '(100.0 * (a.desde_contato_aulas - a.desde_contato_faltas) / a.desde_contato_aulas)';
        $situacao = "COALESCE(a.risco_tipo, 'sem_risco')";
        $prioridade = "CASE WHEN {$presenca} < " . self::PRESENCA_ALTA . " THEN 'alta' ELSE 'media' END";
        $from = '
             FROM acompanhamento_alunos a
             JOIN alunos al ON al.id = a.aluno_id
             JOIN cursos cu ON cu.id = a.curso_id
             LEFT JOIN (
                SELECT aluno_id, curso_id, MAX(substr(data_contato, 1, 10)) AS ultimo
                FROM contatos_aluno GROUP BY aluno_id, curso_id
             ) u ON u.aluno_id = a.aluno_id AND u.curso_id = a.curso_id
             WHERE a.execucao_id = :execucao AND a.saiu = 0
               AND a.ultimo_contato IS NOT NULL
               AND substr(a.ultimo_contato, 1, 10) <= :limite
               AND (u.ultimo IS NULL OR u.ultimo <= substr(a.ultimo_contato, 1, 10))
               AND a.desde_contato_aulas >= :min_aulas
               AND ' . $presenca . ' < :voltou' . $sqlBase;

        $porSituacao = array_fill_keys(array_keys(self::ROTULOS_SITUACAO), 0);
        $porPrioridade = ['alta' => 0, 'media' => 0];
        foreach ($this->consultar(
            "SELECT {$situacao} AS situacao, {$prioridade} AS prioridade, COUNT(*) AS alunos {$from}
             GROUP BY 1, 2",
            $params
        ) as $row) {
            $porSituacao[(string)$row['situacao']] = ($porSituacao[(string)$row['situacao']] ?? 0) + (int)$row['alunos'];
            $porPrioridade[(string)$row['prioridade']] += (int)$row['alunos'];
        }

        $sqlLista = '';
        if (!empty($filtros['situacao']) && isset(self::ROTULOS_SITUACAO[(string)$filtros['situacao']])) {
            $sqlLista .= " AND {$situacao} = :situacao";
            $params['situacao'] = (string)$filtros['situacao'];
        }
        if (in_array($filtros['prioridade'] ?? null, ['alta', 'media'], true)) {
            $sqlLista .= " AND {$prioridade} = :prioridade";
            $params['prioridade'] = (string)$filtros['prioridade'];
        }

        $total = (int)($this->consultar("SELECT COUNT(*) AS n {$from}{$sqlLista}", $params)[0]['n'] ?? 0);
        $limite = '';
        if ($porPagina !== null) {
            $pagina = max(1, $pagina);
            $limite = ' LIMIT :por_pagina OFFSET :offset';
            $params['por_pagina'] = $porPagina;
            $params['offset'] = ($pagina - 1) * $porPagina;
        }
        $linhas = $this->consultar(
            "SELECT a.aluno_id, a.curso_id, al.nome, COALESCE(al.nome_social, '') AS nome_social, al.matricula,
                    cu.nome_curso, {$situacao} AS situacao, a.ingressante, a.auxilio, a.total_contatos,
                    a.ultimo_contato, a.ultimo_canal, a.ultimo_falou, a.ultimo_motivo,
                    a.desde_contato_aulas, a.desde_contato_faltas,
                    {$presenca} AS presenca_desde, {$prioridade} AS prioridade
             {$from}{$sqlLista}
             ORDER BY cu.nome_curso, (prioridade = 'alta') DESC, a.ingressante DESC,
                      (a.risco_tipo = 'faltas_4dias') DESC, presenca_desde, a.ultimo_contato, al.nome
             {$limite}",
            $params
        );

        return [
            'linhas' => $linhas,
            'total' => $total,
            'por_situacao' => $porSituacao,
            'por_prioridade' => $porPrioridade,
        ];
    }

    /**
     * Cobertura dos contatos entre os alunos com alarme crítico (topo da tela),
     * lida direto de contatos_aluno: um contato registrado depois da análise já
     * conta. Contato da equipe = qualquer canal menos o e-mail automático.
     * dias_mediana = do alarme crítico ao 1º contato da equipe (só contatos no
     * dia do alarme ou depois).
     *
     * @param array<string, mixed> $filtros
     * @return array{com_alarme: int, email_automatico: int, equipe: int, sem_contato: int, equipe_dois: int, dias_mediana: float|null}
     */
    public function cobertura(int $execucaoId, array $filtros = []): array
    {
        [$sqlFiltro, $params] = $this->filtros($filtros, 'a');
        $params['execucao'] = $execucaoId;
        $rows = $this->consultar(
            "SELECT a.risco_data,
                    EXISTS (
                        SELECT 1 FROM contatos_aluno c
                        WHERE c.aluno_id = a.aluno_id AND c.curso_id = a.curso_id
                          AND c.data_contato >= e.data_inicio AND c.canal = 'email_automatico'
                    ) AS automatico,
                    (SELECT MIN(substr(c.data_contato, 1, 10)) FROM contatos_aluno c
                     WHERE c.aluno_id = a.aluno_id AND c.curso_id = a.curso_id
                       AND c.data_contato >= e.data_inicio AND c.canal <> 'email_automatico') AS primeiro_equipe,
                    (SELECT COUNT(DISTINCT substr(c.data_contato, 1, 10)) FROM contatos_aluno c
                     WHERE c.aluno_id = a.aluno_id AND c.curso_id = a.curso_id
                       AND c.data_contato >= e.data_inicio AND c.canal <> 'email_automatico') AS dias_equipe
             FROM acompanhamento_alunos a
             JOIN acompanhamento_execucoes e ON e.id = a.execucao_id
             WHERE a.execucao_id = :execucao AND a.risco_tipo IS NOT NULL" . $sqlFiltro,
            $params
        );

        $saida = ['com_alarme' => 0, 'email_automatico' => 0, 'equipe' => 0, 'sem_contato' => 0, 'equipe_dois' => 0];
        $dias = [];
        foreach ($rows as $row) {
            $saida['com_alarme']++;
            $automatico = (int)$row['automatico'] === 1;
            $equipe = $row['primeiro_equipe'] !== null;
            $saida['email_automatico'] += $automatico ? 1 : 0;
            $saida['equipe'] += $equipe ? 1 : 0;
            $saida['sem_contato'] += !$automatico && !$equipe ? 1 : 0;
            $saida['equipe_dois'] += (int)$row['dias_equipe'] >= 2 ? 1 : 0;
            if ($equipe && $row['risco_data'] !== null) {
                $diferenca = (int)(new \DateTimeImmutable(substr((string)$row['risco_data'], 0, 10)))
                    ->diff(new \DateTimeImmutable((string)$row['primeiro_equipe']))->format('%r%a');
                if ($diferenca >= 0) {
                    $dias[] = $diferenca;
                }
            }
        }
        sort($dias);
        $n = count($dias);
        $saida['dias_mediana'] = $n === 0 ? null
            : (float)($n % 2 === 1 ? $dias[intdiv($n, 2)] : ($dias[$n / 2 - 1] + $dias[$n / 2]) / 2);

        return $saida;
    }

    /**
     * O aluno/curso está na análise e dentro do escopo (mesmos filtros de
     * curso e de professor da tela)? Usado antes de registrar contato.
     *
     * @param array<string, mixed> $filtros
     */
    public function alunoNoEscopo(int $execucaoId, int $alunoId, int $cursoId, array $filtros = []): bool
    {
        [$sqlFiltro, $params] = $this->filtros($filtros, 'a');
        $params += ['execucao' => $execucaoId, 'aluno' => $alunoId, 'curso' => $cursoId];

        return $this->consultar(
            'SELECT 1 FROM acompanhamento_alunos a
             WHERE a.execucao_id = :execucao AND a.aluno_id = :aluno AND a.curso_id = :curso' . $sqlFiltro . '
             LIMIT 1',
            $params
        ) !== [];
    }

    /**
     * @param array<string, mixed> $filtros curso_ids, tipo, ingressante, auxilio, professor
     * @return array{0: string, 1: array<string, int|string>}
     */
    private function filtros(array $filtros, string $alias): array
    {
        $sql = '';
        $params = [];
        $cursoIds = $filtros['curso_ids'] ?? null;
        if ($cursoIds !== null) {
            if ($cursoIds === []) {
                return [' AND 1 = 0', []];
            }
            $placeholders = [];
            foreach (array_values($cursoIds) as $i => $cursoId) {
                $placeholders[] = ':curso_' . $i;
                $params['curso_' . $i] = (int)$cursoId;
            }
            $sql .= ' AND ' . $alias . '.curso_id IN (' . implode(', ', $placeholders) . ')';
        }
        if (!empty($filtros['tipo'])) {
            $sql .= ' AND ' . $alias . '.risco_tipo = :tipo';
            $params['tipo'] = (string)$filtros['tipo'];
        }
        foreach (['ingressante', 'auxilio'] as $coluna) {
            if (isset($filtros[$coluna])) {
                $sql .= ' AND ' . $alias . '.' . $coluna . ' = :' . $coluna;
                $params[$coluna] = $filtros[$coluna] ? 1 : 0;
            }
        }
        if (is_array($filtros['professor'] ?? null)) {
            [$sqlProfessor, $paramsProfessor] = self::sqlEscopoProfessor($filtros['professor'], $alias);
            $sql .= $sqlProfessor;
            $params += $paramsProfessor;
        }

        return [$sql, $params];
    }

    /**
     * Professor: só alunos matriculados nas disciplinas (e turmas) dele na
     * coleta da análise. $alias é a tabela com aluno_id e curso_id. Sem
     * disciplinas vinculadas, não vê ninguém.
     *
     * @param array{coleta_id?: int, codigos?: list<string>, turmas?: list<int>} $professor
     * @return array{0: string, 1: array<string, int|string>}
     */
    public static function sqlEscopoProfessor(array $professor, string $alias): array
    {
        $codigos = array_values($professor['codigos'] ?? []);
        if ($codigos === []) {
            return [' AND 1 = 0', []];
        }
        $params = [];
        $phCodigos = [];
        foreach ($codigos as $i => $codigo) {
            $phCodigos[] = ':prof_disc_' . $i;
            $params['prof_disc_' . $i] = (string)$codigo;
        }
        $sqlTurmas = '';
        $turmas = array_values($professor['turmas'] ?? []);
        if ($turmas !== []) {
            $phTurmas = [];
            foreach ($turmas as $i => $turma) {
                $phTurmas[] = ':prof_turma_' . $i;
                $params['prof_turma_' . $i] = (int)$turma;
            }
            $sqlTurmas = ' AND (fd.id_turma IS NULL OR fd.id_turma IN (' . implode(', ', $phTurmas) . '))';
        }
        $params['prof_coleta'] = (int)($professor['coleta_id'] ?? 0);

        return [
            ' AND EXISTS (
                SELECT 1 FROM frequencia_disciplina fd
                WHERE fd.coleta_id = :prof_coleta AND fd.aluno_id = ' . $alias . '.aluno_id
                  AND fd.curso_id = ' . $alias . '.curso_id
                  AND fd.codigo_disciplina IN (' . implode(', ', $phCodigos) . ')' . $sqlTurmas . '
            )',
            $params,
        ];
    }

    /**
     * Inteiros vão como PARAM_INT (comparados a colunas inteiras, um parâmetro
     * texto nunca é convertido pelo SQLite).
     *
     * @param array<string, int|string> $params
     * @return list<array<string, mixed>>
     */
    private function consultar(string $sql, array $params): array
    {
        $statement = $this->db->prepare($sql);
        foreach ($params as $nome => $valor) {
            $statement->bindValue($nome, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->execute();

        return $statement->fetchAll();
    }
}
