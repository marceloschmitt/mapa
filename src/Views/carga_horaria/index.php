<?php

$resumo = $resumo ?? ['total' => 0, 'com_carga' => 0, 'sem_carga' => 0, 'atualizado_em' => null];
$disciplinas = $disciplinas ?? [];
$cursos = $cursos ?? [];
$cursoSelecionado = (string)($cursoSelecionado ?? '');
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h4 mb-1">Carga horária das disciplinas</h1>
        <p class="text-secondary mb-0">
            Catálogo montado a partir do campo <code>carga_horaria</code> da API de alunos
            nos 4 últimos semestres. Valores nulos (TCC, dissertação etc.) são mantidos.
        </p>
    </div>
    <div class="text-md-end">
        <form method="post" action="<?= htmlspecialchars(url('/configuracoes/carga-horaria/gerar'), ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="btn btn-primary">Atualizar carga horária</button>
        </form>
        <p class="small text-secondary mb-0 mt-1" style="max-width: 280px;">
            Executa <code>gerar_carga_horaria.py</code> em segundo plano. Acompanhe
            <code>data/carga_horaria.log</code>.
        </p>
    </div>
</div>

<?php if (!empty($sucesso)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($sucesso, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if (!empty($erro)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="d-flex flex-wrap align-items-end gap-3 mb-3">
    <form method="get" action="<?= htmlspecialchars(url('/configuracoes/carga-horaria'), ENT_QUOTES, 'UTF-8') ?>"
          class="d-flex flex-wrap align-items-end gap-2">
        <div>
            <label for="curso" class="form-label mb-0 small text-secondary">Curso</label>
            <select class="form-select" id="curso" name="curso" style="min-width: 280px;"
                    onchange="this.form.submit()">
                <option value="">Todos os cursos</option>
                <?php foreach ($cursos as $curso): ?>
                    <option value="<?= htmlspecialchars((string)$curso, ENT_QUOTES, 'UTF-8') ?>"
                        <?= $cursoSelecionado === (string)$curso ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string)$curso, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($cursoSelecionado !== ''): ?>
            <a class="btn btn-outline-secondary" href="<?= htmlspecialchars(url('/configuracoes/carga-horaria'), ENT_QUOTES, 'UTF-8') ?>">
                Limpar filtro
            </a>
        <?php endif; ?>
    </form>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-secondary small">Disciplinas</div>
                <div class="fs-4 fw-semibold"><?= (int)$resumo['total'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-secondary small">Com carga horária</div>
                <div class="fs-4 fw-semibold"><?= (int)$resumo['com_carga'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-secondary small">Sem carga horária</div>
                <div class="fs-4 fw-semibold"><?= (int)$resumo['sem_carga'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-secondary small">Última atualização</div>
                <div class="fs-6 fw-semibold">
                    <?= $resumo['atualizado_em']
                        ? htmlspecialchars((string)$resumo['atualizado_em'], ENT_QUOTES, 'UTF-8')
                        : '—' ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="p-3 pb-0 d-flex justify-content-between align-items-center gap-2 flex-wrap">
            <h2 class="h6 mb-0">Disciplinas cadastradas</h2>
            <span class="small text-secondary">Exibindo até 500 registros</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Código</th>
                        <th>Disciplina</th>
                        <?php if ($cursoSelecionado === ''): ?>
                            <th>Curso</th>
                        <?php endif; ?>
                        <th class="text-end">Carga horária (h)</th>
                        <th>Origem</th>
                        <th class="pe-3">Atualizado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($disciplinas === []): ?>
                        <tr>
                            <td colspan="<?= $cursoSelecionado === '' ? 6 : 5 ?>" class="text-secondary ps-3 py-4">
                                Nenhuma disciplina ainda<?= $cursoSelecionado !== '' ? ' neste curso' : '' ?>.
                                <?php if ($cursoSelecionado === ''): ?>
                                    Use o botão para gerar a partir da API.
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($disciplinas as $row): ?>
                            <tr>
                                <td class="ps-3 font-monospace">
                                    <?= htmlspecialchars((string)($row['codigo_disciplina'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td><?= htmlspecialchars((string)($row['disciplina'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <?php if ($cursoSelecionado === ''): ?>
                                    <td class="small">
                                        <?= htmlspecialchars((string)($row['nome_curso'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                <?php endif; ?>
                                <td class="text-end">
                                    <?php if ($row['carga_horaria'] === null || $row['carga_horaria'] === ''): ?>
                                        <span class="text-secondary">null</span>
                                    <?php else: ?>
                                        <?= (int)$row['carga_horaria'] ?>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-secondary">
                                    <?= htmlspecialchars((string)($row['origem_periodo'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td class="pe-3 small text-secondary">
                                    <?= htmlspecialchars((string)($row['atualizado_em'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
