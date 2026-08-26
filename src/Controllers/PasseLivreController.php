<?php
declare(strict_types=1);

namespace Mapa\Controllers;

use Mapa\Core\Auth;
use Mapa\Core\Controller;
use Mapa\Core\Session;
use Mapa\Models\AnalyticsRepository;

class PasseLivreController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();

        $repo = new AnalyticsRepository();
        $meta = $repo->metaPasseLivre();

        $isCoordenador = Auth::isCoordenador();
        $isProfessor = Auth::isProfessor();
        $semSeletorCurso = $isCoordenador || $isProfessor;
        $podeGerar = Auth::canGerarPasseLivre();

        $cursosDisponiveis = $semSeletorCurso ? [] : $repo->listarCursos(null);
        $cursoSelecionado = $this->cursoSelecionado($cursosDisponiveis);
        $escopo = $this->resolverEscopo($repo, $cursoSelecionado);
        $filtroNome = $this->filtroNome();

        $linhas = [];
        $disciplinasPorLinha = [];

        if ($meta !== null && $escopo['aviso'] === null) {
            $linhas = $repo->linhasPasseLivre(
                $escopo['cursoIds'],
                $escopo['codigosDisciplina'],
                $filtroNome
            );
            $ids = array_map(
                static fn(array $l): int => (int)$l['id'],
                $linhas
            );
            $disciplinasPorLinha = $repo->disciplinasPasseLivre($ids);
        }

        $erro = Session::flash('erro');
        if ($erro === null && $meta === null) {
            $erro = $podeGerar
                ? 'Nenhuma análise de passe livre gerada. Use o botão “Gerar passe livre”.'
                : 'Nenhuma análise de passe livre gerada.';
        }

        $this->render('passe_livre/index', [
            'meta' => $meta,
            'linhas' => $linhas,
            'disciplinasPorLinha' => $disciplinasPorLinha,
            'totalAlunos' => count($linhas),
            'cursosDisponiveis' => $cursosDisponiveis,
            'cursoSelecionado' => $cursoSelecionado,
            'cursoExibido' => $escopo['cursoExibido'],
            'filtroNome' => $filtroNome,
            'rotuloGeral' => 'Todos os cursos',
            'semSeletorCurso' => $semSeletorCurso,
            'avisoCoordenador' => $escopo['aviso'],
            'erro' => $erro,
            'sucesso' => Session::flash('sucesso'),
            'podeGerarPasseLivre' => $podeGerar,
            'isAdmin' => Auth::isAdmin(),
        ]);
    }

    public function gerar(): void
    {
        $this->requireAuth();
        if (!Auth::canGerarPasseLivre()) {
            http_response_code(403);
            Session::flash('erro', 'Acesso restrito a administradores e perfil geral.');
            $this->redirect('/passe-livre');
        }

        $root = dirname(__DIR__, 2);
        $script = $root . '/python/gerar_passe_livre.py';
        if (!is_file($script)) {
            Session::flash('erro', 'Script python/gerar_passe_livre.py não encontrado.');
            $this->redirect('/passe-livre');
        }

        $dataDir = $root . '/data';
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0775, true);
        }
        $log = $dataDir . '/passe_livre.log';
        Session::flash(
            'sucesso',
            'Geração de passe livre iniciada. Atualize a página em alguns minutos.'
        );
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $this->dispararEmSegundoPlano(sprintf(
            'cd %s && nohup python3 %s >> %s 2>&1 < /dev/null',
            escapeshellarg($root),
            escapeshellarg($script),
            escapeshellarg($log)
        ));
        $this->redirect('/passe-livre');
    }

    private function dispararEmSegundoPlano(string $comando): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen('start /B ' . $comando, 'r'));

            return;
        }

        // Subshell em background: PHP não espera o Python terminar (exec() com & bloqueia).
        pclose(popen('(' . $comando . ') > /dev/null 2>&1 &', 'r'));
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

    private function filtroNome(): string
    {
        $nome = isset($_GET['nome']) ? trim((string)$_GET['nome']) : '';
        if ($nome === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($nome, 0, 120);
        }

        return substr($nome, 0, 120);
    }
}
