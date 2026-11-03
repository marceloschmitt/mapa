<?php
declare(strict_types=1);

namespace Mapa\Controllers;

use Mapa\Core\Auth;
use Mapa\Core\Controller;
use Mapa\Core\Session;
use Mapa\Models\AnalyticsRepository;
use Mapa\Models\ConfigRepository;

class FrequenciaAnualController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();
        if (!Auth::canVerFrequenciaAnual()) {
            http_response_code(403);
            Session::flash('erro', 'Acesso ao relatório de frequência corrente restrito.');
            $this->redirect('/');
        }

        $periodoAtual = $this->periodoAtual();
        $repo = new AnalyticsRepository();
        $meta = $repo->ultimaColeta();

        $cursosDisponiveis = $repo->listarCursos(null);
        $cursoSelecionado = $this->cursoSelecionado($cursosDisponiveis);
        $cursoIds = null;
        $cursoExibido = 'Todos os cursos';
        if ($cursoSelecionado !== 'todos') {
            $cursoId = (int)$cursoSelecionado;
            $cursoIds = [$cursoId];
            foreach ($cursosDisponiveis as $curso) {
                if ((int)$curso['id'] === $cursoId) {
                    $cursoExibido = (string)$curso['nome_curso'];
                    break;
                }
            }
        }

        $filtroNome = $this->filtroNome();
        $somenteAuxilio = $this->filtroAuxilio();
        $linhas = [];
        $disciplinasPorLinha = [];
        $loginsComAuxilio = $repo->loginsComAuxilio();

        if ($meta !== null) {
            $coletaId = (int)$meta['id'];
            $linhas = $repo->linhasFrequenciaCorrente(
                $coletaId,
                $cursoIds,
                $filtroNome,
                $somenteAuxilio
            );
            $disciplinasPorLinha = $repo->disciplinasFrequenciaCorrente(
                $coletaId,
                array_map(static fn(array $l): int => (int)$l['aluno_id'], $linhas)
            );
        }

        $erro = Session::flash('erro');
        if ($erro === null && $meta === null) {
            $erro = 'Nenhuma coleta de frequência. Aguarde a próxima coleta.';
        }

        $this->render('frequencia_anual/index', [
            'periodoAtual' => $periodoAtual,
            'meta' => $meta,
            'linhas' => $linhas,
            'disciplinasPorLinha' => $disciplinasPorLinha,
            'totalAlunos' => count($linhas),
            'cursosDisponiveis' => $cursosDisponiveis,
            'cursoSelecionado' => $cursoSelecionado,
            'cursoExibido' => $cursoExibido,
            'filtroNome' => $filtroNome,
            'somenteAuxilio' => $somenteAuxilio,
            'loginsComAuxilio' => $loginsComAuxilio,
            'podeMarcarAuxilio' => Auth::canMarcarAuxilioEstudantil(),
            'erro' => $erro,
            'sucesso' => Session::flash('sucesso'),
        ]);
    }

    public function auxilio(): void
    {
        $this->requireAuth();
        header('Content-Type: application/json; charset=utf-8');

        if (!Auth::canVerFrequenciaAnual() || !Auth::canMarcarAuxilioEstudantil()) {
            http_response_code(403);
            echo json_encode(
                ['ok' => false, 'erro' => 'Sem permissão para marcar auxílio estudantil.'],
                JSON_UNESCAPED_UNICODE
            );
            exit;
        }

        $login = trim((string)($_POST['login'] ?? ''));
        if ($login === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'erro' => 'Aluno inválido.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $usuario = Auth::user();
        $usuarioId = isset($usuario['id']) ? (int)$usuario['id'] : 0;
        $marcado = (new AnalyticsRepository())->alternarAuxilio($login, $usuarioId);

        echo json_encode([
            'ok' => true,
            'login' => $login,
            'marcado' => $marcado,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function periodoAtual(): string
    {
        return trim((string)((new ConfigRepository())->get(ConfigRepository::API_PERIODO_LETIVO) ?? ''));
    }

    /**
     * @param list<array<string, mixed>> $cursos
     */
    private function cursoSelecionado(array $cursos): string
    {
        $pedido = trim((string)($_GET['curso'] ?? 'todos'));
        if ($pedido === 'todos' || $pedido === '') {
            return 'todos';
        }
        foreach ($cursos as $curso) {
            if ((string)$curso['id'] === $pedido) {
                return $pedido;
            }
        }

        return 'todos';
    }

    private function filtroNome(): string
    {
        return trim((string)($_GET['nome'] ?? ''));
    }

    private function filtroAuxilio(): bool
    {
        $valor = strtolower(trim((string)($_GET['auxilio'] ?? '')));

        return in_array($valor, ['1', 'sim', 'true', 'on'], true);
    }
}
