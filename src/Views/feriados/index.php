<?php

$feriados = $feriados ?? [];
$ano = (int)($ano ?? date('Y'));
$anos = $anos ?? [$ano];
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h4 mb-1">Feriados</h1>
        <p class="text-secondary mb-0">
            Datas cadastradas aqui não entram na grade de aulas previstas e não geram
            atraso de chamada. Útil para feriados nacionais, estaduais e recessos do campus.
        </p>
    </div>
    <form method="get" action="<?= htmlspecialchars(url('/configuracoes/feriados'), ENT_QUOTES, 'UTF-8') ?>">
        <label for="ano" class="form-label mb-0 small text-secondary">Ano</label>
        <select class="form-select" id="ano" name="ano" onchange="this.form.submit()" style="min-width: 120px;">
            <?php foreach ($anos as $opcao): ?>
                <option value="<?= (int)$opcao ?>" <?= (int)$opcao === $ano ? 'selected' : '' ?>>
                    <?= (int)$opcao ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<?php if (!empty($sucesso)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($sucesso, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if (!empty($erro)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h2 class="h6 mb-3">Adicionar feriado</h2>
                <form method="post"
                      action="<?= htmlspecialchars(url('/configuracoes/feriados'), ENT_QUOTES, 'UTF-8') ?>"
                      autocomplete="off">
                    <div class="mb-3">
                        <label for="data" class="form-label">Data</label>
                        <input type="date" class="form-control" id="data" name="data" required
                               value="<?= htmlspecialchars(
                                   (int)date('Y') === $ano ? date('Y-m-d') : sprintf('%04d-01-01', $ano),
                                   ENT_QUOTES,
                                   'UTF-8'
                               ) ?>">
                    </div>
                    <div class="mb-3">
                        <label for="descricao" class="form-label">Descrição</label>
                        <input type="text" class="form-control" id="descricao" name="descricao"
                               maxlength="120" placeholder="Ex.: Independência do Brasil"
                               autocomplete="off">
                    </div>
                    <button type="submit" class="btn btn-primary">Incluir</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="p-3 pb-0">
                    <h2 class="h6 mb-0">Feriados de <?= (int)$ano ?></h2>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Data</th>
                                <th>Descrição</th>
                                <th class="text-end pe-3">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($feriados === []): ?>
                                <tr>
                                    <td colspan="3" class="text-secondary px-3">
                                        Nenhum feriado cadastrado neste ano.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($feriados as $feriado): ?>
                                    <?php
                                    $dataIso = (string)$feriado['data'];
                                    $ts = strtotime($dataIso);
                                    $dataFmt = $ts !== false ? date('d/m/Y', $ts) : $dataIso;
                                    ?>
                                    <tr>
                                        <td class="ps-3"><?= htmlspecialchars($dataFmt, ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <?php if (trim((string)$feriado['descricao']) !== ''): ?>
                                                <?= htmlspecialchars((string)$feriado['descricao'], ENT_QUOTES, 'UTF-8') ?>
                                            <?php else: ?>
                                                <span class="text-secondary">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-3">
                                            <form method="post"
                                                  action="<?= htmlspecialchars(url('/configuracoes/feriados/excluir'), ENT_QUOTES, 'UTF-8') ?>"
                                                  class="d-inline"
                                                  onsubmit="return confirm('Remover este feriado?');">
                                                <input type="hidden" name="data" value="<?= htmlspecialchars($dataIso, ENT_QUOTES, 'UTF-8') ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Excluir</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
