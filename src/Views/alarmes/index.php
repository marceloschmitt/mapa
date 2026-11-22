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
$diasNaoTratado = (int)($diasNaoTratado ?? 14);
$statusFiltro = (string)($statusFiltro ?? 'abertos');
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

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
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
    <div class="d-flex flex-column align-items-stretch align-items-md-end gap-2">
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
        <?php
        $filtrosStatus = [
            'abertos' => 'Abertos',
            'tratados' => 'Tratados',
            'nao_tratados' => 'Não tratados',
            'todos' => 'Todos',
        ];
        ?>
        <div class="btn-group flex-wrap">
            <?php foreach ($filtrosStatus as $valor => $rotulo): ?>
                <a href="<?= htmlspecialchars(url('/alarmes?status=' . $valor . $queryCurso), ENT_QUOTES, 'UTF-8') ?>"
                   class="btn btn-sm <?= $statusFiltro === $valor ? 'btn-primary' : 'btn-outline-primary' ?>">
                    <?= $rotulo ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
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
    <template id="tpl-menu-geral-fechado"><?= $itemContato ?></template>
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
                    default => 'alarme-card-aberto',
                };
                $rotuloStatusGrupo = match ($statusCasoGrupo) {
                    'tratado' => 'Tratado',
                    'nao_tratado' => 'Não tratado',
                    default => 'Aberto',
                };
                ?>
                <article class="card border-0 shadow-sm aluno-alarme-card <?= $classeFundo ?>"
                         data-aluno-id="<?= (int)$aluno['id'] ?>"
                         data-curso-id="<?= (int)$aluno['curso_id'] ?>">
                    <div class="card-header border-0 pb-0 pt-3 px-3 bg-transparent">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <div class="fw-semibold fs-6">
                                <?= htmlspecialchars($nomeExibido, ENT_QUOTES, 'UTF-8') ?>
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
                            <span class="badge alarme-badge-<?= htmlspecialchars(str_replace('_', '-', $statusCasoGrupo), ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($rotuloStatusGrupo, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <span class="badge text-bg-light text-dark border">
                                <?= (int)$grupo['total_alarmes'] ?>
                                <?= (int)$grupo['total_alarmes'] === 1 ? 'alarme' : 'alarmes' ?>
                            </span>
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-success dropdown-toggle"
                                        type="button"
                                        data-bs-toggle="dropdown"
                                        aria-expanded="false">
                                    Ação geral
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end"
                                    data-menu="<?= $pendentes > 0 ? 'tpl-menu-geral' : 'tpl-menu-geral-fechado' ?>"></ul>
                            </div>
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
                                    <th>Ação</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($grupo['disciplinas'] as $disciplina): ?>
                                    <?php
                                    $todosTratados = true;
                                    foreach ($disciplina['alarmes'] as $alarme) {
                                        if ((string)($alarme['status_caso'] ?? '') !== 'tratado') {
                                            $todosTratados = false;
                                            break;
                                        }
                                    }
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
                                    ?>
                                    <tr class="<?= trim(($outraDisciplina ? 'linha-outra-disciplina ' : '') . ($todosTratados ? 'table-secondary' : '')) ?>">
                                        <td class="td-codigo">
                                            <?php if ($disciplina['codigo'] !== ''): ?>
                                                <code><?= htmlspecialchars($disciplina['codigo'], ENT_QUOTES, 'UTF-8') ?></code>
                                            <?php else: ?>
                                                <span class="text-secondary">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="td-disciplina">
                                            <?= htmlspecialchars($nomeDisc, ENT_QUOTES, 'UTF-8') ?>
                                            <?php $nomeTurmaDisc = trim((string)($disciplina['nome_turma'] ?? '')); ?>
                                            <?php if ($nomeTurmaDisc !== ''): ?>
                                                <span class="text-secondary">— <?= htmlspecialchars($nomeTurmaDisc, ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php endif; ?>
                                            <?php if ($outraDisciplina): ?>
                                                <div>
                                                    <span class="badge text-bg-light border text-secondary"><?= $rotuloOutra ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php
                                            $profsDisc = trim((string)($disciplina['professores'] ?? ''));
                                            if ($profsDisc !== ''):
                                            ?>
                                                <div class="small text-secondary">
                                                    <?= htmlspecialchars($profsDisc, ENT_QUOTES, 'UTF-8') ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="alarme-stack">
                                                <?php foreach ($disciplina['alarmes'] as $alarme): ?>
                                                    <?php
                                                    $tipo = (string)$alarme['tipo'];
                                                    $rotulo = $rotulosTipo[$tipo] ?? $tipo;
                                                    $diasTipo = $tipo === 'faltas_4dias'
                                                        ? mapaDiasFaltaAlarme($alarme)
                                                        : [];
                                                    $percentualTipo = $tipo === 'percentual_baixo'
                                                        ? mapaPercentualFrequenciaAlarme($alarme)
                                                        : null;
                                                    ?>
                                                    <div class="alarme-slot">
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
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="alarme-stack">
                                                <?php foreach ($disciplina['alarmes'] as $alarme): ?>
                                                    <?php $badge = $alarme['severidade'] === 'critico' ? 'danger' : 'warning'; ?>
                                                    <div class="alarme-slot">
                                                        <span class="badge text-bg-<?= $badge ?>">
                                                            <?= htmlspecialchars((string)$alarme['severidade'], ENT_QUOTES, 'UTF-8') ?>
                                                        </span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                        <?php if ($mostrarMensagem): ?>
                                            <td>
                                                <div class="alarme-stack">
                                                    <?php foreach ($disciplina['alarmes'] as $alarme): ?>
                                                        <?php
                                                        $tipoMsg = (string)$alarme['tipo'];
                                                        $diasFalta = $tipoMsg === 'faltas_4dias'
                                                            ? mapaDiasFaltaAlarme($alarme)
                                                            : [];
                                                        ?>
                                                        <div class="alarme-slot">
                                                            <?php
                                                            if ($diasFalta !== []) {
                                                                $textoMsg = count($diasFalta) . ' dias úteis: '
                                                                    . implode(', ', $diasFalta);
                                                            } else {
                                                                $textoMsg = (string)$alarme['mensagem'];
                                                            }
                                                            ?>
                                                            <code><?= htmlspecialchars($textoMsg, ENT_QUOTES, 'UTF-8') ?></code>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                        <td class="td-acao">
                                            <div class="alarme-stack">
                                                <?php foreach ($disciplina['alarmes'] as $alarme): ?>
                                                    <?php
                                                    $statusAlarme = (string)($alarme['status_caso'] ?? 'aberto');
                                                    $pendenteAlarme = $statusAlarme === 'aberto' || $statusAlarme === 'nao_tratado';
                                                    $enviadoEm = trim((string)($alarme['email_enviado_em'] ?? ''));
                                                    ?>
                                                    <div class="alarme-slot">
                                                        <?php if ($pendenteAlarme): ?>
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
                                                            <?php if ($statusAlarme === 'nao_tratado'): ?>
                                                                <span class="small text-danger-emphasis ms-2 align-self-center">Não tratado</span>
                                                            <?php endif; ?>
                                                        <?php elseif ((int)$alarme['visualizado'] === 1): ?>
                                                            <?php $info = mapaFormatarContatoAlarme($alarme, $rotulosContatoExibicao); ?>
                                                            <div class="small" title="<?= htmlspecialchars($info['titulo'], ENT_QUOTES, 'UTF-8') ?>">
                                                                <div class="fw-semibold"><?= htmlspecialchars($info['rotulo'], ENT_QUOTES, 'UTF-8') ?></div>
                                                                <?php if ($info['data'] !== '' || $info['por'] !== ''): ?>
                                                                    <div class="text-secondary">
                                                                        <?php if ($info['data'] !== ''): ?>
                                                                            <?= htmlspecialchars($info['data'], ENT_QUOTES, 'UTF-8') ?>
                                                                        <?php endif; ?>
                                                                        <?php if ($info['data'] !== '' && $info['por'] !== ''): ?>
                                                                            ·
                                                                        <?php endif; ?>
                                                                        <?php if ($info['por'] !== ''): ?>
                                                                            <?= htmlspecialchars($info['por'], ENT_QUOTES, 'UTF-8') ?>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php elseif ($enviadoEm !== '' && ($ts = strtotime($enviadoEm)) !== false): ?>
                                                            <div class="small">
                                                                <div class="fw-semibold">E-mail automático</div>
                                                                <div class="text-secondary"><?= date('d/m/Y', $ts) ?></div>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="small fw-semibold">Tratado</div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                    </tr>
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
    })();
    </script>
<?php endif; ?>

<style>
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
