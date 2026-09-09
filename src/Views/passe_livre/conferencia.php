<?php

$valido = !empty($valido);
$atestado = is_array($atestado ?? null) ? $atestado : null;

$fmtPct = static function ($valor): string {
    if ($valor === null || $valor === '' || !is_numeric($valor)) {
        return '—';
    }

    return number_format((float)$valor, 1, ',', '.') . '%';
};
?>

<style>
    .login-card { max-width: 720px; }
</style>

<h1 class="h5 mb-3">Conferência de assinatura digital</h1>
<p class="text-secondary small mb-4">
    Verificação de autenticidade do atestado de matrícula / passe livre.
    Confira se o número, a data e as frequências coincidem com o documento apresentado.
</p>

<?php if (!$valido || $atestado === null): ?>
    <div class="alert alert-danger mb-0">
        Código inválido ou documento não encontrado.
    </div>
<?php else: ?>
    <?php
    $disciplinas = $atestado['disciplinas'] ?? [];
    if (!is_array($disciplinas)) {
        $disciplinas = [];
    }
    ?>
    <div class="alert alert-success">
        Documento válido e assinado digitalmente.
    </div>
    <dl class="row small mb-4">
        <dt class="col-sm-4">Número</dt>
        <dd class="col-sm-8"><?= htmlspecialchars((string)$atestado['numero_formatado'], ENT_QUOTES, 'UTF-8') ?></dd>

        <dt class="col-sm-4">Data do documento</dt>
        <dd class="col-sm-8"><?= htmlspecialchars((string)$atestado['data_documento'], ENT_QUOTES, 'UTF-8') ?></dd>

        <dt class="col-sm-4">Assinado em</dt>
        <dd class="col-sm-8">
            <?= htmlspecialchars(
                \Mapa\Lib\PasseLivreAtestadoPdf::formatarAssinadoEm((string)$atestado['assinado_em']),
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </dd>

        <dt class="col-sm-4">Aluno(a)</dt>
        <dd class="col-sm-8"><?= htmlspecialchars((string)$atestado['nome_aluno'], ENT_QUOTES, 'UTF-8') ?></dd>

        <dt class="col-sm-4">Matrícula</dt>
        <dd class="col-sm-8"><?= htmlspecialchars((string)$atestado['matricula'], ENT_QUOTES, 'UTF-8') ?></dd>

        <dt class="col-sm-4">Curso</dt>
        <dd class="col-sm-8"><?= htmlspecialchars((string)$atestado['nome_curso'], ENT_QUOTES, 'UTF-8') ?></dd>

        <dt class="col-sm-4">Semestre</dt>
        <dd class="col-sm-8"><?= htmlspecialchars((string)$atestado['periodo'], ENT_QUOTES, 'UTF-8') ?></dd>

        <dt class="col-sm-4">Frequência global</dt>
        <dd class="col-sm-8 mb-0">
            <strong><?= htmlspecialchars($fmtPct($atestado['frequencia_geral'] ?? null), ENT_QUOTES, 'UTF-8') ?></strong>
        </dd>
    </dl>

    <h2 class="h6 mb-2">Frequências por disciplina</h2>
    <?php if ($disciplinas === []): ?>
        <p class="text-secondary small mb-0">
            Este documento foi assinado antes do registro das frequências na conferência.
            Emita e assine novamente se precisar conferir os percentuais.
        </p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle mb-0 small bg-white">
                <thead class="table-light">
                    <tr>
                        <th>Semestre</th>
                        <th>Código</th>
                        <th>Disciplina</th>
                        <th class="text-end">Frequência</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($disciplinas as $disc): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$atestado['periodo'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php if (trim((string)($disc['codigo'] ?? '')) !== ''): ?>
                                    <code><?= htmlspecialchars((string)$disc['codigo'], ENT_QUOTES, 'UTF-8') ?></code>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars((string)($disc['nome'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-end">
                                <?= htmlspecialchars($fmtPct($disc['frequencia'] ?? null), ENT_QUOTES, 'UTF-8') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="small text-secondary mt-2 mb-0">
            * A frequência é o percentual de presença em relação ao número de aulas ministradas.
        </p>
    <?php endif; ?>
<?php endif; ?>
