<?php if (!empty($sucesso)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($sucesso, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if (!empty($erro)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-md-4">
        <a href="<?= htmlspecialchars(url('/analytics'), ENT_QUOTES, 'UTF-8') ?>" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h5">Relatório geral</h2>
                    <p class="text-secondary mb-0 small">
                        Gráficos de frequência por curso, dias da semana e evolução temporal
                        a partir do SQLite.
                    </p>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-4">
        <a href="<?= htmlspecialchars(url('/alarmes'), ENT_QUOTES, 'UTF-8') ?>" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h5">Alarmes de risco</h2>
                    <p class="text-secondary mb-0 small">
                        Frequência &lt; 75%, faltas nos últimos 4 dias úteis e 3 semanas
                        consecutivas — gerados no banco por <code>gerar_alarmes.py</code>.
                    </p>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-4">
        <a href="<?= htmlspecialchars(url('/ingressantes'), ENT_QUOTES, 'UTF-8') ?>" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h5">Ingressantes</h2>
                    <p class="text-secondary mb-0 small">
                        Alunos do período letivo atual com frequência do curso abaixo de 75%,
                        organizados por curso.
                    </p>
                </div>
            </div>
        </a>
    </div>
    <?php if (!empty($podeVerChamadas)): ?>
        <div class="col-md-4">
            <a href="<?= htmlspecialchars(url('/chamadas'), ENT_QUOTES, 'UTF-8') ?>" class="text-decoration-none">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h5">Últimas chamadas</h2>
                        <p class="text-secondary mb-0 small">
                            Disciplinas pela data do último registro de presença — para acompanhar
                            chamadas em atraso.
                        </p>
                    </div>
                </div>
            </a>
        </div>
    <?php endif; ?>
</div>
