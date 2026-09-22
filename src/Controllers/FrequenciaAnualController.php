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
        $meta = $periodoAtual !== ''
            ? $repo->metaPasseLivre($periodoAtual)
            : null;

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

        if ($periodoAtual !== '' && $meta !== null) {
            $linhas = $repo->linhasPasseLivre(
                $cursoIds,
                null,
                $filtroNome,
                $periodoAtual
            );
            $ids = array_map(
                static fn(array $l): int => (int)$l['id'],
                $linhas
            );
            $disciplinasPorLinha = $repo->disciplinasPasseLivre($ids);
        }

        $erro = Session::flash('erro');
        if ($erro === null && $periodoAtual === '') {
            $erro = 'Período letivo não configurado na API.';
        } elseif ($erro === null && $meta === null) {
            $erro = 'Nenhum dado de frequência para o semestre '
                . $periodoAtual
                . '. Aguarde a próxima coleta (sincroniza o semestre atual em passe_livre_*).';
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
