<div class="text-center mb-4">
    <img
        src="/logo.png"
        alt="<?= htmlspecialchars($app['short_name'] . ' — ' . $app['full_name'], ENT_QUOTES, 'UTF-8') ?>"
        class="logo-mapa mb-3"
    >
    <p class="text-secondary mb-0">Defina a senha do administrador para começar a usar o portal.</p>
</div>

<?php if (!empty($erro)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<form method="post" action="<?= htmlspecialchars(url('/setup'), ENT_QUOTES, 'UTF-8') ?>" class="vstack gap-3">
    <div>
        <label class="form-label">Usuário</label>
        <input type="text" class="form-control" value="<?= htmlspecialchars((string)$admin['username'], ENT_QUOTES, 'UTF-8') ?>" readonly>
    </div>
    <div>
        <label for="senha_nova" class="form-label">Senha</label>
        <input type="password" class="form-control" id="senha_nova" name="senha_nova" minlength="6" required autofocus>
    </div>
    <div>
        <label for="senha_confirma" class="form-label">Confirmar senha</label>
        <input type="password" class="form-control" id="senha_confirma" name="senha_confirma" minlength="6" required>
    </div>
    <button type="submit" class="btn btn-primary w-100">Salvar e entrar</button>
</form>
