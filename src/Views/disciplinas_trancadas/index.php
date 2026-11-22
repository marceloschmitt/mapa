<?php

use Mapa\Core\View;

$porAluno = $porAluno ?? [];
$totalAlunos = (int)($totalAlunos ?? 0);
$totalCursos = (int)($totalCursos ?? 0);
$totalRegistros = (int)($totalRegistros ?? 0);
$totalTodasTrancadas = (int)($totalTodasTrancadas ?? 0);
$porSemana = $porSemana ?? ['labels' => [], 'disciplinas' => [], 'alunos' => [], 'sem_data' => 0];
$porQuantidade = $porQuantidade ?? ['labels' => [], 'values' => []];
$semSeletorCurso = !empty($semSeletorCurso);
$cursoSelecionado = (string)($cursoSelecionado ?? 'todos');
$cursosDisponiveis = $cursosDisponiveis ?? [];
$mostrarBadgeCurso = $semSeletorCurso;
$dadosFrequenciaCorrente = $dadosFrequenciaCorrente ?? [];
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h4 mb-1">Disciplinas trancadas</h1>
        <?php if ($mostrarBadgeCurso): ?>
            <p class="mb-1">
                <span class="badge text-bg-primary text-wrap text-start fw-normal" style="font-size: 0.85rem;">
                    <?= htmlspecialchars($cursoExibido ?? 'Todos os cursos', ENT_QUOTES, 'UTF-8') ?>
                </span>
            </p>
        <?php endif; ?>
        <p class="text-secondary mb-2">
            Disciplinas com trancamento informados pela API — sem percentual nem alarmes
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
                    <?= $totalRegistros ?> disciplina<?= $totalRegistros === 1 ? '' : 's' ?>
                </span>
                <?php if ($totalTodasTrancadas > 0): ?>
                    <span class="badge text-bg-danger">
                        <?= $totalTodasTrancadas ?> trancou<?= $totalTodasTrancadas === 1 ? '' : 'aram' ?> todas
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php if (!$semSeletorCurso && $cursosDisponiveis !== []): ?>
        <form method="get"
              action="<?= htmlspecialchars(url('/disciplinas-trancadas'), ENT_QUOTES, 'UTF-8') ?>"
              class="d-flex align-items-center gap-2">
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

<?php if ($totalTodasTrancadas > 0): ?>
    <div class="alert alert-danger d-flex align-items-start gap-2">
        <i class="bi bi-exclamation-triangle-fill mt-1"></i>
        <div>
            <strong>
                <?= $totalTodasTrancadas ?>
                aluno<?= $totalTodasTrancadas === 1 ? '' : 's' ?>
                trancou<?= $totalTodasTrancadas === 1 ? '' : 'aram' ?> todas as disciplinas sem trancar o curso.
            </strong>
            Continua<?= $totalTodasTrancadas === 1 ? '' : 'm' ?> matriculado<?= $totalTodasTrancadas === 1 ? '' : 's' ?>,
            mas sem nenhuma disciplina em andamento — risco de evasão. Aparece<?= $totalTodasTrancadas === 1 ? '' : 'm' ?>
            primeiro na lista, em vermelho.
        </div>
    </div>
<?php endif; ?>

<?php if ($porSemana['labels'] !== []): ?>
    <?php $semData = (int)$porSemana['sem_data']; ?>
    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 mb-3">Disciplinas trancadas por semana</h2>
                    <p class="small text-secondary mb-2">
                        Pela data de trancamento, de segunda a domingo.
                        <?php if ($semData > 0): ?>
                            <?= $semData ?> sem data de trancamento não entra<?= $semData === 1 ? '' : 'm' ?> no gráfico.
                        <?php endif; ?>
                    </p>
                    <div style="position: relative; width: 100%; height: 240px;">
                        <canvas id="chartTrancadasSemana"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 mb-3">Alunos que trancaram por semana</h2>
                    <p class="small text-secondary mb-2">
                        Alunos com ao menos uma disciplina trancada na semana. Quem trancou
                        em semanas diferentes conta em cada uma delas.
                    </p>
                    <div style="position: relative; width: 100%; height: 240px;">
                        <canvas id="chartAlunosTrancaramSemana"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 mb-3">Quantidade de alunos por disciplinas trancadas</h2>
                    <div style="position: relative; width: 100%; height: 300px;">
                        <canvas id="chartAlunosPorQuantidade"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 mb-3">Percentual de alunos por quantidade de disciplinas trancadas</h2>
                    <div style="position: relative; width: 100%; height: 300px;">
                        <canvas id="chartAlunosPorQuantidadePizza"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
    (function () {
        const porSemana = <?= json_encode(
            [
                'labels' => $porSemana['labels'],
                'disciplinas' => $porSemana['disciplinas'],
                'alunos' => $porSemana['alunos'],
            ],
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
        ) ?>;
        const porQuantidade = <?= json_encode(
            $porQuantidade,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
        ) ?>;

        const valorNaBarra = {
            id: 'valorNaBarra',
            afterDatasetsDraw(chart) {
                const ctx = chart.ctx;
                ctx.save();
                ctx.font = '600 11px system-ui, sans-serif';
                ctx.fillStyle = '#212529';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'bottom';
                chart.getDatasetMeta(0).data.forEach((barra, i) => {
                    const valor = chart.data.datasets[0].data[i];
                    if (valor > 0) {
                        ctx.fillText(String(valor), barra.x, barra.y - 2);
                    }
                });
                ctx.restore();
            }
        };

        function grafico(id, rotulos, rotulo, valores, cor, corBorda, girarRotulos, tituloX = '') {
            new Chart(document.getElementById(id), {
                type: 'bar',
                data: {
                    labels: rotulos,
                    datasets: [{
                        label: rotulo,
                        data: valores,
                        backgroundColor: cor,
                        borderColor: corBorda,
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: {
                            ticks: girarRotulos
                                ? { maxRotation: 60, minRotation: 45, autoSkip: false, font: { size: 10 } }
                                : { autoSkip: false, font: { size: 10 } },
                            title: { display: tituloX !== '', text: tituloX, font: { size: 11 } }
                        },
                        y: { beginAtZero: true, grace: '12%', ticks: { precision: 0 } }
                    }
                },
                plugins: [valorNaBarra]
            });
        }

        grafico('chartTrancadasSemana', porSemana.labels, 'Disciplinas trancadas',
            porSemana.disciplinas, 'rgba(242, 140, 40, 0.8)', '#f28c28', true);
        grafico('chartAlunosTrancaramSemana', porSemana.labels, 'Alunos que trancaram',
            porSemana.alunos, 'rgba(13, 110, 253, 0.7)', '#0d6efd', true);
        grafico('chartAlunosPorQuantidade', porQuantidade.labels, 'Alunos',
            porQuantidade.values, 'rgba(111, 66, 193, 0.7)', '#6f42c1', false,
            'Disciplinas trancadas pelo aluno');

        const fatias = porQuantidade.labels
            .map((n, i) => ({ n: Number(n), alunos: porQuantidade.values[i] }))
            .filter((f) => f.alunos > 0);
        const totalAlunos = fatias.reduce((soma, f) => soma + f.alunos, 0);
        const percentual = (alunos) => (100 * alunos / totalAlunos)
            .toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + '%';
        const coresPizza = ['#6f42c1', '#0d6efd', '#20c997', '#f28c28', '#dc3545',
            '#ffc107', '#6610f2', '#198754', '#d63384', '#6c757d'];

        const percentualNaFatia = {
            id: 'percentualNaFatia',
            afterDatasetsDraw(chart) {
                const ctx = chart.ctx;
                ctx.save();
                ctx.font = '600 11px system-ui, sans-serif';
                ctx.fillStyle = '#fff';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                chart.getDatasetMeta(0).data.forEach((fatia, i) => {
                    if (fatias[i].alunos / totalAlunos < 0.05) {
                        return;
                    }
                    const pos = fatia.tooltipPosition();
                    ctx.fillText(percentual(fatias[i].alunos), pos.x, pos.y);
                });
                ctx.restore();
            }
        };

        const canvasPizza = document.getElementById('chartAlunosPorQuantidadePizza');
        const legendaAoLado = canvasPizza.parentNode.clientWidth >= 480;
        if (totalAlunos > 0) {
            new Chart(canvasPizza, {
                type: 'pie',
                data: {
                    labels: fatias.map((f) => f.n + ' disciplina' + (f.n === 1 ? '' : 's')
                        + ': ' + f.alunos + ' aluno' + (f.alunos === 1 ? '' : 's')
                        + ' (' + percentual(f.alunos) + ')'),
                    datasets: [{
                        data: fatias.map((f) => f.alunos),
                        backgroundColor: fatias.map((f, i) => coresPizza[i % coresPizza.length]),
                        borderColor: '#fff',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: legendaAoLado ? 'right' : 'bottom',
                            labels: { boxWidth: 12, font: { size: 11 } }
                        },
                        tooltip: { callbacks: { label: (item) => item.label } }
                    }
                },
                plugins: [percentualNaFatia]
            });
        }
    })();
    </script>
<?php endif; ?>

<?php if ($coleta !== null && empty($erro) && empty($avisoCoordenador) && $porAluno === []): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-secondary">
            Nenhuma disciplina trancada nesta coleta.
        </div>
    </div>
<?php elseif ($porAluno !== []): ?>
    <div class="d-flex flex-column gap-4">
        <?php foreach ($porAluno as $grupo): ?>
            <?php
            $aluno = $grupo['aluno'];
            $nomeSocial = trim((string)($aluno['nome_social'] ?? ''));
            $nomeExibido = $nomeSocial !== ''
                ? $nomeSocial
                : trim((string)($aluno['nome'] ?? ''));
            $emailAluno = trim((string)($aluno['email'] ?? ''));
            $disciplinas = $grupo['disciplinas'] ?? [];
            $nDisc = count($disciplinas);
            $todasTrancadas = !empty($grupo['todas_trancadas']);
            $chaveFreqAluno = (int)$aluno['id'] . '-' . (int)$aluno['curso_id'];
            $temFreqCorrente = isset($dadosFrequenciaCorrente[$chaveFreqAluno]);
            ?>
            <article class="card border-0 shadow-sm<?= $todasTrancadas ? ' border-start border-4 border-danger' : '' ?>">
                <div class="card-header <?= $todasTrancadas ? 'bg-danger-subtle' : 'bg-white' ?> border-0 pb-0 pt-3 px-3">
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
                                Matrícula <?= htmlspecialchars((string)($aluno['matricula'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                ·
                                <?= htmlspecialchars((string)($aluno['nome_curso'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        </div>
                        <div class="d-flex flex-wrap gap-2 justify-content-end">
                            <?php if ($todasTrancadas): ?>
                                <span class="badge text-bg-danger"
                                      title="Trancou todas as disciplinas da coleta, mas não trancou o curso">
                                    <i class="bi bi-exclamation-triangle-fill"></i> Trancou todas as disciplinas
                                </span>
                            <?php endif; ?>
                            <span class="badge text-bg-light text-dark border">
                                <?= $nDisc ?>
                                disciplina<?= $nDisc === 1 ? '' : 's' ?>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="card-body pt-3 px-3 pb-3">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th>Código</th>
                                    <th>Disciplina</th>
                                    <th>Situação</th>
                                    <th>Data de trancamento</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($disciplinas as $disc): ?>
                                    <?php
                                    $codigo = trim((string)($disc['codigo_disciplina'] ?? ''));
                                    $dataTranc = trim((string)($disc['data_trancamento'] ?? ''));
                                    ?>
                                    <tr>
                                        <td>
                                            <?php if ($codigo !== ''): ?>
                                                <code><?= htmlspecialchars($codigo, ENT_QUOTES, 'UTF-8') ?></code>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars((string)($disc['disciplina'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <span class="badge text-bg-warning text-dark">Trancada</span>
                                        </td>
                                        <td><?= htmlspecialchars($dataTranc !== '' ? $dataTranc : '—', ENT_QUOTES, 'UTF-8') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <script type="application/json" id="dadosFrequenciaCorrenteTrancadas"><?= json_encode(
        (object)$dadosFrequenciaCorrente,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
    ) ?></script>

    <div class="modal fade" id="modalFrequenciaCorrenteTrancadas" tabindex="-1"
         aria-labelledby="modalFrequenciaCorrenteTrancadasTitulo" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header py-2 px-3 bg-primary text-white">
                    <h2 class="modal-title h6 mb-0" id="modalFrequenciaCorrenteTrancadasTitulo">Frequência por disciplina</h2>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                            aria-label="Fechar"></button>
                </div>
                <div class="modal-body p-3 p-md-4 bg-light">
                    <div class="atestado-passe-livre mx-auto bg-white border shadow-sm p-4 p-md-5">
                        <p class="small text-justify mb-4" id="modalFrequenciaCorrenteTrancadasTexto"></p>
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
                                <tbody id="modalFrequenciaCorrenteTrancadasDisciplinas"></tbody>
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
        const modalFreqEl = document.getElementById('modalFrequenciaCorrenteTrancadas');
        const dadosFreqEl = document.getElementById('dadosFrequenciaCorrenteTrancadas');
        if (!modalFreqEl || !dadosFreqEl || typeof bootstrap === 'undefined') {
            return;
        }
        const textoFreq = document.getElementById('modalFrequenciaCorrenteTrancadasTexto');
        const tbodyFreq = document.getElementById('modalFrequenciaCorrenteTrancadasDisciplinas');
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
