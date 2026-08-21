<?php

use Mapa\Core\View;

$porCursoJson = json_encode($porCurso, JSON_UNESCAPED_UNICODE);
$porDiaJson = json_encode($porDiaSemana, JSON_UNESCAPED_UNICODE);
$porMesJson = json_encode($porMes, JSON_UNESCAPED_UNICODE);
$isProfessor = !empty($isProfessor);
$semSeletorCurso = !empty($isCoordenador) || $isProfessor;
$mostrarFaltas = !$isProfessor;
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Relatório geral</h1>
        <?php if ($semSeletorCurso && !empty($cursoExibido)): ?>
            <p class="mb-1">
                <span class="badge text-bg-primary text-wrap text-start fw-normal" style="font-size: 0.85rem;">
                    <?= htmlspecialchars((string)$cursoExibido, ENT_QUOTES, 'UTF-8') ?>
                </span>
            </p>
        <?php endif; ?>
        <p class="text-secondary mb-0">
            Frequência, evolução de faltas e sinais de risco de evasão (SQLite).
        </p>
    </div>
    <?php if (!$semSeletorCurso && !empty($cursosDisponiveis)): ?>
        <form method="get" action="<?= htmlspecialchars(url('/analytics'), ENT_QUOTES, 'UTF-8') ?>" class="d-flex align-items-center gap-2">
            <label for="curso" class="form-label mb-0 text-nowrap">Curso</label>
            <select class="form-select" id="curso" name="curso" style="min-width: 260px;"
                    onchange="this.form.submit()">
                <option value="todos" <?= ($cursoSelecionado ?? 'todos') === 'todos' ? 'selected' : '' ?>>
                    <?= htmlspecialchars($rotuloGeral ?? 'Todos os cursos', ENT_QUOTES, 'UTF-8') ?>
                </option>
                <?php foreach ($cursosDisponiveis as $curso): ?>
                    <option value="<?= (int)$curso['id'] ?>"
                        <?= (string)($cursoSelecionado ?? '') === (string)$curso['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string)$curso['nome_curso'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    <?php endif; ?>
</div>

<?php if (!empty($avisoCoordenador)): ?>
    <div class="alert alert-warning"><?= htmlspecialchars($avisoCoordenador, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if (!empty($erro)): ?>
    <div class="alert alert-warning"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if (!empty($sucesso)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($sucesso, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if ($coleta !== null): ?>
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-secondary small">Média de frequência</div>
                    <div class="fs-3 fw-bold text-primary"><?= htmlspecialchars((string)$resumo['media_frequencia'], ENT_QUOTES, 'UTF-8') ?>%</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-secondary small">
                        Alunos com frequência menor do que 75% em alguma disciplina
                    </div>
                    <div class="fs-3 fw-bold text-danger"><?= (int)$resumo['abaixo_75'] ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-secondary small">Alarmes abertos</div>
                    <div class="fs-3 fw-bold"><?= (int)$resumo['nao_visualizados'] ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-secondary small"><?= htmlspecialchars(View::rotuloColeta($coleta), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="small mt-1">
                        Período:
                        <?= htmlspecialchars((string)$coleta['data_inicial'], ENT_QUOTES, 'UTF-8') ?>
                        a
                        <?= htmlspecialchars((string)$coleta['data_final'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h2 class="h6 mb-3">Frequência média por curso</h2>
                    <p class="small text-secondary mb-2">
                        Comparativo da frequência média entre todos os cursos.
                    </p>
                    <div id="chartCursosWrap" class="chart-cursos-wrap">
                        <canvas id="chartCursos"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <?php if ($mostrarFaltas): ?>
            <div class="col-lg-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h6 mb-3">Faltas por dia da semana</h2>
                        <div class="chart-dias-wrap">
                            <canvas id="chartDias"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-9">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h6 mb-3">Evolução de faltas por semana</h2>
                        <p class="small text-secondary mb-2">
                            Segunda a domingo. Linhas verticais marcam a troca de mês.
                        </p>
                        <div class="chart-semanas-wrap">
                            <canvas id="chartMeses"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h6 mb-3">
                Disciplinas críticas (mais alunos abaixo de 75%)
                <?php if ($disciplinasCriticas !== []): ?>
                    <span class="text-secondary fw-normal" id="criticasContador"></span>
                <?php endif; ?>
            </h2>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 small" id="tabelaCriticas">
                    <colgroup>
                        <col style="width: 8rem;">
                        <col>
                        <col>
                        <col style="width: 5.5rem;">
                        <col style="width: 5rem;">
                        <col style="width: 5.5rem;">
                    </colgroup>
                    <thead class="table-light">
                        <tr>
                            <th scope="col" class="text-nowrap">Código</th>
                            <th scope="col">Disciplina</th>
                            <th scope="col">Curso</th>
                            <th scope="col" class="text-end text-nowrap">Média %</th>
                            <th scope="col" class="text-end text-nowrap">Alunos</th>
                            <th scope="col" class="text-end text-nowrap" title="Alunos com frequência abaixo de 75%">
                                &lt; 75%
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($disciplinasCriticas === []): ?>
                            <tr><td colspan="6" class="text-secondary">Nenhuma disciplina crítica nesta coleta.</td></tr>
                        <?php else: ?>
                            <?php foreach ($disciplinasCriticas as $indice => $disc): ?>
                                <tr class="linha-critica<?= $indice >= 15 ? ' d-none' : '' ?>">
                                    <td class="text-nowrap"><code><?= htmlspecialchars((string)$disc['codigo_disciplina'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                    <td>
                                        <?php
                                        $nomeCrit = (string)$disc['disciplina'];
                                        $semCrit = View::rotuloSemestre($disc['semestre_oferta'] ?? '');
                                        if ($semCrit !== '') {
                                            $nomeCrit .= ' (' . $semCrit . ')';
                                        }
                                        echo htmlspecialchars($nomeCrit, ENT_QUOTES, 'UTF-8');
                                        $profs = trim((string)($disc['professores'] ?? ''));
                                        if ($profs !== ''):
                                        ?>
                                            <div class="small text-secondary">
                                                <?= htmlspecialchars($profs, ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars((string)$disc['nome_curso'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="text-end text-nowrap"><?= htmlspecialchars((string)$disc['media'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="text-end text-nowrap"><?= (int)$disc['alunos'] ?></td>
                                    <td class="text-end text-nowrap"><?= (int)$disc['abaixo_75'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($disciplinasCriticas) > 15): ?>
                <div class="text-center mt-3">
                    <button type="button" class="btn btn-outline-primary btn-sm" id="btnVerMaisCriticas">
                        Ver mais
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@3.0.1/dist/chartjs-plugin-annotation.min.js"></script>
    <style>
        .chart-cursos-wrap {
            position: relative;
            width: 100%;
            min-height: 320px;
        }
        .chart-dias-wrap {
            position: relative;
            width: 100%;
            height: 220px;
        }
        .chart-semanas-wrap {
            position: relative;
            width: 100%;
            height: 220px;
        }
    </style>
    <script>
    const porCurso = <?= $porCursoJson ?: '{"labels":[],"values":[],"ids":[]}' ?>;
    const porDia = <?= $porDiaJson ?: '{"labels":[],"values":[]}' ?>;
    const porMes = <?= $porMesJson ?: '{"labels":[],"values":[],"semanas":[]}' ?>;
    const cursoSelecionadoId = <?= json_encode(
        (($cursoSelecionado ?? 'todos') !== 'todos') ? (int)$cursoSelecionado : null
    ) ?>;

    const wrapCursos = document.getElementById('chartCursosWrap');
    if (wrapCursos && Array.isArray(porCurso.labels)) {
        wrapCursos.style.height = Math.max(360, porCurso.labels.length * 28) + 'px';
    }

    const maiorRotuloCurso = (porCurso.labels || []).reduce((maior, rotulo) => {
        return Math.max(maior, String(rotulo).length);
    }, 0);
    const larguraEixoCursos = Math.min(520, Math.max(180, maiorRotuloCurso * 7.2));
    const idsCursos = Array.isArray(porCurso.ids) ? porCurso.ids : [];

    function corBarraCurso(media, destacado, haFiltro) {
        // Visao geral: cores cheias. Com filtro: curso selecionado cheio, demais suaves.
        const forte = !haFiltro || destacado;
        if (forte) {
            if (media < 75) {
                return '#c53030';
            }
            if (media < 80) {
                return '#d69e2e';
            }
            return '#2c5282';
        }
        if (media < 75) {
            return 'rgba(197, 48, 48, 0.45)';
        }
        if (media < 80) {
            return 'rgba(214, 158, 46, 0.45)';
        }
        return 'rgba(44, 82, 130, 0.45)';
    }

    const haFiltroCurso = cursoSelecionadoId !== null;

    new Chart(document.getElementById('chartCursos'), {
        type: 'bar',
        data: {
            labels: porCurso.labels,
            datasets: [{
                label: 'Frequência média (%)',
                data: porCurso.values,
                backgroundColor: (porCurso.values || []).map((media, indice) => {
                    const destacado = haFiltroCurso
                        && Number(idsCursos[indice]) === Number(cursoSelecionadoId);
                    return corBarraCurso(media, destacado, haFiltroCurso);
                }),
                borderColor: (porCurso.values || []).map((_, indice) => {
                    const destacado = haFiltroCurso
                        && Number(idsCursos[indice]) === Number(cursoSelecionadoId);
                    return destacado ? '#0b1f33' : 'transparent';
                }),
                borderWidth: (porCurso.values || []).map((_, indice) => {
                    const destacado = haFiltroCurso
                        && Number(idsCursos[indice]) === Number(cursoSelecionadoId);
                    return destacado ? 2 : 0;
                }),
                borderSkipped: false
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { min: 0, max: 100 },
                y: {
                    ticks: {
                        autoSkip: false,
                        font: (ctx) => {
                            const destacado = haFiltroCurso
                                && Number(idsCursos[ctx.index]) === Number(cursoSelecionadoId);
                            return {
                                size: destacado ? 12 : 11,
                                weight: destacado ? '700' : '400'
                            };
                        },
                        color: (ctx) => {
                            const destacado = haFiltroCurso
                                && Number(idsCursos[ctx.index]) === Number(cursoSelecionadoId);
                            return destacado ? '#0b1f33' : '#4a5568';
                        }
                    },
                    afterFit(scale) {
                        scale.width = larguraEixoCursos;
                    }
                }
            },
            plugins: { legend: { display: false } }
        }
    });

    const canvasDias = document.getElementById('chartDias');
    if (canvasDias) {
        new Chart(canvasDias, {
            type: 'bar',
            data: {
                labels: porDia.labels,
                datasets: [{
                    label: 'Faltas',
                    data: porDia.values,
                    backgroundColor: '#c05621',
                    barPercentage: 0.45,
                    categoryPercentage: 0.6,
                    maxBarThickness: 28
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: {
                        ticks: {
                            maxRotation: 45,
                            minRotation: 45,
                            autoSkip: false
                        }
                    },
                    y: { beginAtZero: true }
                }
            }
        });
    }

    const canvasMeses = document.getElementById('chartMeses');
    if (canvasMeses) {
        const semanas = Array.isArray(porMes.semanas) ? porMes.semanas : [];
        const anotacoesMes = {};
        semanas.forEach((item, indice) => {
            const anterior = indice > 0 ? semanas[indice - 1] : null;
            const mudouMes = !anterior || anterior.mes !== item.mes;
            if (!mudouMes) {
                return;
            }
            anotacoesMes['mes_' + indice] = {
                type: 'line',
                xMin: indice - 0.5,
                xMax: indice - 0.5,
                borderColor: 'rgba(26, 54, 93, 0.55)',
                borderWidth: 2,
                label: {
                    display: true,
                    content: item.mes,
                    position: 'start',
                    backgroundColor: 'rgba(26, 54, 93, 0.8)',
                    color: '#fff',
                    font: { size: 10 }
                }
            };
        });

        new Chart(canvasMeses, {
            type: 'line',
            data: {
                labels: porMes.labels,
                datasets: [{
                    label: 'Faltas',
                    data: porMes.values,
                    borderColor: '#2b6cb0',
                    backgroundColor: 'rgba(43, 108, 176, 0.12)',
                    fill: true,
                    tension: 0.25,
                    pointRadius: 3,
                    pointHoverRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    annotation: { annotations: anotacoesMes }
                },
                scales: {
                    x: {
                        ticks: {
                            maxRotation: 60,
                            minRotation: 45,
                            autoSkip: false,
                            font: { size: 10 }
                        }
                    },
                    y: { beginAtZero: true }
                }
            }
        });
    }

    (function () {
        const passo = 15;
        const linhas = Array.from(document.querySelectorAll('#tabelaCriticas .linha-critica'));
        const botao = document.getElementById('btnVerMaisCriticas');
        const contador = document.getElementById('criticasContador');
        let visiveis = Math.min(passo, linhas.length);

        function atualizarContador() {
            if (!contador) {
                return;
            }
            contador.textContent = ' — ' + visiveis + ' de ' + linhas.length;
        }

        atualizarContador();

        if (!botao) {
            return;
        }

        botao.addEventListener('click', function () {
            const proximo = Math.min(visiveis + passo, linhas.length);
            for (let i = visiveis; i < proximo; i++) {
                linhas[i].classList.remove('d-none');
            }
            visiveis = proximo;
            atualizarContador();
            if (visiveis >= linhas.length) {
                botao.remove();
            }
        });
    })();
    </script>
<?php endif; ?>
