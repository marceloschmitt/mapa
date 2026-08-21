<?php

use Mapa\Core\View;

$porCurso = $porCurso ?? [];
$totalAlunos = (int)($totalAlunos ?? 0);
$totalCursos = (int)($totalCursos ?? 0);
$emailsZero = $emailsZero ?? [];
$totalEmailsZero = count($emailsZero);
$emailsZeroCsv = implode(', ', $emailsZero);
$periodo = (string)($periodo ?? '');
$semSeletorCurso = !empty($semSeletorCurso);
$cursoSelecionado = (string)($cursoSelecionado ?? 'todos');
$cursosDisponiveis = $cursosDisponiveis ?? [];
$mostrarBadgeCurso = $semSeletorCurso;
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
            e frequência do curso abaixo de 75%
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
            Nenhum ingressante com frequência do curso abaixo de 75% neste período.
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
                                    ?>
                                    <tr>
                                        <td class="fw-semibold"><?= htmlspecialchars($nome, ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= $email !== '' ? htmlspecialchars($email, ENT_QUOTES, 'UTF-8') : '—' ?></td>
                                        <td><?= htmlspecialchars((string)($linha['matricula'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= $turma !== '' ? htmlspecialchars($turma, ENT_QUOTES, 'UTF-8') : '—' ?></td>
                                        <td class="text-end fw-semibold text-danger">
                                            <?= htmlspecialchars($percentualFmt, ENT_QUOTES, 'UTF-8') ?>
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
