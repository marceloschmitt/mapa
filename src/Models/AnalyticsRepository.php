<?php
declare(strict_types=1);

namespace Mapa\Models;

use Mapa\Core\Database;
use PDO;

class AnalyticsRepository
{
    /** @var PDO */
    private $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /** @return array<string, mixed>|null */
    public function ultimaColeta(): ?array
    {
        $statement = $this->db->query(
            'SELECT id, executada_em, data_inicial, data_final, data_referencia,
                    total_alunos, total_disciplinas, total_faltas_dia, origem
             FROM coletas
             ORDER BY id DESC
             LIMIT 1'
        );
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    /** @return array<string, int|float> */
    public function resumoColeta(int $coletaId, ?array $cursoIds = null): array
    {
        if ($cursoIds !== null && $cursoIds === []) {
            return [
                'total_disciplinas' => 0,
                'media_frequencia' => 0.0,
                'abaixo_75' => 0,
                'total_alarmes' => 0,
                'nao_visualizados' => 0,
                'percentual_baixo' => 0,
                'faltas_4dias' => 0,
                'faltas_3semanas' => 0,
            ];
        }

        [$freqSql, $freqParams] = $this->appendCursoFilter(
            'SELECT
                COUNT(*) AS total_disciplinas,
                ROUND(AVG(percentual_frequencia), 1) AS media_frequencia,
                COUNT(DISTINCT CASE WHEN percentual_frequencia < 75 THEN aluno_id END) AS abaixo_75
             FROM frequencia_disciplina
             WHERE coleta_id = :coleta_id',
            $cursoIds,
            'curso_id'
        );
        $freq = $this->db->prepare($freqSql);
        $freq->bindValue('coleta_id', $coletaId, PDO::PARAM_INT);
        $this->bindCursoParams($freq, $freqParams);
        $freq->execute();
        $freqRow = $freq->fetch() ?: [];

        [$alarmeSql, $alarmeParams] = $this->appendCursoFilter(
            'SELECT
                COUNT(*) AS total_alarmes,
                SUM(CASE WHEN visualizado = 0 THEN 1 ELSE 0 END) AS nao_visualizados,
                SUM(CASE WHEN tipo = \'percentual_baixo\' THEN 1 ELSE 0 END) AS percentual_baixo,
                SUM(CASE WHEN tipo = \'faltas_4dias\' THEN 1 ELSE 0 END) AS faltas_4dias,
                SUM(CASE WHEN tipo = \'faltas_3semanas\' THEN 1 ELSE 0 END) AS faltas_3semanas
             FROM alarmes
             WHERE coleta_id = :coleta_id',
            $cursoIds,
            'curso_id'
        );
        $alarmes = $this->db->prepare($alarmeSql);
        $alarmes->bindValue('coleta_id', $coletaId, PDO::PARAM_INT);
        $this->bindCursoParams($alarmes, $alarmeParams);
        $alarmes->execute();
        $alarmeRow = $alarmes->fetch() ?: [];

        return [
            'total_disciplinas' => (int)($freqRow['total_disciplinas'] ?? 0),
            'media_frequencia' => (float)($freqRow['media_frequencia'] ?? 0),
            'abaixo_75' => (int)($freqRow['abaixo_75'] ?? 0),
            'total_alarmes' => (int)($alarmeRow['total_alarmes'] ?? 0),
            'nao_visualizados' => (int)($alarmeRow['nao_visualizados'] ?? 0),
            'percentual_baixo' => (int)($alarmeRow['percentual_baixo'] ?? 0),
            'faltas_4dias' => (int)($alarmeRow['faltas_4dias'] ?? 0),
            'faltas_3semanas' => (int)($alarmeRow['faltas_3semanas'] ?? 0),
        ];
    }

    /**
     * Alunos distintos com pelo menos uma disciplina abaixo de 75%.
     *
     * @param list<int>|null $cursoIds
     * @param list<string>|null $codigosDisciplina
     */
    public function contarAlunosAbaixo75(
        int $coletaId,
        ?array $cursoIds = null,
        ?array $codigosDisciplina = null
    ): int {
        if (($cursoIds !== null && $cursoIds === [])
            || ($codigosDisciplina !== null && $codigosDisciplina === [])
        ) {
            return 0;
        }

        $sql = 'SELECT COUNT(DISTINCT aluno_id) AS total
                FROM frequencia_disciplina
                WHERE coleta_id = :coleta_id
                  AND percentual_frequencia IS NOT NULL
                  AND percentual_frequencia < 75';
        [$sql, $cursoParams] = $this->appendCursoFilter($sql, $cursoIds, 'curso_id');
        [$sql, $discParams] = $this->appendCodigoDisciplinaFilter(
            $sql,
            $codigosDisciplina,
            'codigo_disciplina'
        );

        $statement = $this->db->prepare($sql);
        $statement->bindValue('coleta_id', $coletaId, PDO::PARAM_INT);
        $this->bindNamedParams($statement, $cursoParams);
        $this->bindNamedParams($statement, $discParams);
        $statement->execute();

        return (int)($statement->fetchColumn() ?: 0);
    }

    /**
     * @param list<int>|null $cursoIds
     * @return array{labels: list<string>, values: list<float>, ids: list<int>}
     */
    public function frequenciaPorCurso(int $coletaId, ?array $cursoIds = null): array
    {
        if ($cursoIds !== null && $cursoIds === []) {
            return ['labels' => [], 'values' => [], 'ids' => []];
        }

        [$sql, $params] = $this->appendCursoFilter(
            'SELECT c.id AS curso_id,
                    c.nome_curso,
                    ROUND(AVG(f.percentual_frequencia), 1) AS media
             FROM frequencia_disciplina f
             INNER JOIN cursos c ON c.id = f.curso_id
             WHERE f.coleta_id = :coleta_id
               AND f.percentual_frequencia IS NOT NULL',
            $cursoIds,
            'f.curso_id'
        );
        $sql .= ' GROUP BY c.id, c.nome_curso ORDER BY media ASC';

        $statement = $this->db->prepare($sql);
        $statement->bindValue('coleta_id', $coletaId, PDO::PARAM_INT);
        $this->bindCursoParams($statement, $params);
        $statement->execute();

        $labels = [];
        $values = [];
        $ids = [];
        foreach ($statement->fetchAll() as $row) {
            $labels[] = (string)$row['nome_curso'];
            $values[] = (float)$row['media'];
            $ids[] = (int)$row['curso_id'];
        }

        return ['labels' => $labels, 'values' => $values, 'ids' => $ids];
    }

    /**
     * @param list<int>|null $cursoIds
     * @return array{labels: list<string>, values: list<int>}
     */
    public function faltasPorDiaSemana(int $coletaId, ?array $cursoIds = null): array
    {
        if ($cursoIds !== null && $cursoIds === []) {
            return [
                'labels' => ['Segunda', 'Terca', 'Quarta', 'Quinta', 'Sexta', 'Sabado'],
                'values' => [0, 0, 0, 0, 0, 0],
            ];
        }

        [$sql, $params] = $this->appendCursoFilter(
            'SELECT CAST(strftime(\'%w\', data_falta) AS INTEGER) AS dia_semana, COUNT(*) AS total
             FROM faltas_dia
             WHERE coleta_id = :coleta_id',
            $cursoIds,
            'curso_id'
        );
        $sql .= ' GROUP BY strftime(\'%w\', data_falta) ORDER BY dia_semana';

        $statement = $this->db->prepare($sql);
        $statement->bindValue('coleta_id', $coletaId, PDO::PARAM_INT);
        $this->bindCursoParams($statement, $params);
        $statement->execute();

        // SQLite strftime('%w'): 0=Domingo ... 6=Sabado (domingo omitido no grafico)
        $nomes = [
            1 => 'Segunda',
            2 => 'Terca',
            3 => 'Quarta',
            4 => 'Quinta',
            5 => 'Sexta',
            6 => 'Sabado',
        ];
        $totais = array_fill(0, 7, 0);

        foreach ($statement->fetchAll() as $row) {
            $dia = (int)$row['dia_semana'];
            $totais[$dia] = (int)$row['total'];
        }

        $labels = [];
        $values = [];
        foreach ($nomes as $numero => $nome) {
            $labels[] = $nome;
            $values[] = $totais[$numero];
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * Evolucao semanal de faltas (total por semana).
     *
     * @param list<int>|null $cursoIds
     * @return array{
     *   labels: list<string>,
     *   values: list<int>,
     *   semanas: list<array{inicio: string, fim: string, mes: string, total: int}>
     * }
     */
    public function faltasPorMes(int $coletaId, ?array $cursoIds = null): array
    {
        if ($cursoIds !== null && $cursoIds === []) {
            return ['labels' => [], 'values' => [], 'semanas' => []];
        }

        [$sql, $params] = $this->appendCursoFilter(
            'SELECT strftime(\'%Y-%W\', data_falta) AS semana,
                    MIN(data_falta) AS data_ref,
                    COUNT(*) AS total
             FROM faltas_dia
             WHERE coleta_id = :coleta_id',
            $cursoIds,
            'curso_id'
        );
        $sql .= ' GROUP BY strftime(\'%Y-%W\', data_falta) ORDER BY semana';

        $statement = $this->db->prepare($sql);
        $statement->bindValue('coleta_id', $coletaId, PDO::PARAM_INT);
        $this->bindCursoParams($statement, $params);
        $statement->execute();

        $labels = [];
        $values = [];
        $semanas = [];

        foreach ($statement->fetchAll() as $row) {
            $limites = $this->limitesSemana((string)$row['data_ref']);
            $inicio = $limites['inicio'];
            $fim = $limites['fim'];
            $total = (int)$row['total'];

            $labels[] = $this->formatarDiaMes($inicio) . '–' . $this->formatarDiaMes($fim);
            $values[] = $total;
            $semanas[] = [
                'inicio' => $inicio,
                'fim' => $fim,
                'mes' => substr($inicio, 0, 7),
                'total' => $total,
            ];
        }

        return [
            'labels' => $labels,
            'values' => $values,
            'semanas' => $semanas,
        ];
    }

    /** @return array{inicio: string, fim: string} */
    private function limitesSemana(string $dataIso): array
    {
        $timestamp = strtotime($dataIso);
        if ($timestamp === false) {
            return ['inicio' => $dataIso, 'fim' => $dataIso];
        }

        // SQLite %W: semana com segunda como primeiro dia (ISO-like).
        $diaSemana = (int)date('N', $timestamp); // 1=Seg ... 7=Dom
        $inicioTs = strtotime('-' . ($diaSemana - 1) . ' days', $timestamp);
        $fimTs = strtotime('+6 days', $inicioTs);

        return [
            'inicio' => date('Y-m-d', $inicioTs),
            'fim' => date('Y-m-d', $fimTs),
        ];
    }

    private function formatarDiaMes(string $dataIso): string
    {
        $timestamp = strtotime($dataIso);
        if ($timestamp === false) {
            return $dataIso;
        }

        return date('d/m', $timestamp);
    }

    /**
     * @param list<int>|null $cursoIds
     * @return list<array<string, mixed>>
     */
    public function disciplinasCriticas(int $coletaId, ?int $limite = 15, ?array $cursoIds = null): array
    {
        if ($cursoIds !== null && $cursoIds === []) {
            return [];
        }

        [$sql, $params] = $this->appendCursoFilter(
            'SELECT f.codigo_disciplina, f.disciplina, f.curso_id, c.nome_curso,
                    g.semestre_oferta,
                    ROUND(AVG(f.percentual_frequencia), 1) AS media,
                    COUNT(*) AS alunos,
                    SUM(CASE WHEN f.percentual_frequencia < 75 THEN 1 ELSE 0 END) AS abaixo_75
             FROM frequencia_disciplina f
             INNER JOIN cursos c ON c.id = f.curso_id
             LEFT JOIN disciplina_grade g
               ON g.codigo_disciplina = f.codigo_disciplina
              AND g.curso_id = f.curso_id
             WHERE f.coleta_id = :coleta_id
               AND f.percentual_frequencia IS NOT NULL',
            $cursoIds,
            'f.curso_id'
        );
        $sql .= ' GROUP BY f.codigo_disciplina, f.disciplina, f.curso_id, c.id, c.nome_curso, g.semestre_oferta
             HAVING abaixo_75 > 0
             ORDER BY media ASC, abaixo_75 DESC';

        if ($limite !== null) {
            $sql .= ' LIMIT :limite';
        }

        $statement = $this->db->prepare($sql);
        $statement->bindValue('coleta_id', $coletaId, PDO::PARAM_INT);
        $this->bindCursoParams($statement, $params);
        if ($limite !== null) {
            $statement->bindValue('limite', $limite, PDO::PARAM_INT);
        }
        $statement->execute();

        $rows = $statement->fetchAll();
        return $this->anexarProfessoresNasDisciplinas($rows);
    }

    /**
     * Nomes de professores por codigo de disciplina (ordenados, separados por virgula).
     *
     * @param list<string> $codigos
     * @return array<string, string>
     */
    public function nomesProfessoresPorCodigo(array $codigos): array
    {
        $codigos = array_values(array_unique(array_filter(array_map(
            static function ($codigo): string {
                return trim((string)$codigo);
            },
            $codigos
        ), static function (string $codigo): bool {
            return $codigo !== '';
        })));

        if ($codigos === []) {
            return [];
        }

        $placeholders = [];
        foreach ($codigos as $i => $_) {
            $placeholders[] = ':c' . $i;
        }

        $sql = 'SELECT dp.codigo_disciplina,
                       GROUP_CONCAT(p.nome, \', \') AS professores
                FROM disciplina_professores dp
                INNER JOIN professores p ON p.id = dp.professor_id
                WHERE dp.codigo_disciplina IN (' . implode(', ', $placeholders) . ')
                GROUP BY dp.codigo_disciplina
                ORDER BY dp.codigo_disciplina';

        $statement = $this->db->prepare($sql);
        foreach ($codigos as $i => $codigo) {
            $statement->bindValue('c' . $i, $codigo, PDO::PARAM_STR);
        }
        $statement->execute();

        $mapa = [];
        foreach ($statement->fetchAll() as $row) {
            $codigo = trim((string)($row['codigo_disciplina'] ?? ''));
            $nomes = trim((string)($row['professores'] ?? ''));
            if ($codigo !== '' && $nomes !== '') {
                // GROUP_CONCAT nao ordena por nome; normaliza.
                $lista = array_values(array_unique(array_filter(array_map('trim', explode(',', $nomes)))));
                sort($lista, SORT_STRING);
                $mapa[$codigo] = implode(', ', $lista);
            }
        }

        return $mapa;
    }

    /**
     * Disciplinas da coleta ordenadas pela data da ultima chamada (mais antigas primeiro).
     * Sem data (NULL) aparecem no topo.
     *
     * @param list<int>|null $cursoIds
     * @return list<array<string, mixed>>
     */
    public function disciplinasUltimaAula(int $coletaId, ?array $cursoIds = null): array
    {
        if ($cursoIds !== null && $cursoIds === []) {
            return [];
        }

        [$sql, $params] = $this->appendCursoFilter(
            'SELECT d.codigo_disciplina,
                    d.disciplina,
                    d.curso_id,
                    c.nome_curso,
                    c.curso_nivel,
                    d.data_ultima_aula,
                    g.dias_semana,
                    g.semestre_oferta,
                    col.data_referencia AS coleta_data_referencia,
                    col.data_inicial AS coleta_data_inicial,
                    (SELECT COUNT(*)
                     FROM disciplina_chamadas ch
                     WHERE ch.codigo_disciplina = d.codigo_disciplina
                       AND ch.curso_id = d.curso_id) AS total_registros
             FROM disciplina_ultima_aula d
             INNER JOIN cursos c ON c.id = d.curso_id
             INNER JOIN coletas col ON col.id = d.coleta_id
             LEFT JOIN disciplina_grade g
               ON g.codigo_disciplina = d.codigo_disciplina
              AND g.curso_id = d.curso_id
             WHERE d.coleta_id = :coleta_id',
            $cursoIds,
            'd.curso_id'
        );
        $sql .= ' ORDER BY
                CASE WHEN d.data_ultima_aula IS NULL OR TRIM(d.data_ultima_aula) = \'\' THEN 1 ELSE 0 END,
                d.data_ultima_aula DESC,
                c.nome_curso ASC,
                d.disciplina ASC';

        $statement = $this->db->prepare($sql);
        $statement->bindValue('coleta_id', $coletaId, PDO::PARAM_INT);
        $this->bindCursoParams($statement, $params);
        $statement->execute();
        $rows = $statement->fetchAll();

        $rows = $this->anexarAtrasoChamadas($rows);
        $rows = $this->anexarDatasChamadas($rows);
        $rows = $this->anexarProfessoresNasDisciplinas($rows);

        usort($rows, static function (array $a, array $b): int {
            $atrasoA = !empty($a['atrasado']) ? 0 : 1;
            $atrasoB = !empty($b['atrasado']) ? 0 : 1;
            if ($atrasoA !== $atrasoB) {
                return $atrasoA <=> $atrasoB;
            }

            if ($atrasoA === 0) {
                // Atrasadas: data faltante da mais recente para a mais antiga.
                $faltanteA = trim((string)($a['dia_esperado'] ?? ''));
                $faltanteB = trim((string)($b['dia_esperado'] ?? ''));
                if ($faltanteA !== $faltanteB) {
                    if ($faltanteA === '') {
                        return 1;
                    }
                    if ($faltanteB === '') {
                        return -1;
                    }

                    return $faltanteB <=> $faltanteA;
                }

                $dataA = trim((string)($a['data_ultima_aula'] ?? ''));
                $dataB = trim((string)($b['data_ultima_aula'] ?? ''));
                if ($dataA !== $dataB) {
                    if ($dataA === '') {
                        return 1;
                    }
                    if ($dataB === '') {
                        return -1;
                    }

                    return $dataB <=> $dataA;
                }

                return strcmp((string)$a['disciplina'], (string)$b['disciplina']);
            }

            // Em dia: última chamada da mais recente para a mais antiga.
            $dataA = trim((string)($a['data_ultima_aula'] ?? ''));
            $dataB = trim((string)($b['data_ultima_aula'] ?? ''));
            if ($dataA === '' && $dataB !== '') {
                return 1;
            }
            if ($dataA !== '' && $dataB === '') {
                return -1;
            }
            if ($dataA !== $dataB) {
                return $dataB <=> $dataA;
            }

            return strcmp((string)$a['disciplina'], (string)$b['disciplina']);
        });

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function anexarAtrasoChamadas(array $rows): array
    {
        $rotulos = [
            2 => 'Seg',
            3 => 'Ter',
            4 => 'Qua',
            5 => 'Qui',
            6 => 'Sex',
            7 => 'Sáb',
        ];

        $mapaDatas = $this->mapaDatasAula($rows);

        foreach ($rows as &$row) {
            $chave = trim((string)($row['codigo_disciplina'] ?? ''))
                . '|'
                . (int)($row['curso_id'] ?? 0);
            $datas = $mapaDatas[$chave] ?? [];
            $dias = $this->parseDiasSemanaSigaa($row['dias_semana'] ?? '');
            if ($dias === [] && $datas !== []) {
                $dias = $this->diasSemanaDasDatas($datas);
            }

            // Referencia do atraso: sempre o dia anterior ao corrente.
            $referencia = (new \DateTimeImmutable('today'))->modify('-1 day')->format('Y-m-d');
            $inicio = $this->dataInicialAtraso(
                (string)($row['coleta_data_inicial'] ?? '')
            );

            // Datas de aula anteriores à data inicial (config/coleta) nao contam.
            $usouDatasEfetivas = $datas !== [];
            if ($inicio !== null && $datas !== []) {
                $datas = array_values(array_filter(
                    $datas,
                    static fn (string $iso): bool => $iso >= $inicio
                ));
            }

            $row['datas_aula_lista'] = $datas;
            $row['dias_aula'] = $dias;
            $row['dias_aula_rotulo'] = implode(
                ', ',
                array_values(array_filter(array_map(
                    static function (int $dia) use ($rotulos): string {
                        return $rotulos[$dia] ?? '';
                    },
                    $dias
                )))
            );

            // Atraso so com datas efetivas da grade. Recorrencia semanal
            // inventa feriados/sábados que o SIGAA ja removeu do turno.
            $esperado = $usouDatasEfetivas
                ? $this->ultimoDiaAulaEsperadoPorDatas($datas, $referencia)
                : null;
            $row['dia_esperado'] = $esperado;

            $ultima = trim((string)($row['data_ultima_aula'] ?? ''));
            $atrasado = false;
            if ($esperado !== null) {
                $atrasado = $ultima === '' || $ultima < $esperado;
            }
            $row['atrasado'] = $atrasado;
        }
        unset($row);

        return $rows;
    }

    /**
     * Datas efetivas por disciplina/curso a partir de disciplina_aulas.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, list<string>> chave "codigo|curso_id" => datas ISO
     */
    private function mapaDatasAula(array $rows): array
    {
        $pares = [];
        foreach ($rows as $row) {
            $codigo = trim((string)($row['codigo_disciplina'] ?? ''));
            $cursoId = (int)($row['curso_id'] ?? 0);
            if ($codigo !== '' && $cursoId > 0) {
                $pares[$codigo . '|' . $cursoId] = [$codigo, $cursoId];
            }
        }

        if ($pares === []) {
            return [];
        }

        $conds = [];
        $bind = [];
        $i = 0;
        foreach ($pares as [$codigo, $cursoId]) {
            $conds[] = '(codigo_disciplina = :cod_' . $i . ' AND curso_id = :cur_' . $i . ')';
            $bind['cod_' . $i] = $codigo;
            $bind['cur_' . $i] = $cursoId;
            $i++;
        }

        $sql = 'SELECT codigo_disciplina, curso_id, data_aula
                FROM disciplina_aulas
                WHERE ' . implode(' OR ', $conds) . '
                ORDER BY data_aula ASC';
        $statement = $this->db->prepare($sql);
        foreach ($bind as $chave => $valor) {
            $statement->bindValue(
                $chave,
                $valor,
                is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }
        $statement->execute();

        $mapa = [];
        foreach ($statement->fetchAll() as $row) {
            $chave = trim((string)$row['codigo_disciplina']) . '|' . (int)$row['curso_id'];
            $data = trim((string)($row['data_aula'] ?? ''));
            if ($data !== '') {
                $mapa[$chave][] = $data;
            }
        }

        return $mapa;
    }

    /**
     * Data inicial efetiva para atraso: preferencia pela config atual,
     * com fallback para a data gravada na coleta.
     */
    private function dataInicialAtraso(string $coletaDataInicial): ?string
    {
        static $configIso = false;
        if ($configIso === false) {
            $bruto = trim((new ConfigRepository())->get(ConfigRepository::FREQUENCIA_DATA_INICIAL));
            $configIso = $this->normalizarDataIso($bruto);
        }

        if (is_string($configIso) && $configIso !== '') {
            return $configIso;
        }

        $fallback = $this->normalizarDataIso(trim($coletaDataInicial));
        return $fallback !== null && $fallback !== '' ? $fallback : null;
    }

    /** Aceita YYYY-MM-DD, DD-MM-AAAA ou DD/MM/AAAA. */
    private function normalizarDataIso(string $texto): ?string
    {
        $texto = trim($texto);
        if ($texto === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $texto, $m) === 1) {
            return $m[1] . '-' . $m[2] . '-' . $m[3];
        }

        if (preg_match('/^(\d{2})[-\\/](\d{2})[-\\/](\d{4})$/', $texto, $m) === 1) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        return null;
    }

    /**
     * @param list<string> $datas
     * @return list<int>
     */
    private function diasSemanaDasDatas(array $datas): array
    {
        $dias = [];
        foreach ($datas as $iso) {
            try {
                $dt = new \DateTimeImmutable($iso);
            } catch (\Exception) {
                continue;
            }
            // PHP N: 1=seg .. 7=dom  => SIGAA: 2=seg .. 7=sab, 1=dom
            $n = (int)$dt->format('N');
            $sigaa = $n === 7 ? 1 : $n + 1;
            if ($sigaa >= 2 && $sigaa <= 7) {
                $dias[] = $sigaa;
            }
        }

        $dias = array_values(array_unique($dias));
        sort($dias);

        return $dias;
    }

    /** @return list<int> */
    private function parseDiasSemanaSigaa(mixed $texto): array
    {
        $bruto = trim((string)$texto);
        if ($bruto === '') {
            return [];
        }

        $dias = [];
        foreach (explode(',', $bruto) as $parte) {
            $parte = trim($parte);
            if ($parte !== '' && ctype_digit($parte)) {
                $dia = (int)$parte;
                if ($dia >= 2 && $dia <= 7) {
                    $dias[] = $dia;
                }
            }
        }

        $dias = array_values(array_unique($dias));
        sort($dias);

        return $dias;
    }

    /**
     * Ultimo dia de aula (da lista efetiva) cuja chamada ja deveria existir.
     *
     * A referencia e o dia anterior ao corrente; aulas ate essa data (inclusive)
     * ja devem ter chamada.
     *
     * @param list<string> $datas ISO YYYY-MM-DD
     */
    private function ultimoDiaAulaEsperadoPorDatas(array $datas, string $referencia): ?string
    {
        if ($datas === []) {
            return null;
        }

        try {
            $ref = new \DateTimeImmutable($referencia);
        } catch (\Exception) {
            return null;
        }

        $limite = $ref->format('Y-m-d');
        $esperado = null;
        foreach ($datas as $iso) {
            if ($iso <= $limite && ($esperado === null || $iso > $esperado)) {
                $esperado = $iso;
            }
        }

        return $esperado;
    }

    /**
     * Fallback: ultimo dia de aula pela recorrencia semanal (sem datas efetivas).
     *
     * A referencia e o dia anterior ao corrente (inclusive).
     *
     * @param list<int> $diasSigaa
     */
    private function ultimoDiaAulaEsperado(array $diasSigaa, string $referencia, ?string $inicio): ?string
    {
        if ($diasSigaa === []) {
            return null;
        }

        // SIGAA 2=seg .. 7=sab  =>  PHP date('N') 1=seg .. 6=sab
        $diasPhp = [];
        foreach ($diasSigaa as $dia) {
            if ($dia >= 2 && $dia <= 7) {
                $diasPhp[] = $dia - 1;
            }
        }
        if ($diasPhp === []) {
            return null;
        }

        try {
            $ref = new \DateTimeImmutable($referencia);
        } catch (\Exception) {
            return null;
        }

        $ate = $ref;

        try {
            $limite = $inicio !== null && $inicio !== ''
                ? new \DateTimeImmutable($inicio)
                : $ate->modify('-180 days');
        } catch (\Exception) {
            $limite = $ate->modify('-180 days');
        }

        if ($ate < $limite) {
            return null;
        }

        for ($dia = $ate; $dia >= $limite; $dia = $dia->modify('-1 day')) {
            if (in_array((int)$dia->format('N'), $diasPhp, true)) {
                return $dia->format('Y-m-d');
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function anexarDatasChamadas(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $pares = [];
        foreach ($rows as $row) {
            $codigo = trim((string)($row['codigo_disciplina'] ?? ''));
            $cursoId = (int)($row['curso_id'] ?? 0);
            if ($codigo !== '' && $cursoId > 0) {
                $pares[$codigo . '|' . $cursoId] = [$codigo, $cursoId];
            }
        }

        $mapa = [];
        if ($pares !== []) {
            $conds = [];
            $bind = [];
            $i = 0;
            foreach ($pares as [$codigo, $cursoId]) {
                $conds[] = '(codigo_disciplina = :cod_' . $i . ' AND curso_id = :cur_' . $i . ')';
                $bind['cod_' . $i] = $codigo;
                $bind['cur_' . $i] = $cursoId;
                $i++;
            }

            $sql = 'SELECT codigo_disciplina, curso_id, data_chamada
                    FROM disciplina_chamadas
                    WHERE ' . implode(' OR ', $conds) . '
                    ORDER BY data_chamada ASC';
            $statement = $this->db->prepare($sql);
            foreach ($bind as $chave => $valor) {
                $statement->bindValue(
                    $chave,
                    $valor,
                    is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR
                );
            }
            $statement->execute();

            foreach ($statement->fetchAll() as $row) {
                $chave = trim((string)$row['codigo_disciplina']) . '|' . (int)$row['curso_id'];
                $mapa[$chave][] = (string)$row['data_chamada'];
            }
        }

        foreach ($rows as &$row) {
            $chave = trim((string)($row['codigo_disciplina'] ?? '')) . '|' . (int)($row['curso_id'] ?? 0);
            $row['datas_chamada'] = $mapa[$chave] ?? [];
        }
        unset($row);

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function anexarProfessoresNasDisciplinas(array $rows): array
    {
        $codigos = [];
        foreach ($rows as $row) {
            $codigo = trim((string)($row['codigo_disciplina'] ?? ''));
            if ($codigo !== '') {
                $codigos[] = $codigo;
            }
        }
        $mapa = $this->nomesProfessoresPorCodigo($codigos);
        foreach ($rows as &$row) {
            $codigo = trim((string)($row['codigo_disciplina'] ?? ''));
            $row['professores'] = $mapa[$codigo] ?? '';
        }
        unset($row);

        return $rows;
    }

    /**
     * @param list<int>|null $idsPermitidos null = todos os cursos
     * @return list<array{id: int, nome_curso: string}>
     */
    public function listarCursos(?array $idsPermitidos = null): array
    {
        if ($idsPermitidos !== null && $idsPermitidos === []) {
            return [];
        }

        if ($idsPermitidos === null) {
            $statement = $this->db->query(
                'SELECT id, nome_curso FROM cursos ORDER BY nome_curso ASC'
            );

            return $statement->fetchAll();
        }

        $placeholders = [];
        foreach (array_values($idsPermitidos) as $i => $_) {
            $placeholders[] = ':curso_' . $i;
        }

        $sql = 'SELECT id, nome_curso FROM cursos
                WHERE id IN (' . implode(', ', $placeholders) . ')
                ORDER BY nome_curso ASC';
        $statement = $this->db->prepare($sql);
        foreach (array_values($idsPermitidos) as $i => $cursoId) {
            $statement->bindValue('curso_' . $i, (int)$cursoId, PDO::PARAM_INT);
        }
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * @param list<int>|null $cursoIds
     * @return array{0: string, 1: array<string, int>}
     */
    private function appendCursoFilter(string $sql, ?array $cursoIds, string $coluna): array
    {
        if ($cursoIds === null) {
            return [$sql, []];
        }

        $placeholders = [];
        $params = [];
        foreach (array_values($cursoIds) as $i => $cursoId) {
            $key = 'filtro_curso_' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = (int)$cursoId;
        }

        $sql .= ' AND ' . $coluna . ' IN (' . implode(', ', $placeholders) . ')';

        return [$sql, $params];
    }

    /**
     * @param list<string>|null $codigos
     * @return array{0: string, 1: array<string, string>}
     */
    private function appendCodigoDisciplinaFilter(string $sql, ?array $codigos, string $coluna): array
    {
        if ($codigos === null) {
            return [$sql, []];
        }

        $placeholders = [];
        $params = [];
        foreach (array_values($codigos) as $i => $codigo) {
            $key = 'filtro_disc_' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = (string)$codigo;
        }

        $sql .= ' AND ' . $coluna . ' IN (' . implode(', ', $placeholders) . ')';

        return [$sql, $params];
    }

    /**
     * Alunos (no curso) que ja tem alarme em alguma das disciplinas informadas.
     *
     * @param list<string>|null $codigos
     * @return array{0: string, 1: array<string, string>}
     */
    private function appendFiltroAlunosDasDisciplinas(
        string $sql,
        ?array $codigos,
        string $aliasAlarmes
    ): array {
        if ($codigos === null) {
            return [$sql, []];
        }

        $placeholders = [];
        $params = [];
        foreach (array_values($codigos) as $i => $codigo) {
            $key = 'escopo_disc_' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = (string)$codigo;
        }

        $sql .= ' AND EXISTS (
                    SELECT 1 FROM alarmes a_escopo
                    WHERE a_escopo.coleta_id = ' . $aliasAlarmes . '.coleta_id
                      AND a_escopo.aluno_id = ' . $aliasAlarmes . '.aluno_id
                      AND a_escopo.curso_id = ' . $aliasAlarmes . '.curso_id
                      AND a_escopo.codigo_disciplina IN (' . implode(', ', $placeholders) . ')
                  )';

        return [$sql, $params];
    }

    /** @param array<string, int|string> $params */
    private function bindNamedParams(\PDOStatement $statement, array $params): void
    {
        foreach ($params as $key => $value) {
            if (is_int($value)) {
                $statement->bindValue($key, $value, PDO::PARAM_INT);
            } else {
                $statement->bindValue($key, (string)$value, PDO::PARAM_STR);
            }
        }
    }

    /** @param array<string, int> $params */
    private function bindCursoParams(\PDOStatement $statement, array $params): void
    {
        $this->bindNamedParams($statement, $params);
    }

    /** @return list<array<string, mixed>> */
    public function evolucaoAluno(string $login, int $limiteColetas = 10): array
    {
        $statement = $this->db->prepare(
            'SELECT c.id AS coleta_id, c.executada_em,
                    ROUND(AVG(f.percentual_frequencia), 1) AS media_frequencia,
                    SUM(f.ausencias) AS ausencias
             FROM frequencia_disciplina f
             INNER JOIN alunos a ON a.id = f.aluno_id
             INNER JOIN coletas c ON c.id = f.coleta_id
             WHERE a.login = :login
               AND f.percentual_frequencia IS NOT NULL
             GROUP BY c.id, c.executada_em
             ORDER BY c.id ASC
             LIMIT :limite'
        );
        $statement->bindValue('login', $login, PDO::PARAM_STR);
        $statement->bindValue('limite', $limiteColetas, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * @param list<int>|null $cursoIds
     * @param list<string>|null $codigosDisciplina
     * @return list<array<string, mixed>>
     */
    public function alarmes(
        int $coletaId,
        bool $somenteNaoVisualizados = false,
        ?string $tipo = null,
        ?int $limite = null,
        ?array $cursoIds = null,
        ?array $codigosDisciplina = null,
        bool $incluirOutrasDisciplinasDosAlunos = false
    ): array {
        if (($cursoIds !== null && $cursoIds === [])
            || ($codigosDisciplina !== null && $codigosDisciplina === [])
        ) {
            return [];
        }

        $sql = 'SELECT al.id, al.tipo, al.severidade, al.mensagem, al.codigo_disciplina,
                       al.disciplina, al.visualizado, al.visualizado_em, al.visualizado_por,
                       al.contato_tipo, al.gerado_em, al.detalhe_json,
                       al.aluno_id, al.curso_id,
                       a.nome AS aluno_nome, a.nome_social AS aluno_nome_social,
                       a.email AS aluno_email, a.login, a.matricula,
                       c.nome_curso,
                       g.semestre_oferta,
                       ac.ano_semestre_ingresso, ac.turma_entrada,
                       u.nome AS visualizado_por_nome, u.username AS visualizado_por_username
                FROM alarmes al
                INNER JOIN alunos a ON a.id = al.aluno_id
                INNER JOIN cursos c ON c.id = al.curso_id
                LEFT JOIN aluno_cursos ac ON ac.aluno_id = al.aluno_id AND ac.curso_id = al.curso_id
                LEFT JOIN disciplina_grade g
                  ON g.codigo_disciplina = al.codigo_disciplina
                 AND g.curso_id = al.curso_id
                LEFT JOIN usuarios u ON u.id = al.visualizado_por
                WHERE al.coleta_id = :coleta_id';

        if ($somenteNaoVisualizados) {
            $sql .= ' AND al.visualizado = 0';
        }

        if ($tipo !== null && $tipo !== '') {
            $sql .= ' AND al.tipo = :tipo';
        }

        [$sql, $cursoParams] = $this->appendCursoFilter($sql, $cursoIds, 'al.curso_id');
        if ($incluirOutrasDisciplinasDosAlunos) {
            [$sql, $discParams] = $this->appendFiltroAlunosDasDisciplinas(
                $sql,
                $codigosDisciplina,
                'al'
            );
        } else {
            [$sql, $discParams] = $this->appendCodigoDisciplinaFilter(
                $sql,
                $codigosDisciplina,
                'al.codigo_disciplina'
            );
        }

        $sql .= ' ORDER BY COALESCE(NULLIF(TRIM(a.nome_social), \'\'), a.nome) ASC,
                    COALESCE(al.disciplina, \'\') ASC,
                    CASE al.tipo
                        WHEN \'percentual_baixo\' THEN 1
                        WHEN \'faltas_4dias\' THEN 2
                        WHEN \'faltas_3semanas\' THEN 3
                        ELSE 4
                    END,
                    al.severidade DESC,
                    al.gerado_em DESC';

        if ($limite !== null) {
            $sql .= ' LIMIT :limite';
        }

        $statement = $this->db->prepare($sql);
        $statement->bindValue('coleta_id', $coletaId, PDO::PARAM_INT);
        if ($tipo !== null && $tipo !== '') {
            $statement->bindValue('tipo', $tipo, PDO::PARAM_STR);
        }
        $this->bindNamedParams($statement, $cursoParams);
        $this->bindNamedParams($statement, $discParams);
        if ($limite !== null) {
            $statement->bindValue('limite', $limite, PDO::PARAM_INT);
        }
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * @param list<int>|null $cursoIds
     * @param list<string>|null $codigosDisciplina
     * @return array<string, int>
     */
    public function contagemAlarmesPorTipo(
        int $coletaId,
        bool $somenteNaoVisualizados = false,
        ?array $cursoIds = null,
        ?array $codigosDisciplina = null,
        bool $incluirOutrasDisciplinasDosAlunos = false
    ): array {
        $vazio = [
            'percentual_baixo' => 0,
            'faltas_4dias' => 0,
            'faltas_3semanas' => 0,
        ];

        if (($cursoIds !== null && $cursoIds === [])
            || ($codigosDisciplina !== null && $codigosDisciplina === [])
        ) {
            return $vazio;
        }

        $sql = 'SELECT tipo, COUNT(*) AS total
                FROM alarmes
                WHERE coleta_id = :coleta_id';

        if ($somenteNaoVisualizados) {
            $sql .= ' AND visualizado = 0';
        }

        [$sql, $cursoParams] = $this->appendCursoFilter($sql, $cursoIds, 'curso_id');
        if ($incluirOutrasDisciplinasDosAlunos) {
            [$sql, $discParams] = $this->appendFiltroAlunosDasDisciplinas(
                $sql,
                $codigosDisciplina,
                'alarmes'
            );
        } else {
            [$sql, $discParams] = $this->appendCodigoDisciplinaFilter(
                $sql,
                $codigosDisciplina,
                'codigo_disciplina'
            );
        }

        $sql .= ' GROUP BY tipo';

        $statement = $this->db->prepare($sql);
        $statement->bindValue('coleta_id', $coletaId, PDO::PARAM_INT);
        $this->bindNamedParams($statement, $cursoParams);
        $this->bindNamedParams($statement, $discParams);
        $statement->execute();

        $totais = $vazio;

        foreach ($statement->fetchAll() as $row) {
            $tipo = (string)$row['tipo'];
            if (array_key_exists($tipo, $totais)) {
                $totais[$tipo] = (int)$row['total'];
            }
        }

        return $totais;
    }

    /**
     * @param list<int>|null $cursoIds
     * @param list<string>|null $codigosDisciplina
     * @return array{alunos: int, alarmes: int, alunos_total: int, alarmes_total: int}
     */
    public function resumoAlarmesNaoTratados(
        int $coletaId,
        ?array $cursoIds = null,
        ?array $codigosDisciplina = null,
        bool $incluirOutrasDisciplinasDosAlunos = false
    ): array {
        $vazio = [
            'alunos' => 0,
            'alarmes' => 0,
            'alunos_total' => 0,
            'alarmes_total' => 0,
        ];

        if (($cursoIds !== null && $cursoIds === [])
            || ($codigosDisciplina !== null && $codigosDisciplina === [])
        ) {
            return $vazio;
        }

        $sqlBase = 'FROM alarmes WHERE coleta_id = :coleta_id';
        [$sqlBase, $cursoParams] = $this->appendCursoFilter($sqlBase, $cursoIds, 'curso_id');
        if ($incluirOutrasDisciplinasDosAlunos) {
            [$sqlBase, $discParams] = $this->appendFiltroAlunosDasDisciplinas(
                $sqlBase,
                $codigosDisciplina,
                'alarmes'
            );
        } else {
            [$sqlBase, $discParams] = $this->appendCodigoDisciplinaFilter(
                $sqlBase,
                $codigosDisciplina,
                'codigo_disciplina'
            );
        }

        $sqlAbertos = 'SELECT COUNT(*) AS alarmes,
                              COUNT(DISTINCT aluno_id || \'-\' || curso_id) AS alunos
                       ' . $sqlBase . ' AND visualizado = 0';

        $sqlTotal = 'SELECT COUNT(*) AS alarmes,
                            COUNT(DISTINCT aluno_id || \'-\' || curso_id) AS alunos
                     ' . $sqlBase;

        $abertos = $this->db->prepare($sqlAbertos);
        $abertos->bindValue('coleta_id', $coletaId, PDO::PARAM_INT);
        $this->bindNamedParams($abertos, $cursoParams);
        $this->bindNamedParams($abertos, $discParams);
        $abertos->execute();
        $rowAbertos = $abertos->fetch() ?: [];

        $total = $this->db->prepare($sqlTotal);
        $total->bindValue('coleta_id', $coletaId, PDO::PARAM_INT);
        $this->bindNamedParams($total, $cursoParams);
        $this->bindNamedParams($total, $discParams);
        $total->execute();
        $rowTotal = $total->fetch() ?: [];

        return [
            'alunos' => (int)($rowAbertos['alunos'] ?? 0),
            'alarmes' => (int)($rowAbertos['alarmes'] ?? 0),
            'alunos_total' => (int)($rowTotal['alunos'] ?? 0),
            'alarmes_total' => (int)($rowTotal['alarmes'] ?? 0),
        ];
    }

    public const CONTATO_TIPOS = ['email', 'whatsapp', 'telefone', 'presencial', 'assistencia'];

    /**
     * @param list<int>|null $cursoIdsPermitidos
     * @param list<string>|null $codigosDisciplinaPermitidos
     */
    public function marcarAlarmeVisualizado(
        int $alarmeId,
        int $usuarioId,
        string $contatoTipo,
        ?array $cursoIdsPermitidos = null,
        ?array $codigosDisciplinaPermitidos = null
    ): bool {
        if (!in_array($contatoTipo, self::CONTATO_TIPOS, true)) {
            return false;
        }

        $select = $this->db->prepare(
            'SELECT id, curso_id, codigo_disciplina, visualizado FROM alarmes WHERE id = :id LIMIT 1'
        );
        $select->execute(['id' => $alarmeId]);
        $alarme = $select->fetch();

        if ($alarme === false) {
            return false;
        }

        if ($cursoIdsPermitidos !== null && !in_array((int)$alarme['curso_id'], $cursoIdsPermitidos, true)) {
            return false;
        }

        if ($codigosDisciplinaPermitidos !== null) {
            $codigo = trim((string)($alarme['codigo_disciplina'] ?? ''));
            if ($codigo === '' || !in_array($codigo, $codigosDisciplinaPermitidos, true)) {
                return false;
            }
        }

        if ((int)$alarme['visualizado'] === 1) {
            return true;
        }

        $statement = $this->db->prepare(
            'UPDATE alarmes
             SET visualizado = 1,
                 visualizado_em = datetime(\'now\'),
                 visualizado_por = :usuario_id,
                 contato_tipo = :contato_tipo
             WHERE id = :id
               AND visualizado = 0'
        );

        $statement->execute([
            'id' => $alarmeId,
            'usuario_id' => $usuarioId,
            'contato_tipo' => $contatoTipo,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * @param list<int>|null $cursoIdsPermitidos
     * @param list<string>|null $codigosDisciplinaPermitidos
     */
    public function marcarAlarmesAlunoVisualizados(
        int $coletaId,
        int $alunoId,
        int $cursoId,
        int $usuarioId,
        string $contatoTipo,
        ?array $cursoIdsPermitidos = null,
        ?array $codigosDisciplinaPermitidos = null
    ): int {
        if (!in_array($contatoTipo, self::CONTATO_TIPOS, true)) {
            return 0;
        }

        if ($cursoIdsPermitidos !== null && !in_array($cursoId, $cursoIdsPermitidos, true)) {
            return 0;
        }

        if ($codigosDisciplinaPermitidos !== null && $codigosDisciplinaPermitidos === []) {
            return 0;
        }

        $sql = 'UPDATE alarmes
                SET visualizado = 1,
                    visualizado_em = datetime(\'now\'),
                    visualizado_por = :usuario_id,
                    contato_tipo = :contato_tipo
                WHERE coleta_id = :coleta_id
                  AND aluno_id = :aluno_id
                  AND curso_id = :curso_id
                  AND visualizado = 0';

        $params = [
            'usuario_id' => $usuarioId,
            'contato_tipo' => $contatoTipo,
            'coleta_id' => $coletaId,
            'aluno_id' => $alunoId,
            'curso_id' => $cursoId,
        ];

        if ($codigosDisciplinaPermitidos !== null) {
            $placeholders = [];
            foreach (array_values($codigosDisciplinaPermitidos) as $i => $codigo) {
                $key = 'disc_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = $codigo;
            }
            $sql .= ' AND codigo_disciplina IN (' . implode(', ', $placeholders) . ')';
        }

        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }

    /**
     * Ingressantes do periodo com frequencia do curso < 75%.
     *
     * @param list<int>|null $cursoIds
     * @param list<string>|null $codigosDisciplina
     * @return list<array<string, mixed>>
     */
    public function ingressantesComProblema(
        int $coletaId,
        string $periodoIngresso,
        ?array $cursoIds = null,
        ?array $codigosDisciplina = null
    ): array {
        $periodoIngresso = trim($periodoIngresso);
        if ($periodoIngresso === '') {
            return [];
        }

        if (($cursoIds !== null && $cursoIds === [])
            || ($codigosDisciplina !== null && $codigosDisciplina === [])
        ) {
            return [];
        }

        $sql = 'SELECT a.id AS aluno_id,
                       a.nome AS aluno_nome,
                       a.nome_social AS aluno_nome_social,
                       a.email AS aluno_email,
                       a.matricula,
                       c.id AS curso_id,
                       c.nome_curso,
                       ac.ano_semestre_ingresso,
                       ac.turma_entrada,
                       fc.percentual_frequencia,
                       fc.horarios,
                       fc.ausencias,
                       fc.presencas
                FROM frequencia_curso fc
                INNER JOIN alunos a ON a.id = fc.aluno_id
                INNER JOIN cursos c ON c.id = fc.curso_id
                INNER JOIN aluno_cursos ac
                        ON ac.aluno_id = fc.aluno_id AND ac.curso_id = fc.curso_id
                WHERE fc.coleta_id = :coleta_id
                  AND ac.ano_semestre_ingresso = :periodo
                  AND fc.percentual_frequencia IS NOT NULL
                  AND fc.percentual_frequencia < 75';

        [$sql, $cursoParams] = $this->appendCursoFilter($sql, $cursoIds, 'fc.curso_id');

        if ($codigosDisciplina !== null) {
            // Professor: so cursos em que leciona alguma disciplina na coleta.
            $placeholders = [];
            foreach (array_values($codigosDisciplina) as $i => $_) {
                $placeholders[] = ':disc_' . $i;
            }
            $sql .= ' AND EXISTS (
                        SELECT 1 FROM frequencia_disciplina fd
                        WHERE fd.coleta_id = fc.coleta_id
                          AND fd.aluno_id = fc.aluno_id
                          AND fd.curso_id = fc.curso_id
                          AND fd.codigo_disciplina IN (' . implode(', ', $placeholders) . ')
                      )';
            $discParams = [];
            foreach (array_values($codigosDisciplina) as $i => $codigo) {
                $discParams['disc_' . $i] = $codigo;
            }
        } else {
            $discParams = [];
        }

        $sql .= ' ORDER BY c.nome_curso ASC,
                          fc.percentual_frequencia ASC,
                          COALESCE(NULLIF(TRIM(a.nome_social), \'\'), a.nome) ASC';

        $statement = $this->db->prepare($sql);
        $statement->bindValue('coleta_id', $coletaId, PDO::PARAM_INT);
        $statement->bindValue('periodo', $periodoIngresso, PDO::PARAM_STR);
        $this->bindNamedParams($statement, $cursoParams);
        $this->bindNamedParams($statement, $discParams);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * Alunos com status TRANCADO / TRANC. AUTOMATICO na coleta.
     *
     * @param list<int>|null $cursoIds
     * @param list<string>|null $codigosDisciplina cursos em que o professor leciona
     * @return list<array<string, mixed>>
     */
    public function alunosTrancados(
        int $coletaId,
        ?array $cursoIds = null,
        ?array $codigosDisciplina = null
    ): array {
        $sql = 'SELECT t.id, t.login, t.matricula, t.nome, t.nome_social, t.email,
                       t.nome_curso, t.status_discente,
                       t.ano_semestre_ingresso, t.turma_entrada,
                       t.aluno_id, t.curso_id
                FROM alunos_trancados t
                WHERE t.coleta_id = :coleta_id';
        $params = [];

        if ($cursoIds !== null) {
            if ($cursoIds === []) {
                return [];
            }
            $placeholders = [];
            foreach (array_values($cursoIds) as $i => $id) {
                $key = 'curso_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = (int)$id;
            }
            $sql .= ' AND t.curso_id IN (' . implode(', ', $placeholders) . ')';
        }

        if ($codigosDisciplina !== null) {
            if ($codigosDisciplina === []) {
                return [];
            }
            $placeholders = [];
            foreach (array_values($codigosDisciplina) as $i => $codigo) {
                $key = 'disc_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = (string)$codigo;
            }
            $sql .= ' AND EXISTS (
                        SELECT 1
                        FROM disciplina_grade dg
                        WHERE dg.curso_id = t.curso_id
                          AND dg.codigo_disciplina IN (' . implode(', ', $placeholders) . ')
                      )';
        }

        $sql .= ' ORDER BY t.nome_curso ASC, t.nome ASC, t.matricula ASC';

        $statement = $this->db->prepare($sql);
        $statement->bindValue('coleta_id', $coletaId, PDO::PARAM_INT);
        $this->bindNamedParams($statement, $params);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * Ultima execucao do script de perda de vaga (manual).
     *
     * @return array<string, mixed>|null
     */
    public function ultimaExecucaoPerdaVaga(): ?array
    {
        $statement = $this->db->query(
            'SELECT id, periodo_atual, semestre_a, semestre_b,
                    total_candidatos, executado_em
             FROM perda_vaga_execucoes
             ORDER BY id DESC
             LIMIT 1'
        );
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Candidatos da execucao, com filtro de curso/disciplinas do perfil.
     *
     * @param list<int>|null $cursoIds
     * @param list<string>|null $codigosDisciplina
     * @return list<array<string, mixed>>
     */
    public function candidatosPerdaVaga(
        int $execucaoId,
        ?array $cursoIds = null,
        ?array $codigosDisciplina = null
    ): array {
        $sql = 'SELECT c.id, c.login, c.matricula, c.nome, c.nome_social, c.email,
                       c.nome_curso, c.aluno_id, c.curso_id,
                       c.matriculado_periodo_atual, c.status_periodo_atual
                FROM perda_vaga_candidatos c
                WHERE c.execucao_id = :execucao_id';
        $params = [];

        if ($cursoIds !== null) {
            if ($cursoIds === []) {
                return [];
            }
            $placeholders = [];
            foreach (array_values($cursoIds) as $i => $id) {
                $key = 'curso_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = (int)$id;
            }
            $sql .= ' AND c.curso_id IN (' . implode(', ', $placeholders) . ')';
        }

        if ($codigosDisciplina !== null) {
            if ($codigosDisciplina === []) {
                return [];
            }
            $placeholders = [];
            foreach (array_values($codigosDisciplina) as $i => $codigo) {
                $key = 'cod_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = (string)$codigo;
            }
            $sql .= ' AND EXISTS (
                        SELECT 1
                        FROM disciplina_grade dg
                        WHERE dg.curso_id = c.curso_id
                          AND dg.codigo_disciplina IN (' . implode(', ', $placeholders) . ')
                      )';
        }

        $sql .= ' ORDER BY c.nome_curso ASC,
                          COALESCE(NULLIF(TRIM(c.nome_social), \'\'), c.nome) ASC,
                          c.matricula ASC';

        $statement = $this->db->prepare($sql);
        $statement->bindValue('execucao_id', $execucaoId, PDO::PARAM_INT);
        $this->bindNamedParams($statement, $params);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * Reprovacoes (disciplinas) dos candidatos da execucao.
     *
     * @param list<int> $candidatoIds
     * @return list<array<string, mixed>>
     */
    public function reprovacoesPerdaVaga(array $candidatoIds): array
    {
        if ($candidatoIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach (array_values($candidatoIds) as $i => $id) {
            $key = 'c_' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = (int)$id;
        }

        $sql = 'SELECT r.candidato_id, r.semestre, r.disciplina, r.cod_disciplina,
                       r.causa
                FROM perda_vaga_reprovacoes r
                WHERE r.candidato_id IN (' . implode(', ', $placeholders) . ')
                ORDER BY r.semestre ASC, r.disciplina ASC';

        $statement = $this->db->prepare($sql);
        $this->bindNamedParams($statement, $params);
        $statement->execute();

        return $statement->fetchAll();
    }
}
