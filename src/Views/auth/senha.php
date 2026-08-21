<div class="mb-4">
    <h1 class="h4 mb-1">Minha senha</h1>
    <p class="text-secondary mb-0">Altere a senha de acesso ao portal MAPA.</p>
</div>

<?php if (!empty($sucesso)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($sucesso, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if (!empty($erro)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm" style="max-width: 420px;">
    <div class="card-body">
        <form method="post" action="<?= htmlspecialchars(url('/conta/senha'), ENT_QUOTES, 'UTF-8') ?>" class="vstack gap-3">
            <div>
                <label for="senha_atual" class="form-label">Senha atual</label>
                <input type="password" class="form-control" id="senha_atual" name="senha_atual" required>
            </div>
            <div>
                <label for="senha_nova" class="form-label">Nova senha</label>
                <input type="password" class="form-control" id="senha_nova" name="senha_nova" minlength="6" required>
            </div>
            <div>
                <label for="senha_confirma" class="form-label">Confirmar nova senha</label>
                <input type="password" class="form-control" id="senha_confirma" name="senha_confirma" minlength="6" required>
            </div>
            <button type="submit" class="btn btn-primary">Salvar senha</button>
        </form>
    </div>
</div>
