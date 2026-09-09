<?php
declare(strict_types=1);

namespace Mapa\Controllers;

use Mapa\Core\Auth;
use Mapa\Core\Controller;
use Mapa\Core\Session;
use Mapa\Core\Url;
use Mapa\Lib\PasseLivreAtestadoPdf;
use Mapa\Models\AnalyticsRepository;
use Mapa\Models\ConfigRepository;
use Mapa\Models\PasseLivreAtestadoRepository;

class PasseLivreController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();

        $repo = new AnalyticsRepository();
        $periodosDisponiveis = $repo->listarPeriodosPasseLivre();
        $semestreSelecionado = $this->semestreSelecionado($periodosDisponiveis);
        $meta = $semestreSelecionado !== ''
            ? $repo->metaPasseLivre($semestreSelecionado)
            : null;

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
        $atestadosPorLinha = [];

        if ($semestreSelecionado !== '' && $escopo['aviso'] === null) {
            $linhas = $repo->linhasPasseLivre(
                $escopo['cursoIds'],
                $escopo['codigosDisciplina'],
                $filtroNome,
                $semestreSelecionado
            );
            $ids = array_map(
                static fn(array $l): int => (int)$l['id'],
                $linhas
            );
            $disciplinasPorLinha = $repo->disciplinasPasseLivre($ids);
            $atestadosPorLinha = (new PasseLivreAtestadoRepository())->mapearPorAlunoCursoIds($ids);
        }

        $erro = Session::flash('erro');
        if ($erro === null && $periodosDisponiveis === []) {
            $erro = $podeGerar
                ? 'Nenhuma análise de passe livre gerada. Use o botão “Gerar passe livre”.'
                : 'Nenhuma análise de passe livre gerada.';
        } elseif ($erro === null && $meta === null && $semestreSelecionado !== '') {
            $erro = 'Nenhum dado de passe livre para o semestre ' . $semestreSelecionado . '.';
        }

        $this->render('passe_livre/index', [
            'meta' => $meta,
            'periodosDisponiveis' => $periodosDisponiveis,
            'semestreSelecionado' => $semestreSelecionado,
            'linhas' => $linhas,
            'disciplinasPorLinha' => $disciplinasPorLinha,
            'atestadosPorLinha' => $atestadosPorLinha,
            'linkConferenciaBase' => $this->urlConferenciaBase(),
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
            'podeAssinarPasseLivre' => Auth::canAssinarPasseLivre(),
            'isAdmin' => Auth::isAdmin(),
        ]);
    }

    public function gerar(): void
    {
        $this->requireAuth();
        if (!Auth::canGerarPasseLivre()) {
            http_response_code(403);
            Session::flash('erro', 'Acesso restrito a administradores.');
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
        $python = $this->resolverPython3();
        $iniciadoEm = date('Y-m-d H:i:s');
        $cabecalho = sprintf("[%s] Geração solicitada via web (python: %s).\n", $iniciadoEm, $python);
        if (@file_put_contents($log, $cabecalho, FILE_APPEND | LOCK_EX) === false) {
            Session::flash(
                'erro',
                'Não foi possível gravar em data/passe_livre.log. Verifique permissões da pasta data/.'
            );
            $this->redirect('/passe-livre');
        }

        if (!$this->dispararEmSegundoPlano(sprintf(
            'cd %s && nohup %s %s --semestres 3 >> %s 2>&1 < /dev/null',
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
            $this->redirect('/passe-livre');
        }

        Session::flash(
            'sucesso',
            'Geração de passe livre iniciada (3 semestres anteriores). Acompanhe em data/passe_livre.log e atualize a página em alguns minutos.'
        );
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $this->redirect('/passe-livre');
    }

    public function assinar(): void
    {
        $this->requireAuth();
        header('Content-Type: application/json; charset=utf-8');

        if (!Auth::canAssinarPasseLivre()) {
            http_response_code(403);
            echo json_encode(
                ['ok' => false, 'erro' => 'Seu usuário não tem permissão para assinar atestados.'],
                JSON_UNESCAPED_UNICODE
            );
            exit;
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'erro' => 'Documento inválido.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $repo = new AnalyticsRepository();
        $escopo = $this->resolverEscopo($repo, 'todos');
        if ($escopo['aviso'] !== null) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'erro' => 'Acesso negado.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $linha = $repo->linhaPasseLivrePorId(
            $id,
            $escopo['cursoIds'],
            $escopo['codigosDisciplina']
        );
        if ($linha === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'erro' => 'Registro não encontrado.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        try {
            $usuario = Auth::user();
            $disciplinas = $repo->disciplinasPasseLivre([$id])[$id] ?? [];
            $substituir = !empty($_POST['renovar']);
            $atestado = (new PasseLivreAtestadoRepository())->assinar(
                $linha,
                $disciplinas,
                isset($usuario['id']) ? (int)$usuario['id'] : null,
                $substituir
            );
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(
                ['ok' => false, 'erro' => 'Não foi possível assinar o documento.'],
                JSON_UNESCAPED_UNICODE
            );
            exit;
        }

        echo json_encode([
            'ok' => true,
            'atestado' => $this->payloadAtestado($atestado),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function pdf(): void
    {
        $this->requireAuth();

        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(404);
            exit;
        }

        $repo = new AnalyticsRepository();
        $escopo = $this->resolverEscopo($repo, 'todos');
        if ($escopo['aviso'] !== null) {
            http_response_code(403);
            exit;
        }

        $linha = $repo->linhaPasseLivrePorId(
            $id,
            $escopo['cursoIds'],
            $escopo['codigosDisciplina']
        );
        if ($linha === null) {
            http_response_code(404);
            exit;
        }

        $atestado = (new PasseLivreAtestadoRepository())->buscarPorAlunoCursoId($id);
        if ($atestado === null) {
            http_response_code(403);
            echo 'Documento ainda não assinado.';
            exit;
        }

        $disciplinas = $repo->disciplinasPasseLivre([$id])[$id] ?? [];
        $dados = $this->montarDadosAtestado($linha, $disciplinas, $atestado);
        $pdf = PasseLivreAtestadoPdf::gerar($dados);
        $pdf->output(PasseLivreAtestadoPdf::nomeArquivo($dados));
    }

    public function conferencia(): void
    {
        $codigo = trim((string)($_GET['c'] ?? ''));
        $atestado = $codigo !== ''
            ? (new PasseLivreAtestadoRepository())->buscarPorCodigo($codigo)
            : null;

        $this->render('passe_livre/conferencia', [
            'atestado' => $atestado,
            'valido' => $atestado !== null,
        ], 'layouts/auth');
    }

    /**
     * @param array<string, mixed> $linha
     * @param list<array<string, mixed>> $disciplinas
     * @param array<string, mixed>|null $atestado
     * @return array<string, mixed>
     */
    private function montarDadosAtestado(array $linha, array $disciplinas, ?array $atestado = null): array
    {
        $nomeSocial = trim((string)($linha['nome_social'] ?? ''));
        $nome = $nomeSocial !== ''
            ? $nomeSocial
            : trim((string)($linha['nome'] ?? ''));

        $dados = [
            'nome' => $nome,
            'matricula' => (string)($linha['matricula'] ?? ''),
            'curso' => (string)($linha['nome_curso'] ?? ''),
            'periodo' => (string)($linha['periodo'] ?? ''),
            'ingresso' => (string)($linha['ano_semestre_ingresso'] ?? ''),
            'frequencia' => $linha['frequencia'] ?? null,
            'data_inicial' => (string)($linha['data_inicial'] ?? ''),
            'data_final' => (string)($linha['data_final'] ?? ''),
            'disciplinas' => array_map(
                static function (array $disc): array {
                    return [
                        'codigo' => (string)($disc['codigo_disciplina'] ?? ''),
                        'nome' => (string)($disc['disciplina'] ?? ''),
                        'frequencia' => $disc['frequencia'] ?? null,
                    ];
                },
                $disciplinas
            ),
        ];

        if ($atestado !== null) {
            $dados['numero'] = (int)$atestado['numero'];
            $dados['ano'] = (int)$atestado['ano'];
            $dados['data_documento'] = (string)$atestado['data_documento'];
            $dados['assinado_em'] = (string)$atestado['assinado_em'];
            $dados['link_conferencia'] = $this->urlConferencia(
                (string)$atestado['codigo_verificacao']
            );
        }

        return $dados;
    }

    /**
     * @param array<string, mixed> $atestado
     * @return array<string, mixed>
     */
    private function payloadAtestado(array $atestado): array
    {
        return [
            'numero' => (int)$atestado['numero'],
            'ano' => (int)$atestado['ano'],
            'numero_formatado' => (string)$atestado['numero_formatado'],
            'data_documento' => (string)$atestado['data_documento'],
            'assinado_em' => (string)$atestado['assinado_em'],
            'assinado_em_fmt' => PasseLivreAtestadoPdf::formatarAssinadoEm(
                (string)$atestado['assinado_em']
            ),
            'codigo_verificacao' => (string)$atestado['codigo_verificacao'],
            'link_conferencia' => $this->urlConferencia(
                (string)$atestado['codigo_verificacao']
            ),
        ];
    }

    private function urlConferenciaBase(): string
    {
        $base = (new ConfigRepository())->getAppUrl();
        if ($base !== '') {
            return rtrim($base, '/') . Url::to('/passe-livre/conferencia');
        }

        return Url::to('/passe-livre/conferencia');
    }

    private function urlConferencia(string $codigo): string
    {
        $base = $this->urlConferenciaBase();
        $sep = str_contains($base, '?') ? '&' : '?';

        return $base . $sep . 'c=' . rawurlencode($codigo);
    }

    private function resolverPython3(): string
    {
        foreach (['/usr/bin/python3', '/usr/local/bin/python3'] as $caminho) {
            if (is_executable($caminho)) {
                return $caminho;
            }
        }

        return 'python3';
    }

    private function dispararEmSegundoPlano(string $comando): bool
    {
        if ($this->funcaoPhpDesabilitada('popen')) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $handle = @popen('start /B ' . $comando, 'r');
            if (!is_resource($handle)) {
                return false;
            }
            pclose($handle);

            return true;
        }

        // Subshell em background: PHP não espera o Python terminar (exec() com & bloqueia).
        $handle = @popen('(' . $comando . ') > /dev/null 2>&1 &', 'r');
        if (!is_resource($handle)) {
            return false;
        }
        pclose($handle);

        return true;
    }

    private function funcaoPhpDesabilitada(string $funcao): bool
    {
        $desabilitadas = ini_get('disable_functions');
        if (!is_string($desabilitadas) || trim($desabilitadas) === '') {
            return false;
        }

        $lista = array_map('trim', explode(',', strtolower($desabilitadas)));

        return in_array(strtolower($funcao), $lista, true);
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

    /**
     * @param list<string> $periodosDisponiveis
     */
    private function semestreSelecionado(array $periodosDisponiveis): string
    {
        if ($periodosDisponiveis === []) {
            return '';
        }

        $param = isset($_GET['semestre']) ? trim((string)$_GET['semestre']) : '';
        if ($param !== '' && in_array($param, $periodosDisponiveis, true)) {
            return $param;
        }

        return $periodosDisponiveis[0];
    }
}
