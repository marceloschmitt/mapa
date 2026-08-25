<?php

use Mapa\Core\View;

$porCurso = $porCurso ?? [];
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
            na segunda consulta SIGAA — fora de alarmes e e-mails automáticos
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
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($nome, ENT_QUOTES, 'UTF-8') ?></td>
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
