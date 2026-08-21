<?php
declare(strict_types=1);

namespace Mapa\Controllers;

use Mapa\Core\Auth;
use Mapa\Core\Controller;
use Mapa\Core\Session;
use Mapa\Models\AnalyticsRepository;

class AnalyticsController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();

        $repo = new AnalyticsRepository();
        $coleta = $repo->ultimaColeta();

        $isCoordenador = Auth::isCoordenador();
        $isProfessor = Auth::isProfessor();
        $cursosDoCoordenador = $isCoordenador ? Auth::cursoIds() : [];
        $cursoProprioId = $cursosDoCoordenador[0] ?? null;
        $codigosProfessor = $isProfessor ? Auth::disciplinaCodigos() : null;

        // Coordenador e professor nao escolhem curso livremente.
        $cursosDisponiveis = ($isCoordenador || $isProfessor)
            ? []
            : $repo->listarCursos(null);
        $cursoSelecionado = $this->cursoSelecionado($cursosDisponiveis, $cursoProprioId);
        $filtroCursoIds = $this->filtroCursoIds($cursoSelecionado, $cursoProprioId);

        // Professor: sem filtro de curso, mas restrito as suas disciplinas nos alarmes do resumo.
        if ($isProfessor) {
            $filtroCursoIds = null;
        }

        $cursoExibido = null;
        if ($isCoordenador && $cursoProprioId !== null) {
            $cursos = $repo->listarCursos([$cursoProprioId]);
            $cursoExibido = $cursos[0]['nome_curso'] ?? null;
        } elseif ($isProfessor) {
            $cursoExibido = 'Minhas disciplinas';
        }

        $avisoCoordenador = null;
        if ($isCoordenador && $cursoProprioId === null) {
            $avisoCoordenador = 'Nenhum curso vinculado ao seu usuário. Contate o administrador.';
        } elseif ($isProfessor && ($codigosProfessor === null || $codigosProfessor === [])) {
            $avisoCoordenador = 'Nenhuma disciplina vinculada ao seu CPF. Contate o administrador.';
        }

        $dadosBase = [
            'cursosDisponiveis' => $cursosDisponiveis,
            'cursoSelecionado' => $cursoSelecionado,
            'cursoExibido' => $cursoExibido,
            'rotuloGeral' => 'Todos os cursos',
            'isCoordenador' => $isCoordenador,
            'isProfessor' => $isProfessor,
            'avisoCoordenador' => $avisoCoordenador,
            'isAdmin' => Auth::isAdmin(),
        ];

        if ($coleta === null) {
            $this->render('analytics/index', array_merge($dadosBase, [
                'coleta' => null,
                'resumo' => null,
                'porCurso' => ['labels' => [], 'values' => [], 'ids' => []],
                'porDiaSemana' => ['labels' => [], 'values' => []],
                'porMes' => ['labels' => [], 'values' => [], 'semanas' => []],
                'disciplinasCriticas' => [],
                'erro' => 'Nenhuma coleta importada. Rode python3 importar_frequencia.py',
            ]));
            return;
        }

        $coletaId = (int)$coleta['id'];

        // Professor sem disciplinas: nao vaza demais dados do campus, mas
        // mantem o comparativo de frequencia media por curso (como o admin).
        if ($isProfessor && ($codigosProfessor === null || $codigosProfessor === [])) {
            $this->render('analytics/index', array_merge($dadosBase, [
                'coleta' => $coleta,
                'resumo' => [
                    'total_disciplinas' => 0,
                    'media_frequencia' => 0.0,
                    'abaixo_75' => 0,
                    'total_alarmes' => 0,
                    'nao_visualizados' => 0,
                    'percentual_baixo' => 0,
                    'faltas_4dias' => 0,
                    'faltas_3semanas' => 0,
                ],
                'porCurso' => $repo->frequenciaPorCurso($coletaId, null),
                'porDiaSemana' => ['labels' => [], 'values' => []],
                'porMes' => ['labels' => [], 'values' => [], 'semanas' => []],
                'disciplinasCriticas' => [],
                'erro' => Session::flash('erro'),
                'sucesso' => Session::flash('sucesso'),
            ]));
            return;
        }

        $resumo = $repo->resumoColeta($coletaId, $filtroCursoIds);
        if ($isProfessor) {
            $resumoAlarmes = $repo->resumoAlarmesNaoTratados($coletaId, null, $codigosProfessor);
            $totaisTipo = $repo->contagemAlarmesPorTipo($coletaId, false, null, $codigosProfessor);
            $resumo['total_alarmes'] = (int)$resumoAlarmes['alarmes_total'];
            $resumo['nao_visualizados'] = (int)$resumoAlarmes['alarmes'];
            $resumo['percentual_baixo'] = (int)$totaisTipo['percentual_baixo'];
            $resumo['faltas_4dias'] = (int)$totaisTipo['faltas_4dias'];
            $resumo['faltas_3semanas'] = (int)$totaisTipo['faltas_3semanas'];
        }

        $disciplinasCriticas = $repo->disciplinasCriticas($coletaId, null, $filtroCursoIds);
        if ($isProfessor && $codigosProfessor !== null) {
            $permitidos = array_fill_keys($codigosProfessor, true);
            $disciplinasCriticas = array_values(array_filter(
                $disciplinasCriticas,
                static function (array $row) use ($permitidos): bool {
                    $codigo = trim((string)($row['codigo_disciplina'] ?? ''));

                    return $codigo !== '' && isset($permitidos[$codigo]);
                }
            ));
            $resumo['total_disciplinas'] = count($disciplinasCriticas);
            $resumo['abaixo_75'] = $repo->contarAlunosAbaixo75(
                $coletaId,
                null,
                $codigosProfessor
            );
            if ($disciplinasCriticas !== []) {
                $medias = array_map(
                    static function (array $row): float {
                        return (float)($row['media'] ?? 0);
                    },
                    $disciplinasCriticas
                );
                $resumo['media_frequencia'] = round(array_sum($medias) / count($medias), 1);
            } else {
                $resumo['media_frequencia'] = 0.0;
            }
        }

        // Comparativo por curso: admin e professor veem todos os cursos.
        // Coordenador continua com a visao geral no grafico (ja era null).
        $porCurso = $repo->frequenciaPorCurso($coletaId, null);
        $porDiaSemana = $isProfessor
            ? ['labels' => [], 'values' => []]
            : $repo->faltasPorDiaSemana($coletaId, $filtroCursoIds);
        $porMes = $isProfessor
            ? ['labels' => [], 'values' => [], 'semanas' => []]
            : $repo->faltasPorMes($coletaId, $filtroCursoIds);

        $this->render('analytics/index', array_merge($dadosBase, [
            'coleta' => $coleta,
            'resumo' => $resumo,
            'porCurso' => $porCurso,
            'porDiaSemana' => $porDiaSemana,
            'porMes' => $porMes,
            'disciplinasCriticas' => $disciplinasCriticas,
            'erro' => Session::flash('erro'),
            'sucesso' => Session::flash('sucesso'),
        ]));
    }

    /**
     * @param list<array{id: int, nome_curso: string}> $cursosDisponiveis
     */
    private function cursoSelecionado(array $cursosDisponiveis, ?int $cursoProprioId): string
    {
        // Coordenador: sempre o proprio curso (sem seletor).
        if (Auth::isCoordenador()) {
            return $cursoProprioId !== null ? (string)$cursoProprioId : 'todos';
        }

        $param = isset($_GET['curso']) ? trim((string)$_GET['curso']) : null;

        if ($param === null || $param === '' || $param === 'todos') {
            return 'todos';
        }

        $id = (int)$param;
        foreach ($cursosDisponiveis as $curso) {
            if ((int)$curso['id'] === $id) {
                return (string)$id;
            }
        }

        return 'todos';
    }

    /**
     * @return list<int>|null null = todos os cursos do campus
     */
    private function filtroCursoIds(string $cursoSelecionado, ?int $cursoProprioId): ?array
    {
        if (Auth::isCoordenador()) {
            return $cursoProprioId !== null ? [$cursoProprioId] : [];
        }

        if ($cursoSelecionado === 'todos') {
            return null;
        }

        return [(int)$cursoSelecionado];
    }
}
