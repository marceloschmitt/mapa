<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($app['short_name'] . ' - ' . $app['full_name'], ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars(asset('assets/img/logo-icone.png'), ENT_QUOTES, 'UTF-8') ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(asset('assets/css/mapa.css') . '?v=' . (string)@filemtime('assets/css/mapa.css'), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
</head>
<body>
<?php
$nomeUsuario = trim((string)($usuario['nome'] ?? ''));
$perfilUsuario = \Mapa\Core\Auth::ROTULOS_PERFIL[$usuario['perfil'] ?? '']
    ?? (string)($usuario['perfil'] ?? '');
$authLocal = ($usuario['auth_type'] ?? 'local') === 'local';

$partesNome = preg_split('/\s+/', $nomeUsuario, -1, PREG_SPLIT_NO_EMPTY) ?: [];
$iniciais = '';
if ($partesNome !== []) {
    $iniciais = mb_substr($partesNome[0], 0, 1);
    if (count($partesNome) > 1) {
        $iniciais .= mb_substr($partesNome[count($partesNome) - 1], 0, 1);
    }
}
$iniciais = $iniciais !== '' ? mb_strtoupper($iniciais) : '?';

$caminhoAtual = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$caminhoAtual = preg_replace('#^.*?/index\.php#', '', $caminhoAtual) ?? '';
$caminhoAtual = '/' . trim($caminhoAtual, '/');

$noCaminho = static function (string ...$prefixos) use ($caminhoAtual): bool {
    foreach ($prefixos as $prefixo) {
        if ($caminhoAtual === $prefixo || str_starts_with($caminhoAtual, rtrim($prefixo, '/') . '/')) {
            return true;
        }
    }

    return false;
};

$e = static fn(string $texto): string => htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');

$itensAcompanhamento = [
    ['/alarmes', 'bi-bell', 'Alarmes', true],
    ['/ingressantes', 'bi-person-plus', 'Ingressantes', true],
    ['/chamadas', 'bi-clipboard-check', 'Últimas chamadas', !empty($podeVerChamadas)],
    ['/frequencia-anual', 'bi-calendar3', 'Frequência corrente', !empty($podeVerFrequenciaAnual)],
];
$itensSituacao = [
    ['/trancados', 'bi-pause-circle', 'Alunos trancados', true],
    ['/disciplinas-trancadas', 'bi-journal-x', 'Disciplinas trancadas', true],
    ['/perda-vaga', 'bi-exclamation-triangle', 'Perda de vaga', true],
];
$itensAdmin = [
    ['/usuarios', 'bi-people', 'Usuários', true],
    ['/estatisticas-uso', 'bi-bar-chart', 'Estatísticas de uso', true],
    ['divisor', '', 'Integrações', true],
    ['/configuracoes/api', 'bi-plug', 'API', true],
    ['/configuracoes/ldap', 'bi-diagram-3', 'LDAP', true],
    ['/configuracoes/email', 'bi-envelope', 'E-mail', true],
    ['divisor', '', 'Regras', true],
    ['/configuracoes/coordenacao', 'bi-mortarboard', 'Coordenação', true],
    ['/configuracoes/alarmes', 'bi-sliders', 'Alarmes', true],
    ['/configuracoes/feriados', 'bi-calendar-event', 'Feriados', true],
];

$grupoAtivo = static function (array $itens) use ($noCaminho): bool {
    foreach ($itens as [$caminho, , , $visivel]) {
        if ($visivel && $caminho !== 'divisor' && $noCaminho($caminho)) {
            return true;
        }
    }

    return false;
};

$renderItens = static function (array $itens) use ($noCaminho, $e): string {
    $html = '';
    foreach ($itens as [$caminho, $icone, $rotulo, $visivel]) {
        if (!$visivel) {
            continue;
        }
        if ($caminho === 'divisor') {
            $html .= '<li><hr class="dropdown-divider"></li>'
                . '<li><h6 class="dropdown-header">' . $e($rotulo) . '</h6></li>';
            continue;
        }
        $ativo = $noCaminho($caminho);
        $html .= '<li><a class="dropdown-item' . ($ativo ? ' active' : '') . '" href="' . $e(url($caminho)) . '"'
            . ($ativo ? ' aria-current="page"' : '') . '>'
            . '<i class="bi ' . $e($icone) . '"></i>' . $e($rotulo) . '</a></li>';
    }

    return $html;
};

$inicioAtivo = $caminhoAtual === '/' || $noCaminho('/analytics');
$passeLivreAtivo = $noCaminho('/passe-livre');
?>
<nav class="navbar navbar-expand-xl topo-mapa mb-4">
    <div class="container">
        <a class="navbar-brand py-1" href="<?= $e(url('/')) ?>">
            <img src="<?= $e(asset('assets/img/logo-icone.png')) ?>" alt="">
            <span class="marca-texto">
                <span class="marca-sigla">M<span class="marca-a">A</span>PA</span>
                <span class="marca-nome"><?= $e($app['full_name']) ?></span>
            </span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMapa"
                aria-controls="navMapa" aria-expanded="false" aria-label="Abrir menu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMapa">
            <ul class="navbar-nav me-auto ms-xl-3">
                <li class="nav-item">
                    <a class="nav-link<?= $inicioAtivo ? ' active' : '' ?>" href="<?= $e(url('/')) ?>"
                        <?= $inicioAtivo ? 'aria-current="page"' : '' ?>>Início</a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle<?= $grupoAtivo($itensAcompanhamento) ? ' active' : '' ?>"
                       href="#" id="navAcompanhamento" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        Acompanhamento
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="navAcompanhamento">
                        <?= $renderItens($itensAcompanhamento) ?>
                    </ul>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle<?= $grupoAtivo($itensSituacao) ? ' active' : '' ?>"
                       href="#" id="navSituacao" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        Situação acadêmica
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="navSituacao">
                        <?= $renderItens($itensSituacao) ?>
                    </ul>
                </li>
                <?php if (!empty($podeVerPasseLivre)): ?>
                    <li class="nav-item">
                        <a class="nav-link<?= $passeLivreAtivo ? ' active' : '' ?>" href="<?= $e(url('/passe-livre')) ?>"
                            <?= $passeLivreAtivo ? 'aria-current="page"' : '' ?>>Passe livre</a>
                    </li>
                <?php endif; ?>
                <?php if (!empty($isAdmin)): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle<?= $grupoAtivo($itensAdmin) ? ' active' : '' ?>"
                           href="#" id="navAdmin" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Administração
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="navAdmin">
                            <?= $renderItens($itensAdmin) ?>
                        </ul>
                    </li>
                <?php endif; ?>
            </ul>

            <?php if (!empty($usuario)): ?>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle conta-link" href="#" id="navConta" role="button"
                           data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="avatar-iniciais"><?= $e($iniciais) ?></span>
                            <span><?= $e($nomeUsuario !== '' ? $nomeUsuario : 'Conta') ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navConta">
                            <?php if ($perfilUsuario !== ''): ?>
                                <li><h6 class="dropdown-header"><?= $e($perfilUsuario) ?></h6></li>
                            <?php endif; ?>
                            <?php if ($authLocal): ?>
                                <li>
                                    <a class="dropdown-item" href="<?= $e(url('/conta/senha')) ?>">
                                        <i class="bi bi-key"></i>Minha senha
                                    </a>
                                </li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item" href="<?= $e(url('/logout')) ?>">
                                    <i class="bi bi-box-arrow-right"></i>Sair
                                </a>
                            </li>
                        </ul>
                    </li>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</nav>

<main class="container pb-5">
    <?= $content ?>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
