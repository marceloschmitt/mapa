<?php

$envios = $envios ?? [];
$total = (int)($total ?? 0);
$periodo = $periodo ?? 30;
$periodos = $periodos ?? [];

$fmtData = static function (string $valor): string {
    $valor = trim($valor);
    if ($valor === '') {
        return '—';
    }
    $ts = strtotime($valor);
    if ($ts === false) {
        return $valor;
    }

    return date('d/m/Y H:i', $ts);
};
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h4 mb-1">Log de e-mails</h1>
        <p class="text-secondary mb-2">
            E-mails enviados pelo MAPA a alunos, professores e demais usuários.
        </p>
        <span class="badge text-bg-secondary">
            <?= $total ?> envio<?= $total === 1 ? '' : 's' ?>
        </span>
        <a class="ms-2 small" href="<?= htmlspecialchars(url('/configuracoes/email'), ENT_QUOTES, 'UTF-8') ?>">
            Configuração SMTP
        </a>
    </div>
    <form method="get" action="<?= htmlspecialchars(url('/configuracoes/email/log'), ENT_QUOTES, 'UTF-8') ?>">
        <label for="dias" class="form-label mb-0 small text-secondary">Período</label>
        <select class="form-select" id="dias" name="dias" onchange="this.form.submit()" style="min-width: 180px;">
            <?php foreach ($periodos as $valor => $rotulo): ?>
                <?php
                $selecionado = ($periodo === null && (int)$valor === 0)
                    || ($periodo !== null && (int)$valor === (int)$periodo);
                ?>
                <option value="<?= (int)$valor ?>" <?= $selecionado ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string)$rotulo, ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<?php if ($envios === []): ?>
    <div class="alert alert-light border mb-0">
        Nenhum e-mail registrado neste período.
        O log passa a gravar a partir dos próximos envios (alarmes, avisos ao staff e chamadas).
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead>
            <tr>
                <th>Nome</th>
                <th>E-mail</th>
                <th class="text-nowrap">Data/hora</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($envios as $envio): ?>
                <?php
                $nome = trim((string)($envio['nome'] ?? ''));
                $email = trim((string)($envio['email'] ?? ''));
                $assunto = trim((string)($envio['assunto'] ?? ''));
                ?>
                <tr>
                    <td>
                        <?= htmlspecialchars($nome !== '' ? $nome : '—', ENT_QUOTES, 'UTF-8') ?>
                        <?php if ($assunto !== ''): ?>
                            <div class="text-secondary" style="font-size: 0.8rem;">
                                <?= htmlspecialchars($assunto, ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="text-nowrap">
                        <?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td class="text-nowrap">
                        <?= htmlspecialchars($fmtData((string)($envio['enviado_em'] ?? '')), ENT_QUOTES, 'UTF-8') ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($total > count($envios)): ?>
        <p class="text-secondary small mt-3 mb-0">
            Exibindo os <?= count($envios) ?> mais recentes de <?= $total ?>.
        </p>
    <?php endif; ?>
<?php endif; ?>
