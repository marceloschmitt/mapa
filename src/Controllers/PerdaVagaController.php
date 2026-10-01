<?php
declare(strict_types=1);

namespace Mapa\Controllers;

use Mapa\Core\Auth;
use Mapa\Core\Controller;
use Mapa\Core\Session;
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

        $podeGerar = Auth::isAdmin();
        $erro = Session::flash('erro');
        if ($erro === null && $execucao === null) {
            $erro = $podeGerar
                ? 'Nenhuma análise de perda de vaga gerada. Use o botão “Gerar análise”.'
                : 'Nenhuma análise de perda de vaga gerada. Solicite a um administrador.';
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
            'erro' => $erro,
            'sucesso' => Session::flash('sucesso'),
            'podeGerarPerdaVaga' => $podeGerar,
            'isAdmin' => Auth::isAdmin(),
        ]);
    }

    public function gerar(): void
    {
        $this->requireAuth();
        if (!Auth::isAdmin()) {
            http_response_code(403);
            Session::flash('erro', 'Acesso restrito a administradores.');
            $this->redirect('/perda-vaga');
        }

        $root = dirname(__DIR__, 2);
        $script = $root . '/python/gerar_perda_vaga.py';
        if (!is_file($script)) {
            Session::flash('erro', 'Script python/gerar_perda_vaga.py não encontrado.');
            $this->redirect('/perda-vaga');
        }

        $dataDir = $root . '/data';
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0775, true);
        }
        $log = $dataDir . '/perda_vaga.log';
        $python = $this->resolverPython3();
        $cabecalho = sprintf("[%s] Geração solicitada via web (python: %s).\n", date('Y-m-d H:i:s'), $python);
        if (@file_put_contents($log, $cabecalho, FILE_APPEND | LOCK_EX) === false) {
            Session::flash(
                'erro',
                'Não foi possível gravar em data/perda_vaga.log. Verifique permissões da pasta data/.'
            );
            $this->redirect('/perda-vaga');
        }

        if (!$this->dispararEmSegundoPlano(sprintf(
            'cd %s && nohup %s %s >> %s 2>&1 < /dev/null',
            escapeshellarg($root),
            escapeshellarg($python),
            escapeshellarg($script),
            escapeshellarg($log)
        ))) {
            @file_put_contents(
                $log,
                sprintf("[%s] ERRO: não foi possível iniciar o processo em segundo plano.\n", date('Y-m-d H:i:s')),
                FILE_APPEND | LOCK_EX
            );
            Session::flash(
                'erro',
                'Não foi possível iniciar a geração. Verifique se popen/proc_open estão habilitados no PHP.'
            );
            $this->redirect('/perda-vaga');
        }

        Session::flash(
            'sucesso',
            'Análise de perda de vaga iniciada. Acompanhe em data/perda_vaga.log e atualize a página em alguns instantes.'
        );
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $this->redirect('/perda-vaga');
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
