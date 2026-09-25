<?php

use Mapa\Core\View;

$porAluno = $porAluno ?? [];
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
            ?>
            <article class="card border-0 shadow-sm">
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
                                Matrícula <?= htmlspecialchars((string)($aluno['matricula'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                ·
                                <?= htmlspecialchars((string)($aluno['nome_curso'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        </div>
                        <span class="badge text-bg-light text-dark border">
                            <?= $nDisc ?>
                            disciplina<?= $nDisc === 1 ? '' : 's' ?>
                        </span>
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
<?php endif; ?>
