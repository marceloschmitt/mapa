<?php

$disciplinas = $disciplinas ?? [];
$total = (int)($total ?? 0);
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h4 mb-1">Disciplinas sem curso</h1>
        <p class="text-secondary mb-2">
            Disciplinas presentes na grade do SIGAA sem vínculo de curso.
            Não entram no seletor de cursos.
        </p>
        <span class="badge text-bg-secondary">
            <?= $total ?> disciplina<?= $total === 1 ? '' : 's' ?>
        </span>
    </div>
</div>

<?php if ($disciplinas === []): ?>
    <div class="alert alert-light border">Nenhuma disciplina sem curso no momento.</div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead>
            <tr>
                <th>Código</th>
                <th>Disciplina</th>
                <th>Turmas</th>
                <th>Professores</th>
                <th class="text-end">Aulas</th>
                <th>Semestre</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($disciplinas as $linha): ?>
                <tr>
                    <td class="text-nowrap">
                        <?= htmlspecialchars((string)($linha['codigo_disciplina'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td>
                        <?= htmlspecialchars((string)($linha['disciplina'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td>
                        <?= htmlspecialchars((string)($linha['turmas'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td>
                        <?= htmlspecialchars((string)($linha['professores'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td class="text-end">
                        <?= (int)($linha['total_aulas'] ?? 0) ?>
                    </td>
                    <td>
                        <?= htmlspecialchars((string)($linha['semestre_oferta'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
