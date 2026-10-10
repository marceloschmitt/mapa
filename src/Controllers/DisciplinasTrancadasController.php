<?php
declare(strict_types=1);

namespace Mapa\Controllers;

use Mapa\Core\Auth;
use Mapa\Core\Controller;
use Mapa\Models\AnalyticsRepository;

class DisciplinasTrancadasController extends Controller
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
            $linhas = $repo->disciplinasTrancadas(
                (int)$coleta['id'],
                $escopo['cursoIds'],
                $escopo['codigosDisciplina']
            );
        }

        $porAluno = [];
        $alunosUnicos = [];
        $cursosUnicos = [];
        foreach ($linhas as $linha) {
            $chaveAluno = (string)($linha['aluno_id'] ?? '') . '|' . (string)($linha['curso_id'] ?? '');
            $alunosUnicos[$chaveAluno] = true;
            $nomeCurso = (string)($linha['nome_curso'] ?? 'Curso não informado');
            $cursosUnicos[$nomeCurso] = true;

            if (!isset($porAluno[$chaveAluno])) {
                $nomeSocial = trim((string)($linha['nome_social'] ?? ''));
                $nomeCivil = trim((string)($linha['nome'] ?? ''));
                $porAluno[$chaveAluno] = [
                    'aluno' => [
                        'id' => (int)($linha['aluno_id'] ?? 0),
                        'curso_id' => (int)($linha['curso_id'] ?? 0),
                        'nome' => $nomeCivil,
                        'nome_social' => $nomeSocial,
                        'matricula' => (string)($linha['matricula'] ?? ''),
                        'email' => (string)($linha['email'] ?? ''),
                        'nome_curso' => $nomeCurso,
                    ],
                    'todas_trancadas' => !empty($linha['todas_trancadas']),
                    'disciplinas' => [],
                ];
            }
            $porAluno[$chaveAluno]['disciplinas'][] = [
                'codigo_disciplina' => (string)($linha['codigo_disciplina'] ?? ''),
                'disciplina' => (string)($linha['disciplina'] ?? ''),
                'situacao' => 'Trancada',
                'data_trancamento' => (string)($linha['data_trancamento'] ?? ''),
            ];
        }

        $this->render('disciplinas_trancadas/index', [
            'coleta' => $coleta,
            'porAluno' => array_values($porAluno),
            'totalAlunos' => count($alunosUnicos),
            'totalCursos' => count($cursosUnicos),
            'totalRegistros' => count($linhas),
            'porSemana' => $this->trancamentosPorSemana($linhas),
            'porQuantidade' => $this->alunosPorQuantidade($porAluno),
            'totalTodasTrancadas' => count(array_filter(
                $porAluno,
                static fn(array $grupo): bool => $grupo['todas_trancadas']
            )),
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
            $repo->restringirTurmas(Auth::turmaIds());
            $cursoExibido = 'Minhas disciplinas';
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
     * Disciplinas trancadas por semana (segunda a domingo), da primeira à última
     * semana com trancamento; semanas sem trancamento entram com zero. Alunos
     * contam uma vez por semana (aluno + curso), mesmo trancando várias disciplinas.
     *
     * @param list<array<string, mixed>> $linhas
     * @return array{labels: list<string>, disciplinas: list<int>, alunos: list<int>, sem_data: int}
     */
    public function trancamentosPorSemana(array $linhas): array
    {
        $disciplinasPorSegunda = [];
        $alunosPorSegunda = [];
        $semData = 0;
        foreach ($linhas as $linha) {
            $data = \DateTimeImmutable::createFromFormat(
                '!d/m/Y',
                trim((string)($linha['data_trancamento'] ?? ''))
            );
            if ($data === false) {
                $semData++;
                continue;
            }
            $segunda = $data->modify('monday this week')->format('Y-m-d');
            $disciplinasPorSegunda[$segunda] = ($disciplinasPorSegunda[$segunda] ?? 0) + 1;
            $chaveAluno = (string)($linha['aluno_id'] ?? '') . '|' . (string)($linha['curso_id'] ?? '');
            $alunosPorSegunda[$segunda][$chaveAluno] = true;
        }

        $labels = [];
        $disciplinas = [];
        $alunos = [];
        if ($disciplinasPorSegunda !== []) {
            ksort($disciplinasPorSegunda);
            $semana = new \DateTimeImmutable((string)array_key_first($disciplinasPorSegunda));
            $ultima = new \DateTimeImmutable((string)array_key_last($disciplinasPorSegunda));
            while ($semana <= $ultima) {
                $chave = $semana->format('Y-m-d');
                $labels[] = $semana->format('d/m') . '–' . $semana->modify('+6 days')->format('d/m');
                $disciplinas[] = $disciplinasPorSegunda[$chave] ?? 0;
                $alunos[] = count($alunosPorSegunda[$chave] ?? []);
                $semana = $semana->modify('+7 days');
            }
        }

        return [
            'labels' => $labels,
            'disciplinas' => $disciplinas,
            'alunos' => $alunos,
            'sem_data' => $semData,
        ];
    }

    /**
     * Quantos alunos (aluno + curso) trancaram 1, 2, 3... disciplinas, de 1 até o
     * maior número encontrado; quantidades sem aluno entram com zero.
     *
     * @param array<string, array{disciplinas: list<array<string, string>>}> $porAluno
     * @return array{labels: list<string>, values: list<int>}
     */
    public function alunosPorQuantidade(array $porAluno): array
    {
        $porQuantidade = [];
        foreach ($porAluno as $grupo) {
            $n = count($grupo['disciplinas']);
            $porQuantidade[$n] = ($porQuantidade[$n] ?? 0) + 1;
        }

        $labels = [];
        $values = [];
        $maior = $porQuantidade === [] ? 0 : max(array_keys($porQuantidade));
        for ($n = 1; $n <= $maior; $n++) {
            $labels[] = (string)$n;
            $values[] = $porQuantidade[$n] ?? 0;
        }

        return ['labels' => $labels, 'values' => $values];
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
