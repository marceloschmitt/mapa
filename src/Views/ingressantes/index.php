<?php

use Mapa\Core\View;

$porCurso = $porCurso ?? [];
$dadosFrequenciaCorrente = $dadosFrequenciaCorrente ?? [];
$totalAlunos = (int)($totalAlunos ?? 0);
$totalCursos = (int)($totalCursos ?? 0);
$emailsZero = $emailsZero ?? [];
$totalEmailsZero = count($emailsZero);
$emailsZeroCsv = implode(', ', $emailsZero);
$periodo = (string)($periodo ?? '');
$periodoAnual = (string)($periodoAnual ?? '');
$semSeletorCurso = !empty($semSeletorCurso);
$cursoSelecionado = (string)($cursoSelecionado ?? 'todos');
$cursosDisponiveis = $cursosDisponiveis ?? [];
$mostrarBadgeCurso = $semSeletorCurso;

// Limite de frequencia configurado em Configuracoes -> Alarmes.
$alarmeConfig = $alarmeConfig ?? [];
$limiteFrequencia = (float)($alarmeConfig['frequencia_limite'] ?? 75);
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h4 mb-1">Ingressantes com problema de frequência</h1>
        <?php if ($mostrarBadgeCurso): ?>
            <p class="mb-1">
                <span class="badge text-bg-primary text-wrap text-start fw-normal" style="font-size: 0.85rem;">
                    <?= htmlspecialchars($cursoExibido ?? 'Todos os cursos', ENT_QUOTES, 'UTF-8') ?>
                </span>
            </p>
        <?php endif; ?>
        <p class="text-secondary mb-2">
            Alunos com ingresso em
            <strong><?= htmlspecialchars($periodo !== '' ? $periodo : '—', ENT_QUOTES, 'UTF-8') ?></strong>
            <?php if ($periodoAnual !== ''): ?>
                (integrados: <strong><?= htmlspecialchars($periodoAnual, ENT_QUOTES, 'UTF-8') ?></strong>, com frequência anual)
            <?php endif; ?>
            e frequência do curso abaixo de <?= htmlspecialchars(View::rotuloLimite($limiteFrequencia), ENT_QUOTES, 'UTF-8') ?>%
            <?php if ($coleta !== null): ?>
                (<?= htmlspecialchars(View::rotuloColeta($coleta), ENT_QUOTES, 'UTF-8') ?>).
            <?php else: ?>
                .
            <?php endif; ?>
        </p>
        <?php if ($coleta !== null && $periodo !== ''): ?>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="badge text-bg-secondary">
                    <?= $totalAlunos ?> aluno<?= $totalAlunos === 1 ? '' : 's' ?>
                </span>
                <span class="badge text-bg-secondary">
                    <?= $totalCursos ?> curso<?= $totalCursos === 1 ? '' : 's' ?>
                </span>
                <?php if (empty($erro) && empty($avisoCoordenador)): ?>
                    <button type="button"
                            id="btn-copiar-emails-zero"
                            class="btn btn-sm btn-outline-primary"
                            data-emails="<?= htmlspecialchars($emailsZeroCsv, ENT_QUOTES, 'UTF-8') ?>"
                            <?= $totalEmailsZero === 0 ? 'disabled' : '' ?>>
                        Copiar e-mails 0%
                        <?php if ($totalEmailsZero > 0): ?>
                            (<?= $totalEmailsZero ?>)
                        <?php endif; ?>
                    </button>
                    <span id="status-copiar-emails-zero" class="small text-secondary" aria-live="polite"></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php if (!$semSeletorCurso && $cursosDisponiveis !== []): ?>
        <form method="get" action="<?= htmlspecialchars(url('/ingressantes'), ENT_QUOTES, 'UTF-8') ?>" class="d-flex align-items-center gap-2">
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

<?php if (!empty($avisoCoordenador)): ?>
    <div class="alert alert-warning"><?= htmlspecialchars($avisoCoordenador, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if (!empty($erro)): ?>
    <div class="alert alert-warning"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if ($porCurso === [] && empty($erro) && empty($avisoCoordenador)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-secondary">
            Nenhum ingressante com frequência do curso abaixo de
            <?= htmlspecialchars(View::rotuloLimite($limiteFrequencia), ENT_QUOTES, 'UTF-8') ?>% neste período.
        </div>
    </div>
<?php else: ?>
    <div class="d-flex flex-column gap-4">
        <?php foreach ($porCurso as $grupo): ?>
            <?php
            $linhasCurso = $grupo['linhas'] ?? [];
            $qtdAlunos = (int)($grupo['total_alunos'] ?? 0);
            ?>
            <section class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 pt-3 pb-0 px-3">
                    <h2 class="h6 mb-1"><?= htmlspecialchars((string)$grupo['nome_curso'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <p class="small text-secondary mb-0">
                        <?= $qtdAlunos ?> aluno<?= $qtdAlunos === 1 ? '' : 's' ?> ingressante<?= $qtdAlunos === 1 ? '' : 's' ?>
                    </p>
                </div>
                <div class="card-body px-3 pt-2">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th>Aluno</th>
                                    <th>E-mail</th>
                                    <th>Matrícula</th>
                                    <th>Turma</th>
                                    <th class="text-end">Frequência</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($linhasCurso as $linha): ?>
                                    <?php
                                    $nomeSocial = trim((string)($linha['aluno_nome_social'] ?? ''));
                                    $nome = $nomeSocial !== '' ? $nomeSocial : (string)($linha['aluno_nome'] ?? '');
                                    $email = trim((string)($linha['aluno_email'] ?? ''));
                                    $turma = trim((string)($linha['turma_entrada'] ?? ''));
                                    $percentual = $linha['percentual_frequencia'] ?? null;
                                    $percentualFmt = is_numeric($percentual)
                                        ? number_format((float)$percentual, 1, ',', '.') . '%'
                                        : '—';
                                    $desde = \DateTimeImmutable::createFromFormat(
                                        '!Y-m-d',
                                        trim((string)($linha['frequencia_desde'] ?? ''))
                                    );
                                    $chaveFreqAluno = (int)($linha['aluno_id'] ?? 0) . '-' . (int)($linha['curso_id'] ?? 0);
                                    $temFreqCorrente = isset($dadosFrequenciaCorrente[$chaveFreqAluno]);
                                    ?>
                                    <tr>
                                        <td class="fw-semibold">
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
                                        <td><?= $email !== '' ? htmlspecialchars($email, ENT_QUOTES, 'UTF-8') : '—' ?></td>
                                        <td><?= htmlspecialchars((string)($linha['matricula'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= $turma !== '' ? htmlspecialchars($turma, ENT_QUOTES, 'UTF-8') : '—' ?></td>
                                        <td class="text-end fw-semibold text-danger">
                                            <?= htmlspecialchars($percentualFmt, ENT_QUOTES, 'UTF-8') ?>
                                            <?php if ($desde !== false): ?>
                                                <div class="small fw-normal text-secondary">
                                                    anual, desde <?= htmlspecialchars($desde->format('d/m/Y'), ENT_QUOTES, 'UTF-8') ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($dadosFrequenciaCorrente !== []): ?>
    <script type="application/json" id="dadosFrequenciaCorrenteIngressantes"><?= json_encode(
        (object)$dadosFrequenciaCorrente,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
    ) ?></script>

    <div class="modal fade" id="modalFrequenciaCorrenteIngressantes" tabindex="-1"
         aria-labelledby="modalFrequenciaCorrenteIngressantesTitulo" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header py-2 px-3 bg-primary text-white">
                    <h2 class="modal-title h6 mb-0" id="modalFrequenciaCorrenteIngressantesTitulo">Frequência por disciplina</h2>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                            aria-label="Fechar"></button>
                </div>
                <div class="modal-body p-3 p-md-4 bg-light">
                    <div class="atestado-passe-livre mx-auto bg-white border shadow-sm p-4 p-md-5">
                        <p class="small text-justify mb-4" id="modalFrequenciaCorrenteIngressantesTexto"></p>
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
                                <tbody id="modalFrequenciaCorrenteIngressantesDisciplinas"></tbody>
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
        const modalFreqEl = document.getElementById('modalFrequenciaCorrenteIngressantes');
        const dadosFreqEl = document.getElementById('dadosFrequenciaCorrenteIngressantes');
        if (!modalFreqEl || !dadosFreqEl || typeof bootstrap === 'undefined') {
            return;
        }
        const textoFreq = document.getElementById('modalFrequenciaCorrenteIngressantesTexto');
        const tbodyFreq = document.getElementById('modalFrequenciaCorrenteIngressantesDisciplinas');
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

<?php if ($totalEmailsZero > 0): ?>
<script>
(() => {
    const botao = document.getElementById('btn-copiar-emails-zero');
    const status = document.getElementById('status-copiar-emails-zero');
    if (!botao) {
        return;
    }

    const copiarFallback = (texto) => {
        const area = document.createElement('textarea');
        area.value = texto;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.left = '-9999px';
        document.body.appendChild(area);
        area.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(area);
        return ok;
    };

    botao.addEventListener('click', async () => {
        const emails = botao.getAttribute('data-emails') || '';
        if (!emails) {
            if (status) {
                status.textContent = 'Nenhum e-mail para copiar.';
            }
            return;
        }

        let copiado = false;
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(emails);
                copiado = true;
            } else {
                copiado = copiarFallback(emails);
            }
        } catch (_erro) {
            copiado = copiarFallback(emails);
        }

        if (status) {
            status.textContent = copiado
                ? 'E-mails copiados.'
                : 'Não foi possível copiar.';
            status.classList.toggle('text-success', copiado);
            status.classList.toggle('text-danger', !copiado);
        }
    });
})();
</script>
<?php endif; ?>
