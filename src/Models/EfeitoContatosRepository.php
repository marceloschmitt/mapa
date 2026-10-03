<?php
declare(strict_types=1);

namespace Mapa\Models;

use Mapa\Core\Database;
use PDO;

/**
 * Leitura da análise gerada por python/gerar_efeito_contatos.py.
 */
class EfeitoContatosRepository
{
    public const CANAL_SEM_CONTATO = 'sem_contato';

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
     * Totais por canal e, em 'primeiro', o primeiro contato de cada aluno (qualquer canal).
     *
     * @param list<int>|null $cursoIds
     * @return array<string, array<string, int|float|null>>
     */
    public function resumoPorCanal(int $execucaoId, int $minAulas, ?array $cursoIds): array
    {
        [$filtro, $params] = $this->filtroCurso('curso_id', $cursoIds);
        $params['execucao'] = $execucaoId;

        $statement = $this->db->prepare(
            'SELECT canal, ' . $this->colunasResumo($minAulas) . '
             FROM efeito_contatos_eventos
             WHERE execucao_id = :execucao' . $filtro . '
             GROUP BY canal'
        );
        $statement->execute($params);
        $saida = [];
        foreach ($statement->fetchAll() as $row) {
            $saida[(string)$row['canal']] = $this->normalizar($row);
        }

        $statement = $this->db->prepare(
            'SELECT ' . $this->colunasResumo($minAulas) . '
             FROM efeito_contatos_eventos
             WHERE execucao_id = :execucao AND primeiro_contato = 1' . $filtro
        );
        $statement->execute($params);
        $row = $statement->fetch();
        if ($row !== false && (int)$row['total'] > 0) {
            $saida['primeiro'] = $this->normalizar($row);
        }

        return $saida;
    }

    /**
     * Por curso: primeiro contato de cada aluno contra alunos com alarme nunca contatados.
     *
     * @param list<int>|null $cursoIds
     * @return list<array{nome_curso: string, contato: array<string, int|float|null>|null, sem_contato: array<string, int|float|null>|null}>
     */
    public function resumoPorCurso(int $execucaoId, int $minAulas, ?array $cursoIds): array
    {
        [$filtro, $params] = $this->filtroCurso('e.curso_id', $cursoIds);
        $params['execucao'] = $execucaoId;

        $statement = $this->db->prepare(
            'SELECT e.curso_id, c.nome_curso,
                    CASE WHEN e.canal = \'' . self::CANAL_SEM_CONTATO . '\' THEN \'sem_contato\' ELSE \'contato\' END AS grupo,
                    ' . $this->colunasResumo($minAulas, 'e.') . '
             FROM efeito_contatos_eventos e
             INNER JOIN cursos c ON c.id = e.curso_id
             WHERE e.execucao_id = :execucao
               AND (e.primeiro_contato = 1 OR e.canal = \'' . self::CANAL_SEM_CONTATO . '\')' . $filtro . '
             GROUP BY e.curso_id, c.nome_curso, grupo
             ORDER BY c.nome_curso'
        );
        $statement->execute($params);

        $porCurso = [];
        foreach ($statement->fetchAll() as $row) {
            $cursoId = (int)$row['curso_id'];
            if (!isset($porCurso[$cursoId])) {
                $porCurso[$cursoId] = [
                    'nome_curso' => (string)$row['nome_curso'],
                    'contato' => null,
                    'sem_contato' => null,
                ];
            }
            $porCurso[$cursoId][(string)$row['grupo']] = $this->normalizar($row);
        }

        return array_values($porCurso);
    }

    /**
     * Só entram na conta os eventos com aulas suficientes nas duas janelas.
     * "Melhorou" compara as taxas por multiplicação cruzada; das que melhoraram somam-se
     * as taxas individuais (média por aluno, não ponderada por aulas).
     */
    private function colunasResumo(int $minAulas, string $alias = ''): string
    {
        $a = $alias;
        $minimo = max(1, $minAulas);
        $valido = '(' . $a . 'aulas_antes >= ' . $minimo . ' AND ' . $a . 'aulas_depois >= ' . $minimo . ')';
        $melhorou = $valido . ' AND ' . $a . 'faltas_depois * ' . $a . 'aulas_antes
                    < ' . $a . 'faltas_antes * ' . $a . 'aulas_depois';

        return 'COUNT(*) AS total,
                SUM(CASE WHEN ' . $valido . ' THEN 1 ELSE 0 END) AS analisados,
                SUM(CASE WHEN ' . $melhorou . ' THEN 1 ELSE 0 END) AS melhoraram,
                SUM(CASE WHEN ' . $melhorou . '
                         THEN 100.0 * ' . $a . 'faltas_antes / ' . $a . 'aulas_antes ELSE 0 END) AS soma_taxa_antes,
                SUM(CASE WHEN ' . $melhorou . '
                         THEN 100.0 * ' . $a . 'faltas_depois / ' . $a . 'aulas_depois ELSE 0 END) AS soma_taxa_depois';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, int|float|null>
     */
    private function normalizar(array $row): array
    {
        $melhoraram = (int)$row['melhoraram'];

        return [
            'total' => (int)$row['total'],
            'analisados' => (int)$row['analisados'],
            'melhoraram' => $melhoraram,
            'taxa_antes' => $melhoraram > 0 ? (float)$row['soma_taxa_antes'] / $melhoraram : null,
            'taxa_depois' => $melhoraram > 0 ? (float)$row['soma_taxa_depois'] / $melhoraram : null,
        ];
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
