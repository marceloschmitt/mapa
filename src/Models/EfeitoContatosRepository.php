<?php
declare(strict_types=1);

namespace Mapa\Models;

use Mapa\Core\Database;
use PDO;

/**
 * Leitura da análise gerada por python/gerar_efeito_contatos.py.
 *
 * Cada aluno tem uma série semanal de aulas e faltas em torno de uma data de
 * referência (semana -1 = 7 dias antes, 1 = 7 dias depois). Contatados: dia do
 * 1º contato ('primeiro') ou do 2º ('segundo'). Comparação: alunos com alarme
 * crítico ainda sem contato, no dia do alarme ('primeiro').
 */
class EfeitoContatosRepository
{
    /** Mínimo de alunos para mostrar um ponto da curva e o efeito líquido. */
    public const MIN_ALUNOS = 10;

    /** Lado "depois" até o corte: todas as semanas depois da referência. */
    private const TODAS_AS_SEMANAS = 999;

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
                    total_eventos, executado_em
             FROM efeito_contatos_execucoes
             ORDER BY id DESC
             LIMIT 1'
        )->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Contatados (no total e por canal do dia da referência) e comparação: taxa de
     * faltas nas $semanas semanas antes da referência × nas $semanas depois
     * ('janela') ou em todas as semanas depois ('corte'). Entram na conta
     * ('analisados') os alunos com pelo menos $minAulas aulas antes e depois.
     * $limiteFaltas = maior taxa de faltas com frequência no limite (ex.: 25 para 75%).
     *
     * @param list<int>|null $cursoIds
     * @return array<string, mixed>
     */
    public function resumo(
        int $execucaoId,
        string $referencia,
        int $semanas,
        string $depois,
        int $minAulas,
        float $limiteFaltas,
        ?array $cursoIds
    ): array {
        [$filtro, $params] = $this->filtroCurso('s.curso_id', $cursoIds);
        $params += [
            'execucao' => $execucaoId,
            'referencia' => $referencia,
            'inicio_antes' => -$semanas,
            'fim_depois' => $depois === 'corte' ? self::TODAS_AS_SEMANAS : $semanas,
        ];

        $rows = $this->consultar(
            'SELECT s.grupo, s.canais, s.total_contatos,
                    COALESCE(SUM(CASE WHEN w.semana BETWEEN :inicio_antes AND -1 THEN w.aulas END), 0) AS aulas_antes,
                    COALESCE(SUM(CASE WHEN w.semana BETWEEN :inicio_antes AND -1 THEN w.faltas END), 0) AS faltas_antes,
                    COALESCE(SUM(CASE WHEN w.semana BETWEEN 1 AND :fim_depois THEN w.aulas END), 0) AS aulas_depois,
                    COALESCE(SUM(CASE WHEN w.semana BETWEEN 1 AND :fim_depois THEN w.faltas END), 0) AS faltas_depois
             FROM efeito_contatos_series s
             LEFT JOIN efeito_contatos_semanas w ON w.serie_id = s.id
             WHERE s.execucao_id = :execucao
               AND s.referencia = :referencia' . $filtro . '
             GROUP BY s.id',
            $params
        );

        $minimo = max(1, $minAulas);
        $saida = [
            'contatos' => 0,
            'varios' => 0,
            'contatado' => $this->novoGrupo() + ['canais' => []],
            'comparacao' => $this->novoGrupo(),
        ];
        foreach ($rows as $row) {
            if ($row['grupo'] !== 'contatado') {
                $this->acumular($saida['comparacao'], $row, $minimo, $limiteFaltas);
                continue;
            }
            $saida['contatos'] += (int)$row['total_contatos'];
            if ((int)$row['total_contatos'] > 1) {
                $saida['varios']++;
            }
            $this->acumular($saida['contatado'], $row, $minimo, $limiteFaltas);
            foreach (array_filter(explode(',', (string)$row['canais'])) as $canal) {
                if (!isset($saida['contatado']['canais'][$canal])) {
                    $saida['contatado']['canais'][$canal] = $this->novoGrupo();
                }
                $this->acumular($saida['contatado']['canais'][$canal], $row, $minimo, $limiteFaltas);
            }
        }

        $this->finalizar($saida['comparacao']);
        $this->finalizar($saida['contatado']);
        $saida['contatado']['efeito'] = $this->efeitoLiquido($saida['contatado'], $saida['comparacao']);
        foreach ($saida['contatado']['canais'] as &$porCanal) {
            $this->finalizar($porCanal);
            $porCanal['efeito'] = $this->efeitoLiquido($porCanal, $saida['comparacao']);
        }
        unset($porCanal);

        return $saida;
    }

    /**
     * Taxa média de faltas por semana relativa, de cada grupo. Só os alunos que
     * entram no resumo da janela de $semanas semanas (aulas suficientes antes e
     * depois), para a curva e os números falarem dos mesmos alunos.
     *
     * @param list<int>|null $cursoIds
     * @return array<string, array<int, array{taxa: float, alunos: int}>>
     */
    public function serieSemanal(
        int $execucaoId,
        string $referencia,
        int $semanas,
        int $minAulas,
        ?array $cursoIds
    ): array {
        [$filtro, $params] = $this->filtroCurso('s.curso_id', $cursoIds);
        $params += [
            'execucao' => $execucaoId,
            'referencia' => $referencia,
            'inicio_antes' => -$semanas,
            'fim_depois' => $semanas,
            'minimo' => max(1, $minAulas),
        ];

        $rows = $this->consultar(
            'WITH analisadas AS (
                 SELECT s.id, s.grupo
                 FROM efeito_contatos_series s
                 JOIN efeito_contatos_semanas w ON w.serie_id = s.id
                 WHERE s.execucao_id = :execucao
                   AND s.referencia = :referencia' . $filtro . '
                 GROUP BY s.id
                 HAVING SUM(CASE WHEN w.semana BETWEEN :inicio_antes AND -1 THEN w.aulas ELSE 0 END) >= :minimo
                    AND SUM(CASE WHEN w.semana BETWEEN 1 AND :fim_depois THEN w.aulas ELSE 0 END) >= :minimo
             )
             SELECT a.grupo, w.semana, COUNT(*) AS alunos, AVG(100.0 * w.faltas / w.aulas) AS taxa
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
                'taxa' => (float)$row['taxa'],
                'alunos' => (int)$row['alunos'],
            ];
        }

        return $saida;
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
            'frequentes_antes' => 0,
            'frequentes_depois' => 0,
            'soma_antes' => 0.0,
            'soma_depois' => 0.0,
            'soma_quadrados' => 0.0,
        ];
    }

    /**
     * @param array<string, mixed> $grupo
     * @param array<string, mixed> $row
     */
    private function acumular(array &$grupo, array $row, int $minimo, float $limiteFaltas): void
    {
        $grupo['alunos']++;
        $aulasAntes = (int)$row['aulas_antes'];
        $faltasAntes = (int)$row['faltas_antes'];
        $aulasDepois = (int)$row['aulas_depois'];
        $faltasDepois = (int)$row['faltas_depois'];
        if ($aulasAntes < $minimo || $aulasDepois < $minimo) {
            return;
        }

        $taxaAntes = 100 * $faltasAntes / $aulasAntes;
        $taxaDepois = 100 * $faltasDepois / $aulasDepois;
        $grupo['analisados']++;
        $grupo['soma_antes'] += $taxaAntes;
        $grupo['soma_depois'] += $taxaDepois;
        $grupo['soma_quadrados'] += ($taxaDepois - $taxaAntes) ** 2;

        // Produto cruzado: compara as taxas sem erro de arredondamento.
        $comparacao = $faltasDepois * $aulasAntes <=> $faltasAntes * $aulasDepois;
        $grupo[$comparacao < 0 ? 'melhoraram' : ($comparacao > 0 ? 'pioraram' : 'iguais')]++;

        if ($taxaAntes <= $limiteFaltas + 1e-9) {
            $grupo['frequentes_antes']++;
        }
        if ($taxaDepois <= $limiteFaltas + 1e-9) {
            $grupo['frequentes_depois']++;
        }
    }

    /**
     * Médias de todos os analisados (não só dos que melhoraram) e desvio-padrão da variação.
     *
     * @param array<string, mixed> $grupo
     */
    private function finalizar(array &$grupo): void
    {
        $n = (int)$grupo['analisados'];
        $antes = $n > 0 ? $grupo['soma_antes'] / $n : null;
        $depoisMedia = $n > 0 ? $grupo['soma_depois'] / $n : null;
        $variacao = $n > 0 ? $depoisMedia - $antes : null;
        $desvio = null;
        if ($n > 1) {
            $desvio = sqrt(max(0.0, ($grupo['soma_quadrados'] - $n * $variacao ** 2) / ($n - 1)));
        }

        $grupo['taxa_antes'] = $antes;
        $grupo['taxa_depois'] = $depoisMedia;
        $grupo['variacao'] = $variacao;
        $grupo['desvio'] = $desvio;
        unset($grupo['soma_antes'], $grupo['soma_depois'], $grupo['soma_quadrados']);
    }

    /**
     * Variação dos contatados menos a da comparação (p.p. de faltas; negativo =
     * os contatados passaram a faltar menos), com intervalo de 95%.
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

    /**
     * @param list<int>|null $cursoIds
     * @return array{0: string, 1: array<string, int>}
     */
    private function filtroCurso(string $coluna, ?array $cursoIds): array
    {
        if ($cursoIds === null) {
            return ['', []];
        }
        if ($cursoIds === []) {
            return [' AND 1 = 0', []];
        }

        $placeholders = [];
        $params = [];
        foreach (array_values($cursoIds) as $i => $cursoId) {
            $placeholders[] = ':curso_' . $i;
            $params['curso_' . $i] = (int)$cursoId;
        }

        return [' AND ' . $coluna . ' IN (' . implode(', ', $placeholders) . ')', $params];
    }
}
