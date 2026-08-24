<?php
$cursos = $cursos ?? [];
?>

<div class="mb-4">
    <h1 class="h4 mb-1">E-mails de coordenação</h1>
    <p class="text-secondary mb-0">
        Cada curso pode ter um endereço institucional da coordenação. Esse e-mail recebe os avisos
        automáticos de alarmes (quando configurado). Os usuários coordenadores vinculados ao curso
        aparecem apenas como referência — o cadastro deles continua em Usuários.
    </p>
</div>

<?php if (!empty($sucesso)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($sucesso, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if (!empty($erro)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if ($cursos === []): ?>
    <div class="alert alert-warning">
        Nenhum curso cadastrado. Rode a coleta (<code>executar_coleta.py</code>) para importar os cursos.
    </div>
<?php else: ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="post"
                  action="<?= htmlspecialchars(url('/configuracoes/coordenacao'), ENT_QUOTES, 'UTF-8') ?>"
                  autocomplete="off">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Curso</th>
                                <th scope="col">Coordenadores (usuários)</th>
                                <th scope="col" style="min-width: 18rem;">E-mail da coordenação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cursos as $curso): ?>
                                <?php
                                $cursoId = (int)($curso['id'] ?? 0);
                                $coordenadores = $curso['coordenadores'] ?? [];
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars((string)($curso['nome_curso'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <?php if ($coordenadores === []): ?>
                                            <span class="text-secondary">Nenhum coordenador vinculado</span>
                                        <?php else: ?>
                                            <ul class="list-unstyled mb-0 small">
                                                <?php foreach ($coordenadores as $coord): ?>
                                                    <li>
                                                        <?= htmlspecialchars((string)($coord['nome'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                                        <?php if (trim((string)($coord['email'] ?? '')) !== ''): ?>
                                                            <span class="text-secondary">
                                                                (<?= htmlspecialchars((string)$coord['email'], ENT_QUOTES, 'UTF-8') ?>)
                                                            </span>
                                                        <?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <input type="email"
                                               class="form-control form-control-sm"
                                               name="email_coordenacao[<?= $cursoId ?>]"
                                               value="<?= htmlspecialchars((string)($curso['email_coordenacao'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                               placeholder="coordenacao@exemplo.edu.br"
                                               autocomplete="off">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mt-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Salvar</button>
                    <a href="<?= htmlspecialchars(url('/'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>
