<?php
declare(strict_types=1);

namespace Mapa\Controllers;

use Mapa\Core\Auth;
use Mapa\Core\Controller;
use Mapa\Models\AnalyticsRepository;

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
            $nomeCurso = (string)($linha['nome_curso'] ?? 'Curso não informado');
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

        $this->render('trancados/index', [
            'coleta' => $coleta,
            'porCurso' => array_values($porCurso),
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
