<?php
declare(strict_types=1);

namespace Mapa\Controllers;

use Mapa\Core\Auth;
use Mapa\Core\Controller;
use Mapa\Models\AnalyticsRepository;

class PerdaVagaController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();

        $repo = new AnalyticsRepository();
        $execucao = $repo->ultimaExecucaoPerdaVaga();

        $isCoordenador = Auth::isCoordenador();
        $isProfessor = Auth::isProfessor();
        $semSeletorCurso = $isCoordenador || $isProfessor;

        $cursosDisponiveis = $semSeletorCurso ? [] : $repo->listarCursos(null);
        $cursoSelecionado = $this->cursoSelecionado($cursosDisponiveis);
        $escopo = $this->resolverEscopo($repo, $cursoSelecionado);

        $porCurso = [];
        $totalAlunos = 0;
        $totalReprovacoes = 0;
        $totalMatriculadosAtual = 0;

        if ($execucao !== null && $escopo['aviso'] === null) {
            $candidatos = $repo->candidatosPerdaVaga(
                (int)$execucao['id'],
                $escopo['cursoIds'],
                $escopo['codigosDisciplina']
            );
            $ids = array_map(
                static fn(array $c): int => (int)$c['id'],
                $candidatos
            );
            $reprovacoes = $repo->reprovacoesPerdaVaga($ids);
            $porCandidato = [];
            foreach ($reprovacoes as $linha) {
                $cid = (int)$linha['candidato_id'];
                if (!isset($porCandidato[$cid])) {
                    $porCandidato[$cid] = [];
                }
                $porCandidato[$cid][] = $linha;
                $totalReprovacoes++;
            }

            foreach ($candidatos as $candidato) {
                $nomeCurso = (string)($candidato['nome_curso'] ?? 'Curso não informado');
                if (!isset($porCurso[$nomeCurso])) {
                    $porCurso[$nomeCurso] = [
                        'nome_curso' => $nomeCurso,
                        'candidatos' => [],
                    ];
                }
                $candidato['reprovacoes'] = $porCandidato[(int)$candidato['id']] ?? [];
                $porCurso[$nomeCurso]['candidatos'][] = $candidato;
                $totalAlunos++;
                if (!empty($candidato['matriculado_periodo_atual'])) {
                    $totalMatriculadosAtual++;
                }
            }
        }

        $this->render('perda_vaga/index', [
            'execucao' => $execucao,
            'porCurso' => array_values($porCurso),
            'totalAlunos' => $totalAlunos,
            'totalCursos' => count($porCurso),
            'totalReprovacoes' => $totalReprovacoes,
            'totalMatriculadosAtual' => $totalMatriculadosAtual,
            'cursosDisponiveis' => $cursosDisponiveis,
            'cursoSelecionado' => $cursoSelecionado,
            'cursoExibido' => $escopo['cursoExibido'],
            'rotuloGeral' => 'Todos os cursos',
            'semSeletorCurso' => $semSeletorCurso,
            'avisoCoordenador' => $escopo['aviso'],
            'erro' => $execucao === null
                ? 'Nenhuma análise de perda de vaga gerada. Execute: python3 python/gerar_perda_vaga.py'
                : null,
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
