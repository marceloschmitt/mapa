<?php

$porCurso = $porCurso ?? [];
$totalAlunos = (int)($totalAlunos ?? 0);
$totalCursos = (int)($totalCursos ?? 0);
$totalReprovacoes = (int)($totalReprovacoes ?? 0);
$semSeletorCurso = !empty($semSeletorCurso);
$cursoSelecionado = (string)($cursoSelecionado ?? 'todos');
$cursosDisponiveis = $cursosDisponiveis ?? [];
$execucao = $execucao ?? null;
$mostrarBadgeCurso = $semSeletorCurso;
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h4 mb-1">Candidatos a perda de vaga</h1>
        <?php if ($mostrarBadgeCurso): ?>
            <p class="mb-1">
                <span class="badge text-bg-primary text-wrap text-start fw-normal" style="font-size: 0.85rem;">
                    <?= htmlspecialchars($cursoExibido ?? 'Todos os cursos', ENT_QUOTES, 'UTF-8') ?>
                </span>
            </p>
        <?php endif; ?>
        <p class="text-secondary mb-2">
            Alunos que reprovaram em <strong>todas</strong> as disciplinas nos dois semestres
            anteriores ao período atual
            <?php if (is_array($execucao)): ?>
                (<?= htmlspecialchars((string)$execucao['semestre_a'], ENT_QUOTES, 'UTF-8') ?>
                e <?= htmlspecialchars((string)$execucao['semestre_b'], ENT_QUOTES, 'UTF-8') ?>;
                referência <?= htmlspecialchars((string)$execucao['periodo_atual'], ENT_QUOTES, 'UTF-8') ?>).
            <?php else: ?>
                .
            <?php endif; ?>
        </p>
        <?php if (is_array($execucao)): ?>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="badge text-bg-secondary">
                    <?= $totalAlunos ?> aluno<?= $totalAlunos === 1 ? '' : 's' ?>
                </span>
                <span class="badge text-bg-secondary">
                    <?= $totalCursos ?> curso<?= $totalCursos === 1 ? '' : 's' ?>
                </span>
                <span class="badge text-bg-secondary">
                    <?= $totalReprovacoes ?> reprovaç<?= $totalReprovacoes === 1 ? 'ão' : 'ões' ?>
                </span>
                <span class="badge text-bg-light text-dark border">
                    Gerado em <?= htmlspecialchars((string)($execucao['executado_em'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
        <?php endif; ?>
    </div>
    <?php if (!$semSeletorCurso && $cursosDisponiveis !== []): ?>
        <form method="get" action="<?= htmlspecialchars(url('/perda-vaga'), ENT_QUOTES, 'UTF-8') ?>" class="d-flex align-items-center gap-2">
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

<?php if (is_array($execucao) && empty($erro) && empty($avisoCoordenador) && $porCurso === []): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-secondary">
            Nenhum candidato a perda de vaga nesta análise.
        </div>
    </div>
<?php endif; ?>

<?php foreach ($porCurso as $grupo): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white border-0 pt-3 pb-0 px-3">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <h2 class="h6 mb-1"><?= htmlspecialchars((string)$grupo['nome_curso'], ENT_QUOTES, 'UTF-8') ?></h2>
                <span class="badge text-bg-light text-dark border">
                    <?= count($grupo['candidatos'] ?? []) ?>
                    aluno<?= count($grupo['candidatos'] ?? []) === 1 ? '' : 's' ?>
                </span>
            </div>
        </div>
        <div class="card-body pt-3 px-3 pb-3">
            <?php foreach ($grupo['candidatos'] as $candidato): ?>
                <?php
                $nomeSocial = trim((string)($candidato['nome_social'] ?? ''));
                $nome = $nomeSocial !== ''
                    ? $nomeSocial
                    : trim((string)($candidato['nome'] ?? ''));
                $reprovacoes = $candidato['reprovacoes'] ?? [];
                ?>
                <div class="border rounded mb-3 p-3">
                    <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                        <div>
                            <div class="fw-semibold"><?= htmlspecialchars($nome, ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="small text-secondary">
                                Matrícula <?= htmlspecialchars((string)($candidato['matricula'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                <?php if (!empty($candidato['email'])): ?>
                                    · <?= htmlspecialchars((string)$candidato['email'], ENT_QUOTES, 'UTF-8') ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php if ($reprovacoes !== []): ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0 small">
                                <thead class="table-light">
                                    <tr>
                                        <th>Semestre</th>
                                        <th>Disciplina</th>
                                        <th>Causa</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($reprovacoes as $rep): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string)($rep['semestre'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string)($rep['disciplina'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td>
                                                <span class="badge text-bg-danger">
                                                    <?= htmlspecialchars((string)($rep['causa'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endforeach; ?>
