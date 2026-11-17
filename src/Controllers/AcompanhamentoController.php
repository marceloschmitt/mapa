<?php
declare(strict_types=1);

namespace Mapa\Controllers;

use Mapa\Core\Auth;
use Mapa\Core\Controller;
use Mapa\Core\Session;
use Mapa\Models\AcompanhamentoRepository;
use Mapa\Models\AnalyticsRepository;
use Mapa\Models\ContatoRepository;
use Mapa\Models\EfeitoContatosRepository;

/**
 * Eficácia dos alarmes: os contatos com alunos em alarme crítico fazem eles
 * voltarem a frequentar? Duas análises (presença antes × depois do contato e
 * "voltou a 75%"), sempre contra o grupo de comparação (alarme crítico ainda sem
 * contato), por recorte; e a lista de quem precisa de nova abordagem.
 *
 * Escopo: admin e perfil geral veem tudo (com seletor de curso); coordenador,
 * os cursos dele; professor, os alunos das disciplinas/turmas dele.
 */
class AcompanhamentoController extends Controller
{
    /** Parâmetros GET que a tela mantém entre filtros, paginação e retorno do contato. */
    private const PARAMETROS = ['curso', 'situacao', 'ingressante', 'auxilio', 'contatos', 'prioridade', 'pagina', 'por_pagina'];

    public function index(): void
    {
        $this->requireAuth();
        $repo = new AcompanhamentoRepository();
        $execucao = $repo->ultimaExecucao();
        $escopo = $this->escopo($execucao);
        $filtros = $this->filtrosPedidos();

        $dados = [
            'execucao' => $execucao,
            'escopo' => $escopo,
            'filtros' => $filtros,
            'podeGerar' => Auth::isAdmin(),
            'podeExportar' => self::podeExportar(),
            'rotulosCanal' => ContatoRepository::ROTULOS_CANAL,
            'rotulosContato' => ContatoRepository::CANAIS,
            'rotulosMotivo' => ContatoRepository::MOTIVOS,
            'maxObservacao' => ContatoRepository::MAX_OBSERVACAO,
            'hoje' => date('Y-m-d'),
            'contatoMaisAntigo' => date('Y-m-d', strtotime('-' . ContatoRepository::DIAS_RETROATIVOS . ' days')),
            'query' => $this->queryAtual(),
            'erro' => Session::flash('erro'),
            'sucesso' => Session::flash('sucesso'),
        ];

        if ($execucao === null || $escopo['aviso'] !== null) {
            if ($execucao === null && $dados['erro'] === null) {
                $dados['erro'] = Auth::isAdmin()
                    ? 'Nenhuma análise gerada ainda. Use o botão “Gerar análise”.'
                    : 'Nenhuma análise gerada ainda. Solicite a um administrador.';
            }
            $this->render('acompanhamento/index', $dados);

            return;
        }

        $execucaoId = (int)$execucao['id'];
        $corte = (string)$execucao['data_corte'];
        $tipo = in_array($filtros['situacao'], AcompanhamentoRepository::TIPOS, true) ? $filtros['situacao'] : null;
        $base = $escopo['filtros'] + [
            'ingressante' => $filtros['ingressante'],
            'auxilio' => $filtros['auxilio'],
        ];

        // As duas análises e a curva saem das séries semanais (gravadas na mesma rodada).
        $efeito = new EfeitoContatosRepository();
        $series = $efeito->ultimaExecucao();
        $avaliacao = null;
        $segundo = null;
        $curva = null;
        if ($series !== null) {
            $semanas = max(1, intdiv((int)$series['janela_dias'], 7));
            $minAulas = (int)$series['min_aulas'];
            $filtrosEfeito = $base + ['tipo' => $tipo];
            // Contato da equipe × sem contato da equipe, só de quem ainda estava abaixo de 75% antes.
            $avaliacao = $efeito->avaliar((int)$series['id'], $filtrosEfeito, $semanas, $minAulas, 'primeiro', true);
            // Vale insistir? 2º contato da equipe, contra quem teve um contato da equipe só.
            $segundo = $efeito->avaliar((int)$series['id'], $filtrosEfeito, $semanas, $minAulas, 'segundo', true);
            $curva = $efeito->serieSemanal((int)$series['id'], $filtrosEfeito, $semanas, $minAulas, true);
        }
        $cobertura = $repo->cobertura($execucaoId, $base + ['tipo' => $tipo]);

        $lista = $repo->novaAbordagem(
            $execucaoId,
            $corte,
            $base + [
                'situacao' => $filtros['situacao'],
                'prioridade' => $filtros['prioridade'],
                'contatos' => $filtros['contatos'],
            ],
            $filtros['pagina'],
            $filtros['por_pagina']
        );

        $this->render('acompanhamento/index', $dados + [
            'series' => $series,
            'avaliacao' => $avaliacao,
            'segundo' => $segundo,
            'cobertura' => $cobertura,
            'curva' => $curva,
            'lista' => $lista,
        ]);
    }

    /**
     * Registra um novo contato a partir da lista de nova abordagem (mesmo
     * formulário da tela de alarmes) e volta para a lista com os mesmos filtros.
     */
    public function contato(): void
    {
        $user = $this->requireAuth();
        $voltar = $this->queryDe((string)($_POST['voltar'] ?? ''));
        $destino = '/acompanhamento' . ($voltar !== '' ? '?' . $voltar : '') . '#nova-abordagem';
        $alunoId = (int)($_POST['aluno_id'] ?? 0);
        $cursoId = (int)($_POST['curso_id'] ?? 0);
        if ($alunoId <= 0 || $cursoId <= 0) {
            Session::flash('erro', 'Aluno inválido.');
            $this->redirect($destino);
        }

        $validacao = ContatoRepository::validar($_POST, date('Y-m-d'));
        if ($validacao['erros'] !== []) {
            Session::flash('erro', implode(' ', $validacao['erros']));
            $this->redirect($destino);
        }

        $repo = new AcompanhamentoRepository();
        $execucao = $repo->ultimaExecucao();
        $escopo = $this->escopo($execucao);
        if ($execucao === null || $escopo['aviso'] !== null
            || !$repo->alunoNoEscopo((int)$execucao['id'], $alunoId, $cursoId, $escopo['filtros'])) {
            Session::flash('erro', 'Sem permissão para registrar contato com este aluno.');
            $this->redirect($destino);
        }

        (new ContatoRepository())->registrar($validacao['dados'] + [
            'aluno_id' => $alunoId,
            'curso_id' => $cursoId,
            'origem' => 'manual',
            'codigo_disciplina' => '',
            'registrado_por' => (int)$user['id'],
        ]);

        Session::flash(
            'sucesso',
            'Contato registrado. O aluno sai desta lista e volta se, em 2 semanas, não tiver voltado a frequentar.'
        );
        $this->redirect($destino);
    }

    /** CSV da lista de nova abordagem, com os filtros da tela (sem paginação). */
    public function exportar(): void
    {
        $this->requireAuth();
        if (!self::podeExportar()) {
            http_response_code(403);
            echo 'Exportação restrita a administradores e ao perfil geral.';
            exit;
        }

        $repo = new AcompanhamentoRepository();
        $execucao = $repo->ultimaExecucao();
        $escopo = $this->escopo($execucao);
        if ($execucao === null || $escopo['aviso'] !== null) {
            http_response_code(404);
            echo $escopo['aviso'] ?? 'Nenhuma análise gerada.';
            exit;
        }

        $filtros = $this->filtrosPedidos();
        $lista = $repo->novaAbordagem(
            (int)$execucao['id'],
            (string)$execucao['data_corte'],
            $escopo['filtros'] + [
                'ingressante' => $filtros['ingressante'],
                'auxilio' => $filtros['auxilio'],
                'situacao' => $filtros['situacao'],
                'prioridade' => $filtros['prioridade'],
                'contatos' => $filtros['contatos'],
            ],
            1,
            null
        );

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="nova-abordagem-' . date('Y-m-d') . '.csv"');
        $saida = fopen('php://output', 'w');
        fwrite($saida, "\xEF\xBB\xBF");  // BOM: o Excel abre os acentos certos
        foreach (self::linhasCsv($lista['linhas']) as $linha) {
            fputcsv($saida, $linha, ';');
        }
        fclose($saida);
        exit;
    }

    public function gerar(): void
    {
        $this->requireAuth();
        if (!Auth::isAdmin()) {
            http_response_code(403);
            Session::flash('erro', 'Acesso restrito a administradores.');
            $this->redirect('/acompanhamento');
        }

        $erro = $this->iniciarScriptPython('gerar_efeito_contatos.py', 'efeito_contatos.log');
        Session::flash(
            $erro !== null ? 'erro' : 'sucesso',
            $erro ?? 'Análise iniciada. Acompanhe em data/efeito_contatos.log e atualize a página em alguns instantes.'
        );
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $this->redirect('/acompanhamento');
    }

    /** Quem pode baixar a lista com dados pessoais (decisão pendente: hoje, admin e perfil geral). */
    public static function podeExportar(): bool
    {
        return Auth::isAdmin() || Auth::isGeral();
    }

    /**
     * @param list<array<string, mixed>> $linhas
     * @return list<list<string>>
     */
    public static function linhasCsv(array $linhas): array
    {
        $saida = [[
            'Curso', 'Estudante', 'Matrícula', 'Situação', 'Prioridade', 'Ingressante', 'Auxílio estudantil',
            'Contatos', 'Último contato', 'Canal', 'Conseguiu falar', 'Motivo', 'Presença desde o contato (%)',
        ]];
        foreach ($linhas as $l) {
            $nome = (string)$l['nome_social'] !== '' ? (string)$l['nome_social'] : (string)$l['nome'];
            $data = strtotime((string)$l['ultimo_contato']);
            $falou = $l['ultimo_falou'] === null ? '' : ((int)$l['ultimo_falou'] === 1 ? 'sim' : 'não');
            $saida[] = [
                (string)$l['nome_curso'],
                $nome,
                (string)$l['matricula'],
                AcompanhamentoRepository::ROTULOS_SITUACAO[(string)$l['situacao']] ?? (string)$l['situacao'],
                (string)$l['prioridade'] === 'alta' ? 'alta' : 'média',
                (int)$l['ingressante'] === 1 ? 'sim' : 'não',
                (int)$l['auxilio'] === 1 ? 'sim' : 'não',
                (string)(int)$l['total_contatos'],
                $data !== false ? date('d/m/Y', $data) : '',
                ContatoRepository::ROTULOS_CANAL[(string)$l['ultimo_canal']] ?? (string)$l['ultimo_canal'],
                $falou,
                ContatoRepository::MOTIVOS[(string)$l['ultimo_motivo']] ?? (string)($l['ultimo_motivo'] ?? ''),
                number_format((float)$l['presenca_desde'], 0, ',', ''),
            ];
        }

        return $saida;
    }

    /**
     * Escopo do usuário: filtros do repositório, aviso (sem cursos/disciplinas
     * vinculados) e o que mostrar no lugar do seletor de curso.
     *
     * @param array<string, mixed>|null $execucao
     * @return array{filtros: array<string, mixed>, aviso: string|null, cursos: list<array<string, mixed>>, cursoSelecionado: string, rotulo: string|null}
     */
    private function escopo(?array $execucao): array
    {
        $analytics = new AnalyticsRepository();
        if (Auth::isCoordenador()) {
            $cursoIds = Auth::cursoIds();
            $rotulo = null;
            if ($cursoIds !== []) {
                $cursos = $analytics->listarCursos($cursoIds);
                $rotulo = count($cursos) === 1 ? (string)$cursos[0]['nome_curso'] : count($cursoIds) . ' cursos vinculados';
            }

            return [
                'filtros' => ['curso_ids' => $cursoIds],
                'aviso' => $cursoIds === [] ? 'Nenhum curso vinculado ao seu usuário. Contate o administrador.' : null,
                'cursos' => [],
                'cursoSelecionado' => 'todos',
                'rotulo' => $rotulo,
            ];
        }

        if (Auth::isProfessor()) {
            $codigos = Auth::disciplinaCodigos();
            $coletaId = (int)($execucao['coleta_id'] ?? 0);
            if ($coletaId <= 0) {
                $coletaId = (int)(($analytics->ultimaColeta() ?? [])['id'] ?? 0);
            }

            return [
                'filtros' => ['professor' => [
                    'coleta_id' => $coletaId,
                    'codigos' => $codigos,
                    'turmas' => Auth::turmaIds(),
                ]],
                'aviso' => $codigos === [] ? 'Nenhuma disciplina vinculada ao seu usuário. Contate o administrador.' : null,
                'cursos' => [],
                'cursoSelecionado' => 'todos',
                'rotulo' => 'Alunos das suas disciplinas',
            ];
        }

        $cursos = $analytics->listarCursos(null);
        $pedido = trim((string)($_GET['curso'] ?? $_POST['curso'] ?? 'todos'));
        foreach ($cursos as $curso) {
            if ((string)$curso['id'] === $pedido) {
                return [
                    'filtros' => ['curso_ids' => [(int)$pedido]],
                    'aviso' => null,
                    'cursos' => $cursos,
                    'cursoSelecionado' => $pedido,
                    'rotulo' => null,
                ];
            }
        }

        return ['filtros' => [], 'aviso' => null, 'cursos' => $cursos, 'cursoSelecionado' => 'todos', 'rotulo' => null];
    }

    /**
     * Filtros da URL, já validados.
     *
     * @return array{situacao: string|null, ingressante: bool|null, auxilio: bool|null, contatos: int, prioridade: string|null, pagina: int, por_pagina: int}
     */
    private function filtrosPedidos(): array
    {
        $situacao = trim((string)($_GET['situacao'] ?? ''));
        $sim = static function (string $nome): ?bool {
            $valor = (string)($_GET[$nome] ?? '');

            return $valor === '1' ? true : ($valor === '0' ? false : null);
        };
        $prioridade = (string)($_GET['prioridade'] ?? '');
        $porPagina = (int)($_GET['por_pagina'] ?? 25);

        return [
            'situacao' => isset(AcompanhamentoRepository::ROTULOS_SITUACAO[$situacao]) ? $situacao : null,
            'ingressante' => $sim('ingressante'),
            'auxilio' => $sim('auxilio'),
            'contatos' => max(0, min(3, (int)($_GET['contatos'] ?? 0))),
            'prioridade' => in_array($prioridade, ['alta', 'media'], true) ? $prioridade : null,
            'pagina' => max(1, (int)($_GET['pagina'] ?? 1)),
            'por_pagina' => in_array($porPagina, AcompanhamentoRepository::POR_PAGINA, true) ? $porPagina : 25,
        ];
    }

    /** @return array<string, string> */
    private function queryAtual(): array
    {
        $query = [];
        foreach (self::PARAMETROS as $nome) {
            $valor = trim((string)($_GET[$nome] ?? ''));
            if ($valor !== '') {
                $query[$nome] = $valor;
            }
        }

        return $query;
    }

    /** Query string só com os parâmetros conhecidos (o "voltar" do formulário). */
    private function queryDe(string $texto): string
    {
        parse_str($texto, $pedido);
        $query = [];
        foreach (self::PARAMETROS as $nome) {
            if (isset($pedido[$nome]) && is_string($pedido[$nome]) && trim($pedido[$nome]) !== '') {
                $query[$nome] = trim($pedido[$nome]);
            }
        }

        return http_build_query($query);
    }
}
