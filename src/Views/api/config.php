<?php
$config = $config ?? [];
$oauthUrl = (string)($config['oauth_url'] ?? '');
$clientId = (string)($config['client_id'] ?? '');
$clientSecret = (string)($config['client_secret'] ?? '');
$urlMatriculados = (string)($config['url_matriculados'] ?? '');
$urlAlunos = (string)($config['url_alunos'] ?? '');
$verifySsl = !empty($config['verify_ssl']);
$periodoLetivo = (string)($config['periodo_letivo'] ?? '');
$dataInicial = (string)($config['frequencia_data_inicial'] ?? '');
$dataFinal = (string)($config['frequencia_data_final'] ?? '');
$dataReferencia = (string)($config['data_referencia'] ?? 'hoje-2');
$temClientSecret = !empty($temClientSecret);

if ($periodoLetivo === '' && preg_match('/periodo_letivo=([^&]+)/', $urlMatriculados, $m)) {
    $periodoLetivo = rawurldecode($m[1]);
}
if ($dataInicial === '') {
    $dataInicial = '01-01-2026';
}
if ($dataFinal === '') {
    $dataFinal = '31-12-2026';
}
?>

<div class="mb-4">
    <h1 class="h4 mb-1">Configuração da API</h1>
    <p class="text-secondary mb-0">
        Credenciais, período, intervalo e URLs do webservice SIGAA.
        Ficam gravadas no banco de dados (não em arquivos do projeto).
    </p>
</div>

<?php if (!empty($sucesso)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($sucesso, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if (!empty($erro)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="alert alert-info">
    Os scripts Python leem estes valores da tabela <code>configuracoes</code>.
    Use o ícone do olho para revelar o Client Secret.
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="post"
              action="<?= htmlspecialchars(url('/configuracoes/api'), ENT_QUOTES, 'UTF-8') ?>"
              class="row g-3"
              autocomplete="off">
            <div class="col-12">
                <label for="api_oauth_url" class="form-label">URL OAuth (token) <span class="text-danger">*</span></label>
                <input type="url"
                       class="form-control"
                       id="api_oauth_url"
                       name="api_oauth_url"
                       value="<?= htmlspecialchars($oauthUrl, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="https://app.exemplo.edu.br/oauth/token"
                       required>
            </div>

            <div class="col-md-6">
                <label for="api_client_id" class="form-label">Client ID <span class="text-danger">*</span></label>
                <input type="text"
                       class="form-control"
                       id="api_client_id"
                       name="api_client_id"
                       value="<?= htmlspecialchars($clientId, ENT_QUOTES, 'UTF-8') ?>"
                       required
                       autocomplete="off">
            </div>

            <div class="col-md-6">
                <label for="api_client_secret" class="form-label">
                    Client Secret <?= $temClientSecret ? '' : '<span class="text-danger">*</span>' ?>
                </label>
                <div class="input-group">
                    <input type="password"
                           class="form-control"
                           id="api_client_secret"
                           name="api_client_secret"
                           value="<?= htmlspecialchars($clientSecret, ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="<?= $temClientSecret ? '' : 'Client Secret OAuth' ?>"
                           autocomplete="new-password"
                           <?= $temClientSecret || $clientSecret !== '' ? '' : 'required' ?>>
                    <button class="btn btn-outline-secondary"
                            type="button"
                            id="toggleClientSecret"
                            title="Mostrar ou ocultar secret"
                            aria-label="Mostrar ou ocultar secret">
                        <svg id="iconeOlhoSecret" xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                            <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8M1.173 8a13 13 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5s3.879 1.168 5.168 2.457A13 13 0 0 1 14.828 8q-.086.13-.195.288c-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5s-3.879-1.168-5.168-2.457A13 13 0 0 1 1.172 8z"/>
                            <path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5M4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0"/>
                        </svg>
                    </button>
                </div>
                <div class="form-text">Clique no olho para revelar ou ocultar o valor.</div>
            </div>

            <div class="col-md-4">
                <label for="api_periodo_letivo" class="form-label">Período letivo <span class="text-danger">*</span></label>
                <input type="text"
                       class="form-control"
                       id="api_periodo_letivo"
                       name="api_periodo_letivo"
                       value="<?= htmlspecialchars($periodoLetivo, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="2026/2"
                       required>
                <div class="form-text">Aplicado na URL de matriculados (<code>periodo_letivo</code>).</div>
            </div>

            <div class="col-md-4">
                <label for="frequencia_data_inicial" class="form-label">Data inicial <span class="text-danger">*</span></label>
                <input type="text"
                       class="form-control"
                       id="frequencia_data_inicial"
                       name="frequencia_data_inicial"
                       value="<?= htmlspecialchars($dataInicial, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="01-01-2026"
                       required>
            </div>

            <div class="col-md-4">
                <label for="frequencia_data_final" class="form-label">Data final <span class="text-danger">*</span></label>
                <input type="text"
                       class="form-control"
                       id="frequencia_data_final"
                       name="frequencia_data_final"
                       value="<?= htmlspecialchars($dataFinal, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="31-12-2026"
                       required>
            </div>

            <div class="col-md-4">
                <label for="data_referencia" class="form-label">Data de referência</label>
                <input type="text"
                       class="form-control"
                       id="data_referencia"
                       name="data_referencia"
                       value="<?= htmlspecialchars($dataReferencia, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="hoje-2">
                <div class="form-text"><code>hoje-2</code> ou data <code>DD-MM-AAAA</code>.</div>
            </div>

            <div class="col-12">
                <label for="api_url_matriculados" class="form-label">URL matriculados <span class="text-danger">*</span></label>
                <textarea class="form-control font-monospace"
                          id="api_url_matriculados"
                          name="api_url_matriculados"
                          rows="3"
                          required><?= htmlspecialchars($urlMatriculados, ENT_QUOTES, 'UTF-8') ?></textarea>
                <div class="form-text">
                    O período letivo do campo acima substitui <code>periodo_letivo</code> ao salvar.
                    Sem <code>tipo=extracao</code> a API devolve disciplinas e docentes (necessário para professores).
                </div>
            </div>

            <div class="col-12">
                <label for="api_url_alunos" class="form-label">URL alunos <span class="text-danger">*</span></label>
                <textarea class="form-control font-monospace"
                          id="api_url_alunos"
                          name="api_url_alunos"
                          rows="2"
                          required><?= htmlspecialchars($urlAlunos, ENT_QUOTES, 'UTF-8') ?></textarea>
                <div class="form-text">
                    Deve conter <code>{login}</code>. As datas do intervalo são acrescentadas pelo script.
                </div>
            </div>

            <div class="col-12">
                <div class="form-check">
                    <input class="form-check-input"
                           type="checkbox"
                           id="api_verify_ssl"
                           name="api_verify_ssl"
                           value="1"
                           <?= $verifySsl ? 'checked' : '' ?>>
                    <label class="form-check-label" for="api_verify_ssl">
                        Verificar certificado SSL (recomendado em produção Linux)
                    </label>
                </div>
            </div>

            <div class="col-12 d-flex justify-content-end gap-2">
                <a href="<?= htmlspecialchars(url('/'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary">Cancelar</a>
                <button type="submit" class="btn btn-primary">Salvar configurações</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var botao = document.getElementById('toggleClientSecret');
    var campo = document.getElementById('api_client_secret');
    var icone = document.getElementById('iconeOlhoSecret');
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
        botao.title = revelado ? 'Mostrar secret' : 'Ocultar secret';
    });
})();
</script>
