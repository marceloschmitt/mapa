<div class="text-center mb-4">
    <img
        src="/logo.png"
        alt="<?= htmlspecialchars($app['short_name'] . ' — ' . $app['full_name'], ENT_QUOTES, 'UTF-8') ?>"
        class="logo-mapa mb-3"
    >
    <p class="text-secondary mb-0">Acesso ao portal</p>
</div>

<?php if (!empty($erro)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if (!empty($sucesso)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($sucesso, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<form method="post" action="<?= htmlspecialchars(url('/login'), ENT_QUOTES, 'UTF-8') ?>" class="vstack gap-3">
    <div>
        <label for="username" class="form-label">Usuário</label>
        <input type="text" class="form-control" id="username" name="username" required autofocus>
    </div>
    <div>
        <label for="password" class="form-label">Senha</label>
        <input type="password" class="form-control" id="password" name="password" required>
    </div>
    <button type="submit" class="btn btn-primary w-100">Entrar</button>
</form>
