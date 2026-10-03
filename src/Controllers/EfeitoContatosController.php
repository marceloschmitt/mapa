<?php
declare(strict_types=1);

namespace Mapa\Controllers;

use Mapa\Core\Auth;
use Mapa\Core\Controller;
use Mapa\Core\Session;
use Mapa\Models\AnalyticsRepository;
use Mapa\Models\EfeitoContatosRepository;

class EfeitoContatosController extends Controller
{
    /** Ordem das linhas na tela; 'primeiro' é o primeiro contato de cada aluno, por qualquer canal. */
    public const ROTULOS_CANAL = [
        'primeiro' => 'Qualquer contato (o primeiro de cada aluno)',
        'email_automatico' => 'E-mail automático',
        'email' => 'E-mail enviado',
        'whatsapp' => 'WhatsApp',
        'telefone' => 'Ligação telefônica',
        'presencial' => 'Conversa presencial',
        'assistencia' => 'Encaminhamento para Assistência Estudantil',
        'nao_informado' => 'Contato sem tipo registrado',
    ];

    public function index(): void
    {
        $this->requireAdminOuGeral();

        $repo = new EfeitoContatosRepository();
        $analytics = new AnalyticsRepository();
        $execucao = $repo->ultimaExecucao();

        $isCoordenador = Auth::isCoordenador();
        $cursosDisponiveis = $isCoordenador ? [] : $analytics->listarCursos(null);
        $cursoSelecionado = $this->cursoSelecionado($cursosDisponiveis);

        $cursoIds = null;
        $cursoExibido = 'Todos os cursos';
        $aviso = null;
        if ($isCoordenador) {
            $cursoIds = Auth::cursoIds();
            if ($cursoIds === []) {
                $aviso = 'Nenhum curso vinculado ao seu usuário.';
            } elseif (count($cursoIds) === 1) {
                $cursos = $analytics->listarCursos($cursoIds);
                $cursoExibido = (string)($cursos[0]['nome_curso'] ?? 'Curso vinculado');
            } else {
                $cursoExibido = count($cursoIds) . ' cursos vinculados';
            }
        } elseif ($cursoSelecionado !== 'todos') {
            $cursoIds = [(int)$cursoSelecionado];
            foreach ($cursosDisponiveis as $curso) {
                if ((int)$curso['id'] === (int)$cursoSelecionado) {
                    $cursoExibido = (string)$curso['nome_curso'];
                }
            }
        }

        $porCanal = [];
        $porCurso = [];
        if ($execucao !== null && $aviso === null) {
            $minAulas = (int)$execucao['min_aulas'];
            $porCanal = $repo->resumoPorCanal((int)$execucao['id'], $minAulas, $cursoIds);
            $porCurso = $repo->resumoPorCurso((int)$execucao['id'], $minAulas, $cursoIds);
        }

        $podeGerar = Auth::isAdmin();
        $erro = Session::flash('erro');
        if ($erro === null && $execucao === null) {
            $erro = $podeGerar
                ? 'Nenhuma análise gerada ainda. Use o botão “Gerar análise”.'
                : 'Nenhuma análise gerada ainda. Solicite a um administrador.';
        }

        $this->render('efeito_contatos/index', [
            'execucao' => $execucao,
            'porCanal' => $porCanal,
            'porCurso' => $porCurso,
            'rotulosCanal' => self::ROTULOS_CANAL,
            'cursosDisponiveis' => $cursosDisponiveis,
            'cursoSelecionado' => $cursoSelecionado,
            'cursoExibido' => $cursoExibido,
            'semSeletorCurso' => $isCoordenador,
            'avisoCoordenador' => $aviso,
            'podeGerar' => $podeGerar,
            'erro' => $erro,
            'sucesso' => Session::flash('sucesso'),
        ]);
    }

    public function gerar(): void
    {
        $this->requireAuth();
        if (!Auth::isAdmin()) {
            http_response_code(403);
            Session::flash('erro', 'Acesso restrito a administradores.');
            $this->redirect('/efeito-contatos');
        }

        $erro = $this->iniciarScriptPython('gerar_efeito_contatos.py', 'efeito_contatos.log');
        if ($erro !== null) {
            Session::flash('erro', $erro);
        } else {
            Session::flash(
                'sucesso',
                'Análise iniciada. Acompanhe em data/efeito_contatos.log e atualize a página em alguns instantes.'
            );
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $this->redirect('/efeito-contatos');
    }

    /**
     * @param list<array<string, mixed>> $cursos
     */
    private function cursoSelecionado(array $cursos): string
    {
        $pedido = trim((string)($_GET['curso'] ?? 'todos'));
        foreach ($cursos as $curso) {
            if ((string)$curso['id'] === $pedido) {
                return $pedido;
            }
        }

        return 'todos';
    }
}
