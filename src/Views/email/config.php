<?php

$config = $config ?? [];
$host = (string)($config['host'] ?? '');
$port = (int)($config['port'] ?? 587);
$encryption = (string)($config['encryption'] ?? 'tls');
$username = (string)($config['username'] ?? '');
$password = (string)($config['password'] ?? '');
$fromAddress = (string)($config['from_address'] ?? '');
$fromName = (string)($config['from_name'] ?? 'MAPA');
$enabled = !empty($config['enabled']);
$alarmesAlunosEnabled = !empty($config['alarmes_alunos_enabled']);
$alarmesStaffEnabled = !empty($config['alarmes_staff_enabled']);
$temSenha = !empty($temSenha) || $password !== '';
$envioPermitido = !empty($envioPermitido);
$staffApenasEmail = trim((string)($staffApenasEmail ?? ''));
$staffApenasAtivo = $staffApenasEmail !== '' && filter_var($staffApenasEmail, FILTER_VALIDATE_EMAIL);

$rotuloDia = static function (string $dia): string {
    return match (strtolower($dia)) {
        'segunda' => 'segundas-feiras',
        'terca', 'terça' => 'terças-feiras',
        'quarta' => 'quartas-feiras',
        'quinta' => 'quintas-feiras',
        'sexta' => 'sextas-feiras',
        'sabado', 'sábado' => 'sábados',
        'domingo' => 'domingos',
        'todos', 'qualquer', '' => 'todos os dias',
        default => $dia,
    };
};

$alarmesDiaStaff = trim((string)($alarmesDiaStaff ?? 'quarta'));
$alarmesDiaStaffRotulo = $rotuloDia($alarmesDiaStaff);
?>

<div class="mb-4">
    <h1 class="h4 mb-1">Configuração de e-mail</h1>
    <p class="text-secondary mb-0">
        Servidor SMTP usado nos e-mails automáticos do MAPA.
        Chamadas em atraso, alarmes aos alunos e avisos ao staff são fluxos separados.
    </p>
</div>

<?php if (!empty($sucesso)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($sucesso, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if (!empty($erro)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if (!$envioPermitido): ?>
    <div class="alert alert-warning">
        Este ambiente está com <code>EMAIL_SEND=false</code> no arquivo <code>.env</code>.
        Nenhum e-mail automático será enviado daqui, mesmo com o interruptor abaixo ligado.
        No servidor de produção, use <code>EMAIL_SEND=true</code>.
    </div>
<?php else: ?>
    <div class="alert alert-info">
        Os envios são disparados pelo pipeline de coleta:
        <code>enviar_emails_chamadas.php</code>,
        <code>enviar_emails_alarmes_alunos.php</code> e
        <code>enviar_emails_alarmes_staff.php</code>.
    </div>
<?php endif; ?>

<?php if ($staffApenasAtivo): ?>
    <div class="alert alert-warning">
        Modo piloto de avisos ao staff:
        somente <strong>Marcelo Augusto Rauh Schmitt</strong>
        (<code><?= htmlspecialchars($staffApenasEmail, ENT_QUOTES, 'UTF-8') ?></code>)
        recebe resumos (disciplinas/cursos dele).
        Demais professores e coordenadores não recebem até remover
        <code>EMAIL_ALARMES_STAFF_APENAS</code> do <code>.env</code>;
        ao liberar, os contatos pendentes do piloto serão comunicados a toda a equipe.
    </div>
<?php endif; ?>

<?php if ($alarmesDiaStaffRotulo !== 'todos os dias'): ?>
    <div class="alert alert-info">
        Avisos ao staff saem apenas às
        <strong><?= htmlspecialchars($alarmesDiaStaffRotulo, ENT_QUOTES, 'UTF-8') ?></strong>,
        fuso <code>America/Sao_Paulo</code>
        (<code>EMAIL_ALARMES_DIA_STAFF</code>).
        E-mails aos alunos podem sair em qualquer dia da coleta (máx. 1 por aluno a cada 7 dias).
    </div>
<?php else: ?>
    <div class="alert alert-info">
        E-mails aos alunos podem sair em qualquer dia da coleta (máx. 1 por aluno a cada 7 dias).
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="post"
              action="<?= htmlspecialchars(url('/configuracoes/email'), ENT_QUOTES, 'UTF-8') ?>"
              class="row g-3"
              autocomplete="off">
            <div class="col-12">
                <div class="form-check form-switch">
                    <input class="form-check-input"
                           type="checkbox"
                           role="switch"
                           id="email_enabled"
                           name="email_enabled"
                           value="1"
                           <?= $enabled ? 'checked' : '' ?>>
                    <label class="form-check-label" for="email_enabled">
                        Enviar e-mails automáticos de chamadas em atraso (após 2 dias)
                    </label>
                </div>
            </div>

            <div class="col-12">
                <div class="form-check form-switch">
                    <input class="form-check-input"
                           type="checkbox"
                           role="switch"
                           id="email_alarmes_alunos_enabled"
                           name="email_alarmes_alunos_enabled"
                           value="1"
                           <?= $alarmesAlunosEnabled ? 'checked' : '' ?>>
                    <label class="form-check-label" for="email_alarmes_alunos_enabled">
                        Enviar e-mails de alarmes críticos aos alunos
                        (máx. 1 por aluno a cada 7 dias, em qualquer dia da coleta)
                    </label>
                </div>
            </div>

            <div class="col-12">
                <div class="form-check form-switch">
                    <input class="form-check-input"
                           type="checkbox"
                           role="switch"
                           id="email_alarmes_staff_enabled"
                           name="email_alarmes_staff_enabled"
                           value="1"
                           <?= $alarmesStaffEnabled ? 'checked' : '' ?>>
                    <label class="form-check-label" for="email_alarmes_staff_enabled">
                        Enviar avisos de alarmes a professores e coordenadores
                        (sobre e-mails já enviados aos alunos)
                    </label>
                </div>
            </div>

            <div class="col-md-8">
                <label for="email_host" class="form-label">Host SMTP <span class="text-danger">*</span></label>
                <input type="text"
                       class="form-control"
                       id="email_host"
                       name="email_host"
                       value="<?= htmlspecialchars($host, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="smtp.exemplo.edu.br"
                       required
                       autocomplete="off">
            </div>

            <div class="col-md-4">
                <label for="email_port" class="form-label">Porta <span class="text-danger">*</span></label>
                <input type="number"
                       class="form-control"
                       id="email_port"
                       name="email_port"
                       value="<?= $port > 0 ? $port : 587 ?>"
                       min="1"
                       max="65535"
                       required>
            </div>

            <div class="col-md-4">
                <label for="email_encryption" class="form-label">Criptografia</label>
                <select class="form-select" id="email_encryption" name="email_encryption">
                    <option value="tls" <?= $encryption === 'tls' ? 'selected' : '' ?>>TLS (STARTTLS)</option>
                    <option value="ssl" <?= $encryption === 'ssl' ? 'selected' : '' ?>>SSL</option>
                    <option value="none" <?= $encryption === 'none' ? 'selected' : '' ?>>Nenhuma</option>
                </select>
            </div>

            <div class="col-md-4">
                <label for="email_username" class="form-label">Usuário</label>
                <input type="text"
                       class="form-control"
                       id="email_username"
                       name="email_username"
                       value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>"
                       autocomplete="off">
            </div>

            <div class="col-md-4">
                <label for="email_password" class="form-label">Senha</label>
                <div class="input-group">
                    <input type="password"
                           class="form-control"
                           id="email_password"
                           name="email_password"
                           value=""
                           placeholder="<?= $temSenha ? '•••••••• (deixe em branco para manter)' : '' ?>"
                           autocomplete="new-password">
                    <button class="btn btn-outline-secondary"
                            type="button"
                            id="toggleEmailPassword"
                            title="Mostrar ou ocultar senha"
                            aria-label="Mostrar ou ocultar senha">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                            <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8M1.173 8a13 13 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5s3.879 1.168 5.168 2.457A13 13 0 0 1 14.828 8q-.086.13-.195.288c-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5s-3.879-1.168-5.168-2.457A13 13 0 0 1 1.172 8z"/>
                            <path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5M4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="col-md-6">
                <label for="email_from_address" class="form-label">Remetente (e-mail) <span class="text-danger">*</span></label>
                <input type="email"
                       class="form-control"
                       id="email_from_address"
                       name="email_from_address"
                       value="<?= htmlspecialchars($fromAddress, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="mapa@exemplo.edu.br"
                       required>
            </div>

            <div class="col-md-6">
                <label for="email_from_name" class="form-label">Nome do remetente</label>
                <input type="text"
                       class="form-control"
                       id="email_from_name"
                       name="email_from_name"
                       value="<?= htmlspecialchars($fromName, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="MAPA">
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-primary">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script>
(() => {
    const botao = document.getElementById('toggleEmailPassword');
    const campo = document.getElementById('email_password');
    if (!botao || !campo) {
        return;
    }
    botao.addEventListener('click', () => {
        campo.type = campo.type === 'password' ? 'text' : 'password';
    });
})();
</script>
