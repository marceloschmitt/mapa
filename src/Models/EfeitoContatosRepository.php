<?php
declare(strict_types=1);

namespace Mapa\Models;

use Mapa\Core\Database;
use PDO;

/**
 * Leitura da análise gerada por python/gerar_efeito_contatos.py, para a tela
 * Eficácia dos alarmes.
 *
 * Só entram alunos com alarme crítico real (tabela alarmes). Cada um tem uma
 * série semanal de aulas e faltas em torno de uma data de referência (semana -1
 * = 7 dias antes, 1 = 7 dias depois). O e-mail automático sai para quase todos,
 * então a pergunta é o contato da equipe: "contatado" = dia do 1º contato da
 * equipe ('primeiro') ou do 2º ('segundo'); "comparação" = sem contato da
 * equipe até a mesma distância do alarme (ou, no 'segundo', do 1º contato).
 *
 * Duas análises, sempre contra a comparação:
 *  1. presença antes × depois (média dos alunos e efeito líquido com margem de 95%);
 *  2. voltou a frequentar: dos que estavam abaixo de 75% antes, quantos chegaram a 75% depois.
 */
class EfeitoContatosRepository
{
    /** Mínimo de alunos para mostrar um ponto da curva e o efeito líquido. */
    public const MIN_ALUNOS = 10;

    /** Presença (%) que conta como "voltou a frequentar". */
    public const PRESENCA_VOLTOU = 75;

    public const ROTULOS_TIPO = [
        'percentual_baixo' => 'Frequência abaixo de 50%',
        'faltas_3semanas' => '3 semanas sem aparecer',
        'faltas_4dias' => 'Dias seguidos de falta',
    ];

    public const ROTULOS_FALOU = [
        'sim' => 'Conseguiu falar',
        'nao' => 'Não conseguiu falar',
        'sem_resposta' => 'Não informado',
    ];

    public const ROTULOS_RAPIDEZ = [
        'antes' => 'Antes do alarme crítico',
        'ate3' => 'Até 3 dias do alarme',
        'ate7' => 'De 4 a 7 dias',
        'mais7' => 'Mais de 7 dias',
    ];

    /**
     * t de Student (95%, bicaudal) por grau de liberdade; vale a maior chave ≤ gl
     * (arredonda o intervalo para cima).
     */
    private const T_95 = [9 => 2.262, 14 => 2.145, 19 => 2.093, 29 => 2.045, 49 => 2.010, 99 => 1.984, 999 => 1.962];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /** @return array<string, mixed>|null */
    public function ultimaExecucao(): ?array
    {
        $row = $this->db->query(
            'SELECT id, coleta_id, data_inicio, data_corte, janela_dias, min_aulas,
                    total_eventos, intervalo_segundo, intervalo_equipe, executado_em
             FROM efeito_contatos_execucoes
             ORDER BY id DESC
             LIMIT 1'
        )->fetch();

        return $row === false ? null : $row;
    }

    /**
     * As duas análises do 1º contato, no total e por recorte, contra a
     * comparação. Analisados = alunos com pelo menos $minAulas aulas nas
     * $semanas semanas antes e nas $semanas depois.
     *
     * Recortes: canal e rapidez só existem nos contatados (comparam com a
     * comparação inteira); tipo de alarme, ingresso, auxílio e curso comparam
     * com a parte da comparação do mesmo recorte.
     *
     * $referencia 'segundo' = efeito do 2º contato: contatados alinhados no 2º
     * contato; comparação = contatados uma vez, alinhados à mesma distância do
     * 1º (intervalo_segundo). Com $soAbaixo, ficam só os que ainda estavam
     * abaixo de 75% antes (quem já tinha voltado não precisa de insistência).
     *
     * @param array<string, mixed> $filtros curso_ids, professor, tipo, ingressante, auxilio
     * @return array<string, mixed>
     */
    public function avaliar(
        int $execucaoId,
        array $filtros,
        int $semanas,
        int $minAulas,
        string $referencia = 'primeiro',
        bool $soAbaixo = false
    ): array {
        [$filtro, $params] = $this->filtros($filtros);
        $params += [
            'execucao' => $execucaoId,
            'referencia' => $referencia === 'segundo' ? 'segundo' : 'primeiro',
            'inicio_antes' => -$semanas,
            'fim_depois' => $semanas,
        ];

        $rows = $this->consultar(
            'SELECT s.aluno_id, s.curso_id, cu.nome_curso, s.grupo, s.canais, s.total_contatos,
                    s.alarme_tipo, s.dias_ate_contato, s.falou, s.ingressante, s.auxilio,
                    COALESCE(SUM(CASE WHEN w.semana BETWEEN :inicio_antes AND -1 THEN w.aulas END), 0) AS aulas_antes,
                    COALESCE(SUM(CASE WHEN w.semana BETWEEN :inicio_antes AND -1 THEN w.faltas END), 0) AS faltas_antes,
                    COALESCE(SUM(CASE WHEN w.semana BETWEEN 1 AND :fim_depois THEN w.aulas END), 0) AS aulas_depois,
                    COALESCE(SUM(CASE WHEN w.semana BETWEEN 1 AND :fim_depois THEN w.faltas END), 0) AS faltas_depois
             FROM efeito_contatos_series s
             LEFT JOIN cursos cu ON cu.id = s.curso_id
             LEFT JOIN efeito_contatos_semanas w ON w.serie_id = s.id
             WHERE s.execucao_id = :execucao
               AND s.referencia = :referencia' . $filtro . '
             GROUP BY s.id',
            $params
        );

        $minimo = max(1, $minAulas);
        if ($soAbaixo) {
            $rows = array_values(array_filter($rows, fn(array $row): bool => !$this->jaFrequentava($row, $minimo)));
        }
        $total = ['contatado' => $this->novoGrupo(), 'comparacao' => $this->novoGrupo()];
        $recortes = [];
        $comAlarme = [];
        $contatados = [];
        $dias = [];
        $contatos = 0;
        $varios = 0;

        foreach ($rows as $row) {
            $grupo = (string)$row['grupo'];
            $par = $row['aluno_id'] . '-' . $row['curso_id'];
            $comAlarme[$par] = true;
            $this->acumular($total[$grupo], $row, $minimo);

            $chaves = [
                'tipo' => (string)($row['alarme_tipo'] ?? ''),
                'ingressante' => (int)$row['ingressante'] === 1 ? 'sim' : 'nao',
                'auxilio' => (int)$row['auxilio'] === 1 ? 'sim' : 'nao',
                'curso' => (string)($row['nome_curso'] ?? 'Curso ' . $row['curso_id']),
            ];
            if ($grupo === 'contatado') {
                $contatados[$par] = true;
                $contatos += (int)$row['total_contatos'];
                $varios += (int)$row['total_contatos'] > 1 ? 1 : 0;
                $diasAte = $row['dias_ate_contato'];
                if ($diasAte !== null && (int)$diasAte >= 0) {
                    $dias[] = (int)$diasAte;
                }
                $chaves['rapidez'] = self::faixaRapidez($diasAte === null ? null : (int)$diasAte);
                $chaves['falou'] = $row['falou'] === null ? 'sem_resposta' : ((int)$row['falou'] === 1 ? 'sim' : 'nao');
                foreach (array_filter(explode(',', (string)$row['canais'])) as $canal) {
                    $this->acumularRecorte($recortes, 'canal', $canal, $grupo, $row, $minimo);
                }
            }
            foreach ($chaves as $recorte => $chave) {
                if ($chave !== '') {
                    $this->acumularRecorte($recortes, $recorte, $chave, $grupo, $row, $minimo);
                }
            }
        }

        $saida = $this->fecharComparacao($total['contatado'], $total['comparacao']);
        $semComparacaoPropria = ['canal', 'rapidez', 'falou'];
        foreach ($recortes as $recorte => &$porChave) {
            foreach ($porChave as &$grupos) {
                $comparacao = in_array($recorte, $semComparacaoPropria, true)
                    ? $total['comparacao']
                    : $grupos['comparacao'];
                $grupos = $this->fecharComparacao($grupos['contatado'], $comparacao);
            }
            unset($grupos);
            if ($recorte === 'curso') {
                ksort($porChave, SORT_NATURAL | SORT_FLAG_CASE);
            }
        }
        unset($porChave);

        sort($dias);
        $n = count($dias);

        return $saida + [
            'resumo' => [
                'com_alarme' => count($comAlarme),
                'contatados' => count($contatados),
                'contatos' => $contatos,
                'varios' => $varios,
                'dias_mediana' => $n === 0 ? null
                    : (float)($n % 2 === 1 ? $dias[intdiv($n, 2)] : ($dias[$n / 2 - 1] + $dias[$n / 2]) / 2),
            ],
            'recortes' => $recortes,
        ];
    }

    /**
     * Presença média (%) por semana relativa, de cada grupo (1º contato). Só os
     * alunos que entram nas análises (aulas suficientes antes e depois e, com
     * $soAbaixo, abaixo de 75% antes), para a curva e os números falarem dos
     * mesmos alunos.
     *
     * @param array<string, mixed> $filtros
     * @return array<string, array<int, array{presenca: float, alunos: int}>>
     */
    public function serieSemanal(int $execucaoId, array $filtros, int $semanas, int $minAulas, bool $soAbaixo = false): array
    {
        [$filtro, $params] = $this->filtros($filtros);
        $params += [
            'execucao' => $execucaoId,
            'inicio_antes' => -$semanas,
            'fim_depois' => $semanas,
            'minimo' => max(1, $minAulas),
        ];
        $abaixo = '';
        if ($soAbaixo) {
            $abaixo = ' AND SUM(CASE WHEN w.semana BETWEEN :inicio_antes2 AND -1 THEN w.aulas - w.faltas ELSE 0 END) * 100
                          < :voltou * SUM(CASE WHEN w.semana BETWEEN :inicio_antes3 AND -1 THEN w.aulas ELSE 0 END)';
            $params += ['inicio_antes2' => -$semanas, 'inicio_antes3' => -$semanas, 'voltou' => self::PRESENCA_VOLTOU];
        }

        $rows = $this->consultar(
            'WITH analisadas AS (
                 SELECT s.id, s.grupo
                 FROM efeito_contatos_series s
                 JOIN efeito_contatos_semanas w ON w.serie_id = s.id
                 WHERE s.execucao_id = :execucao
                   AND s.referencia = \'primeiro\'' . $filtro . '
                 GROUP BY s.id
                 HAVING SUM(CASE WHEN w.semana BETWEEN :inicio_antes AND -1 THEN w.aulas ELSE 0 END) >= :minimo
                    AND SUM(CASE WHEN w.semana BETWEEN 1 AND :fim_depois THEN w.aulas ELSE 0 END) >= :minimo' . $abaixo . '
             )
             SELECT a.grupo, w.semana, COUNT(*) AS alunos, AVG(100.0 - 100.0 * w.faltas / w.aulas) AS presenca
             FROM analisadas a
             JOIN efeito_contatos_semanas w ON w.serie_id = a.id
             WHERE w.aulas > 0
             GROUP BY a.grupo, w.semana
             ORDER BY w.semana',
            $params
        );

        $saida = ['contatado' => [], 'comparacao' => []];
        foreach ($rows as $row) {
            $saida[(string)$row['grupo']][(int)$row['semana']] = [
                'presenca' => (float)$row['presenca'],
                'alunos' => (int)$row['alunos'],
            ];
        }

        return $saida;
    }

    /**
     * Já estava com 75% ou mais nas semanas antes (com aulas suficientes para dizer)?
     *
     * @param array<string, mixed> $row
     */
    private function jaFrequentava(array $row, int $minimo): bool
    {
        $aulas = (int)$row['aulas_antes'];

        return $aulas >= $minimo
            && ($aulas - (int)$row['faltas_antes']) * 100 >= self::PRESENCA_VOLTOU * $aulas;
    }

    /** Faixa de dias do alarme crítico ao 1º contato (negativo = contato antes do alarme). */
    public static function faixaRapidez(?int $dias): string
    {
        if ($dias === null) {
            return '';
        }
        if ($dias < 0) {
            return 'antes';
        }

        return $dias <= 3 ? 'ate3' : ($dias <= 7 ? 'ate7' : 'mais7');
    }

    /**
     * Efeito líquido (análise 1) e comparação do "voltou" (análise 2) de um grupo de contatados.
     *
     * @param array<string, mixed> $contatado
     * @param array<string, mixed> $comparacao
     * @return array<string, mixed>
     */
    private function fecharComparacao(array $contatado, array $comparacao): array
    {
        $this->finalizar($contatado);
        $this->finalizar($comparacao);

        return [
            'contatado' => $contatado,
            'comparacao' => $comparacao,
            'efeito' => $this->efeitoLiquido($contatado, $comparacao),
            'voltou' => AcompanhamentoRepository::comparar(
                (int)$contatado['abaixo_antes'],
                (int)$contatado['voltaram'],
                (int)$comparacao['abaixo_antes'],
                (int)$comparacao['voltaram']
            ),
        ];
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $recortes
     * @param array<string, mixed> $row
     */
    private function acumularRecorte(array &$recortes, string $recorte, string $chave, string $grupo, array $row, int $minimo): void
    {
        if (!isset($recortes[$recorte][$chave])) {
            $recortes[$recorte][$chave] = ['contatado' => $this->novoGrupo(), 'comparacao' => $this->novoGrupo()];
        }
        $this->acumular($recortes[$recorte][$chave][$grupo], $row, $minimo);
    }

    /**
     * Filtros da tela: curso_ids, professor (disciplinas/turmas na coleta da
     * análise), tipo do alarme, ingressante, auxilio.
     *
     * @param array<string, mixed> $filtros
     * @return array{0: string, 1: array<string, int|string>}
     */
    private function filtros(array $filtros): array
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
            $sql .= ' AND s.curso_id IN (' . implode(', ', $placeholders) . ')';
        }
        if (!empty($filtros['tipo'])) {
            $sql .= ' AND s.alarme_tipo = :tipo';
            $params['tipo'] = (string)$filtros['tipo'];
        }
        foreach (['ingressante', 'auxilio'] as $coluna) {
            if (isset($filtros[$coluna])) {
                $sql .= ' AND s.' . $coluna . ' = :' . $coluna;
                $params[$coluna] = $filtros[$coluna] ? 1 : 0;
            }
        }
        if (is_array($filtros['professor'] ?? null)) {
            [$sqlProfessor, $paramsProfessor] = AcompanhamentoRepository::sqlEscopoProfessor($filtros['professor'], 's');
            $sql .= $sqlProfessor;
            $params += $paramsProfessor;
        }

        return [$sql, $params];
    }

    /**
     * Inteiros vão como PARAM_INT: comparado a SUM(...), um parâmetro texto
     * nunca é convertido pelo SQLite e a comparação sai sempre falsa.
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

    /** @return array<string, mixed> */
    private function novoGrupo(): array
    {
        return [
            'alunos' => 0,
            'analisados' => 0,
            'melhoraram' => 0,
            'iguais' => 0,
            'pioraram' => 0,
            'abaixo_antes' => 0,
            'voltaram' => 0,
            'soma_antes' => 0.0,
            'soma_depois' => 0.0,
            'soma_quadrados' => 0.0,
        ];
    }

    /**
     * Soma um aluno ao grupo. Taxas em presença (%): 100 − faltas ÷ aulas.
     *
     * @param array<string, mixed> $grupo
     * @param array<string, mixed> $row
     */
    private function acumular(array &$grupo, array $row, int $minimo): void
    {
        $grupo['alunos']++;
        $aulasAntes = (int)$row['aulas_antes'];
        $faltasAntes = (int)$row['faltas_antes'];
        $aulasDepois = (int)$row['aulas_depois'];
        $faltasDepois = (int)$row['faltas_depois'];
        if ($aulasAntes < $minimo || $aulasDepois < $minimo) {
            return;
        }

        $antes = 100 - 100 * $faltasAntes / $aulasAntes;
        $depois = 100 - 100 * $faltasDepois / $aulasDepois;
        $grupo['analisados']++;
        $grupo['soma_antes'] += $antes;
        $grupo['soma_depois'] += $depois;
        $grupo['soma_quadrados'] += ($depois - $antes) ** 2;

        // Produto cruzado: compara as taxas sem erro de arredondamento.
        $comparacao = $faltasDepois * $aulasAntes <=> $faltasAntes * $aulasDepois;
        $grupo[$comparacao < 0 ? 'melhoraram' : ($comparacao > 0 ? 'pioraram' : 'iguais')]++;

        // Voltou: estava abaixo de 75% e chegou a 75% (presenças × 100 ≥ 75 × aulas, sem arredondar).
        if (($aulasAntes - $faltasAntes) * 100 < self::PRESENCA_VOLTOU * $aulasAntes) {
            $grupo['abaixo_antes']++;
            if (($aulasDepois - $faltasDepois) * 100 >= self::PRESENCA_VOLTOU * $aulasDepois) {
                $grupo['voltaram']++;
            }
        }
    }

    /**
     * Médias de todos os analisados (não só dos que melhoraram) e desvio-padrão da variação.
     *
     * @param array<string, mixed> $grupo
     */
    private function finalizar(array &$grupo): void
    {
        if (!array_key_exists('soma_antes', $grupo)) {
            return;
        }
        $n = (int)$grupo['analisados'];
        $antes = $n > 0 ? $grupo['soma_antes'] / $n : null;
        $depoisMedia = $n > 0 ? $grupo['soma_depois'] / $n : null;
        $variacao = $n > 0 ? $depoisMedia - $antes : null;
        $desvio = null;
        if ($n > 1) {
            $desvio = sqrt(max(0.0, ($grupo['soma_quadrados'] - $n * $variacao ** 2) / ($n - 1)));
        }

        $grupo['presenca_antes'] = $antes;
        $grupo['presenca_depois'] = $depoisMedia;
        $grupo['variacao'] = $variacao;
        $grupo['desvio'] = $desvio;
        unset($grupo['soma_antes'], $grupo['soma_depois'], $grupo['soma_quadrados']);
    }

    /**
     * Variação de presença dos contatados menos a da comparação (p.p.; positivo
     * = os contatados passaram a frequentar mais), com intervalo de 95%.
     *
     * @param array<string, mixed> $contatado
     * @param array<string, mixed> $comparacao
     * @return array{valor: float, min: float, max: float}|null
     */
    private function efeitoLiquido(array $contatado, array $comparacao): ?array
    {
        $n1 = (int)$contatado['analisados'];
        $n2 = (int)$comparacao['analisados'];
        if ($n1 < self::MIN_ALUNOS || $n2 < self::MIN_ALUNOS) {
            return null;
        }

        $valor = (float)$contatado['variacao'] - (float)$comparacao['variacao'];
        $erro = sqrt((float)$contatado['desvio'] ** 2 / $n1 + (float)$comparacao['desvio'] ** 2 / $n2);
        $margem = $this->valorT(min($n1, $n2) - 1) * $erro;

        return ['valor' => $valor, 'min' => $valor - $margem, 'max' => $valor + $margem];
    }

    private function valorT(int $grausLiberdade): float
    {
        $t = self::T_95[9];
        foreach (self::T_95 as $gl => $valor) {
            if ($gl <= $grausLiberdade) {
                $t = $valor;
            }
        }

        return $t;
    }
}
