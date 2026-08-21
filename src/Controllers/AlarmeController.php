<?php
declare(strict_types=1);

namespace Mapa\Controllers;

use Mapa\Core\Auth;
use Mapa\Core\Controller;
use Mapa\Core\Session;
use Mapa\Models\AnalyticsRepository;
use Mapa\Models\UserRepository;

class AlarmeController extends Controller
{
    public const CONTATO_TIPOS = [
        'email' => 'E-mail enviado',
        'whatsapp' => 'WhatsApp',
        'telefone' => 'Ligação telefônica',
        'presencial' => 'Conversa presencial',
        'assistencia' => 'Encaminhamento para Assistência Estudantil',
    ];

    public const ROTULOS_CONTATO_EXIBICAO = [
        'email' => 'E-mail enviado',
        'email_automatico' => 'E-mail automático enviado',
        'whatsapp' => 'WhatsApp',
        'telefone' => 'Ligação telefônica',
        'presencial' => 'Conversa presencial',
        'assistencia' => 'Encaminhamento para Assistência Estudantil',
    ];

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

        $escopo = $this->escopoAlarmes($cursoSelecionado);
        $cursoIds = $escopo['cursoIds'];
        $codigosDisciplina = $escopo['codigosDisciplina'];
        $cursoExibido = $this->rotuloEscopoExibido($repo, $cursoIds, $codigosDisciplina);
        $avisoEscopo = $this->avisoEscopo($cursoIds, $codigosDisciplina);
        $somenteAbertos = ($_GET['abertos'] ?? '1') === '1';

        $dadosBase = [
            'cursosDisponiveis' => $cursosDisponiveis,
            'cursoSelecionado' => $cursoSelecionado,
            'cursoExibido' => $cursoExibido,
            'rotuloGeral' => 'Todos os cursos',
            'semSeletorCurso' => $semSeletorCurso,
            'isCoordenador' => $isCoordenador,
            'isProfessor' => $isProfessor,
            'codigosMinhasDisciplinas' => $isProfessor ? ($codigosDisciplina ?? []) : [],
            'somenteAbertos' => $somenteAbertos,
            'rotulosContato' => self::CONTATO_TIPOS,
            'rotulosContatoExibicao' => self::ROTULOS_CONTATO_EXIBICAO,
            'isAdmin' => Auth::isAdmin(),
        ];

        if ($coleta === null) {
            $this->render('alarmes/index', array_merge($dadosBase, [
                'coleta' => null,
                'alarmesPorAluno' => [],
                'totalAlarmes' => 0,
                'exibidos' => 0,
                'alunosNaoTratados' => 0,
                'alarmesNaoTratados' => 0,
                'alunosComAlarmes' => 0,
                'erro' => 'Nenhuma coleta importada.',
                'avisoCoordenador' => $avisoEscopo,
            ]));
            return;
        }

        $coletaId = (int)$coleta['id'];

        if ($avisoEscopo !== null) {
            $this->render('alarmes/index', array_merge($dadosBase, [
                'coleta' => $coleta,
                'alarmesPorAluno' => [],
                'totalAlarmes' => 0,
                'exibidos' => 0,
                'alunosNaoTratados' => 0,
                'alarmesNaoTratados' => 0,
                'alunosComAlarmes' => 0,
                'avisoCoordenador' => $avisoEscopo,
                'sucesso' => Session::flash('sucesso'),
                'erro' => Session::flash('erro'),
            ]));
            return;
        }

        $incluirOutras = $isProfessor;
        $resumoAbertos = $repo->resumoAlarmesNaoTratados(
            $coletaId,
            $cursoIds,
            $codigosDisciplina,
            $incluirOutras
        );
        $alarmes = $repo->alarmes(
            $coletaId,
            $somenteAbertos,
            null,
            null,
            $cursoIds,
            $codigosDisciplina,
            $incluirOutras
        );
        $totaisPorTipo = $repo->contagemAlarmesPorTipo(
            $coletaId,
            $somenteAbertos,
            $cursoIds,
            $codigosDisciplina,
            $incluirOutras
        );
        $totalAlarmes = array_sum($totaisPorTipo);

        $alarmesPorAluno = [];
        foreach ($alarmes as $alarme) {
            $chaveAluno = (string)$alarme['matricula'] . '|' . (string)$alarme['nome_curso'];
            if (!isset($alarmesPorAluno[$chaveAluno])) {
                $nomeSocial = trim((string)($alarme['aluno_nome_social'] ?? ''));
                $alarmesPorAluno[$chaveAluno] = [
                    'aluno' => [
                        'id' => (int)$alarme['aluno_id'],
                        'curso_id' => (int)$alarme['curso_id'],
                        'nome' => (string)$alarme['aluno_nome'],
                        'nome_social' => $nomeSocial,
                        'email' => trim((string)($alarme['aluno_email'] ?? '')),
                        'matricula' => (string)$alarme['matricula'],
                        'nome_curso' => (string)$alarme['nome_curso'],
                        'ano_semestre_ingresso' => trim((string)($alarme['ano_semestre_ingresso'] ?? '')),
                        'turma_entrada' => trim((string)($alarme['turma_entrada'] ?? '')),
                    ],
                    'disciplinas' => [],
                    'total_alarmes' => 0,
                    'abertos' => 0,
                ];
            }

            $codigo = trim((string)($alarme['codigo_disciplina'] ?? ''));
            $nomeDisc = trim((string)($alarme['disciplina'] ?? ''));
            $chaveDisc = $codigo !== '' ? $codigo : ($nomeDisc !== '' ? $nomeDisc : '_sem_disciplina');

            if (!isset($alarmesPorAluno[$chaveAluno]['disciplinas'][$chaveDisc])) {
                $alarmesPorAluno[$chaveAluno]['disciplinas'][$chaveDisc] = [
                    'codigo' => $codigo,
                    'nome' => $nomeDisc !== '' ? $nomeDisc : 'Sem disciplina',
                    'semestre_oferta' => trim((string)($alarme['semestre_oferta'] ?? '')),
                    'professores' => '',
                    'alarmes' => [],
                ];
            }

            $alarmesPorAluno[$chaveAluno]['disciplinas'][$chaveDisc]['alarmes'][] = $alarme;
            $alarmesPorAluno[$chaveAluno]['total_alarmes']++;
            if ((int)$alarme['visualizado'] === 0) {
                $alarmesPorAluno[$chaveAluno]['abertos']++;
            }
        }

        $codigosDisc = [];
        foreach ($alarmesPorAluno as $grupo) {
            foreach ($grupo['disciplinas'] as $disciplina) {
                $codigo = trim((string)($disciplina['codigo'] ?? ''));
                if ($codigo !== '') {
                    $codigosDisc[] = $codigo;
                }
            }
        }
        $professoresPorCodigo = $repo->nomesProfessoresPorCodigo($codigosDisc);

        foreach ($alarmesPorAluno as &$grupo) {
            foreach ($grupo['disciplinas'] as &$disciplina) {
                $codigo = trim((string)($disciplina['codigo'] ?? ''));
                $disciplina['professores'] = $professoresPorCodigo[$codigo] ?? '';
            }
            unset($disciplina);
            $grupo['disciplinas'] = array_values($grupo['disciplinas']);
            if ($isProfessor && $codigosDisciplina !== null && $codigosDisciplina !== []) {
                $minhas = array_fill_keys($codigosDisciplina, true);
                usort(
                    $grupo['disciplinas'],
                    static function (array $a, array $b) use ($minhas): int {
                        $aMinha = isset($minhas[(string)($a['codigo'] ?? '')]);
                        $bMinha = isset($minhas[(string)($b['codigo'] ?? '')]);
                        if ($aMinha !== $bMinha) {
                            return $aMinha ? -1 : 1;
                        }
                        return strcasecmp((string)($a['nome'] ?? ''), (string)($b['nome'] ?? ''));
                    }
                );
            }
        }
        unset($grupo);

        $this->render('alarmes/index', array_merge($dadosBase, [
            'coleta' => $coleta,
            'alarmesPorAluno' => array_values($alarmesPorAluno),
            'totalAlarmes' => $totalAlarmes,
            'exibidos' => count($alarmes),
            'alunosNaoTratados' => (int)$resumoAbertos['alunos'],
            'alarmesNaoTratados' => (int)$resumoAbertos['alarmes'],
            'alunosComAlarmes' => (int)$resumoAbertos['alunos_total'],
            'avisoCoordenador' => null,
            'sucesso' => Session::flash('sucesso'),
            'erro' => Session::flash('erro'),
        ]));
    }

    public function visualizar(): void
    {
        $user = $this->requireAuth();
        $alarmeId = (int)($_POST['alarme_id'] ?? 0);
        $coletaId = (int)($_POST['coleta_id'] ?? 0);
        $alunoId = (int)($_POST['aluno_id'] ?? 0);
        $cursoId = (int)($_POST['curso_id'] ?? 0);
        $contatoTipo = trim((string)($_POST['contato_tipo'] ?? ''));
        $abertos = ($_POST['abertos'] ?? '1') === '1' ? '1' : '0';
        $cursoParam = trim((string)($_POST['curso'] ?? 'todos'));
        if ($cursoParam === '') {
            $cursoParam = 'todos';
        }
        $redirect = '/alarmes?abertos=' . $abertos;
        if ($cursoParam !== 'todos') {
            $redirect .= '&curso=' . rawurlencode($cursoParam);
        }

        if (!array_key_exists($contatoTipo, self::CONTATO_TIPOS)) {
            Session::flash('erro', 'Selecione o tipo de ação no menu.');
            $this->redirect($redirect);
        }

        $escopo = $this->escopoAlarmes($cursoParam);
        $cursoIds = $escopo['cursoIds'];
        $codigosDisciplina = $escopo['codigosDisciplina'];
        $repo = new AnalyticsRepository();
        $usuarioId = (int)$user['id'];

        if ($alarmeId > 0) {
            $ok = $repo->marcarAlarmeVisualizado(
                $alarmeId,
                $usuarioId,
                $contatoTipo,
                $cursoIds,
                $codigosDisciplina
            );
            if (!$ok) {
                Session::flash('erro', 'Não foi possível registrar o contato.');
            }
            $this->redirect($redirect);
        }

        if ($coletaId <= 0 || $alunoId <= 0 || $cursoId <= 0) {
            Session::flash('erro', 'Aluno inválido.');
            $this->redirect($redirect);
        }

        if ($cursoIds !== null && !in_array($cursoId, $cursoIds, true)) {
            Session::flash('erro', 'Sem permissão para este curso.');
            $this->redirect($redirect);
        }

        $repo->marcarAlarmesAlunoVisualizados(
            $coletaId,
            $alunoId,
            $cursoId,
            $usuarioId,
            $contatoTipo,
            $cursoIds,
            $codigosDisciplina
        );

        $this->redirect($redirect);
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

    /**
     * @return array{cursoIds: list<int>|null, codigosDisciplina: list<string>|null}
     */
    private function escopoAlarmes(string $cursoSelecionado = 'todos'): array
    {
        if (Auth::isCoordenador()) {
            return [
                'cursoIds' => Auth::cursoIds(),
                'codigosDisciplina' => null,
            ];
        }

        if (Auth::isProfessor()) {
            return [
                'cursoIds' => null,
                'codigosDisciplina' => Auth::disciplinaCodigos(),
            ];
        }

        if ($cursoSelecionado === 'todos') {
            return [
                'cursoIds' => null,
                'codigosDisciplina' => null,
            ];
        }

        return [
            'cursoIds' => [(int)$cursoSelecionado],
            'codigosDisciplina' => null,
        ];
    }

    /**
     * @param list<int>|null $cursoIds
     * @param list<string>|null $codigosDisciplina
     */
    private function avisoEscopo(?array $cursoIds, ?array $codigosDisciplina): ?string
    {
        if (Auth::isCoordenador() && ($cursoIds === null || $cursoIds === [])) {
            return 'Nenhum curso vinculado ao seu usuário. Contate o administrador.';
        }

        if (Auth::isProfessor() && ($codigosDisciplina === null || $codigosDisciplina === [])) {
            return 'Nenhuma disciplina vinculada ao seu CPF. Confirme o CPF do usuário e rode importar_professores.py.';
        }

        return null;
    }

    /**
     * @param list<int>|null $cursoIds
     * @param list<string>|null $codigosDisciplina
     */
    private function rotuloEscopoExibido(
        AnalyticsRepository $repo,
        ?array $cursoIds,
        ?array $codigosDisciplina
    ): string {
        if (Auth::isProfessor()) {
            if ($codigosDisciplina === null || $codigosDisciplina === []) {
                return 'Nenhuma disciplina vinculada';
            }

            $user = Auth::user();
            $nomes = [];
            if ($user !== null) {
                $nomes = (new UserRepository())->nomesDisciplinasDoUsuario((int)$user['id']);
            }

            if ($nomes === []) {
                return 'Minhas disciplinas (' . count($codigosDisciplina) . ')';
            }

            if (count($nomes) <= 3) {
                return implode(' · ', $nomes);
            }

            return implode(' · ', array_slice($nomes, 0, 3)) . ' · +' . (count($nomes) - 3);
        }

        if ($cursoIds === null) {
            return 'Todos os cursos';
        }

        if ($cursoIds === []) {
            return 'Nenhum curso vinculado';
        }

        $cursos = $repo->listarCursos($cursoIds);
        if ($cursos === []) {
            return 'Curso não encontrado';
        }

        $nomes = array_map(
            static function (array $curso): string {
                return (string)$curso['nome_curso'];
            },
            $cursos
        );

        return implode(' · ', $nomes);
    }
}
