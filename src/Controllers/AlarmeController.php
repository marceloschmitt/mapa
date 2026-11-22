<?php
declare(strict_types=1);

namespace Mapa\Controllers;

use Mapa\Core\Auth;
use Mapa\Core\Controller;
use Mapa\Core\Session;
use Mapa\Models\AnalyticsRepository;
use Mapa\Models\ConfigRepository;
use Mapa\Models\ContatoRepository;
use Mapa\Models\UserRepository;
use Mapa\Services\AlarmeEmailService;

class AlarmeController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();

        $repo = new AnalyticsRepository();
        $coleta = $repo->ultimaColeta();
        $isCoordenador = Auth::isCoordenador();
        $isProfessor = Auth::isProfessor();
        $semSeletorCurso = $isCoordenador || $isProfessor;
        $turmasProfessor = $isProfessor ? Auth::turmaIds() : [];
        if ($isProfessor) {
            $repo->restringirTurmas($turmasProfessor);
        }

        $cursosDisponiveis = $semSeletorCurso ? [] : $repo->listarCursos(null);
        $cursoSelecionado = $this->cursoSelecionado($cursosDisponiveis);

        $escopo = $this->escopoAlarmes($cursoSelecionado);
        $cursoIds = $escopo['cursoIds'];
        $codigosDisciplina = $escopo['codigosDisciplina'];
        $cursoExibido = $this->rotuloEscopoExibido($repo, $cursoIds, $codigosDisciplina);
        $avisoEscopo = $this->avisoEscopo($cursoIds, $codigosDisciplina);
        $statusFiltro = AnalyticsRepository::normalizarStatusFiltro(
            $_GET['status'] ?? null,
            array_key_exists('abertos', $_GET) ? $_GET['abertos'] : null
        );

        $dadosBase = [
            'cursosDisponiveis' => $cursosDisponiveis,
            'cursoSelecionado' => $cursoSelecionado,
            'cursoExibido' => $cursoExibido,
            'rotuloGeral' => 'Todos os cursos',
            'semSeletorCurso' => $semSeletorCurso,
            'isCoordenador' => $isCoordenador,
            'isProfessor' => $isProfessor,
            'codigosMinhasDisciplinas' => $isProfessor ? ($codigosDisciplina ?? []) : [],
            'statusFiltro' => $statusFiltro,
            'diasNaoTratado' => AnalyticsRepository::DIAS_NAO_TRATADO,
            'mesesAulasResolvido' => AnalyticsRepository::MESES_AULAS_RESOLVIDO,
            'periodoAtual' => trim((string)((new ConfigRepository())->get(ConfigRepository::API_PERIODO_LETIVO) ?? '')),
            'dadosFrequenciaCorrente' => [],
            'rotulosContato' => ContatoRepository::CANAIS,
            'rotulosContatoExibicao' => ContatoRepository::ROTULOS_CANAL,
            'rotulosMotivo' => ContatoRepository::MOTIVOS,
            'hoje' => date('Y-m-d'),
            'contatoMaisAntigo' => date('Y-m-d', strtotime('-' . ContatoRepository::DIAS_RETROATIVOS . ' days')),
            'maxObservacao' => ContatoRepository::MAX_OBSERVACAO,
            'alunosSemContato' => 0,
            'alunosAguardandoRetorno' => 0,
            'resumoStatus' => [
                'alunos_abertos' => 0,
                'alarmes_abertos' => 0,
                'alunos_tratados' => 0,
                'alarmes_tratados' => 0,
                'alunos_nao_tratados' => 0,
                'alarmes_nao_tratados' => 0,
                'alunos_resolvidos' => 0,
                'alunos_parciais' => 0,
                'disciplinas_resolvidas' => 0,
                'disciplinas_parciais' => 0,
                'alarmes_resolvidos' => 0,
                'alarmes_parciais' => 0,
            ],
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
        $resumoStatus = $repo->resumoAlarmesNaoTratados(
            $coletaId,
            $cursoIds,
            $codigosDisciplina,
            $incluirOutras
        );
        $modoResolvidos = in_array(
            $statusFiltro,
            AnalyticsRepository::STATUS_HISTORICO_RESOLUCAO,
            true
        );
        $modoParcialmenteResolvidos = $statusFiltro
            === AnalyticsRepository::STATUS_PARCIALMENTE_RESOLVIDOS;
        if ($modoResolvidos) {
            // Uma consulta; a tela atual filtra resolvido × parcial.
            $todosResolucao = $this->agruparEnviosResolvidos(
                $repo->alarmesResolvidos(
                    $coletaId,
                    $cursoIds,
                    $codigosDisciplina,
                    $incluirOutras
                )
            );
            $totaisPorTipo = [
                'percentual_baixo' => 0,
                'faltas_4dias' => 0,
                'faltas_3semanas' => 0,
            ];
            $alunosResolvidosUnicos = [];
            $alunosParciaisUnicos = [];
            $disciplinasResolvidasUnicas = [];
            $disciplinasParciaisUnicas = [];
            $alarmesResolvidos = [];
            $alarmesParciais = [];
            foreach ($todosResolucao as $alarme) {
                $alunoChave = (int)$alarme['aluno_id'] . '-' . (int)$alarme['curso_id'];
                $discChave = $alunoChave . '-' . trim((string)($alarme['codigo_disciplina'] ?? ''));
                $ehParcial = (string)($alarme['status_caso'] ?? '') === 'parcialmente_resolvido';
                if ($ehParcial) {
                    $alunosParciaisUnicos[$alunoChave] = true;
                    $disciplinasParciaisUnicas[$discChave] = true;
                    $alarmesParciais[] = $alarme;
                } else {
                    $alunosResolvidosUnicos[$alunoChave] = true;
                    $disciplinasResolvidasUnicas[$discChave] = true;
                    $alarmesResolvidos[] = $alarme;
                }
            }
            $resumoStatus['alunos_resolvidos'] = count($alunosResolvidosUnicos);
            $resumoStatus['alunos_parciais'] = count($alunosParciaisUnicos);
            $resumoStatus['disciplinas_resolvidas'] = count($disciplinasResolvidasUnicas);
            $resumoStatus['disciplinas_parciais'] = count($disciplinasParciaisUnicas);
            $resumoStatus['alarmes_resolvidos'] = count($alarmesResolvidos);
            $resumoStatus['alarmes_parciais'] = count($alarmesParciais);

            $alarmes = $modoParcialmenteResolvidos ? $alarmesParciais : $alarmesResolvidos;
            foreach ($alarmes as $alarme) {
                $tipo = (string)($alarme['tipo'] ?? '');
                if (array_key_exists($tipo, $totaisPorTipo)) {
                    $totaisPorTipo[$tipo]++;
                }
            }
        } else {
            // Alertas atuais: não roda a consulta pesada de resolvidos.
            $alarmes = $repo->alarmes(
                $coletaId,
                $statusFiltro,
                null,
                null,
                $cursoIds,
                $codigosDisciplina,
                $incluirOutras
            );
            $totaisPorTipo = $repo->contagemAlarmesPorTipo(
                $coletaId,
                $statusFiltro,
                $cursoIds,
                $codigosDisciplina,
                $incluirOutras
            );
        }
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
                    'pendentes' => 0,
                    'status_caso' => $modoResolvidos
                        ? ($modoParcialmenteResolvidos ? 'parcialmente_resolvido' : 'resolvido')
                        : 'tratado',
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
                    'id_turma' => 0,
                    'nome_turma' => '',
                    'professores' => '',
                    'alarmes' => [],
                ];
            }

            // Alarme tratado copiado de coleta antiga pode vir sem turma.
            $idTurmaAlarme = (int)($alarme['id_turma'] ?? 0);
            if ($idTurmaAlarme > 0 && $alarmesPorAluno[$chaveAluno]['disciplinas'][$chaveDisc]['id_turma'] === 0) {
                $alarmesPorAluno[$chaveAluno]['disciplinas'][$chaveDisc]['id_turma'] = $idTurmaAlarme;
                $alarmesPorAluno[$chaveAluno]['disciplinas'][$chaveDisc]['nome_turma'] =
                    trim((string)($alarme['nome_turma'] ?? ''));
            }

            $alarmesPorAluno[$chaveAluno]['disciplinas'][$chaveDisc]['alarmes'][] = $alarme;
            $alarmesPorAluno[$chaveAluno]['total_alarmes']++;
            $statusCaso = (string)($alarme['status_caso'] ?? 'aberto');
            if ($statusCaso === 'aberto') {
                $alarmesPorAluno[$chaveAluno]['abertos']++;
                $alarmesPorAluno[$chaveAluno]['pendentes']++;
            } elseif ($statusCaso === 'nao_tratado') {
                $alarmesPorAluno[$chaveAluno]['pendentes']++;
            }
            if ($modoResolvidos) {
                $alarmesPorAluno[$chaveAluno]['status_caso'] = $modoParcialmenteResolvidos
                    ? 'parcialmente_resolvido'
                    : 'resolvido';
            } else {
                $alarmesPorAluno[$chaveAluno]['status_caso'] = $this->piorStatusCaso(
                    (string)$alarmesPorAluno[$chaveAluno]['status_caso'],
                    $statusCaso
                );
            }
        }

        $codigosDisc = [];
        $idsTurma = [];
        foreach ($alarmesPorAluno as $grupo) {
            foreach ($grupo['disciplinas'] as $disciplina) {
                $codigo = trim((string)($disciplina['codigo'] ?? ''));
                if ((int)$disciplina['id_turma'] > 0) {
                    $idsTurma[] = (int)$disciplina['id_turma'];
                }
                if ($codigo !== '') {
                    $codigosDisc[] = $codigo;
                }
            }
        }
        $professoresPorCodigo = $repo->nomesProfessoresPorCodigo($codigosDisc);
        $professoresPorTurma = $repo->nomesProfessoresPorTurmas($idsTurma);

        $minhas = array_fill_keys($codigosDisciplina ?? [], true);
        $minhasTurmas = array_fill_keys($turmasProfessor, true);
        $candidatosEmailCritico = [];
        foreach ($alarmesPorAluno as &$grupo) {
            foreach ($grupo['disciplinas'] as &$disciplina) {
                $codigo = trim((string)($disciplina['codigo'] ?? ''));
                $idTurma = (int)$disciplina['id_turma'];
                // Turma sem professor cadastrado: mostra os do código, como antes.
                $disciplina['professores'] = ($idTurma > 0 ? ($professoresPorTurma[$idTurma] ?? '') : '')
                    ?: ($professoresPorCodigo[$codigo] ?? '');
                // Professor: mesma disciplina em turma de outro docente não é dele.
                $disciplina['minha'] = !$isProfessor || (
                    isset($minhas[$codigo])
                    && ($idTurma <= 0 || $minhasTurmas === [] || isset($minhasTurmas[$idTurma]))
                );
            }
            unset($disciplina);
            $grupo['disciplinas'] = array_values($grupo['disciplinas']);
            if ($isProfessor && $minhas !== []) {
                usort(
                    $grupo['disciplinas'],
                    static function (array $a, array $b): int {
                        $aMinha = $a['minha'];
                        $bMinha = $b['minha'];
                        if ($aMinha !== $bMinha) {
                            return $aMinha ? -1 : 1;
                        }
                        return strcasecmp((string)($a['nome'] ?? ''), (string)($b['nome'] ?? ''));
                    }
                );
            }

            $temCriticoPendente = false;
            foreach ($grupo['disciplinas'] as $disciplina) {
                foreach ($disciplina['alarmes'] as $alarme) {
                    $statusCaso = (string)($alarme['status_caso'] ?? '');
                    if (
                        (string)($alarme['severidade'] ?? '') === 'critico'
                        && ($statusCaso === 'aberto' || $statusCaso === 'nao_tratado')
                    ) {
                        $temCriticoPendente = true;
                        break 2;
                    }
                }
            }
            $grupo['email_critico_motivo'] = null;
            if ($temCriticoPendente) {
                $candidatosEmailCritico[] = [
                    'aluno_id' => (int)$grupo['aluno']['id'],
                    'curso_id' => (int)$grupo['aluno']['curso_id'],
                    'email' => (string)($grupo['aluno']['email'] ?? ''),
                ];
            }
        }
        unset($grupo);

        if ($candidatosEmailCritico !== []) {
            $motivos = (new AlarmeEmailService())->motivosNaoEnvioCritico(
                $coletaId,
                $candidatosEmailCritico
            );
            foreach ($alarmesPorAluno as &$grupo) {
                $chave = (int)$grupo['aluno']['id'] . '|' . (int)$grupo['aluno']['curso_id'];
                if (isset($motivos[$chave])) {
                    $grupo['email_critico_motivo'] = $motivos[$chave];
                }
            }
            unset($grupo);
        }

        // Contatos de cada aluno; com pendentes, quem ainda não teve contato e quem aguarda retorno.
        $contatoRepo = new ContatoRepository();
        $idsAlunos = array_map(
            static fn(array $grupo): int => (int)$grupo['aluno']['id'],
            array_values($alarmesPorAluno)
        );
        $resumoContatos = $contatoRepo->resumoPorAluno($idsAlunos);
        $enviosEmail = $contatoRepo->enviosEmailPorAluno($idsAlunos);
        $alunosSemContato = 0;
        $alunosAguardandoRetorno = 0;
        foreach ($alarmesPorAluno as &$grupo) {
            $chave = (int)$grupo['aluno']['id'] . '|' . (int)$grupo['aluno']['curso_id'];
            $grupo['contatos'] = $resumoContatos[$chave] ?? null;
            $grupo['envios_email'] = $enviosEmail[$chave] ?? [];
            if ((int)$grupo['pendentes'] > 0) {
                $grupo['contatos'] === null ? $alunosSemContato++ : $alunosAguardandoRetorno++;
            }
        }
        unset($grupo);

        $disciplinasFreq = $repo->disciplinasFrequenciaCorrente($coletaId, $idsAlunos);
        $freqCurso = $repo->frequenciaCursoPorAlunos($coletaId, $idsAlunos);
        $periodoAtual = (string)($dadosBase['periodoAtual'] ?? '');
        $dadosFrequenciaCorrente = [];
        foreach ($alarmesPorAluno as $grupo) {
            $aluno = $grupo['aluno'];
            $chaveFreq = (int)$aluno['id'] . '-' . (int)$aluno['curso_id'];
            $nomeSocial = trim((string)($aluno['nome_social'] ?? ''));
            $nome = $nomeSocial !== '' ? $nomeSocial : trim((string)($aluno['nome'] ?? ''));
            $fc = $freqCurso[$chaveFreq] ?? [];
            $desdeRaw = trim((string)($fc['frequencia_desde'] ?? ''));
            $desdeTs = $desdeRaw !== '' ? strtotime($desdeRaw) : false;
            $disciplinas = $disciplinasFreq[$chaveFreq] ?? [];
            $dadosFrequenciaCorrente[$chaveFreq] = [
                'nome' => $nome,
                'matricula' => (string)($aluno['matricula'] ?? ''),
                'curso' => (string)($aluno['nome_curso'] ?? ''),
                'periodo' => $periodoAtual,
                'anual_desde' => $desdeTs !== false ? date('d/m/Y', $desdeTs) : '',
                'ingresso' => (string)($aluno['ano_semestre_ingresso'] ?? ''),
                'frequencia' => $fc['frequencia'] ?? null,
                'disciplinas' => array_map(
                    static function (array $d): array {
                        return array_filter([
                            'codigo' => (string)($d['codigo_disciplina'] ?? ''),
                            'nome' => (string)($d['disciplina'] ?? ''),
                            'frequencia' => $d['frequencia'] ?? null,
                            'situacao' => (string)($d['situacao'] ?? ''),
                            'data_trancamento' => (string)($d['data_trancamento'] ?? ''),
                        ], static fn($v): bool => $v !== '' && $v !== null);
                    },
                    $disciplinas
                ),
            ];
        }

        $relatorioAlunos = count($alarmesPorAluno);
        $relatorioDisciplinas = 0;
        foreach ($alarmesPorAluno as $grupoRelatorio) {
            $relatorioDisciplinas += count($grupoRelatorio['disciplinas'] ?? []);
        }

        $this->render('alarmes/index', array_merge($dadosBase, [
            'alunosSemContato' => $alunosSemContato,
            'alunosAguardandoRetorno' => $alunosAguardandoRetorno,
            'resumoStatus' => $resumoStatus,
            'coleta' => $coleta,
            'alarmesPorAluno' => array_values($alarmesPorAluno),
            'dadosFrequenciaCorrente' => $dadosFrequenciaCorrente,
            'totalAlarmes' => $totalAlarmes,
            'exibidos' => count($alarmes),
            'relatorioAlunos' => $relatorioAlunos,
            'relatorioDisciplinas' => $relatorioDisciplinas,
            'alunosNaoTratados' => (int)$resumoStatus['alunos_nao_tratados'],
            'alarmesNaoTratados' => (int)$resumoStatus['alarmes_nao_tratados'],
            'alunosComAlarmes' => (int)$resumoStatus['alunos_total'],
            'avisoCoordenador' => null,
            'sucesso' => Session::flash('sucesso'),
            'erro' => Session::flash('erro'),
        ]));
    }

    /**
     * Junta linhas envio×alarme em uma chave com lista cronológica de envios.
     *
     * @param list<array<string, mixed>> $linhas
     * @return list<array<string, mixed>>
     */
    private function agruparEnviosResolvidos(array $linhas): array
    {
        $porChave = [];
        foreach ($linhas as $linha) {
            $chave = (int)$linha['aluno_id']
                . '|' . (int)$linha['curso_id']
                . '|' . trim((string)($linha['codigo_disciplina'] ?? ''))
                . '|' . (string)$linha['tipo'];
            $envio = [
                'alarme_email_id' => (int)($linha['alarme_email_id'] ?? 0),
                'enviado_em' => (string)($linha['enviado_em'] ?? ''),
                'canal' => (string)($linha['canal_email'] ?? 'email_automatico'),
                'coleta_id' => (int)($linha['coleta_id'] ?? 0),
                'alarme_id' => (int)($linha['id'] ?? 0),
                'severidade' => (string)($linha['severidade'] ?? ''),
                'mensagem' => (string)($linha['mensagem'] ?? ''),
                'detalhe_json' => (string)($linha['detalhe_json'] ?? ''),
            ];
            if (!isset($porChave[$chave])) {
                $alarme = $linha;
                unset($alarme['alarme_email_id'], $alarme['enviado_em'], $alarme['canal_email']);
                $statusCaso = (string)($linha['status_caso'] ?? 'resolvido');
                $alarme['status_caso'] = $statusCaso === 'parcialmente_resolvido'
                    ? 'parcialmente_resolvido'
                    : 'resolvido';
                $alarme['envios'] = [$envio];
                $porChave[$chave] = $alarme;
                continue;
            }
            $porChave[$chave]['envios'][] = $envio;
            // Mantém o snapshot mais recente no nível do alarme (última evolução).
            $porChave[$chave]['id'] = $envio['alarme_id'];
            $porChave[$chave]['coleta_id'] = $envio['coleta_id'];
            $porChave[$chave]['severidade'] = $envio['severidade'];
            $porChave[$chave]['mensagem'] = $envio['mensagem'];
            $porChave[$chave]['detalhe_json'] = $envio['detalhe_json'];
        }

        return array_values($porChave);
    }

    /** Prioridade visual: não tratado > aberto > tratado. */
    private function piorStatusCaso(string $atual, string $novo): string
    {
        $ordem = ['tratado' => 0, 'aberto' => 1, 'nao_tratado' => 2];
        $atualN = $ordem[$atual] ?? 0;
        $novoN = $ordem[$novo] ?? 0;

        return $novoN >= $atualN ? $novo : $atual;
    }

    /**
     * Registra um contato com o aluno (data real, canal, se conseguiu falar,
     * motivo). O alarme passa a contar como tratado na tela de alarmes.
     */
    public function contato(): void
    {
        $user = $this->requireAuth();
        [$redirect, $cursoParam] = $this->destinoRetorno();
        $coletaId = (int)($_POST['coleta_id'] ?? 0);
        $alunoId = (int)($_POST['aluno_id'] ?? 0);
        $cursoId = (int)($_POST['curso_id'] ?? 0);
        $codigoDisciplina = trim((string)($_POST['codigo_disciplina'] ?? ''));

        if ($coletaId <= 0 || $alunoId <= 0 || $cursoId <= 0) {
            Session::flash('erro', 'Aluno inválido.');
            $this->redirect($redirect);
        }

        $validacao = ContatoRepository::validar($_POST, date('Y-m-d'));
        if ($validacao['erros'] !== []) {
            Session::flash('erro', implode(' ', $validacao['erros']));
            $this->redirect($redirect);
        }

        if (!$this->podeAgirNoAluno($coletaId, $alunoId, $cursoId, $cursoParam, $codigoDisciplina)) {
            Session::flash('erro', 'Sem permissão para registrar contato com este aluno.');
            $this->redirect($redirect);
        }

        $contatoRepo = new ContatoRepository();
        $alarmeIds = $contatoRepo->idsAlarmesDaColeta(
            $coletaId,
            $alunoId,
            $cursoId,
            $codigoDisciplina !== '' ? $codigoDisciplina : null
        );
        $contatoRepo->registrar($validacao['dados'] + [
            'aluno_id' => $alunoId,
            'curso_id' => $cursoId,
            'origem' => 'manual',
            'codigo_disciplina' => $codigoDisciplina,
            'registrado_por' => (int)$user['id'],
            'coleta_id' => $coletaId,
            'alarme_ids' => $alarmeIds,
        ]);

        Session::flash('sucesso', 'Contato registrado. O alarme passa a constar como tratado.');
        $this->redirect($redirect);
    }

    /**
     * Encerra a mão um alarme (alarme_id) ou o caso inteiro do aluno.
     */
    public function encerrar(): void
    {
        $user = $this->requireAuth();
        [$redirect, $cursoParam] = $this->destinoRetorno();
        $alarmeId = (int)($_POST['alarme_id'] ?? 0);
        $coletaId = (int)($_POST['coleta_id'] ?? 0);
        $alunoId = (int)($_POST['aluno_id'] ?? 0);
        $cursoId = (int)($_POST['curso_id'] ?? 0);

        $escopo = $this->escopoAlarmes($cursoParam);
        $repo = new AnalyticsRepository();
        if (Auth::isProfessor()) {
            $repo->restringirTurmas(Auth::turmaIds());
        }
        $usuarioId = (int)$user['id'];

        if ($alarmeId > 0) {
            $ok = $repo->encerrarAlarme($alarmeId, $usuarioId, $escopo['cursoIds'], $escopo['codigosDisciplina']);
            Session::flash($ok ? 'sucesso' : 'erro', $ok ? 'Alarme encerrado.' : 'Não foi possível encerrar o alarme.');
            $this->redirect($redirect);
        }

        if ($coletaId <= 0 || $alunoId <= 0 || $cursoId <= 0) {
            Session::flash('erro', 'Aluno inválido.');
            $this->redirect($redirect);
        }

        $encerrados = $repo->encerrarAlarmesAluno(
            $coletaId,
            $alunoId,
            $cursoId,
            $usuarioId,
            $escopo['cursoIds'],
            $escopo['codigosDisciplina']
        );
        Session::flash(
            $encerrados > 0 ? 'sucesso' : 'erro',
            $encerrados > 0
                ? 'Caso encerrado (' . $encerrados . ' ' . ($encerrados === 1 ? 'alarme' : 'alarmes') . ').'
                : 'Nenhum alarme aberto para encerrar.'
        );
        $this->redirect($redirect);
    }

    /** Rota antiga (registrar contato encerrava o alarme): só sobra para telas abertas antes da mudança. */
    public function visualizar(): void
    {
        $this->requireAuth();
        [$redirect] = $this->destinoRetorno();
        Session::flash('erro', 'A tela de alarmes foi atualizada. Registre o contato de novo pelo menu Ação.');
        $this->redirect($redirect);
    }

    /**
     * Volta para a lista com os mesmos filtros; devolve [url, curso selecionado].
     *
     * @return array{0: string, 1: string}
     */
    private function destinoRetorno(): array
    {
        $status = AnalyticsRepository::normalizarStatusFiltro(
            $_POST['status'] ?? null,
            array_key_exists('abertos', $_POST) ? $_POST['abertos'] : null
        );
        $cursoParam = trim((string)($_POST['curso'] ?? 'todos'));
        if ($cursoParam === '') {
            $cursoParam = 'todos';
        }
        $redirect = '/alarmes?status=' . rawurlencode($status);
        if ($cursoParam !== 'todos') {
            $redirect .= '&curso=' . rawurlencode($cursoParam);
        }

        return [$redirect, $cursoParam];
    }

    /**
     * O usuário pode registrar contato com este aluno? Coordenador: curso dele.
     * Professor: o aluno tem alarme (aberto ou não) numa disciplina/turma dele;
     * com $codigoDisciplina, nessa disciplina.
     */
    private function podeAgirNoAluno(
        int $coletaId,
        int $alunoId,
        int $cursoId,
        string $cursoParam,
        string $codigoDisciplina
    ): bool {
        $escopo = $this->escopoAlarmes($cursoParam);
        if ($escopo['cursoIds'] !== null && !in_array($cursoId, $escopo['cursoIds'], true)) {
            return false;
        }

        $codigos = $escopo['codigosDisciplina'];
        if ($codigos === null) {
            return true;
        }
        if ($codigoDisciplina !== '') {
            if (!in_array($codigoDisciplina, $codigos, true)) {
                return false;
            }
            $codigos = [$codigoDisciplina];
        }
        if ($codigos === []) {
            return false;
        }

        $repo = new AnalyticsRepository();
        $repo->restringirTurmas(Auth::turmaIds());

        return $repo->alunoTemAlarmeAbertoNasDisciplinas($coletaId, $alunoId, $cursoId, $codigos, false);
    }

    /**
     * Força o e-mail (mesmo texto do automático) e registra como contato da
     * equipe (canal e-mail). Marca os alarmes abertos do aluno/curso (ou só
     * de uma disciplina) como já avisados por e-mail.
     */
    public function enviarEmail(): void
    {
        $user = $this->requireAuth();
        $coletaId = (int)($_POST['coleta_id'] ?? 0);
        $alunoId = (int)($_POST['aluno_id'] ?? 0);
        $cursoId = (int)($_POST['curso_id'] ?? 0);
        $codigoDisciplina = array_key_exists('codigo_disciplina', $_POST)
            ? trim((string)$_POST['codigo_disciplina'])
            : null;
        $status = AnalyticsRepository::normalizarStatusFiltro(
            $_POST['status'] ?? null,
            array_key_exists('abertos', $_POST) ? $_POST['abertos'] : null
        );
        $cursoParam = trim((string)($_POST['curso'] ?? 'todos'));
        if ($cursoParam === '') {
            $cursoParam = 'todos';
        }
        $redirect = '/alarmes?status=' . rawurlencode($status);
        if ($cursoParam !== 'todos') {
            $redirect .= '&curso=' . rawurlencode($cursoParam);
        }

        if ($coletaId <= 0 || $alunoId <= 0 || $cursoId <= 0) {
            Session::flash('erro', 'Aluno inválido.');
            $this->redirect($redirect);
        }

        $escopo = $this->escopoAlarmes($cursoParam);
        $cursoIds = $escopo['cursoIds'];
        $codigosDisciplina = $escopo['codigosDisciplina'];

        if ($cursoIds !== null && !in_array($cursoId, $cursoIds, true)) {
            Session::flash('erro', 'Sem permissão para este curso.');
            $this->redirect($redirect);
        }

        $repo = new AnalyticsRepository();
        if (Auth::isProfessor()) {
            $repo->restringirTurmas(Auth::turmaIds());
        }
        if ($codigosDisciplina !== null) {
            if ($codigosDisciplina === []) {
                Session::flash('erro', 'Nenhuma disciplina vinculada ao seu CPF.');
                $this->redirect($redirect);
            }
            if ($codigoDisciplina !== null) {
                if ($codigoDisciplina === '' || !in_array($codigoDisciplina, $codigosDisciplina, true)) {
                    Session::flash('erro', 'Sem permissão para enviar e-mail nesta disciplina.');
                    $this->redirect($redirect);
                }
                if (!$repo->alunoTemAlarmeAbertoNasDisciplinas(
                    $coletaId,
                    $alunoId,
                    $cursoId,
                    [$codigoDisciplina]
                )) {
                    Session::flash('erro', 'Nenhum alarme aberto nesta disciplina.');
                    $this->redirect($redirect);
                }
            } elseif (!$repo->alunoTemAlarmeAbertoNasDisciplinas(
                $coletaId,
                $alunoId,
                $cursoId,
                $codigosDisciplina
            )) {
                Session::flash('erro', 'Sem permissão para enviar e-mail neste aluno.');
                $this->redirect($redirect);
            }
        }

        $resultado = (new AlarmeEmailService())->enviarForcadoAoAluno(
            $coletaId,
            $alunoId,
            $cursoId,
            (int)$user['id'],
            $codigoDisciplina
        );

        if ($resultado['ok']) {
            Session::flash('sucesso', $resultado['mensagem']);
        } else {
            Session::flash('erro', $resultado['mensagem']);
        }

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
