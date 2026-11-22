<?php

use Mapa\Core\View;

$porCurso = $porCurso ?? [];
$dadosFrequenciaCorrente = $dadosFrequenciaCorrente ?? [];
$totalAlunos = (int)($totalAlunos ?? 0);
$totalCursos = (int)($totalCursos ?? 0);
$totalRegistros = (int)($totalRegistros ?? 0);
$semSeletorCurso = !empty($semSeletorCurso);
$cursoSelecionado = (string)($cursoSelecionado ?? 'todos');
$cursosDisponiveis = $cursosDisponiveis ?? [];
$mostrarBadgeCurso = $semSeletorCurso;
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h4 mb-1">Alunos trancados</h1>
        <?php if ($mostrarBadgeCurso): ?>
            <p class="mb-1">
                <span class="badge text-bg-primary text-wrap text-start fw-normal" style="font-size: 0.85rem;">
                    <?= htmlspecialchars($cursoExibido ?? 'Todos os cursos', ENT_QUOTES, 'UTF-8') ?>
                </span>
            </p>
        <?php endif; ?>
        <p class="text-secondary mb-2">
            Alunos com status
            <strong>TRANCADO</strong> ou <strong>TRANC. AUTOMÁTICO</strong>
            na consulta em massa ao SIGAA — fora de alarmes e e-mails automáticos
            <?php if ($coleta !== null): ?>
                (<?= htmlspecialchars(View::rotuloColeta($coleta), ENT_QUOTES, 'UTF-8') ?>).
            <?php else: ?>
                .
            <?php endif; ?>
        </p>
        <?php if ($coleta !== null): ?>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="badge text-bg-secondary">
                    <?= $totalAlunos ?> aluno<?= $totalAlunos === 1 ? '' : 's' ?>
                </span>
                <span class="badge text-bg-secondary">
                    <?= $totalCursos ?> curso<?= $totalCursos === 1 ? '' : 's' ?>
                </span>
                <span class="badge text-bg-secondary">
                    <?= $totalRegistros ?> registro<?= $totalRegistros === 1 ? '' : 's' ?>
                </span>
            </div>
        <?php endif; ?>
    </div>
    <?php if (!$semSeletorCurso && $cursosDisponiveis !== []): ?>
        <form method="get" action="<?= htmlspecialchars(url('/trancados'), ENT_QUOTES, 'UTF-8') ?>" class="d-flex align-items-center gap-2">
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

<?php if (!empty($erro)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if (!empty($avisoCoordenador)): ?>
    <div class="alert alert-warning"><?= htmlspecialchars($avisoCoordenador, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if ($coleta !== null && empty($erro) && empty($avisoCoordenador) && $porCurso === []): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-secondary">
            Nenhum aluno trancado nesta coleta.
        </div>
    </div>
<?php endif; ?>

<?php foreach ($porCurso as $grupo): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white border-0 pt-3 pb-0 px-3">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <h2 class="h6 mb-1"><?= htmlspecialchars((string)$grupo['nome_curso'], ENT_QUOTES, 'UTF-8') ?></h2>
                <span class="badge text-bg-light text-dark border">
                    <?= (int)$grupo['total_alunos'] ?>
                    aluno<?= (int)$grupo['total_alunos'] === 1 ? '' : 's' ?>
                </span>
            </div>
        </div>
        <div class="card-body pt-3 px-3 pb-3">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th>Nome</th>
                            <th>Matrícula</th>
                            <th>E-mail</th>
                            <th>Status</th>
                            <th>Ingresso</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($grupo['linhas'] as $linha): ?>
                            <?php
                            $nomeSocial = trim((string)($linha['nome_social'] ?? ''));
                            $nome = $nomeSocial !== ''
                                ? $nomeSocial
                                : trim((string)($linha['nome'] ?? ''));
                            $chaveFreqAluno = (int)($linha['aluno_id'] ?? 0) . '-' . (int)($linha['curso_id'] ?? 0);
                            $temFreqCorrente = isset($dadosFrequenciaCorrente[$chaveFreqAluno]);
                            ?>
                            <tr>
                                <td>
                                    <?php if ($temFreqCorrente): ?>
                                        <button type="button"
                                                class="btn btn-link p-0 align-baseline fw-semibold text-decoration-underline js-abrir-frequencia-corrente"
                                                data-id="<?= htmlspecialchars($chaveFreqAluno, ENT_QUOTES, 'UTF-8') ?>"
                                                title="Ver frequência corrente">
                                            <?= htmlspecialchars($nome, ENT_QUOTES, 'UTF-8') ?>
                                        </button>
                                    <?php else: ?>
                                        <?= htmlspecialchars($nome, ENT_QUOTES, 'UTF-8') ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars((string)($linha['matricula'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string)($linha['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <span class="badge text-bg-warning text-dark">
                                        <?= htmlspecialchars((string)($linha['status_discente'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars((string)($linha['ano_semestre_ingresso'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php if ($dadosFrequenciaCorrente !== []): ?>
    <script type="application/json" id="dadosFrequenciaCorrenteTrancados"><?= json_encode(
        (object)$dadosFrequenciaCorrente,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
    ) ?></script>

    <div class="modal fade" id="modalFrequenciaCorrenteTrancados" tabindex="-1"
         aria-labelledby="modalFrequenciaCorrenteTrancadosTitulo" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header py-2 px-3 bg-primary text-white">
                    <h2 class="modal-title h6 mb-0" id="modalFrequenciaCorrenteTrancadosTitulo">Frequência por disciplina</h2>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                            aria-label="Fechar"></button>
                </div>
                <div class="modal-body p-3 p-md-4 bg-light">
                    <div class="atestado-passe-livre mx-auto bg-white border shadow-sm p-4 p-md-5">
                        <p class="small text-justify mb-4" id="modalFrequenciaCorrenteTrancadosTexto"></p>
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
                                <tbody id="modalFrequenciaCorrenteTrancadosDisciplinas"></tbody>
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
    </style>
    <script>
    window.addEventListener('load', function () {
        const modalFreqEl = document.getElementById('modalFrequenciaCorrenteTrancados');
        const dadosFreqEl = document.getElementById('dadosFrequenciaCorrenteTrancados');
        if (!modalFreqEl || !dadosFreqEl || typeof bootstrap === 'undefined') {
            return;
        }
        const textoFreq = document.getElementById('modalFrequenciaCorrenteTrancadosTexto');
        const tbodyFreq = document.getElementById('modalFrequenciaCorrenteTrancadosDisciplinas');
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
    </script>
<?php endif; ?>
