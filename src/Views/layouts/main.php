<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($app['short_name'] . ' - ' . $app['full_name'], ENT_QUOTES, 'UTF-8') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f0f4f8; }
        .navbar-mapa { background: linear-gradient(135deg, #1a365d, #2c5282); }
        .navbar-mapa .navbar-brand,
        .navbar-mapa .nav-link,
        .navbar-mapa .navbar-text { color: #fff !important; }
        .navbar-mapa .nav-link.active { font-weight: 600; text-decoration: underline; }
        .report-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            background: #ebf4ff;
            color: #1a365d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.85rem;
        }
        .perfil-badge { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; }
        .navbar-mapa .dropdown-menu { min-width: 12rem; }
        .navbar-mapa .dropdown-item { color: #1a365d; }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark navbar-mapa mb-4">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= htmlspecialchars(url('/'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($app['short_name'], ENT_QUOTES, 'UTF-8') ?></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMapa">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMapa">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link" href="<?= htmlspecialchars(url('/'), ENT_QUOTES, 'UTF-8') ?>">Relatórios</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= htmlspecialchars(url('/analytics'), ENT_QUOTES, 'UTF-8') ?>">Relatório geral</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= htmlspecialchars(url('/alarmes'), ENT_QUOTES, 'UTF-8') ?>">Alarmes</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= htmlspecialchars(url('/ingressantes'), ENT_QUOTES, 'UTF-8') ?>">Ingressantes</a>
                </li>
                <?php if (!empty($podeVerChamadas)): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= htmlspecialchars(url('/chamadas'), ENT_QUOTES, 'UTF-8') ?>">Chamadas</a>
                    </li>
                <?php endif; ?>
                <?php if (!empty($isAdmin)): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navConfig" role="button"
                           data-bs-toggle="dropdown" aria-expanded="false">
                            Configurações
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="navConfig">
                            <li>
                                <a class="dropdown-item" href="<?= htmlspecialchars(url('/usuarios'), ENT_QUOTES, 'UTF-8') ?>">Usuários</a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="<?= htmlspecialchars(url('/configuracoes/api'), ENT_QUOTES, 'UTF-8') ?>">API</a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="<?= htmlspecialchars(url('/configuracoes/ldap'), ENT_QUOTES, 'UTF-8') ?>">LDAP</a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="<?= htmlspecialchars(url('/configuracoes/email'), ENT_QUOTES, 'UTF-8') ?>">E-mail</a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="<?= htmlspecialchars(url('/configuracoes/coordenacao'), ENT_QUOTES, 'UTF-8') ?>">Coordenação</a>
                            </li>
                        </ul>
                    </li>
                <?php endif; ?>
            </ul>
            <?php if (!empty($usuario)): ?>
                <span class="navbar-text me-3">
                    <?= htmlspecialchars($usuario['nome'], ENT_QUOTES, 'UTF-8') ?>
                    <span class="badge bg-light text-dark perfil-badge ms-1">
                        <?= htmlspecialchars(
                            \Mapa\Core\Auth::ROTULOS_PERFIL[$usuario['perfil']] ?? $usuario['perfil'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </span>
                </span>
                <?php if (($usuario['auth_type'] ?? 'local') === 'local'): ?>
                    <a class="nav-link me-2" href="<?= htmlspecialchars(url('/conta/senha'), ENT_QUOTES, 'UTF-8') ?>">Minha senha</a>
                <?php endif; ?>
                <a class="nav-link" href="<?= htmlspecialchars(url('/logout'), ENT_QUOTES, 'UTF-8') ?>">Sair</a>
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
