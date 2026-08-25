<?php

use Mapa\Core\View;

$rotulosTipo = [
    'percentual_baixo' => 'Frequência < 75%',
    'faltas_4dias' => 'Faltas recentes (4 dias)',
    'faltas_3semanas' => '3 semanas consecutivas',
];
$rotulosContato = $rotulosContato ?? [
    'email' => 'E-mail enviado',
    'whatsapp' => 'WhatsApp',
    'telefone' => 'Ligação telefônica',
    'presencial' => 'Conversa presencial',
    'assistencia' => 'Encaminhamento para Assistência Estudantil',
];
$rotulosContatoExibicao = $rotulosContatoExibicao ?? array_merge(
    $rotulosContato,
    ['email_automatico' => 'E-mail automático enviado']
);
$alarmesPorAluno = $alarmesPorAluno ?? [];
$totalAlarmes = (int)($totalAlarmes ?? 0);
$exibidos = (int)($exibidos ?? 0);
$alunosNaoTratados = (int)($alunosNaoTratados ?? 0);
$alarmesNaoTratados = (int)($alarmesNaoTratados ?? 0);
$alunosComAlarmes = (int)($alunosComAlarmes ?? 0);
$coletaId = $coleta !== null ? (int)$coleta['id'] : 0;
$filtroAbertos = !empty($somenteAbertos) ? '1' : '0';
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
 * @param array<string, mixed> $alarme
 * @param array<string, string> $rotulosContato
 * @return array{rotulo: string, data: string, por: string, titulo: string}
 */
if (!function_exists('mapaFormatarContatoAlarme')) {
    function mapaFormatarContatoAlarme(array $alarme, array $rotulosContato): array
    {
        $tipo = (string)($alarme['contato_tipo'] ?? '');
        $rotulo = $rotulosContato[$tipo] ?? 'Contato';
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
                <span class="badge text-bg-warning text-dark">
                    <?= $alunosNaoTratados ?> aluno<?= $alunosNaoTratados === 1 ? '' : 's' ?> com alarmes não tratados
                </span>
                <span class="badge text-bg-danger">
                    <?= $alarmesNaoTratados ?> alarme<?= $alarmesNaoTratados === 1 ? '' : 's' ?> não tratado<?= $alarmesNaoTratados === 1 ? '' : 's' ?>
                </span>
            </div>
        <?php endif; ?>
    </div>
    <div class="d-flex flex-column align-items-stretch align-items-md-end gap-2">
        <?php if (!$semSeletorCurso && $cursosDisponiveis !== []): ?>
            <form method="get" action="<?= htmlspecialchars(url('/alarmes'), ENT_QUOTES, 'UTF-8') ?>" class="d-flex align-items-center gap-2">
                <input type="hidden" name="abertos" value="<?= $filtroAbertos ?>">
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
        <div class="btn-group">
            <a href="<?= htmlspecialchars(url('/alarmes?abertos=1' . $queryCurso), ENT_QUOTES, 'UTF-8') ?>"
               class="btn btn-sm <?= !empty($somenteAbertos) ? 'btn-primary' : 'btn-outline-primary' ?>">
                Abertos
            </a>
            <a href="<?= htmlspecialchars(url('/alarmes?abertos=0' . $queryCurso), ENT_QUOTES, 'UTF-8') ?>"
               class="btn btn-sm <?= empty($somenteAbertos) ? 'btn-primary' : 'btn-outline-primary' ?>">
                Todos
            </a>
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
    <div class="d-flex flex-column gap-4 lista-alarmes-alunos">
            <?php foreach ($alarmesPorAluno as $grupo): ?>
                <?php
                $aluno = $grupo['aluno'];
                $usaNomeSocial = $aluno['nome_social'] !== '';
                $nomeExibido = $usaNomeSocial ? $aluno['nome_social'] : $aluno['nome'];
                $emailAluno = trim((string)($aluno['email'] ?? ''));
                $ingresso = trim((string)($aluno['ano_semestre_ingresso'] ?? ''));
                $turmaEntrada = trim((string)($aluno['turma_entrada'] ?? ''));
                $abertos = (int)($grupo['abertos'] ?? 0);
                ?>
                <article class="card border-0 shadow-sm aluno-alarme-card">
                    <div class="card-header bg-white border-0 pb-0 pt-3 px-3">
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
                        </div>
                        <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
                            <span class="badge text-bg-light text-dark border">
                                <?= (int)$grupo['total_alarmes'] ?>
                                <?= (int)$grupo['total_alarmes'] === 1 ? 'alarme' : 'alarmes' ?>
                            </span>
                            <?php if ($abertos > 0): ?>
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-success dropdown-toggle"
                                            type="button"
                                            data-bs-toggle="dropdown"
                                            aria-expanded="false">
                                        Ação geral
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <?php foreach ($rotulosContato as $valor => $rotulo): ?>
                                            <li>
                                                <form method="post" action="<?= htmlspecialchars(url('/alarmes/visualizar'), ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="coleta_id" value="<?= $coletaId ?>">
                                                    <input type="hidden" name="aluno_id" value="<?= (int)$aluno['id'] ?>">
                                                    <input type="hidden" name="curso_id" value="<?= (int)$aluno['curso_id'] ?>">
                                                    <input type="hidden" name="contato_tipo" value="<?= htmlspecialchars($valor, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="abertos" value="<?= $filtroAbertos ?>">
                                                    <input type="hidden" name="curso" value="<?= htmlspecialchars($cursoSelecionado, ENT_QUOTES, 'UTF-8') ?>">
                                                    <button type="submit" class="dropdown-item">
                                                        <?= htmlspecialchars($rotulo, ENT_QUOTES, 'UTF-8') ?>
                                                    </button>
                                                </form>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
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
                                    <th>Ação</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($grupo['disciplinas'] as $disciplina): ?>
                                    <?php
                                    $todosVisualizados = true;
                                    foreach ($disciplina['alarmes'] as $alarme) {
                                        if ((int)$alarme['visualizado'] === 0) {
                                            $todosVisualizados = false;
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
                                        || isset($minhasDisciplinasMapa[$codigoDisc]);
                                    $outraDisciplina = $isProfessor && $codigoDisc !== ''
                                        && !isset($minhasDisciplinasMapa[$codigoDisc]);
                                    ?>
                                    <tr class="<?= trim(($outraDisciplina ? 'linha-outra-disciplina ' : '') . ($todosVisualizados ? 'table-secondary' : '')) ?>">
                                        <td class="td-codigo">
                                            <?php if ($disciplina['codigo'] !== ''): ?>
                                                <code><?= htmlspecialchars($disciplina['codigo'], ENT_QUOTES, 'UTF-8') ?></code>
                                            <?php else: ?>
                                                <span class="text-secondary">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="td-disciplina">
                                            <?= htmlspecialchars($nomeDisc, ENT_QUOTES, 'UTF-8') ?>
                                            <?php if ($outraDisciplina): ?>
                                                <div>
                                                    <span class="badge text-bg-light border text-secondary">Outra disciplina</span>
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
                                                    <div class="alarme-slot">
                                                        <?php if ((int)$alarme['visualizado'] === 0): ?>
                                                            <?php if ($outraDisciplina): ?>
                                                                <span class="small text-secondary">—</span>
                                                            <?php else: ?>
                                                            <div class="dropdown">
                                                                <button class="btn btn-sm btn-outline-success dropdown-toggle py-0 px-2"
                                                                        type="button"
                                                                        data-bs-toggle="dropdown"
                                                                        data-bs-popper-config='{"strategy":"fixed"}'
                                                                        aria-expanded="false">
                                                                    Ação
                                                                </button>
                                                                <ul class="dropdown-menu dropdown-menu-end">
                                                                    <?php foreach ($rotulosContato as $valor => $rotulo): ?>
                                                                        <li>
                                                                            <form method="post" action="<?= htmlspecialchars(url('/alarmes/visualizar'), ENT_QUOTES, 'UTF-8') ?>">
                                                                                <input type="hidden" name="alarme_id" value="<?= (int)$alarme['id'] ?>">
                                                                                <input type="hidden" name="contato_tipo" value="<?= htmlspecialchars($valor, ENT_QUOTES, 'UTF-8') ?>">
                                                                                <input type="hidden" name="abertos" value="<?= $filtroAbertos ?>">
                                                                                <input type="hidden" name="curso" value="<?= htmlspecialchars($cursoSelecionado, ENT_QUOTES, 'UTF-8') ?>">
                                                                                <button type="submit" class="dropdown-item">
                                                                                    <?= htmlspecialchars($rotulo, ENT_QUOTES, 'UTF-8') ?>
                                                                                </button>
                                                                            </form>
                                                                        </li>
                                                                    <?php endforeach; ?>
                                                                </ul>
                                                            </div>
                                                            <?php endif; ?>
                                                        <?php else: ?>
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

        <?php if ($exibidos < $totalAlarmes): ?>
            <div class="text-secondary small text-center mt-3">
                Mostrando <?= $exibidos ?> de <?= $totalAlarmes ?> alarmes.
            </div>
        <?php endif; ?>
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
