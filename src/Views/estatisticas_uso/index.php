<?php

$stats = $stats ?? [
    'dias' => 30,
    'desde' => null,
    'total_acessos' => 0,
    'total_logins' => 0,
    'usuarios_unicos' => 0,
    'por_perfil' => [],
    'por_usuario' => [],
    'por_rota' => [],
    'por_dia' => [],
    'emails' => [
        'alunos' => 0,
        'staff_total' => 0,
        'staff_professor' => 0,
        'staff_coordenador' => 0,
        'chamadas' => 0,
    ],
];
$periodo = $periodo ?? 30;
$periodos = $periodos ?? [];
$emails = $stats['emails'];

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

$fmtDia = static function (string $valor): string {
    $valor = trim($valor);
    if ($valor === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
        return $valor !== '' ? $valor : '—';
    }

    return date('d/m/Y', strtotime($valor) ?: time());
};
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h4 mb-1">Estatísticas de uso</h1>
        <p class="text-secondary mb-0">
            Acessos ao MAPA e e-mails enviados
            <?php if (!empty($stats['desde'])): ?>
                (desde <?= htmlspecialchars($fmtDia((string)$stats['desde']), ENT_QUOTES, 'UTF-8') ?>).
            <?php else: ?>
                (todo o histórico disponível).
            <?php endif; ?>
        </p>
    </div>
    <form method="get" action="<?= htmlspecialchars(url('/estatisticas-uso'), ENT_QUOTES, 'UTF-8') ?>">
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

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-secondary small">Acessos</div>
                <div class="fs-3 fw-semibold"><?= (int)$stats['total_acessos'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-secondary small">Logins</div>
                <div class="fs-3 fw-semibold"><?= (int)$stats['total_logins'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-secondary small">Usuários distintos</div>
                <div class="fs-3 fw-semibold"><?= (int)$stats['usuarios_unicos'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-secondary small">E-mails (total)</div>
                <div class="fs-3 fw-semibold">
                    <?= (int)$emails['alunos'] + (int)$emails['staff_total'] + (int)$emails['chamadas'] ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h2 class="h6 mb-3">E-mails enviados</h2>
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr>
                            <td>Alunos (alarmes)</td>
                            <td class="text-end fw-semibold"><?= (int)$emails['alunos'] ?></td>
                        </tr>
                        <tr>
                            <td>Professores (alarmes)</td>
                            <td class="text-end fw-semibold"><?= (int)$emails['staff_professor'] ?></td>
                        </tr>
                        <tr>
                            <td>Coordenadores (alarmes)</td>
                            <td class="text-end fw-semibold"><?= (int)$emails['staff_coordenador'] ?></td>
                        </tr>
                        <tr>
                            <td>Staff (total alarmes)</td>
                            <td class="text-end fw-semibold"><?= (int)$emails['staff_total'] ?></td>
                        </tr>
                        <tr>
                            <td>Professores (chamadas atrasadas)</td>
                            <td class="text-end fw-semibold"><?= (int)$emails['chamadas'] ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h2 class="h6 mb-3">Acessos por tipo de usuário</h2>
                <?php if ($stats['por_perfil'] === []): ?>
                    <p class="text-secondary mb-0 small">Ainda não há acessos registrados neste período.</p>
                <?php else: ?>
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Perfil</th>
                                <th class="text-end">Usuários</th>
                                <th class="text-end">Acessos</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['por_perfil'] as $linha): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string)$linha['rotulo'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="text-end"><?= (int)$linha['usuarios'] ?></td>
                                    <td class="text-end"><?= (int)$linha['acessos'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-0">
                <div class="p-3 pb-0">
                    <h2 class="h6 mb-0">O que acessam</h2>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Página</th>
                                <th class="text-end">Acessos</th>
                                <th class="text-end">Usuários</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($stats['por_rota'] === []): ?>
                                <tr>
                                    <td colspan="3" class="text-secondary px-3">Sem dados.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($stats['por_rota'] as $linha): ?>
                                    <tr>
                                        <td class="px-3">
                                            <?= htmlspecialchars((string)$linha['rotulo'], ENT_QUOTES, 'UTF-8') ?>
                                            <div class="small text-secondary">
                                                <?= htmlspecialchars((string)$linha['rota'], ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        </td>
                                        <td class="text-end"><?= (int)$linha['acessos'] ?></td>
                                        <td class="text-end pe-3"><?= (int)$linha['usuarios'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-0">
                <div class="p-3 pb-0">
                    <h2 class="h6 mb-0">Acessos por dia</h2>
                </div>
                <div class="table-responsive" style="max-height: 28rem; overflow: auto;">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="px-3">Dia</th>
                                <th class="text-end">Acessos</th>
                                <th class="text-end pe-3">Logins</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($stats['por_dia'] === []): ?>
                                <tr>
                                    <td colspan="3" class="text-secondary px-3">Sem dados.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($stats['por_dia'] as $linha): ?>
                                    <tr>
                                        <td class="px-3"><?= htmlspecialchars($fmtDia((string)$linha['dia']), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="text-end"><?= (int)$linha['acessos'] ?></td>
                                        <td class="text-end pe-3"><?= (int)$linha['logins'] ?></td>
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

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="p-3 pb-0">
            <h2 class="h6 mb-0">Usuários que usam o sistema</h2>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Nome</th>
                        <th>Login</th>
                        <th>Perfil</th>
                        <th class="text-end">Acessos</th>
                        <th class="text-end">Logins</th>
                        <th class="text-end pe-3">Último acesso</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($stats['por_usuario'] === []): ?>
                        <tr>
                            <td colspan="6" class="text-secondary px-3">
                                Ainda não há logs de acesso. Eles passam a ser gravados a partir de agora.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($stats['por_usuario'] as $linha): ?>
                            <?php
                            $perfil = (string)$linha['perfil'];
                            $rotuloPerfil = \Mapa\Core\Auth::ROTULOS_PERFIL[$perfil] ?? $perfil;
                            ?>
                            <tr>
                                <td class="ps-3"><?= htmlspecialchars((string)$linha['nome'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string)$linha['username'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string)$rotuloPerfil, ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="text-end"><?= (int)$linha['acessos'] ?></td>
                                <td class="text-end"><?= (int)$linha['logins'] ?></td>
                                <td class="text-end pe-3">
                                    <?= htmlspecialchars($fmtData((string)$linha['ultimo_acesso']), ENT_QUOTES, 'UTF-8') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
