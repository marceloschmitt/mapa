<?php
declare(strict_types=1);

namespace Mapa\Controllers;

use Mapa\Core\Auth;
use Mapa\Core\Controller;
use Mapa\Models\AnalyticsRepository;
use Mapa\Models\ConfigRepository;

class TrancadosController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();

        $repo = new AnalyticsRepository();
        $coleta = $repo->ultimaColeta();

        $isCoordenador = Auth::isCoordenador();
        $isProfessor = Auth::isProfessor();
        $semSeletorCurso = $isCoordenador || $isProfessor;

        $cursosDisponiveis = $semSeletorCurso ? [] : $repo->listarCursos(null);
        $cursoSelecionado = $this->cursoSelecionado($cursosDisponiveis);
        $escopo = $this->resolverEscopo($repo, $cursoSelecionado);
        $periodoAtual = trim((string)((new ConfigRepository())->get(ConfigRepository::API_PERIODO_LETIVO) ?? ''));

        $linhas = [];
        if ($coleta !== null && $escopo['aviso'] === null) {
            $linhas = $repo->alunosTrancados(
                (int)$coleta['id'],
                $escopo['cursoIds'],
                $escopo['codigosDisciplina']
            );
        }

        $porCurso = [];
        $alunosUnicos = [];
        foreach ($linhas as $linha) {
            $nomeCurso = (string)($linha['nome_curso'] ?? 'Sem curso');
            if (!isset($porCurso[$nomeCurso])) {
                $porCurso[$nomeCurso] = [
                    'nome_curso' => $nomeCurso,
                    'linhas' => [],
                    'alunos' => [],
                ];
            }
            $porCurso[$nomeCurso]['linhas'][] = $linha;
            $chaveAluno = (string)($linha['aluno_id'] ?? '') . '|' . (string)($linha['curso_id'] ?? '');
            $porCurso[$nomeCurso]['alunos'][$chaveAluno] = true;
            $alunosUnicos[$chaveAluno] = true;
        }

        foreach ($porCurso as &$grupo) {
            $grupo['total_alunos'] = count($grupo['alunos']);
            unset($grupo['alunos']);
        }
        unset($grupo);

        $dadosFrequenciaCorrente = [];
        if ($coleta !== null && $linhas !== []) {
            $coletaId = (int)$coleta['id'];
            $idsAlunos = array_values(array_unique(array_map(
                static fn(array $linha): int => (int)($linha['aluno_id'] ?? 0),
                $linhas
            )));
            $disciplinasFreq = $repo->disciplinasFrequenciaCorrente($coletaId, $idsAlunos);
            $freqCurso = $repo->frequenciaCursoPorAlunos($coletaId, $idsAlunos);
            $vistos = [];
            foreach ($linhas as $linha) {
                $chaveFreq = (int)($linha['aluno_id'] ?? 0) . '-' . (int)($linha['curso_id'] ?? 0);
                if (isset($vistos[$chaveFreq])) {
                    continue;
                }
                $vistos[$chaveFreq] = true;
                $nomeSocial = trim((string)($linha['nome_social'] ?? ''));
                $nome = $nomeSocial !== ''
                    ? $nomeSocial
                    : trim((string)($linha['nome'] ?? ''));
                $fc = $freqCurso[$chaveFreq] ?? [];
                $desdeRaw = trim((string)($fc['frequencia_desde'] ?? ''));
                $desdeTs = $desdeRaw !== '' ? strtotime($desdeRaw) : false;
                $disciplinas = $disciplinasFreq[$chaveFreq] ?? [];
                $dadosFrequenciaCorrente[$chaveFreq] = [
                    'nome' => $nome,
                    'matricula' => (string)($linha['matricula'] ?? ''),
                    'curso' => (string)($linha['nome_curso'] ?? ''),
                    'periodo' => $periodoAtual,
                    'anual_desde' => $desdeTs !== false ? date('d/m/Y', $desdeTs) : '',
                    'ingresso' => (string)($linha['ano_semestre_ingresso'] ?? ''),
                    'frequencia' => $fc['frequencia'] ?? null,
                    'disciplinas' => array_map(
                        static function (array $d): array {
                            return array_filter([
                                'codigo' => (string)($d['codigo_disciplina'] ?? ''),
                                'nome' => (string)($d['disciplina'] ?? ''),
                                'frequencia' => $d['frequencia'] ?? null,
                                'situacao' => (string)($d['situacao'] ?? ''),
                                'data_trancamento' => (string)($d['data_trancamento'] ?? ''),
                            ], static fn($v): bool => $v !== '' && $v !== null);
                        },
                        $disciplinas
                    ),
                ];
            }
        }

        $this->render('trancados/index', [
            'coleta' => $coleta,
            'porCurso' => array_values($porCurso),
            'dadosFrequenciaCorrente' => $dadosFrequenciaCorrente,
            'totalAlunos' => count($alunosUnicos),
            'totalCursos' => count($porCurso),
            'totalRegistros' => count($linhas),
            'cursosDisponiveis' => $cursosDisponiveis,
            'cursoSelecionado' => $cursoSelecionado,
            'cursoExibido' => $escopo['cursoExibido'],
            'rotuloGeral' => 'Todos os cursos',
            'semSeletorCurso' => $semSeletorCurso,
            'avisoCoordenador' => $escopo['aviso'],
            'erro' => $coleta === null ? 'Nenhuma coleta importada.' : null,
            'isAdmin' => Auth::isAdmin(),
        ]);
    }

    /**
     * @return array{
     *   cursoIds: list<int>|null,
     *   codigosDisciplina: list<string>|null,
     *   cursoExibido: string,
     *   aviso: string|null
     * }
     */
    private function resolverEscopo(AnalyticsRepository $repo, string $cursoSelecionado): array
    {
        $cursoIds = null;
        $codigosDisciplina = null;
        $cursoExibido = 'Todos os cursos';
        $aviso = null;

        if (Auth::isCoordenador()) {
            $cursoIds = Auth::cursoIds();
            if ($cursoIds === []) {
                $aviso = 'Nenhum curso vinculado ao seu usuário.';
            } elseif (count($cursoIds) === 1) {
                $cursos = $repo->listarCursos($cursoIds);
                $cursoExibido = (string)($cursos[0]['nome_curso'] ?? 'Curso vinculado');
            } else {
                $cursoExibido = count($cursoIds) . ' cursos vinculados';
            }
        } elseif (Auth::isProfessor()) {
            $codigosDisciplina = Auth::disciplinaCodigos();
            $cursoExibido = 'Cursos das minhas disciplinas';
            if ($codigosDisciplina === []) {
                $aviso = 'Nenhuma disciplina vinculada ao seu CPF.';
            }
        } elseif ($cursoSelecionado !== 'todos') {
            $cursoIds = [(int)$cursoSelecionado];
            $cursos = $repo->listarCursos($cursoIds);
            $cursoExibido = (string)($cursos[0]['nome_curso'] ?? 'Curso selecionado');
        }

        return [
            'cursoIds' => $cursoIds,
            'codigosDisciplina' => $codigosDisciplina,
            'cursoExibido' => $cursoExibido,
            'aviso' => $aviso,
        ];
    }

    /**
     * @param list<array{id: int, nome_curso: string}> $cursosDisponiveis
     */
    private function cursoSelecionado(array $cursosDisponiveis): string
    {
        if (Auth::isCoordenador() || Auth::isProfessor()) {
            return 'todos';
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
}
