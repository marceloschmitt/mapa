<?php

use Mapa\Core\View;

$linhas = $linhas ?? [];
$totais = $totais ?? [
    'populacao' => 0,
    'com_alarme' => 0,
    'alarmes' => 0,
    'taxa_pct' => null,
];
$cobertura = $cobertura_modalidade ?? null;
$maxTaxa = 0.0;
foreach ($linhas as $linha) {
    if ($linha['taxa_pct'] !== null && (float)$linha['taxa_pct'] > $maxTaxa) {
        $maxTaxa = (float)$linha['taxa_pct'];
    }
}
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h4 mb-1">Alarmes por modalidade de ingresso</h1>
        <p class="text-secondary mb-2">
            Proporção de vínculos com pelo menos um alarme na coleta, por
            <code>modalidade_ingresso</code> (cota/acesso do SIGAA).
            <?php if ($coleta !== null): ?>
                <?= htmlspecialchars(View::rotuloColeta($coleta), ENT_QUOTES, 'UTF-8') ?>.
            <?php endif; ?>
        </p>
        <?php if ($cobertura !== null): ?>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="badge text-bg-secondary">
                    Cobertura da modalidade: <?= htmlspecialchars((string)$cobertura, ENT_QUOTES, 'UTF-8') ?>%
                </span>
                <span class="badge text-bg-light text-dark border">
                    Taxa geral: <?= $totais['taxa_pct'] !== null
                        ? htmlspecialchars((string)$totais['taxa_pct'], ENT_QUOTES, 'UTF-8') . '%'
                        : '—' ?>
                </span>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($erro)): ?>
    <div class="alert alert-warning"><?= htmlspecialchars((string)$erro, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if ($linhas === [] && empty($erro)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-secondary">
            Sem dados de frequência nesta coleta.
        </div>
    </div>
<?php elseif ($linhas !== []): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body px-3 pt-3 pb-2">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 3rem;">Cód.</th>
                            <th>Modalidade</th>
                            <th class="text-end">População</th>
                            <th class="text-end">Com alarme</th>
                            <th class="text-end">Alarmes</th>
                            <th style="min-width: 12rem;">Taxa</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($linhas as $linha): ?>
                            <?php
                            $taxa = $linha['taxa_pct'];
                            $largura = ($taxa !== null && $maxTaxa > 0)
                                ? max(2, (int)round(100 * (float)$taxa / $maxTaxa))
                                : 0;
                            $rotuloTaxa = $taxa !== null
                                ? number_format((float)$taxa, 1, ',', '.') . '%'
                                : '—';
                            ?>
                            <tr>
                                <td>
                                    <?php if ((string)$linha['codigo'] !== ''): ?>
                                        <code><?= htmlspecialchars((string)$linha['codigo'], ENT_QUOTES, 'UTF-8') ?></code>
                                    <?php else: ?>
                                        <span class="text-secondary">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars((string)$linha['modalidade'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="text-end"><?= (int)$linha['populacao'] ?></td>
                                <td class="text-end"><?= (int)$linha['com_alarme'] ?></td>
                                <td class="text-end"><?= (int)$linha['alarmes'] ?></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="progress flex-grow-1" style="height: 0.55rem;">
                                            <div class="progress-bar bg-danger" role="progressbar"
                                                 style="width: <?= $largura ?>%;"
                                                 aria-valuenow="<?= $taxa !== null ? (float)$taxa : 0 ?>"
                                                 aria-valuemin="0" aria-valuemax="100"></div>
                                        </div>
                                        <span class="fw-semibold text-nowrap" style="min-width: 3.5rem;">
                                            <?= htmlspecialchars($rotuloTaxa, ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th></th>
                            <th>Total</th>
                            <th class="text-end"><?= (int)$totais['populacao'] ?></th>
                            <th class="text-end"><?= (int)$totais['com_alarme'] ?></th>
                            <th class="text-end"><?= (int)$totais['alarmes'] ?></th>
                            <th>
                                <?= $totais['taxa_pct'] !== null
                                    ? htmlspecialchars(
                                        number_format((float)$totais['taxa_pct'], 1, ',', '.') . '%',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    )
                                    : '—' ?>
                            </th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p class="small text-secondary mb-0 mt-3">
                População = vínculos em frequência na coleta.
                Taxa = (vínculos com ≥1 alarme) / população.
                Sem modalidade = vínculo ainda sem o campo importado da API.
            </p>
        </div>
    </div>
<?php endif; ?>
