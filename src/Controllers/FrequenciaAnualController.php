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
        $linhas = [];
        $disciplinasPorLinha = [];

        if ($meta !== null) {
            $coletaId = (int)$meta['id'];
            $linhas = $repo->linhasFrequenciaCorrente($coletaId, $cursoIds, $filtroNome);
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
            'erro' => $erro,
            'sucesso' => Session::flash('sucesso'),
        ]);
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
}
