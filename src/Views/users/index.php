<div class="d-flex justify-content-between align-items-center mb-4 gap-2 flex-wrap">
    <div>
        <h1 class="h4 mb-1">Gerência de usuários</h1>
        <p class="text-secondary mb-0">Cadastre usuários, senhas e perfis de acesso.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <form method="post"
              action="<?= htmlspecialchars(url('/usuarios/criar-professores'), ENT_QUOTES, 'UTF-8') ?>"
              class="d-inline"
              onsubmit="return confirm('Criar usuários LDAP (perfil Professor) para os docentes do banco que ainda não têm cadastro?');">
            <button type="submit" class="btn btn-outline-primary">
                Criar usuários dos professores
            </button>
        </form>
        <a href="<?= htmlspecialchars(url('/usuarios/novo'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary">Novo usuário</a>
    </div>
</div>

<?php if (!empty($sucesso)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($sucesso, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if (!empty($erro)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Nome</th>
                    <th>Login</th>
                    <th>E-mail</th>
                    <th>Perfil</th>
                    <th>Status</th>
                    <th class="text-end">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($usuarios === []): ?>
                    <tr>
                        <td colspan="6" class="text-secondary">Nenhum usuário cadastrado.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($usuarios as $usuarioLista): ?>
                        <?php
                        $rotulo = \Mapa\Core\Auth::ROTULOS_PERFIL[$usuarioLista['perfil']] ?? $usuarioLista['perfil'];
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($usuarioLista['nome'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><code><?= htmlspecialchars($usuarioLista['username'], ENT_QUOTES, 'UTF-8') ?></code></td>
                            <td><?= htmlspecialchars((string)$usuarioLista['email'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <span class="badge text-bg-secondary">
                                    <?= htmlspecialchars($rotulo, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td>
                                <?php if ((int)$usuarioLista['ativo'] === 1): ?>
                                    <span class="badge text-bg-success">Ativo</span>
                                <?php else: ?>
                                    <span class="badge text-bg-danger">Inativo</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <a href="<?= htmlspecialchars(url('/usuarios/editar?id=' . (int)$usuarioLista['id']), ENT_QUOTES, 'UTF-8') ?>"
                                   class="btn btn-sm btn-outline-primary">Editar</a>
                                <form method="post" action="<?= htmlspecialchars(url('/usuarios/excluir'), ENT_QUOTES, 'UTF-8') ?>" class="d-inline"
                                      onsubmit="return confirm('Excluir este usuário?');">
                                    <input type="hidden" name="id" value="<?= (int)$usuarioLista['id'] ?>">
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
