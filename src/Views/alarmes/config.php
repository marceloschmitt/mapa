<?php
$config = $config ?? [];
$padrao = $padrao ?? [];

$valor = static function (string $campo) use ($config, $padrao) {
    return $config[$campo] ?? ($padrao[$campo] ?? '');
};
$numero = static function (string $campo) use ($valor): string {
    $bruto = $valor($campo);
    if (is_float($bruto)) {
        return rtrim(rtrim(number_format($bruto, 1, '.', ''), '0'), '.');
    }

    return (string)$bruto;
};
$marcado = static function (string $campo) use ($valor): string {
    return !empty($valor($campo)) ? ' checked' : '';
};
$escapar = static function ($texto): string {
    return htmlspecialchars((string)$texto, ENT_QUOTES, 'UTF-8');
};
?>

<div class="mb-4">
    <h1 class="h4 mb-1">Regras de alarme</h1>
    <p class="text-secondary mb-0">
        Os critérios de risco deixam de ser fixos: o limite de frequência, as janelas de faltas e o
        texto de cada alerta são definidos aqui. Os valores valem para o portal e para a geração de
        alarmes da coleta (<code>gerar_alarmes.py</code>).
    </p>
</div>

<?php if (!empty($sucesso)): ?>
    <div class="alert alert-success"><?= $escapar($sucesso) ?></div>
<?php endif; ?>

<?php if (!empty($erro)): ?>
    <div class="alert alert-danger"><?= $escapar($erro) ?></div>
<?php endif; ?>

<div class="alert alert-warning">
    Alarmes <strong>críticos</strong> disparam o e-mail automático ao aluno (quando o envio está
    ligado em Configurações → E-mail). Ao afrouxar ou apertar os limites, o volume de e-mails muda
    junto. Alarmes já tratados continuam preservados; os abertos são recalculados na próxima coleta.
</div>

<form method="post"
      action="<?= $escapar(url('/configuracoes/alarmes')) ?>"
      autocomplete="off">

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch"
                       id="frequencia_ativo" name="frequencia_ativo" value="1"<?= $marcado('frequencia_ativo') ?>>
                <label class="form-check-label h5 mb-0" for="frequencia_ativo">
                    Frequência abaixo do limite
                </label>
            </div>
            <p class="text-secondary small">
                Gera um alarme por disciplina quando a frequência do aluno fica abaixo do limite,
                respeitando a carência contada do primeiro dia de aula (ou do primeiro dia após
                matrícula atrasada).
            </p>

            <div class="row g-3">
                <div class="col-sm-4">
                    <label class="form-label" for="frequencia_limite">Limite de frequência (%)</label>
                    <input type="number" class="form-control" id="frequencia_limite"
                           name="frequencia_limite" step="0.1" min="0.1" max="100"
                           value="<?= $escapar($numero('frequencia_limite')) ?>" required>
                    <div class="form-text">Abaixo disso o alarme é gerado (padrão 75).</div>
                </div>
                <div class="col-sm-4">
                    <label class="form-label" for="frequencia_limite_critico">Limite crítico (%)</label>
                    <input type="number" class="form-control" id="frequencia_limite_critico"
                           name="frequencia_limite_critico" step="0.1" min="0" max="100"
                           value="<?= $escapar($numero('frequencia_limite_critico')) ?>" required>
                    <div class="form-text">Abaixo disso a severidade é <strong>crítica</strong> (padrão 50). Use 0 para nunca marcar como crítico.</div>
                </div>
                <div class="col-sm-4">
                    <label class="form-label" for="frequencia_carencia_semanas">Carência (semanas)</label>
                    <input type="number" class="form-control" id="frequencia_carencia_semanas"
                           name="frequencia_carencia_semanas" step="1" min="0" max="52"
                           value="<?= $escapar($numero('frequencia_carencia_semanas')) ?>" required>
                    <div class="form-text">Semanas de aula antes de começar a alarmar (padrão 3).</div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="frequencia_mensagem">Mensagem do alerta</label>
                    <input type="text" class="form-control" id="frequencia_mensagem"
                           name="frequencia_mensagem" maxlength="200"
                           value="<?= $escapar($valor('frequencia_mensagem')) ?>" required>
                    <div class="form-text">
                        Campos disponíveis:
                        <code>{percentual}</code> <code>{limite}</code> <code>{limite_critico}</code>
                        <code>{ausencias}</code> <code>{horarios}</code> <code>{disciplina}</code>
                        <code>{codigo_disciplina}</code> <code>{carencia_semanas}</code>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch"
                       id="faltas_dias_ativo" name="faltas_dias_ativo" value="1"<?= $marcado('faltas_dias_ativo') ?>>
                <label class="form-check-label h5 mb-0" for="faltas_dias_ativo">
                    Faltas em dias úteis consecutivos
                </label>
            </div>
            <p class="text-secondary small">
                Um alarme por aluno/curso quando há uma sequência de faltas em dias úteis (sábado e
                domingo não contam nem quebram a sequência) que alcança a janela recente.
            </p>

            <div class="row g-3">
                <div class="col-sm-4">
                    <label class="form-label" for="faltas_dias_minimo">Mínimo de dias consecutivos</label>
                    <input type="number" class="form-control" id="faltas_dias_minimo"
                           name="faltas_dias_minimo" step="1" min="2" max="30"
                           value="<?= $escapar($numero('faltas_dias_minimo')) ?>" required>
                    <div class="form-text">Padrão 3.</div>
                </div>
                <div class="col-sm-4">
                    <label class="form-label" for="faltas_dias_janela">Janela recente (dias úteis)</label>
                    <input type="number" class="form-control" id="faltas_dias_janela"
                           name="faltas_dias_janela" step="1" min="1" max="30"
                           value="<?= $escapar($numero('faltas_dias_janela')) ?>" required>
                    <div class="form-text">A sequência precisa tocar esses últimos dias (padrão 4).</div>
                </div>
                <div class="col-sm-4">
                    <label class="form-label" for="faltas_dias_critico">Dias para severidade crítica</label>
                    <input type="number" class="form-control" id="faltas_dias_critico"
                           name="faltas_dias_critico" step="1" min="2" max="60"
                           value="<?= $escapar($numero('faltas_dias_critico')) ?>" required>
                    <div class="form-text">Sequências desse tamanho ou maiores viram críticas (padrão 4).</div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="faltas_dias_mensagem">Mensagem do alerta</label>
                    <input type="text" class="form-control" id="faltas_dias_mensagem"
                           name="faltas_dias_mensagem" maxlength="200"
                           value="<?= $escapar($valor('faltas_dias_mensagem')) ?>" required>
                    <div class="form-text">
                        Campos disponíveis:
                        <code>{dias}</code> <code>{datas}</code> <code>{primeira_falta}</code>
                        <code>{ultima_falta}</code> <code>{minimo}</code> <code>{janela}</code>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch"
                       id="faltas_semanas_ativo" name="faltas_semanas_ativo" value="1"<?= $marcado('faltas_semanas_ativo') ?>>
                <label class="form-check-label h5 mb-0" for="faltas_semanas_ativo">
                    Faltas em semanas consecutivas
                </label>
            </div>
            <p class="text-secondary small">
                Um alarme por disciplina quando o aluno falta em semanas seguidas e a última falta da
                sequência é recente.
            </p>

            <div class="row g-3">
                <div class="col-sm-4">
                    <label class="form-label" for="faltas_semanas_total">Semanas consecutivas</label>
                    <input type="number" class="form-control" id="faltas_semanas_total"
                           name="faltas_semanas_total" step="1" min="2" max="20"
                           value="<?= $escapar($numero('faltas_semanas_total')) ?>" required>
                    <div class="form-text">Padrão 3.</div>
                </div>
                <div class="col-sm-4">
                    <label class="form-label" for="faltas_semanas_janela_dias">Recência (dias)</label>
                    <input type="number" class="form-control" id="faltas_semanas_janela_dias"
                           name="faltas_semanas_janela_dias" step="1" min="1" max="90"
                           value="<?= $escapar($numero('faltas_semanas_janela_dias')) ?>" required>
                    <div class="form-text">Última falta até essa distância da data de referência (padrão 7).</div>
                </div>
                <div class="col-sm-4">
                    <label class="form-label" for="faltas_semanas_severidade">Severidade</label>
                    <select class="form-select" id="faltas_semanas_severidade" name="faltas_semanas_severidade">
                        <?php foreach (['critico' => 'Crítico', 'alto' => 'Alto'] as $chave => $rotulo): ?>
                            <option value="<?= $escapar($chave) ?>"
                                <?= (string)$valor('faltas_semanas_severidade') === $chave ? ' selected' : '' ?>>
                                <?= $escapar($rotulo) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Crítico envia e-mail automático ao aluno.</div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="faltas_semanas_mensagem">Mensagem do alerta</label>
                    <input type="text" class="form-control" id="faltas_semanas_mensagem"
                           name="faltas_semanas_mensagem" maxlength="200"
                           value="<?= $escapar($valor('faltas_semanas_mensagem')) ?>" required>
                    <div class="form-text">
                        Campos disponíveis:
                        <code>{semanas}</code> <code>{ultima_falta}</code> <code>{disciplina}</code>
                        <code>{codigo_disciplina}</code> <code>{janela_dias}</code>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <button type="submit" class="btn btn-primary">Salvar</button>
        <button type="submit" name="restaurar" value="1" class="btn btn-outline-secondary"
                formnovalidate
                onclick="return confirm('Restaurar os valores padrão das regras de alarme?');">
            Restaurar padrões
        </button>
        <a href="<?= $escapar(url('/alarmes')) ?>" class="btn btn-outline-secondary">Ver alarmes</a>
    </div>
</form>
