<?php

use Mapa\Core\View;

$alarmeConfig = $alarmeConfig ?? [];
$limiteFrequencia = (float)($alarmeConfig['frequencia_limite'] ?? 75);
$minimoDiasFalta = (int)($alarmeConfig['faltas_dias_minimo'] ?? 3);
$janelaDiasFalta = (int)($alarmeConfig['faltas_dias_janela'] ?? 4);
$semanasConsecutivas = (int)($alarmeConfig['faltas_semanas_total'] ?? 3);

// Rotulos seguem os criterios configurados em Configuracoes -> Alarmes.
$rotulosTipo = [
    'percentual_baixo' => 'Frequência < ' . View::rotuloLimite($limiteFrequencia) . '%',
    'faltas_4dias' => 'Faltas recentes (' . $janelaDiasFalta . ' dias)',
    'faltas_3semanas' => $semanasConsecutivas . ' semanas consecutivas',
];
$rotulosContato = $rotulosContato ?? [];
$rotulosContatoExibicao = $rotulosContatoExibicao ?? $rotulosContato;
$rotulosMotivo = $rotulosMotivo ?? [];
$hoje = (string)($hoje ?? date('Y-m-d'));
$contatoMaisAntigo = (string)($contatoMaisAntigo ?? $hoje);
$maxObservacao = (int)($maxObservacao ?? 500);
$alarmesPorAluno = $alarmesPorAluno ?? [];
$totalAlarmes = (int)($totalAlarmes ?? 0);
$exibidos = (int)($exibidos ?? 0);
$alunosNaoTratados = (int)($alunosNaoTratados ?? 0);
$alunosComAlarmes = (int)($alunosComAlarmes ?? 0);
$alunosSemContato = (int)($alunosSemContato ?? 0);
$alunosAguardandoRetorno = (int)($alunosAguardandoRetorno ?? 0);
$resumoStatus = $resumoStatus ?? [];
$alunosAbertos = (int)($resumoStatus['alunos_abertos'] ?? 0);
$alunosTratados = (int)($resumoStatus['alunos_tratados'] ?? 0);
$alunosNaoTratadosStatus = (int)($resumoStatus['alunos_nao_tratados'] ?? $alunosNaoTratados);
$alunosResolvidos = (int)($resumoStatus['alunos_resolvidos'] ?? 0);
$alunosParciais = (int)($resumoStatus['alunos_parciais'] ?? 0);
$disciplinasResolvidas = (int)($resumoStatus['disciplinas_resolvidas'] ?? 0);
$disciplinasParciais = (int)($resumoStatus['disciplinas_parciais'] ?? 0);
$relatorioAlunos = (int)($relatorioAlunos ?? 0);
$relatorioDisciplinas = (int)($relatorioDisciplinas ?? 0);
$diasNaoTratado = (int)($diasNaoTratado ?? 14);
$mesesAulasResolvido = (int)($mesesAulasResolvido ?? 1);
$statusFiltro = (string)($statusFiltro ?? 'abertos');
$modoResolvidos = $statusFiltro === 'resolvidos' || $statusFiltro === 'parcialmente_resolvidos';
$modoParcialmenteResolvidos = $statusFiltro === 'parcialmente_resolvidos';
$modoAlertasAtuais = !$modoResolvidos;
$dadosFrequenciaCorrente = $dadosFrequenciaCorrente ?? [];
$periodoAtual = (string)($periodoAtual ?? '');
$coletaId = $coleta !== null ? (int)$coleta['id'] : 0;
$mostrarMensagem = true;
$semSeletorCurso = !empty($semSeletorCurso);
$cursoSelecionado = (string)($cursoSelecionado ?? 'todos');
$cursosDisponiveis = $cursosDisponiveis ?? [];
$queryCurso = $cursoSelecionado !== 'todos' ? '&curso=' . rawurlencode($cursoSelecionado) : '';
$mostrarBadgeCurso = $semSeletorCurso;
$isProfessor = !empty($isProfessor);
$codigosMinhasDisciplinas = $codigosMinhasDisciplinas ?? [];
$minhasDisciplinasMapa = array_fill_keys(
    array_map('strval', $codigosMinhasDisciplinas),
    true
);

/**
 * @param array<string, mixed> $alarme
 * @return list<string>
 */
if (!function_exists('mapaDiasFaltaAlarme')) {
    function mapaDiasFaltaAlarme(array $alarme): array
    {
        $raw = trim((string)($alarme['detalhe_json'] ?? ''));
        if ($raw === '') {
            return [];
        }
        $detalhe = json_decode($raw, true);
        if (!is_array($detalhe) || !isset($detalhe['dias_falta']) || !is_array($detalhe['dias_falta'])) {
            return [];
        }
        $saida = [];
        foreach ($detalhe['dias_falta'] as $dia) {
            $texto = trim((string)$dia);
            if ($texto === '') {
                continue;
            }
            $ts = strtotime($texto);
            $saida[] = $ts !== false ? date('d/m', $ts) : $texto;
        }
        return $saida;
    }
}

/**
 * @param array<string, mixed> $alarme
 */
if (!function_exists('mapaPercentualFrequenciaAlarme')) {
    function mapaPercentualFrequenciaAlarme(array $alarme): ?string
    {
        $raw = trim((string)($alarme['detalhe_json'] ?? ''));
        if ($raw !== '') {
            $detalhe = json_decode($raw, true);
            if (is_array($detalhe) && isset($detalhe['percentual_frequencia'])
                && is_numeric($detalhe['percentual_frequencia'])) {
                return number_format((float)$detalhe['percentual_frequencia'], 1, '.', '') . '%';
            }
        }

        $mensagem = trim((string)($alarme['mensagem'] ?? ''));
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*%/', $mensagem, $m) === 1) {
            return str_replace(',', '.', $m[1]) . '%';
        }

        return null;
    }
}

/**
 * Métrica do snapshot no envio: percentual, dias de falta ou semanas.
 *
 * @param array<string, mixed> $alarme
 */
if (!function_exists('mapaMetricaSnapshotAlarme')) {
    function mapaMetricaSnapshotAlarme(array $alarme): string
    {
        $tipo = (string)($alarme['tipo'] ?? '');
        if ($tipo === 'percentual_baixo') {
            return mapaPercentualFrequenciaAlarme($alarme) ?? '—';
        }
        if ($tipo === 'faltas_4dias') {
            $dias = mapaDiasFaltaAlarme($alarme);
            return $dias !== [] ? implode(', ', $dias) : '—';
        }
        if ($tipo === 'faltas_3semanas') {
            $raw = trim((string)($alarme['detalhe_json'] ?? ''));
            if ($raw !== '') {
                $detalhe = json_decode($raw, true);
                if (is_array($detalhe)) {
                    $n = $detalhe['semanas_consecutivas'] ?? null;
                    if (is_numeric($n)) {
                        return ((int)$n) . ' semanas';
                    }
                    if (isset($detalhe['semanas']) && is_array($detalhe['semanas']) && $detalhe['semanas'] !== []) {
                        return count($detalhe['semanas']) . ' semanas';
                    }
                }
            }
            $mensagem = trim((string)($alarme['mensagem'] ?? ''));
            return $mensagem !== '' ? $mensagem : '—';
        }

        return trim((string)($alarme['mensagem'] ?? '')) ?: '—';
    }
}

/**
 * Como um alarme encerrado aparece: encerrado à mão, aluno voltou, ou (antes da
 * separação entre contato e encerramento) o tipo de contato que o encerrou.
 *
 * @param array<string, mixed> $alarme
 * @param array<string, string> $rotulosContato
 * @return array{rotulo: string, data: string, por: string, titulo: string}
 */
if (!function_exists('mapaFormatarContatoAlarme')) {
    function mapaFormatarContatoAlarme(array $alarme, array $rotulosContato): array
    {
        $fechamento = (string)($alarme['fechamento'] ?? '');
        $tipo = (string)($alarme['contato_tipo'] ?? '');
        $rotulo = match ($fechamento) {
            'manual' => 'Encerrado',
            'retorno' => 'Voltou a frequentar',
            default => $rotulosContato[$tipo] ?? 'Contato',
        };
        $vistoPor = trim((string)($alarme['visualizado_por_nome'] ?? ''));
        if ($vistoPor === '') {
            $vistoPor = trim((string)($alarme['visualizado_por_username'] ?? ''));
        }
        $vistoEm = trim((string)($alarme['visualizado_em'] ?? ''));
        $data = '';
        if ($vistoEm !== '') {
            $ts = strtotime($vistoEm);
            $data = $ts !== false ? date('d/m/Y', $ts) : $vistoEm;
        }

        return [
            'rotulo' => $rotulo,
            'data' => $data,
            'por' => $vistoPor,
            'titulo' => trim(
                $rotulo
                . ($data !== '' ? ' em ' . $data : '')
                . ($vistoPor !== '' ? ' por ' . $vistoPor : '')
            ),
        ];
    }
}

/**
 * Linha "Último contato: ..." do aluno.
 *
 * @param array{total: int, ultimo: array<string, mixed>} $contatos
 * @param array<string, string> $rotulosCanal
 * @param array<string, string> $rotulosMotivo
 */
if (!function_exists('mapaResumoContato')) {
    function mapaResumoContato(array $contatos, array $rotulosCanal, array $rotulosMotivo): string
    {
        $ultimo = $contatos['ultimo'];
        $ts = strtotime((string)$ultimo['data_contato']);
        $partes = [$ts !== false ? date('d/m/Y', $ts) : (string)$ultimo['data_contato']];
        $partes[] = $rotulosCanal[(string)$ultimo['canal']] ?? (string)$ultimo['canal'];
        if ($ultimo['conseguiu_falar'] !== null) {
            $partes[] = (int)$ultimo['conseguiu_falar'] === 1 ? 'conseguiu falar' : 'não conseguiu falar';
        }
        $motivo = (string)($ultimo['motivo'] ?? '');
        if ($motivo !== '') {
            $partes[] = 'motivo: ' . mb_strtolower($rotulosMotivo[$motivo] ?? $motivo);
        }
        $por = trim((string)($ultimo['registrado_por_nome'] ?? ''));
        if ($por !== '') {
            $partes[] = 'por ' . $por;
        }
        $total = (int)$contatos['total'];

        return 'Último contato: ' . implode(' · ', $partes)
            . ($total > 1 ? ' (' . $total . ' contatos)' : '');
    }
}
?>

<?php
$filtrosAlertasAtuais = [
    'abertos' => ['rotulo' => 'Abertos', 'classe' => 'btn-alarme-status-aberto'],
    'tratados' => ['rotulo' => 'Tratados', 'classe' => 'btn-alarme-status-tratado'],
    'nao_tratados' => ['rotulo' => 'Não tratados', 'classe' => 'btn-alarme-status-nao-tratado'],
    'todos' => ['rotulo' => 'Todos', 'classe' => 'btn-alarme-status-todos'],
];
$filtrosAlertasResolvidos = [
    'resolvidos' => [
        'rotulo' => 'Disciplinas resolvidas',
        'classe' => 'btn-alarme-status-resolvido',
    ],
    'parcialmente_resolvidos' => [
        'rotulo' => 'Disciplinas parcialmente resolvidas',
        'classe' => 'btn-alarme-status-parcial',
    ],
];
?>
<div class="mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
        <div>
            <h1 class="h4 mb-1">Alarmes de risco</h1>
            <?php if ($mostrarBadgeCurso): ?>
                <p class="mb-1">
                    <span class="badge text-bg-primary text-wrap text-start fw-normal" style="font-size: 0.85rem;">
                        <?= htmlspecialchars($cursoExibido ?? 'Todos os cursos', ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </p>
            <?php endif; ?>
            <p class="text-secondary mb-2">
                Sinais gerados a partir do banco para antecipar evasão.
                <?php if ($isProfessor): ?>
                    Inclui suas disciplinas e, nos mesmos alunos, alarmes em outras disciplinas.
                <?php endif; ?>
                <?php if ($coleta !== null): ?>
                    <?= htmlspecialchars(View::rotuloColeta($coleta), ENT_QUOTES, 'UTF-8') ?>.
                <?php endif; ?>
            </p>
            <?php if ($coleta !== null): ?>
                <div class="d-flex flex-wrap gap-2">
                    <span class="badge text-bg-secondary">
                        <?= $alunosComAlarmes ?> aluno<?= $alunosComAlarmes === 1 ? '' : 's' ?> com alarmes
                    </span>
                    <span class="badge alarme-badge-aberto">
                        <?= $alunosAbertos ?> aberto<?= $alunosAbertos === 1 ? '' : 's' ?>
                    </span>
                    <span class="badge alarme-badge-tratado">
                        <?= $alunosTratados ?> tratado<?= $alunosTratados === 1 ? '' : 's' ?>
                    </span>
                    <span class="badge alarme-badge-nao-tratado">
                        <?= $alunosNaoTratadosStatus ?> não tratado<?= $alunosNaoTratadosStatus === 1 ? '' : 's' ?>
                        <span class="fw-normal">(≥ <?= $diasNaoTratado ?> dias sem ação)</span>
                    </span>
                </div>
            <?php endif; ?>
        </div>
        <?php if (!$semSeletorCurso && $cursosDisponiveis !== []): ?>
            <form method="get" action="<?= htmlspecialchars(url('/alarmes'), ENT_QUOTES, 'UTF-8') ?>" class="d-flex align-items-center gap-2">
                <input type="hidden" name="status" value="<?= htmlspecialchars($statusFiltro, ENT_QUOTES, 'UTF-8') ?>">
                <label for="curso" class="form-label mb-0 text-nowrap">Curso</label>
                <select class="form-select" id="curso" name="curso" style="min-width: 260px;"
                        onchange="this.form.submit()">
                    <option value="todos" <?= $cursoSelecionado === 'todos' ? 'selected' : '' ?>>
                        <?= htmlspecialchars($rotuloGeral ?? 'Todos os cursos', ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <?php foreach ($cursosDisponiveis as $curso): ?>
                        <option value="<?= (int)$curso['id'] ?>"
                            <?= $cursoSelecionado === (string)$curso['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string)$curso['nome_curso'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
    </div>

    <div class="alarme-filtros-grupos">
        <div class="alarme-filtro-grupo<?= $modoAlertasAtuais ? ' alarme-filtro-grupo-ativo' : '' ?>">
            <div class="alarme-filtro-grupo-titulo">Alertas atuais</div>
            <div class="alarme-filtro-botoes">
                <?php foreach ($filtrosAlertasAtuais as $valor => $filtro): ?>
                    <a href="<?= htmlspecialchars(url('/alarmes?status=' . $valor . $queryCurso), ENT_QUOTES, 'UTF-8') ?>"
                       class="btn btn-alarme-status <?= $filtro['classe'] ?><?= $statusFiltro === $valor ? ' ativo' : '' ?>">
                        <?= $filtro['rotulo'] ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="alarme-filtro-grupo<?= $modoResolvidos ? ' alarme-filtro-grupo-ativo' : '' ?>">
            <div class="alarme-filtro-grupo-titulo">Alertas resolvidos</div>
            <div class="alarme-filtro-botoes">
                <?php foreach ($filtrosAlertasResolvidos as $valor => $filtro): ?>
                    <a href="<?= htmlspecialchars(url('/alarmes?status=' . $valor . $queryCurso), ENT_QUOTES, 'UTF-8') ?>"
                       class="btn btn-alarme-status <?= $filtro['classe'] ?><?= $statusFiltro === $valor ? ' ativo' : '' ?>">
                        <?= $filtro['rotulo'] ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php if ($coleta !== null): ?>
        <p class="small text-secondary mb-0 mt-3" style="max-width: 56rem;">
            <?php if ($statusFiltro === 'abertos'): ?>
                <strong class="fw-semibold">Aberto:</strong> alarmes sem ação e com menos de <?= $diasNaoTratado ?> dias.
            <?php elseif ($statusFiltro === 'tratados'): ?>
                <strong class="fw-semibold">Tratado:</strong> alarmes com ação.
            <?php elseif ($statusFiltro === 'nao_tratados'): ?>
                <strong class="fw-semibold">Não tratado:</strong> alarmes sem ação e com <?= $diasNaoTratado ?> dias ou mais.
            <?php elseif ($statusFiltro === 'todos'): ?>
                <strong class="fw-semibold">Todos:</strong> alarmes da última coleta com qualquer status.
            <?php elseif ($statusFiltro === 'parcialmente_resolvidos'): ?>
                <strong class="fw-semibold">Disciplinas parcialmente resolvidas:</strong> disciplinas com algum tipo de alarme
                solucionado após ação (e-mail automático, e-mail pelo botão ou contato registrado) mas ainda com outro ativo
                (não inclui alarmes do primeiro mês e tampouco trancamento/cancelamento de disciplina).
            <?php else: ?>
                <strong class="fw-semibold">Disciplinas resolvidas:</strong> disciplinas com todos alarmes solucionados após ação
                (e-mail automático, e-mail pelo botão ou contato registrado)
                (não inclui alarmes do primeiro mês e tampouco trancamento/cancelamento de disciplina).
            <?php endif; ?>
        </p>
        <div class="alarme-relatorio-resumo mt-2">
            <span><strong>Número de alunos:</strong> <?= $relatorioAlunos ?></span>
            <span><strong>Número de disciplinas:</strong> <?= $relatorioDisciplinas ?></span>
        </div>
    <?php endif; ?>
</div>

<?php if (!empty($sucesso)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($sucesso, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if (!empty($erro)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if (!empty($avisoCoordenador)): ?>
    <div class="alert alert-warning"><?= htmlspecialchars($avisoCoordenador, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if ($alarmesPorAluno === []): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-secondary">
            <?php if (!empty($avisoCoordenador)): ?>
                <?= htmlspecialchars($avisoCoordenador, ENT_QUOTES, 'UTF-8') ?>
            <?php elseif ($modoParcialmenteResolvidos): ?>
                Nenhuma disciplina parcialmente resolvida neste filtro.
            <?php elseif ($modoResolvidos): ?>
                Nenhuma disciplina resolvida neste filtro.
            <?php else: ?>
                Nenhum alarme encontrado.
                Rode <code>python3 gerar_alarmes.py</code> após importar a coleta.
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <?php
    // Os menus de ação ficam vazios e são preenchidos ao abrir, a partir dos <template> abaixo;
    // o formulário é montado no clique. Com formulários em cada menu, a lista de todos os
    // cursos passava de 60 MB de HTML e estourava a memória do PHP.
    $itemContato = '<li><button type="button" class="dropdown-item js-acao-alarme" data-acao="contato">Registrar contato realizado fora do sistema…</button></li>';
    $itemEmail = '<li><button type="button" class="dropdown-item js-acao-alarme" data-acao="email">Enviar e-mail automático</button></li>';
    ?>
    <template id="tpl-menu-geral"><?= $itemContato . $itemEmail ?></template>
    <template id="tpl-menu-alarme"><?= $itemContato . $itemEmail ?></template>
    <?php ob_start(); ?>
    <div class="d-flex flex-column gap-4 lista-alarmes-alunos">
            <?php foreach ($alarmesPorAluno as $grupo): ?>
                <?php
                $aluno = $grupo['aluno'];
                $usaNomeSocial = $aluno['nome_social'] !== '';
                $nomeExibido = $usaNomeSocial ? $aluno['nome_social'] : $aluno['nome'];
                $emailAluno = trim((string)($aluno['email'] ?? ''));
                $ingresso = trim((string)($aluno['ano_semestre_ingresso'] ?? ''));
                $turmaEntrada = trim((string)($aluno['turma_entrada'] ?? ''));
                $pendentes = (int)($grupo['pendentes'] ?? $grupo['abertos'] ?? 0);
                $statusCasoGrupo = (string)($grupo['status_caso'] ?? 'aberto');
                $classeFundo = match ($statusCasoGrupo) {
                    'tratado' => 'alarme-card-tratado',
                    'nao_tratado' => 'alarme-card-nao-tratado',
                    'resolvido' => 'alarme-card-resolvido',
                    'parcialmente_resolvido' => 'alarme-card-parcial',
                    default => 'alarme-card-aberto',
                };
                // Em "Todos" a cor vai em cada linha de alarme, não no card do aluno.
                $colorirPorAlarme = $statusFiltro === 'todos';
                $enviosEmailAluno = $grupo['envios_email'] ?? [];
                $chaveFreqAluno = (int)$aluno['id'] . '-' . (int)$aluno['curso_id'];
                $temFreqCorrente = isset($dadosFrequenciaCorrente[$chaveFreqAluno]);
                ?>
                <article class="card border-0 shadow-sm aluno-alarme-card <?= $colorirPorAlarme ? '' : $classeFundo ?>"
                         data-aluno-id="<?= (int)$aluno['id'] ?>"
                         data-curso-id="<?= (int)$aluno['curso_id'] ?>">
                    <div class="card-header border-0 pb-0 pt-3 px-3 bg-transparent">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <div class="fw-semibold fs-6">
                                <?php if ($temFreqCorrente): ?>
                                    <button type="button"
                                            class="btn btn-link p-0 align-baseline fw-semibold text-decoration-underline js-abrir-frequencia-corrente"
                                            data-id="<?= htmlspecialchars($chaveFreqAluno, ENT_QUOTES, 'UTF-8') ?>"
                                            title="Ver frequência corrente">
                                        <?= htmlspecialchars($nomeExibido, ENT_QUOTES, 'UTF-8') ?>
                                    </button>
                                <?php else: ?>
                                    <?= htmlspecialchars($nomeExibido, ENT_QUOTES, 'UTF-8') ?>
                                <?php endif; ?>
                                <?php if ($emailAluno !== ''): ?>
                                    <span class="fw-normal text-secondary">
                                        · <?= htmlspecialchars($emailAluno, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="small text-secondary">
                                Matrícula <?= htmlspecialchars($aluno['matricula'], ENT_QUOTES, 'UTF-8') ?>
                                ·
                                <?= htmlspecialchars($aluno['nome_curso'], ENT_QUOTES, 'UTF-8') ?>
                                <?php if ($ingresso !== ''): ?>
                                    · Ingresso <?= htmlspecialchars($ingresso, ENT_QUOTES, 'UTF-8') ?>
                                <?php endif; ?>
                                <?php if ($turmaEntrada !== ''): ?>
                                    · Turma <?= htmlspecialchars($turmaEntrada, ENT_QUOTES, 'UTF-8') ?>
                                <?php endif; ?>
                            </div>
                            <?php
                            $motivoEmailCritico = trim((string)($grupo['email_critico_motivo'] ?? ''));
                            if ($motivoEmailCritico !== ''):
                            ?>
                                <div class="small mt-1 text-warning-emphasis">
                                    E-mail automático (crítico):
                                    <?= htmlspecialchars($motivoEmailCritico, ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php endif; ?>
                            <?php $contatosAluno = $grupo['contatos'] ?? null; ?>
                            <?php if ($contatosAluno !== null): ?>
                                <div class="small mt-1 text-body-secondary">
                                    <i class="bi bi-chat-left-text" aria-hidden="true"></i>
                                    <?= htmlspecialchars(mapaResumoContato($contatosAluno, $rotulosContatoExibicao, $rotulosMotivo), ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php elseif ($pendentes > 0): ?>
                                <div class="small mt-1 text-danger-emphasis">Nenhum contato registrado.</div>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
                            <span class="badge text-bg-light text-dark border">
                                <?= (int)$grupo['total_alarmes'] ?>
                                <?= (int)$grupo['total_alarmes'] === 1 ? 'alarme' : 'alarmes' ?>
                            </span>
                            <?php if ($pendentes > 0): ?>
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-success dropdown-toggle"
                                            type="button"
                                            data-bs-toggle="dropdown"
                                            aria-expanded="false">
                                        Ação geral
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end"
                                        data-menu="tpl-menu-geral"></ul>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    </div>

                    <div class="card-body pt-3 px-3 pb-3">
                        <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 small tabela-alarmes<?= $mostrarMensagem ? '' : ' sem-mensagem' ?>">
                            <colgroup>
                                <col class="col-codigo">
                                <col class="col-disciplina">
                                <col class="col-tipo">
                                <col class="col-severidade">
                                <?php if ($mostrarMensagem): ?>
                                    <col class="col-mensagem">
                                <?php endif; ?>
                                <col class="col-acao">
                            </colgroup>
                            <thead class="table-light">
                                <tr>
                                    <th>Código</th>
                                    <th>Disciplina</th>
                                    <th>Tipo</th>
                                    <th>Risco</th>
                                    <?php if ($mostrarMensagem): ?>
                                        <th>Mensagem</th>
                                    <?php endif; ?>
                                    <th><?= ($statusFiltro === 'tratados' || $modoResolvidos) ? 'Ação realizada' : 'Ação' ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($grupo['disciplinas'] as $disciplina): ?>
                                    <?php
                                    $semestreRotulo = View::rotuloSemestre($disciplina['semestre_oferta'] ?? '');
                                    $nomeDisc = (string)$disciplina['nome'];
                                    if ($semestreRotulo !== '') {
                                        $nomeDisc .= ' (' . $semestreRotulo . ')';
                                    }
                                    $codigoDisc = (string)($disciplina['codigo'] ?? '');
                                    $minhaDisciplina = !$isProfessor
                                        || $codigoDisc === ''
                                        || !empty($disciplina['minha']);
                                    $outraDisciplina = !$minhaDisciplina;
                                    $rotuloOutra = isset($minhasDisciplinasMapa[$codigoDisc])
                                        ? 'Outra turma'
                                        : 'Outra disciplina';
                                    $nomeTurmaDisc = trim((string)($disciplina['nome_turma'] ?? ''));
                                    $profsDisc = trim((string)($disciplina['professores'] ?? ''));
                                    foreach ($disciplina['alarmes'] as $alarme):
                                        $statusAlarme = (string)($alarme['status_caso'] ?? 'aberto');
                                        $classeLinhaStatus = $colorirPorAlarme
                                            ? match ($statusAlarme) {
                                                'tratado' => 'alarme-linha-tratado',
                                                'nao_tratado' => 'alarme-linha-nao-tratado',
                                                'resolvido' => 'alarme-linha-resolvido',
                                                'parcialmente_resolvido' => 'alarme-linha-parcial',
                                                default => 'alarme-linha-aberto',
                                            }
                                            : ($modoResolvidos
                                                ? match ($statusAlarme) {
                                                    'parcialmente_resolvido' => 'alarme-linha-parcial',
                                                    'resolvido' => 'alarme-linha-resolvido',
                                                    default => '',
                                                }
                                                : '');
                                        $tipo = (string)$alarme['tipo'];
                                        $rotulo = $rotulosTipo[$tipo] ?? $tipo;
                                        $diasTipo = $tipo === 'faltas_4dias' ? mapaDiasFaltaAlarme($alarme) : [];
                                        $percentualTipo = $tipo === 'percentual_baixo'
                                            ? mapaPercentualFrequenciaAlarme($alarme)
                                            : null;
                                        $badgeSev = ($alarme['severidade'] ?? '') === 'critico' ? 'danger' : 'warning';
                                        $pendenteAlarme = $statusAlarme === 'aberto' || $statusAlarme === 'nao_tratado';
                                        $geradoEm = trim((string)($alarme['gerado_em'] ?? ''));
                                        $diaGerado = $geradoEm !== '' ? substr($geradoEm, 0, 10) : '';
                                        $datasAuto = [];
                                        $datasBotao = [];
                                        foreach ($enviosEmailAluno as $envio) {
                                            $diaEnvio = (string)($envio['data_contato'] ?? '');
                                            if ($diaEnvio === '' || ($diaGerado !== '' && $diaEnvio < $diaGerado)) {
                                                continue;
                                            }
                                            $tsEnvio = strtotime($diaEnvio);
                                            $dataFmt = $tsEnvio !== false ? date('d/m/Y', $tsEnvio) : $diaEnvio;
                                            if ((string)($envio['canal'] ?? '') === 'email') {
                                                $datasBotao[] = $dataFmt;
                                            } else {
                                                $datasAuto[] = $dataFmt;
                                            }
                                        }
                                        $temEnvioEmail = $datasAuto !== [] || $datasBotao !== [];
                                        $classeTr = trim(
                                            ($outraDisciplina ? 'linha-outra-disciplina ' : '')
                                            . $classeLinhaStatus
                                        );
                                    ?>
                                    <tr class="<?= htmlspecialchars($classeTr, ENT_QUOTES, 'UTF-8') ?>">
                                        <td class="td-codigo">
                                            <?php if ($codigoDisc !== ''): ?>
                                                <code><?= htmlspecialchars($codigoDisc, ENT_QUOTES, 'UTF-8') ?></code>
                                            <?php else: ?>
                                                <span class="text-secondary">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="td-disciplina">
                                            <?= htmlspecialchars($nomeDisc, ENT_QUOTES, 'UTF-8') ?>
                                            <?php if ($nomeTurmaDisc !== ''): ?>
                                                <span class="text-secondary">— <?= htmlspecialchars($nomeTurmaDisc, ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php endif; ?>
                                            <?php if ($outraDisciplina): ?>
                                                <div>
                                                    <span class="badge text-bg-light border text-secondary"><?= $rotuloOutra ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($profsDisc !== ''): ?>
                                                <div class="small text-secondary">
                                                    <?= htmlspecialchars($profsDisc, ENT_QUOTES, 'UTF-8') ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge text-bg-secondary">
                                                <?= htmlspecialchars($rotulo, ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                            <?php if ($diasTipo !== [] && !$mostrarMensagem): ?>
                                                <div class="mt-1">
                                                    <code><?= htmlspecialchars(implode(', ', $diasTipo), ENT_QUOTES, 'UTF-8') ?></code>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($percentualTipo !== null && !$mostrarMensagem): ?>
                                                <div class="mt-1">
                                                    <code><?= htmlspecialchars($percentualTipo, ENT_QUOTES, 'UTF-8') ?></code>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge text-bg-<?= $badgeSev ?>">
                                                <?= htmlspecialchars((string)$alarme['severidade'], ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </td>
                                        <?php if ($mostrarMensagem): ?>
                                            <td>
                                                <?php
                                                if ($diasTipo !== []) {
                                                    $textoMsg = count($diasTipo) . ' dias úteis: '
                                                        . implode(', ', $diasTipo);
                                                } else {
                                                    $textoMsg = (string)$alarme['mensagem'];
                                                }
                                                ?>
                                                <code><?= htmlspecialchars($textoMsg, ENT_QUOTES, 'UTF-8') ?></code>
                                            </td>
                                        <?php endif; ?>
                                        <td class="td-acao">
                                            <?php if ($pendenteAlarme): ?>
                                                <div class="d-flex align-items-center flex-wrap gap-1">
                                                    <?php if ($outraDisciplina): ?>
                                                        <span class="small text-secondary">—</span>
                                                    <?php else: ?>
                                                    <div class="dropdown"
                                                         data-alarme-id="<?= (int)$alarme['id'] ?>"
                                                         data-disciplina="<?= htmlspecialchars($codigoDisc, ENT_QUOTES, 'UTF-8') ?>">
                                                        <button class="btn btn-sm btn-outline-success dropdown-toggle py-0 px-2"
                                                                type="button"
                                                                data-bs-toggle="dropdown"
                                                                data-bs-popper-config='{"strategy":"fixed"}'
                                                                aria-expanded="false">
                                                            Ação
                                                        </button>
                                                        <ul class="dropdown-menu dropdown-menu-end"
                                                            data-menu="tpl-menu-alarme"></ul>
                                                    </div>
                                                    <?php endif; ?>
                                                    <?php if ($colorirPorAlarme): ?>
                                                        <span class="badge alarme-badge-<?= htmlspecialchars(str_replace('_', '-', $statusAlarme), ENT_QUOTES, 'UTF-8') ?>">
                                                            <?= $statusAlarme === 'nao_tratado' ? 'Não tratado' : ($statusAlarme === 'tratado' ? 'Tratado' : 'Aberto') ?>
                                                        </span>
                                                    <?php elseif ($statusAlarme === 'nao_tratado' && $statusFiltro !== 'nao_tratados'): ?>
                                                        <span class="small text-danger-emphasis">Não tratado</span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php elseif ($modoResolvidos || $statusAlarme === 'resolvido'
                                                || $statusAlarme === 'parcialmente_resolvido'): ?>
                                                <?php
                                                $enviosResolvido = $alarme['envios'] ?? [];
                                                if (!is_array($enviosResolvido)) {
                                                    $enviosResolvido = [];
                                                }
                                                $rotuloResolucao = $statusAlarme === 'parcialmente_resolvido'
                                                    ? 'Parcialmente resolvido'
                                                    : 'Resolvido';
                                                $classeBadgeResolucao = $statusAlarme === 'parcialmente_resolvido'
                                                    ? 'alarme-badge-parcial'
                                                    : 'alarme-badge-resolvido';
                                                ?>
                                                <div class="mb-1">
                                                    <span class="badge <?= $classeBadgeResolucao ?>">
                                                        <?= htmlspecialchars($rotuloResolucao, ENT_QUOTES, 'UTF-8') ?>
                                                    </span>
                                                </div>
                                                <?php if ($enviosResolvido !== []): ?>
                                                    <div class="small">
                                                        <?php foreach ($enviosResolvido as $envioSnap): ?>
                                                            <?php
                                                            if (!is_array($envioSnap)) {
                                                                continue;
                                                            }
                                                            $canalSnap = (string)($envioSnap['canal'] ?? 'email_automatico');
                                                            $rotuloCanalSnap = $canalSnap === 'email'
                                                                ? 'E-mail (botão)'
                                                                : 'E-mail automático';
                                                            $tsEnvioSnap = strtotime((string)($envioSnap['enviado_em'] ?? ''));
                                                            $dataEnvioSnap = $tsEnvioSnap !== false
                                                                ? date('d/m/Y', $tsEnvioSnap)
                                                                : (string)($envioSnap['enviado_em'] ?? '');
                                                            $metricaSnap = mapaMetricaSnapshotAlarme([
                                                                'tipo' => (string)($alarme['tipo'] ?? ''),
                                                                'detalhe_json' => (string)($envioSnap['detalhe_json'] ?? ''),
                                                                'mensagem' => (string)($envioSnap['mensagem'] ?? ''),
                                                            ]);
                                                            ?>
                                                            <div class="mb-1">
                                                                <div class="fw-semibold"><?= htmlspecialchars($rotuloCanalSnap, ENT_QUOTES, 'UTF-8') ?></div>
                                                                <div class="text-secondary">
                                                                    <?= htmlspecialchars($dataEnvioSnap, ENT_QUOTES, 'UTF-8') ?>
                                                                    · <?= htmlspecialchars($metricaSnap, ENT_QUOTES, 'UTF-8') ?>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php elseif ($temEnvioEmail): ?>
                                                <div class="small">
                                                    <?php if ($datasAuto !== []): ?>
                                                        <div class="fw-semibold">E-mail automático</div>
                                                        <div class="text-secondary">
                                                            <?= htmlspecialchars(implode(' · ', $datasAuto), ENT_QUOTES, 'UTF-8') ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($datasBotao !== []): ?>
                                                        <div class="fw-semibold<?= $datasAuto !== [] ? ' mt-1' : '' ?>">E-mail (botão)</div>
                                                        <div class="text-secondary">
                                                            <?= htmlspecialchars(implode(' · ', $datasBotao), ENT_QUOTES, 'UTF-8') ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($colorirPorAlarme): ?>
                                                        <div class="mt-1">
                                                            <span class="badge alarme-badge-tratado">Tratado</span>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="small">
                                                    <?php if ($colorirPorAlarme): ?>
                                                        <span class="badge alarme-badge-tratado">Tratado</span>
                                                        <div class="text-secondary">contato fora do sistema</div>
                                                    <?php else: ?>
                                                        <div class="fw-semibold">Tratado</div>
                                                        <div class="text-secondary">contato fora do sistema</div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
    </div>
    <?= preg_replace('/^[ \t]+/m', '', (string)ob_get_clean()) ?>

        <?php if ($exibidos < $totalAlarmes): ?>
            <div class="text-secondary small text-center mt-3">
                Mostrando <?= $exibidos ?> de <?= $totalAlarmes ?> alarmes.
            </div>
        <?php endif; ?>

    <div class="modal fade" id="modalContato" tabindex="-1" aria-labelledby="modalContatoTitulo" aria-hidden="true">
        <div class="modal-dialog">
            <form class="modal-content" method="post" action="<?= htmlspecialchars(url('/alarmes/contato'), ENT_QUOTES, 'UTF-8') ?>">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="modalContatoTitulo">Registrar contato realizado fora do sistema</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body d-flex flex-column gap-3">
                    <p class="small text-secondary mb-0" id="modalContatoAluno"></p>
                    <input type="hidden" name="coleta_id" value="<?= (int)$coletaId ?>">
                    <input type="hidden" name="aluno_id" value="">
                    <input type="hidden" name="curso_id" value="">
                    <input type="hidden" name="codigo_disciplina" value="">
                    <input type="hidden" name="status" value="<?= htmlspecialchars($statusFiltro, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="curso" value="<?= htmlspecialchars($cursoSelecionado, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="row g-3">
                        <div class="col-sm-7">
                            <label class="form-label" for="contatoCanal">Como foi o contato</label>
                            <select class="form-select" id="contatoCanal" name="canal" required>
                                <option value="">Escolha…</option>
                                <?php foreach ($rotulosContato as $valor => $rotulo): ?>
                                    <option value="<?= htmlspecialchars($valor, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($rotulo, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-5">
                            <label class="form-label" for="contatoData">Quando</label>
                            <input class="form-control" type="date" id="contatoData" name="data_contato" required
                                   value="<?= htmlspecialchars($hoje, ENT_QUOTES, 'UTF-8') ?>"
                                   min="<?= htmlspecialchars($contatoMaisAntigo, ENT_QUOTES, 'UTF-8') ?>"
                                   max="<?= htmlspecialchars($hoje, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                    </div>
                    <fieldset>
                        <legend class="form-label fs-6 mb-1">Conseguiu falar com o aluno?</legend>
                        <div class="d-flex flex-wrap gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="conseguiu_falar" id="falouSim" value="1">
                                <label class="form-check-label" for="falouSim">Sim</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="conseguiu_falar" id="falouNao" value="0">
                                <label class="form-check-label" for="falouNao">Não</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="conseguiu_falar" id="falouNa" value="" checked>
                                <label class="form-check-label" for="falouNa">Não se aplica</label>
                            </div>
                        </div>
                    </fieldset>
                    <div>
                        <label class="form-label" for="contatoMotivo">Motivo principal das faltas <span class="text-secondary">(se souber)</span></label>
                        <select class="form-select" id="contatoMotivo" name="motivo">
                            <option value="">Não informado</option>
                            <?php foreach ($rotulosMotivo as $valor => $rotulo): ?>
                                <option value="<?= htmlspecialchars($valor, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($rotulo, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label" for="contatoObservacao">Observação <span class="text-secondary">(opcional)</span></label>
                        <textarea class="form-control" id="contatoObservacao" name="observacao" rows="3"
                                  maxlength="<?= $maxObservacao ?>"></textarea>
                    </div>
                    <p class="small text-secondary mb-0">
                        Com o contato registrado, o alarme passa a constar como tratado.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Registrar contato realizado fora do sistema</button>
                </div>
            </form>
        </div>
    </div>

    <script type="application/json" id="dadosFrequenciaCorrenteAlarmes"><?= json_encode(
        (object)$dadosFrequenciaCorrente,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
    ) ?></script>

    <div class="modal fade" id="modalFrequenciaCorrenteAlarmes" tabindex="-1"
         aria-labelledby="modalFrequenciaCorrenteAlarmesTitulo" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header py-2 px-3 bg-primary text-white">
                    <h2 class="modal-title h6 mb-0" id="modalFrequenciaCorrenteAlarmesTitulo">Frequência por disciplina</h2>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                            aria-label="Fechar"></button>
                </div>
                <div class="modal-body p-3 p-md-4 bg-light">
                    <div class="atestado-passe-livre mx-auto bg-white border shadow-sm p-4 p-md-5">
                        <p class="small text-justify mb-4" id="modalFrequenciaCorrenteAlarmesTexto"></p>
                        <div class="table-responsive mb-3">
                            <table class="table table-sm table-bordered align-middle mb-0 small atestado-tabela">
                                <thead class="table-light">
                                    <tr>
                                        <th>Semestre</th>
                                        <th>Código</th>
                                        <th>Disciplina</th>
                                        <th class="text-end">Frequência*</th>
                                    </tr>
                                </thead>
                                <tbody id="modalFrequenciaCorrenteAlarmesDisciplinas"></tbody>
                            </table>
                        </div>
                        <p class="small text-secondary mb-0">
                            * A frequência é o percentual de presença em relação ao número de aulas ministradas.
                        </p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function () {
        const config = <?= json_encode([
            'urlEmail' => url('/alarmes/enviar-email'),
            'coletaId' => (int)$coletaId,
            'status' => (string)$statusFiltro,
            'curso' => (string)$cursoSelecionado,
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        function enviar(url, campos) {
            const form = document.createElement('form');
            form.method = 'post';
            form.action = url;
            Object.keys(campos).forEach(function (nome) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = nome;
                input.value = String(campos[nome]);
                form.appendChild(input);
            });
            document.body.appendChild(form);
            form.submit();
        }

        document.addEventListener('show.bs.dropdown', function (evento) {
            const menu = evento.target.parentElement
                ? evento.target.parentElement.querySelector('.dropdown-menu[data-menu]')
                : null;
            if (!menu || menu.children.length > 0) {
                return;
            }
            const modelo = document.getElementById(menu.dataset.menu);
            if (modelo) {
                menu.appendChild(modelo.content.cloneNode(true));
            }
        });

        document.addEventListener('click', function (evento) {
            const botao = evento.target.closest('.js-acao-alarme');
            if (!botao) {
                return;
            }
            const cartao = botao.closest('[data-aluno-id]');
            if (!cartao) {
                return;
            }
            // Dentro da linha de uma disciplina, a ação vale só para aquele alarme/disciplina.
            const alarme = botao.closest('[data-alarme-id]');
            const campos = { status: config.status, curso: config.curso };
            const doAluno = {
                coleta_id: config.coletaId,
                aluno_id: cartao.dataset.alunoId,
                curso_id: cartao.dataset.cursoId
            };

            if (botao.dataset.acao === 'contato') {
                const modal = document.getElementById('modalContato');
                const form = modal.querySelector('form');
                form.reset();
                form.elements.aluno_id.value = doAluno.aluno_id;
                form.elements.curso_id.value = doAluno.curso_id;
                form.elements.codigo_disciplina.value = alarme ? (alarme.dataset.disciplina || '') : '';
                const nome = cartao.querySelector('.fw-semibold');
                document.getElementById('modalContatoAluno').textContent = nome
                    ? nome.firstChild.textContent.trim()
                    : '';
                bootstrap.Modal.getOrCreateInstance(modal).show();
                return;
            }

            if (botao.dataset.acao === 'email') {
                const pergunta = alarme
                    ? 'Enviar o e-mail automático desta disciplina ao aluno agora?'
                    : 'Enviar o e-mail automático ao aluno agora?';
                if (!confirm(pergunta)) {
                    return;
                }
                Object.assign(campos, doAluno);
                if (alarme) {
                    campos.codigo_disciplina = alarme.dataset.disciplina || '';
                }
                enviar(config.urlEmail, campos);
            }
        });

        // Bootstrap carrega depois do conteúdo no layout — registrar no load.
        window.addEventListener('load', function () {
            const modalFreqEl = document.getElementById('modalFrequenciaCorrenteAlarmes');
            const dadosFreqEl = document.getElementById('dadosFrequenciaCorrenteAlarmes');
            if (!modalFreqEl || !dadosFreqEl || typeof bootstrap === 'undefined') {
                return;
            }
            const textoFreq = document.getElementById('modalFrequenciaCorrenteAlarmesTexto');
            const tbodyFreq = document.getElementById('modalFrequenciaCorrenteAlarmesDisciplinas');
            let dadosFreq = {};
            try {
                dadosFreq = JSON.parse(dadosFreqEl.textContent || '{}');
            } catch (e) {
                dadosFreq = {};
            }

            function fmtPctFreq(valor) {
                if (valor === null || valor === undefined || valor === '' || Number.isNaN(Number(valor))) {
                    return '—';
                }
                return Number(valor).toLocaleString('pt-BR', {
                    minimumFractionDigits: 1,
                    maximumFractionDigits: 1
                }) + '%';
            }

            function escapeHtmlFreq(texto) {
                return String(texto)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function fmtFreqDiscHtml(d) {
                const sit = String((d && d.situacao) || '').trim();
                if (sit !== '') {
                    const data = String((d && d.data_trancamento) || '').trim();
                    if (data !== '') {
                        return escapeHtmlFreq(sit) + '<br>' + escapeHtmlFreq(data);
                    }
                    return escapeHtmlFreq(sit);
                }
                return escapeHtmlFreq(fmtPctFreq(d && d.frequencia));
            }

            function valorOu(traco, valor) {
                const limpo = String(valor || '').trim();
                return limpo !== '' ? limpo : traco;
            }

            function abrirFrequenciaCorrente(dados) {
                const nome = valorOu('---', dados.nome);
                const matricula = valorOu('---', dados.matricula);
                const ingresso = valorOu('---', dados.ingresso);
                const curso = valorOu('---', dados.curso);
                const periodo = valorOu('---', dados.periodo);
                const anualDesde = String(dados.anual_desde || '').trim();
                const rotuloPeriodo = anualDesde !== '' ? 'Ano letivo' : periodo;

                textoFreq.textContent =
                    'Frequência do(a) aluno(a) '
                    + nome
                    + ', matrícula nº '
                    + matricula
                    + ', com ingresso em '
                    + ingresso
                    + ', no curso '
                    + curso
                    + (anualDesde !== ''
                        ? ', no ano letivo, desde ' + anualDesde
                        : ', no semestre letivo ' + periodo)
                    + ':';

                tbodyFreq.innerHTML = '';
                const discs = Array.isArray(dados.disciplinas) ? dados.disciplinas : [];
                discs.forEach(function (d) {
                    const tr = document.createElement('tr');
                    tr.innerHTML =
                        '<td>' + escapeHtmlFreq(rotuloPeriodo) + '</td>' +
                        '<td>' + (d.codigo
                            ? '<code>' + escapeHtmlFreq(d.codigo) + '</code>'
                            : '<span class="text-secondary">—</span>') + '</td>' +
                        '<td>' + escapeHtmlFreq(d.nome || '') + '</td>' +
                        '<td class="text-end">' + fmtFreqDiscHtml(d) + '</td>';
                    tbodyFreq.appendChild(tr);
                });

                bootstrap.Modal.getOrCreateInstance(modalFreqEl).show();
            }

            document.addEventListener('click', function (evento) {
                const botao = evento.target.closest('.js-abrir-frequencia-corrente');
                if (!botao) {
                    return;
                }
                const dados = dadosFreq[botao.dataset.id];
                if (dados) {
                    abrirFrequenciaCorrente(dados);
                }
            });
        });
    })();
    </script>
<?php endif; ?>

<style>
    .js-abrir-frequencia-corrente {
        color: inherit;
        text-underline-offset: 0.15em;
    }
    .js-abrir-frequencia-corrente:hover,
    .js-abrir-frequencia-corrente:focus {
        color: #2c5282;
    }
    .atestado-passe-livre {
        max-width: 820px;
        color: #111;
        line-height: 1.45;
    }
    .atestado-tabela th,
    .atestado-tabela td {
        vertical-align: middle;
    }
    .lista-alarmes-alunos {
        gap: 1.25rem !important;
    }
    .aluno-alarme-card {
        border-left: 4px solid #2c5282 !important;
        overflow: visible;
    }
    .aluno-alarme-card .card-body,
    .aluno-alarme-card .table-responsive {
        overflow: visible;
    }
    .aluno-alarme-card .card-header {
        border-bottom: 1px solid #e2e8f0;
        margin-bottom: 0;
        padding-bottom: 0.85rem !important;
    }
    .tabela-alarmes {
        table-layout: fixed;
        width: 100%;
    }
    .tabela-alarmes .col-codigo { width: 9rem; }
    .tabela-alarmes .col-disciplina { width: 28%; }
    .tabela-alarmes .col-tipo { width: 11rem; }
    .tabela-alarmes .col-severidade { width: 6rem; }
    .tabela-alarmes .col-mensagem { width: auto; }
    .tabela-alarmes .col-acao { width: 8.5rem; }
    .tabela-alarmes.sem-mensagem .col-disciplina { width: 30%; }
    .tabela-alarmes.sem-mensagem .col-acao { width: 28%; }
    .alarme-stack {
        display: flex;
        flex-direction: column;
        gap: 0.1rem;
    }
    .alarme-slot {
        min-height: 2rem;
        display: flex;
        align-items: flex-start;
        line-height: 1.2;
        margin: 0;
        padding: 0;
    }
    .alarme-slot > .small {
        line-height: 1.2;
    }
    .alarme-slot > .small > div {
        margin: 0;
    }
    .alarme-slot > .badge,
    .alarme-slot > .dropdown {
        margin-top: 0.18rem;
    }
    .tabela-alarmes .td-codigo {
        white-space: nowrap;
        vertical-align: top;
    }
    .tabela-alarmes .td-disciplina {
        word-break: break-word;
        vertical-align: top;
        padding-right: 1.5rem;
    }
    .tabela-alarmes .td-acao {
        word-break: break-word;
    }
    .tabela-alarmes th,
    .tabela-alarmes td {
        vertical-align: top;
    }
    .tabela-alarmes tr.linha-outra-disciplina > td {
        background-color: #d4f1f4;
    }
    .tabela-alarmes tr.linha-outra-disciplina.table-secondary > td {
        background-color: #b8e4e9;
    }
</style>
