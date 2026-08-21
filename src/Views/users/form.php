<?php
$usuarioForm = $usuarioForm ?? null;
$editando = is_array($usuarioForm);
$titulo = $editando ? 'Editar usuário' : 'Novo usuário';
$action = $editando ? url('/usuarios/atualizar') : url('/usuarios');
$cursos = $cursos ?? [];
$cursoIdsSelecionados = $cursoIdsSelecionados ?? [];
$rotulos = \Mapa\Core\Auth::ROTULOS_PERFIL;
$valorUsername = $editando ? (string)($usuarioForm['username'] ?? '') : '';
$valorNome = $editando ? (string)($usuarioForm['nome'] ?? '') : '';
$valorEmail = $editando ? (string)($usuarioForm['email'] ?? '') : '';
$valorCpf = $editando ? (string)($usuarioForm['cpf'] ?? '') : '';
$valorPerfil = $editando ? (string)($usuarioForm['perfil'] ?? '') : '';
$valorAuth = $editando ? (string)($usuarioForm['auth_type'] ?? 'local') : '';
$ativoMarcado = $editando ? ((int)($usuarioForm['ativo'] ?? 1) === 1) : true;
?>

<div class="mb-4">
    <a href="<?= htmlspecialchars(url('/usuarios'), ENT_QUOTES, 'UTF-8') ?>" class="text-decoration-none">&larr; Voltar</a>
    <h1 class="h4 mt-2 mb-1"><?= htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="text-secondary mb-0">
        Defina login, tipo de autenticação e perfil de acesso ao portal MAPA.
        Senhas locais são gravadas apenas como hash seguro (nunca em texto aberto).
    </p>
</div>

<?php if (!empty($erro)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="post"
              action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>"
              class="row g-3"
              id="formUsuario"
              autocomplete="off">
            <?php if ($editando): ?>
                <input type="hidden" name="id" value="<?= (int)$usuarioForm['id'] ?>">
            <?php endif; ?>

            <div class="col-md-6">
                <label for="novo_username" class="form-label">Login</label>
                <input type="text"
                       class="form-control"
                       id="novo_username"
                       name="username"
                       value="<?= htmlspecialchars($valorUsername, ENT_QUOTES, 'UTF-8') ?>"
                       autocomplete="off"
                       required>
            </div>

            <div class="col-md-6">
                <label for="novo_nome" class="form-label">Nome</label>
                <input type="text"
                       class="form-control"
                       id="novo_nome"
                       name="nome"
                       value="<?= htmlspecialchars($valorNome, ENT_QUOTES, 'UTF-8') ?>"
                       autocomplete="off"
                       required>
            </div>

            <div class="col-md-6">
                <label for="novo_email" class="form-label">E-mail</label>
                <input type="email"
                       class="form-control"
                       id="novo_email"
                       name="email"
                       value="<?= htmlspecialchars($valorEmail, ENT_QUOTES, 'UTF-8') ?>"
                       autocomplete="off">
            </div>

            <div class="col-md-6">
                <label for="novo_cpf" class="form-label">CPF</label>
                <input type="text"
                       class="form-control"
                       id="novo_cpf"
                       name="cpf"
                       value="<?= htmlspecialchars($valorCpf, ENT_QUOTES, 'UTF-8') ?>"
                       inputmode="numeric"
                       maxlength="14"
                       placeholder="00000000000"
                       autocomplete="off">
                <div class="form-text" id="ajudaCpf">
                    Opcional. 11 dígitos (único entre usuários). Para professores, vincula às disciplinas importadas.
                </div>
            </div>

            <div class="col-md-6">
                <label for="auth_type" class="form-label">Autenticação</label>
                <select class="form-select" id="auth_type" name="auth_type" required>
                    <?php if (!$editando): ?>
                        <option value="" selected disabled>Selecione</option>
                    <?php endif; ?>
                    <option value="local" <?= $valorAuth === 'local' ? 'selected' : '' ?>>Senha local (MAPA)</option>
                    <option value="ldap" <?= $valorAuth === 'ldap' ? 'selected' : '' ?>>LDAP institucional</option>
                </select>
            </div>

            <div class="col-md-6">
                <label for="perfil" class="form-label">Perfil</label>
                <select class="form-select" id="perfil" name="perfil" required>
                    <?php if (!$editando): ?>
                        <option value="" selected disabled>Selecione o perfil</option>
                    <?php endif; ?>
                    <?php foreach ($rotulos as $perfil => $rotulo): ?>
                        <option value="<?= htmlspecialchars($perfil, ENT_QUOTES, 'UTF-8') ?>"
                            <?= $valorPerfil === $perfil ? 'selected' : '' ?>>
                            <?= htmlspecialchars($rotulo, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6" id="blocoSenha">
                <label for="nova_senha_usuario" class="form-label">
                    <?= $editando ? 'Nova senha local (opcional)' : 'Senha local' ?>
                </label>
                <input type="password"
                       class="form-control"
                       id="nova_senha_usuario"
                       name="senha"
                       value=""
                       minlength="6"
                       autocomplete="new-password">
                <div class="form-text" id="ajudaSenha">
                    <?= $editando
                        ? 'Deixe em branco para manter a senha atual (apenas autenticação local).'
                        : 'Obrigatória para autenticação local. Nunca é salva em texto aberto.' ?>
                </div>
            </div>

            <div class="col-12" id="blocoCursos">
                <label for="curso_ids" class="form-label">Cursos do coordenador</label>
                <select class="form-select" id="curso_ids" name="curso_ids[]" multiple size="8">
                    <?php foreach ($cursos as $curso): ?>
                        <?php $cid = (int)$curso['id']; ?>
                        <option value="<?= $cid ?>"
                            <?= $editando && in_array($cid, $cursoIdsSelecionados, true) ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string)$curso['nome_curso'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Segure Ctrl/Cmd para selecionar vários cursos.</div>
            </div>

            <div class="col-12">
                <div class="form-check">
                    <input class="form-check-input"
                           type="checkbox"
                           id="ativo"
                           name="ativo"
                           value="1"
                        <?= $ativoMarcado ? 'checked' : '' ?>>
                    <label class="form-check-label" for="ativo">Usuário ativo</label>
                </div>
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-primary">
                    <?= $editando ? 'Salvar alterações' : 'Cadastrar usuário' ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const perfil = document.getElementById('perfil');
    const authType = document.getElementById('auth_type');
    const blocoCursos = document.getElementById('blocoCursos');
    const blocoSenha = document.getElementById('blocoSenha');
    const senha = document.getElementById('nova_senha_usuario');
    const editando = <?= $editando ? 'true' : 'false' ?>;

    function atualizar() {
        blocoCursos.style.display = perfil.value === 'coordenador_curso' ? '' : 'none';
        const local = authType.value === 'local';
        blocoSenha.style.display = local ? '' : 'none';
        senha.required = local && !editando;
        if (!local) {
            senha.value = '';
        }
    }

    perfil.addEventListener('change', atualizar);
    authType.addEventListener('change', atualizar);
    atualizar();
})();
</script>
