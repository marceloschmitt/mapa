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

    /** Janelas "antes" e "depois" comparadas: chave => sufixo das colunas. */
    private const ANTES = ['primeiro' => 'antes_primeiro', 'ultimo' => 'antes_ultimo'];
    private const DEPOIS = ['janela' => 'depois_ultimo', 'corte' => 'ate_corte'];

    /**
     * Alunos contatados em dois grupos: 'um' (um contato) e 'varios' (dois ou mais).
     * Em cada grupo (e em cada canal do último contato) compara as faltas antes do
     * primeiro contato ('primeiro') e antes do último ('ultimo') com as faltas depois
     * do último: nos N dias seguintes ('janela') e até a data de corte ('corte').
     * Com um só contato, 'primeiro' e 'ultimo' são iguais.
     *
     * @param list<int>|null $cursoIds
     * @return array<string, mixed>
     */
    public function resumo(int $execucaoId, int $minAulas, ?array $cursoIds): array
    {
        [$filtro, $params] = $this->filtroCurso('curso_id', $cursoIds);
        $params['execucao'] = $execucaoId;

        $statement = $this->db->prepare(
            'SELECT total_contatos, canais_ultimo,
                    aulas_antes_primeiro, faltas_antes_primeiro,
                    aulas_antes_ultimo, faltas_antes_ultimo,
                    aulas_depois_ultimo, faltas_depois_ultimo,
                    aulas_ate_corte, faltas_ate_corte
             FROM efeito_contatos_alunos
             WHERE execucao_id = :execucao' . $filtro
        );
        $statement->execute($params);

        $minimo = max(1, $minAulas);
        $saida = [
            'alunos' => 0,
            'contatos' => 0,
            'um' => $this->novoGrupo() + ['canais' => []],
            'varios' => $this->novoGrupo() + ['canais' => []],
        ];
        foreach ($statement->fetchAll() as $row) {
            $grupo = (int)$row['total_contatos'] > 1 ? 'varios' : 'um';
            $saida['alunos']++;
            $saida['contatos'] += (int)$row['total_contatos'];
            $this->acumular($saida[$grupo], $row, $minimo);
            foreach (array_filter(explode(',', (string)$row['canais_ultimo'])) as $canal) {
                if (!isset($saida[$grupo]['canais'][$canal])) {
                    $saida[$grupo]['canais'][$canal] = $this->novoGrupo();
                }
                $this->acumular($saida[$grupo]['canais'][$canal], $row, $minimo);
            }
        }

        foreach (['um', 'varios'] as $grupo) {
            $this->finalizar($saida[$grupo]);
            foreach ($saida[$grupo]['canais'] as &$porCanal) {
                $this->finalizar($porCanal);
            }
            unset($porCanal);
        }

        return $saida;
    }

    /** @return array<string, mixed> */
    private function novoGrupo(): array
    {
        $comparacao = ['analisados' => 0, 'melhoraram' => 0, 'soma_antes' => 0.0, 'soma_depois' => 0.0];
        $grupo = ['alunos' => 0, 'contatos' => 0];
        foreach (array_keys(self::ANTES) as $antes) {
            $grupo[$antes] = array_fill_keys(array_keys(self::DEPOIS), $comparacao);
        }

        return $grupo;
    }

    /**
     * @param array<string, mixed> $grupo
     * @param array<string, mixed> $row
     */
    private function acumular(array &$grupo, array $row, int $minimo): void
    {
        $grupo['alunos']++;
        $grupo['contatos'] += (int)$row['total_contatos'];
        foreach (self::ANTES as $antes => $colunaAntes) {
            $aulasAntes = (int)$row['aulas_' . $colunaAntes];
            $faltasAntes = (int)$row['faltas_' . $colunaAntes];
            if ($aulasAntes < $minimo) {
                continue;
            }
            foreach (self::DEPOIS as $depois => $colunaDepois) {
                $aulasDepois = (int)$row['aulas_' . $colunaDepois];
                $faltasDepois = (int)$row['faltas_' . $colunaDepois];
                if ($aulasDepois < $minimo) {
                    continue;
                }
                $c = &$grupo[$antes][$depois];
                $c['analisados']++;
                if ($faltasDepois * $aulasAntes < $faltasAntes * $aulasDepois) {
                    $c['melhoraram']++;
                    $c['soma_antes'] += 100 * $faltasAntes / $aulasAntes;
                    $c['soma_depois'] += 100 * $faltasDepois / $aulasDepois;
                }
                unset($c);
            }
        }
    }

    /**
     * Taxas antes/depois = média das taxas individuais dos que melhoraram.
     *
     * @param array<string, mixed> $grupo
     */
    private function finalizar(array &$grupo): void
    {
        foreach (array_keys(self::ANTES) as $antes) {
            foreach (array_keys(self::DEPOIS) as $depois) {
                $c = $grupo[$antes][$depois];
                $melhoraram = (int)$c['melhoraram'];
                $grupo[$antes][$depois] = [
                    'analisados' => (int)$c['analisados'],
                    'melhoraram' => $melhoraram,
                    'taxa_antes' => $melhoraram > 0 ? $c['soma_antes'] / $melhoraram : null,
                    'taxa_depois' => $melhoraram > 0 ? $c['soma_depois'] / $melhoraram : null,
                ];
            }
        }
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
