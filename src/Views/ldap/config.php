<?php
$config = $config ?? [];
$host = (string)($config['host'] ?? '');
$baseDn = (string)($config['base_dn'] ?? '');
$bindDn = (string)($config['bind_dn'] ?? '');
$bindPassword = (string)($config['bind_password'] ?? '');
$userAttribute = (string)($config['user_attribute'] ?? 'sAMAccountName');
$atributos = $atributos ?? ['sAMAccountName'];
$temSenhaBind = !empty($temSenhaBind) || $bindPassword !== '';
$rotulosAtributo = [
    'uid' => 'uid (LDAP padrão)',
    'sAMAccountName' => 'sAMAccountName (Active Directory)',
    'userPrincipalName' => 'userPrincipalName (Active Directory - UPN)',
    'cn' => 'cn (Common Name)',
    'mail' => 'mail (E-mail)',
];
?>

<div class="mb-4">
    <h1 class="h4 mb-1">Configuração LDAP</h1>
    <p class="text-secondary mb-0">
        Parâmetros de conexão com o servidor LDAP institucional.
        Ficam gravados no banco de dados (não no arquivo <code>.env</code>).
    </p>
</div>

<?php if (!empty($sucesso)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($sucesso, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if (!empty($erro)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="alert alert-info">
    Estes parâmetros são usados na autenticação de usuários com tipo <strong>LDAP</strong>.
    Use o ícone do olho para revelar a senha do bind.
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="post" action="<?= htmlspecialchars(url('/configuracoes/ldap'), ENT_QUOTES, 'UTF-8') ?>" class="row g-3" autocomplete="off">
            <div class="col-12">
                <label for="ldap_host" class="form-label">Endereço do servidor LDAP <span class="text-danger">*</span></label>
                <input type="text"
                       class="form-control"
                       id="ldap_host"
                       name="ldap_host"
                       value="<?= htmlspecialchars($host, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="ldap://ldap.exemplo.edu.br"
                       required>
                <div class="form-text">
                    Exemplos: <code>ldap://ldap.exemplo.edu.br</code>, <code>ldaps://ldap.exemplo.edu.br</code>,
                    <code>ldap://ldap.exemplo.edu.br:389</code>
                </div>
            </div>

            <div class="col-12">
                <label for="ldap_base_dn" class="form-label">Base DN <span class="text-danger">*</span></label>
                <input type="text"
                       class="form-control"
                       id="ldap_base_dn"
                       name="ldap_base_dn"
                       value="<?= htmlspecialchars($baseDn, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="dc=exemplo,dc=edu,dc=br"
                       required>
                <div class="form-text">
                    Exemplo: <code>ou=users,dc=exemplo,dc=edu,dc=br</code> ou <code>dc=exemplo,dc=edu,dc=br</code>
                </div>
            </div>

            <div class="col-md-6">
                <label for="ldap_user_attribute" class="form-label">Atributo de usuário <span class="text-danger">*</span></label>
                <select class="form-select" id="ldap_user_attribute" name="ldap_user_attribute" required>
                    <?php foreach ($atributos as $atributo): ?>
                        <option value="<?= htmlspecialchars($atributo, ENT_QUOTES, 'UTF-8') ?>"
                            <?= $userAttribute === $atributo ? 'selected' : '' ?>>
                            <?= htmlspecialchars($rotulosAtributo[$atributo] ?? $atributo, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label for="ldap_bind_dn" class="form-label">Bind DN (opcional)</label>
                <input type="text"
                       class="form-control"
                       id="ldap_bind_dn"
                       name="ldap_bind_dn"
                       value="<?= htmlspecialchars($bindDn, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="cn=admin,dc=exemplo,dc=edu,dc=br">
                <div class="form-text">Deixe em branco para bind anônimo, se o servidor permitir.</div>
            </div>

            <div class="col-12">
                <label for="ldap_bind_password" class="form-label">Senha do Bind DN (opcional)</label>
                <div class="input-group">
                    <input type="password"
                           class="form-control"
                           id="ldap_bind_password"
                           name="ldap_bind_password"
                           value="<?= htmlspecialchars($bindPassword, ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="<?= $temSenhaBind ? '' : 'Senha do bind administrativo' ?>"
                           autocomplete="new-password">
                    <button class="btn btn-outline-secondary"
                            type="button"
                            id="toggleBindPassword"
                            title="Mostrar ou ocultar senha"
                            aria-label="Mostrar ou ocultar senha">
                        <svg id="iconeOlhoBind" xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                            <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8M1.173 8a13 13 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5s3.879 1.168 5.168 2.457A13 13 0 0 1 14.828 8q-.086.13-.195.288c-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5s-3.879-1.168-5.168-2.457A13 13 0 0 1 1.172 8z"/>
                            <path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5M4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0"/>
                        </svg>
                    </button>
                </div>
                <div class="form-text">Clique no olho para revelar ou ocultar o valor.</div>
            </div>

            <div class="col-12 d-flex justify-content-end gap-2">
                <a href="<?= htmlspecialchars(url('/usuarios'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary">Cancelar</a>
                <button type="submit" class="btn btn-primary">Salvar configurações</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var botao = document.getElementById('toggleBindPassword');
    var campo = document.getElementById('ldap_bind_password');
    var icone = document.getElementById('iconeOlhoBind');
    if (!botao || !campo || !icone) {
        return;
    }
    var svgOlho = icone.innerHTML;
    var svgOlhoCortado =
        '<path d="M13.359 11.238C15.06 9.72 16 8 16 8s-3-5.5-8-5.5a7 7 0 0 0-2.79.588l.77.771A6 6 0 0 1 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13 13 0 0 1 14.828 8q-.086.13-.195.288c-.335.48-.83 1.12-1.465 1.755q-.247.248-.517.486z"/>' +
        '<path d="M11.297 9.176a3.5 3.5 0 0 0-4.474-4.474l.823.823a2.5 2.5 0 0 1 2.829 2.829zm-2.763 1.915.822.822.823.823a3.5 3.5 0 0 1-4.474-4.474l.823.823a2.5 2.5 0 0 0 2.829 2.829z"/>' +
        '<path d="M3.35 5.47q-.27.24-.518.487A13 13 0 0 0 1.172 8l.195.288c.335.48.83 1.12 1.465 1.755C4.121 11.332 5.881 12.5 8 12.5c.716 0 1.39-.133 2.02-.36l.77.772A7 7 0 0 1 8 13.5C3 13.5 0 8 0 8s.939-1.721 2.641-3.238l.708.709zm10.296 8.884-12-12 .708-.708 12 12z"/>';
    botao.addEventListener('click', function () {
        var revelado = campo.type === 'text';
        campo.type = revelado ? 'password' : 'text';
        icone.innerHTML = revelado ? svgOlho : svgOlhoCortado;
        botao.title = revelado ? 'Mostrar senha' : 'Ocultar senha';
    });
})();
</script>
