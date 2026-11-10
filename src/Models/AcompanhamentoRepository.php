<?php
declare(strict_types=1);

namespace Mapa\Models;

use Mapa\Core\Database;
use PDO;

/**
 * Leitura do acompanhamento dos alunos em risco (acompanhamento_alunos, gerado
 * por python/gerar_efeito_contatos.py) e da referência histórica
 * (referencia_historica_alunos, gerada por python/gerar_referencia_historica.py).
 */
class AcompanhamentoRepository
{
    public const TIPOS = ['faltas_3semanas', 'faltas_4dias', 'percentual_baixo'];
    public const RETORNOS = ['voltou', 'parcial', 'nao_voltou'];

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
     * Compara a proporção de um grupo com a do outro (ex.: voltou com MAPA x
     * antes do MAPA). Diferença em pontos percentuais e margem de 95%; selo:
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
     * Funil do semestre atual: em risco → contatados → retorno em 2 semanas,
     * no total e por situação. Retorno medido = classificado (as 2 semanas
     * couberam nos dados), separado entre contatados e ainda não contatados.
     *
     * @param array{curso_ids?: list<int>|null, tipo?: string|null, ingressante?: bool|null, auxilio?: bool|null, semana_max?: int|null} $filtros
     * @return array<string, mixed>
     */
    public function funil(int $execucaoId, array $filtros = []): array
    {
        [$sqlFiltro, $params] = $this->filtros($filtros, 'a');
        $params['execucao'] = $execucaoId;

        $rows = $this->consultar(
            'SELECT a.risco_tipo, a.retorno, a.dias_ate_contato,
                    a.primeiro_contato IS NOT NULL AS contatado,
                    a.ultimo_contato, a.desde_contato_aulas, a.desde_contato_faltas, a.saiu
             FROM acompanhamento_alunos a
             WHERE a.execucao_id = :execucao AND a.risco_tipo IS NOT NULL' . $sqlFiltro,
            $params
        );

        $vazio = static fn(): array => [
            'em_risco' => 0, 'contatados' => 0, 'dias' => [],
            'contatados_medidos' => self::retornoVazio(), 'nao_contatados_medidos' => self::retornoVazio(),
        ];
        $total = $vazio();
        $porTipo = array_fill_keys(self::TIPOS, null);
        foreach (self::TIPOS as $tipo) {
            $porTipo[$tipo] = $vazio();
        }

        foreach ($rows as $row) {
            foreach ([&$total, &$porTipo[(string)$row['risco_tipo']]] as &$grupo) {
                $grupo['em_risco']++;
                if ((int)$row['contatado'] === 1) {
                    $grupo['contatados']++;
                    if ($row['dias_ate_contato'] !== null && (int)$row['dias_ate_contato'] >= 0) {
                        $grupo['dias'][] = (int)$row['dias_ate_contato'];
                    }
                }
                $retorno = (string)($row['retorno'] ?? '');
                if (in_array($retorno, self::RETORNOS, true)) {
                    $chave = (int)$row['contatado'] === 1 ? 'contatados_medidos' : 'nao_contatados_medidos';
                    $grupo[$chave]['medidos']++;
                    $grupo[$chave][$retorno]++;
                }
            }
            unset($grupo);
        }

        $fechar = static function (array $grupo): array {
            $dias = $grupo['dias'];
            sort($dias);
            $n = count($dias);
            $grupo['dias_mediana'] = $n === 0 ? null
                : (float)($n % 2 === 1 ? $dias[intdiv($n, 2)] : ($dias[$n / 2 - 1] + $dias[$n / 2]) / 2);
            unset($grupo['dias']);

            return $grupo;
        };

        return ['total' => $fechar($total), 'por_tipo' => array_map($fechar, $porTipo)];
    }

    /**
     * Retorno em 2 semanas nos semestres sem MAPA (referência histórica), no
     * total e por situação, com os mesmos filtros do funil.
     *
     * @param array{curso_ids?: list<int>|null, tipo?: string|null, ingressante?: bool|null, semana_max?: int|null} $filtros
     * @return array{periodos: list<string>, total: array<string, int>, por_tipo: array<string, array<string, int>>}
     */
    public function referencia(array $filtros = []): array
    {
        [$sqlFiltro, $params] = $this->filtros($filtros, 'r');
        $rows = $this->consultar(
            'SELECT r.periodo, r.risco_tipo, r.retorno, COUNT(*) AS alunos
             FROM referencia_historica_alunos r
             WHERE r.com_mapa = 0 AND r.risco_tipo IS NOT NULL' . $sqlFiltro . '
             GROUP BY r.periodo, r.risco_tipo, r.retorno',
            $params
        );

        $periodos = [];
        $total = self::retornoVazio() + ['em_risco' => 0];
        $porTipo = [];
        foreach (self::TIPOS as $tipo) {
            $porTipo[$tipo] = self::retornoVazio() + ['em_risco' => 0];
        }
        foreach ($rows as $row) {
            $periodos[(string)$row['periodo']] = true;
            $alunos = (int)$row['alunos'];
            $retorno = (string)($row['retorno'] ?? '');
            foreach ([&$total, &$porTipo[(string)$row['risco_tipo']]] as &$grupo) {
                $grupo['em_risco'] += $alunos;
                if (in_array($retorno, self::RETORNOS, true)) {
                    $grupo['medidos'] += $alunos;
                    $grupo[$retorno] += $alunos;
                }
            }
            unset($grupo);
        }
        $periodos = array_keys($periodos);
        sort($periodos);

        return ['periodos' => $periodos, 'total' => $total, 'por_tipo' => $porTipo];
    }

    /** @return array<string, int> */
    private static function retornoVazio(): array
    {
        return ['medidos' => 0, 'voltou' => 0, 'parcial' => 0, 'nao_voltou' => 0];
    }

    /**
     * @param array<string, mixed> $filtros
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
        if (isset($filtros['ingressante'])) {
            $sql .= ' AND ' . $alias . '.ingressante = :ingressante';
            $params['ingressante'] = $filtros['ingressante'] ? 1 : 0;
        }
        if (isset($filtros['auxilio']) && $alias === 'a') {
            $sql .= ' AND ' . $alias . '.auxilio = :auxilio';
            $params['auxilio'] = $filtros['auxilio'] ? 1 : 0;
        }
        if (!empty($filtros['semana_max'])) {
            $sql .= ' AND ' . $alias . '.risco_semana <= :semana_max';
            $params['semana_max'] = (int)$filtros['semana_max'];
        }

        return [$sql, $params];
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
