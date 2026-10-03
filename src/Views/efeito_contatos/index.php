<?php

$execucao = $execucao ?? null;
$resumo = $resumo ?? null;
$rotulosCanal = $rotulosCanal ?? [];
$semSeletorCurso = !empty($semSeletorCurso);
$cursoSelecionado = (string)($cursoSelecionado ?? 'todos');
$cursosDisponiveis = $cursosDisponiveis ?? [];
$podeGerar = !empty($podeGerar);

$e = static fn(string $texto): string => htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
$data = static function (?string $valor): string {
    $ts = strtotime((string)$valor);

    return $ts !== false ? date('d/m/Y', $ts) : '';
};
$geradoEm = '';
if (is_array($execucao)) {
    $ts = strtotime((string)($execucao['executado_em'] ?? ''));
    $geradoEm = $ts !== false ? date('d/m/Y H:i', $ts) : '';
}
$pct = static fn(?float $valor): string => $valor === null ? '—' : number_format($valor, 1, ',', '.') . '%';
$num = static fn(int $valor): string => number_format($valor, 0, ',', '.');
$traco = '<span class="text-secondary">—</span>';

$parcela = static function (array $comparacao) use ($num, $traco): string {
    $total = (int)$comparacao['analisados'];
    if ($total === 0) {
        return $traco;
    }
    $parte = (int)$comparacao['melhoraram'];

    return number_format(100 * $parte / $total, 0, ',', '.') . '%'
        . ' <span class="small text-secondary">(' . $num($parte) . ')</span>';
};
$queda = static function (array $comparacao) use ($traco): string {
    if ($comparacao['taxa_antes'] === null || $comparacao['taxa_depois'] === null) {
        return $traco;
    }
    $delta = (float)$comparacao['taxa_antes'] - (float)$comparacao['taxa_depois'];

    return '<span class="fw-semibold text-success">' . number_format($delta, 1, ',', '.') . ' p.p.</span>';
};
$linhasGrupo = static function (array $grupo) use ($rotulosCanal): array {
    $saida = [['Todos os canais', $grupo, true]];
    foreach ($rotulosCanal as $canal => $rotulo) {
        if (isset($grupo['canais'][$canal])) {
            $saida[] = [$rotulo, $grupo['canais'][$canal], false];
        }
    }
    foreach ($grupo['canais'] as $canal => $linha) {
        if (!isset($rotulosCanal[$canal])) {
            $saida[] = [(string)$canal, $linha, false];
        }
    }

    return $saida;
};

$janela = (int)($execucao['janela_dias'] ?? 14);
$minAulas = (int)($execucao['min_aulas'] ?? 3);
$comparacoesVarios = [
    'primeiro' => 'Antes do primeiro contato',
    'ultimo' => 'Antes do último contato',
];
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div class="flex-grow-1" style="flex-basis: 0; min-width: 280px;">
        <h1 class="h4 mb-1">Efeito dos contatos</h1>
        <?php if ($semSeletorCurso): ?>
            <p class="mb-1">
                <span class="badge text-bg-primary text-wrap text-start fw-normal" style="font-size: 0.85rem;">
                    <?= $e((string)($cursoExibido ?? 'Todos os cursos')) ?>
                </span>
            </p>
        <?php endif; ?>
        <p class="text-secondary mb-2">
            Alunos que receberam contato: quantos passaram a faltar menos nos <?= $janela ?> dias depois do
            último contato e quanto melhoraram.
        </p>
        <?php if (is_array($execucao)): ?>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="badge text-bg-secondary">
                    Semestre desde <?= $e($data($execucao['data_inicio'] ?? '')) ?>
                    · dados até <?= $e($data($execucao['data_corte'] ?? '')) ?>
                </span>
                <span class="badge text-bg-secondary">Mínimo de <?= $minAulas ?> aulas em cada janela</span>
                <span class="badge text-bg-light text-dark border">
                    Gerado em <?= $e($geradoEm) ?>
                </span>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($podeGerar): ?>
        <div class="text-md-end">
            <form method="post" action="<?= $e(url('/efeito-contatos/gerar')) ?>">
                <button type="submit" class="btn btn-primary">Gerar análise</button>
            </form>
            <p class="small text-secondary mb-0 mt-1" style="max-width: 280px;">
                Recalcula com os contatos e as faltas do semestre. Roda fora da coleta e pode levar alguns minutos.
            </p>
        </div>
    <?php endif; ?>
    <?php if (!$semSeletorCurso && $cursosDisponiveis !== []): ?>
        <form method="get" action="<?= $e(url('/efeito-contatos')) ?>"
              class="d-flex align-items-center gap-2 w-100">
            <label for="curso" class="form-label mb-0 text-nowrap">Curso</label>
            <select class="form-select w-auto" id="curso" name="curso" style="min-width: 260px;"
                    onchange="this.form.submit()">
                <option value="todos" <?= $cursoSelecionado === 'todos' ? 'selected' : '' ?>>Todos os cursos</option>
                <?php foreach ($cursosDisponiveis as $curso): ?>
                    <option value="<?= (int)$curso['id'] ?>"
                        <?= $cursoSelecionado === (string)$curso['id'] ? 'selected' : '' ?>>
                        <?= $e((string)$curso['nome_curso']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    <?php endif; ?>
</div>

<?php if (!empty($sucesso)): ?>
    <div class="alert alert-success"><?= $e((string)$sucesso) ?></div>
<?php endif; ?>

<?php if (!empty($erro)): ?>
    <div class="alert alert-danger"><?= $e((string)$erro) ?></div>
<?php endif; ?>

<?php if (!empty($avisoCoordenador)): ?>
    <div class="alert alert-warning"><?= $e((string)$avisoCoordenador) ?></div>
<?php endif; ?>

<?php if (is_array($execucao) && is_array($resumo)): ?>
    <?php $um = $resumo['um']; $varios = $resumo['varios']; ?>
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-secondary small">Alunos contatados</div>
                    <div class="fs-3 fw-semibold"><?= $num((int)$resumo['alunos']) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-secondary small">Contatos feitos</div>
                    <div class="fs-3 fw-semibold"><?= $num((int)$resumo['contatos']) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-secondary small">Com um contato</div>
                    <div class="fs-3 fw-semibold"><?= $num((int)$um['alunos']) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-secondary small">Com dois ou mais contatos</div>
                    <div class="fs-3 fw-semibold"><?= $num((int)$varios['alunos']) ?></div>
                    <?php if ((int)$varios['alunos'] > 0): ?>
                        <div class="small text-secondary">
                            <?= $num((int)$varios['contatos']) ?> contatos
                            (média de <?= number_format((int)$varios['contatos'] / (int)$varios['alunos'], 1, ',', '.') ?>)
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h6 mb-1">Alunos com um contato</h2>
            <p class="small text-secondary mb-3">
                Faltas nos <?= $janela ?> dias antes e nos <?= $janela ?> dias depois do contato.
            </p>
            <?php if ((int)$um['alunos'] === 0): ?>
                <p class="text-secondary mb-0">Nenhum aluno com um único contato neste recorte.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th rowspan="2" class="align-bottom">Canal</th>
                                <th rowspan="2" class="text-end align-bottom">Alunos</th>
                                <th rowspan="2" class="text-end align-bottom">Analisados</th>
                                <th rowspan="2" class="text-end align-bottom">Melhoraram</th>
                                <th colspan="3" class="text-center border-start">Faltas dos que melhoraram</th>
                            </tr>
                            <tr>
                                <th class="text-end border-start">Antes</th>
                                <th class="text-end">Depois</th>
                                <th class="text-end">Queda</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($linhasGrupo($um) as [$rotulo, $linha, $todos]): ?>
                                <?php $c = $linha['primeiro']; ?>
                                <tr<?= $todos ? ' class="fw-semibold"' : '' ?>>
                                    <td<?= $todos ? '' : ' class="ps-4"' ?>><?= $e((string)$rotulo) ?></td>
                                    <td class="text-end"><?= $num((int)$linha['alunos']) ?></td>
                                    <td class="text-end"><?= $num((int)$c['analisados']) ?></td>
                                    <td class="text-end"><?= $parcela($c) ?></td>
                                    <td class="text-end border-start"><?= $pct($c['taxa_antes']) ?></td>
                                    <td class="text-end"><?= $pct($c['taxa_depois']) ?></td>
                                    <td class="text-end"><?= $queda($c) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h6 mb-1">Alunos com dois ou mais contatos</h2>
            <p class="small text-secondary mb-3">
                Faltas nos <?= $janela ?> dias depois do último contato, comparadas com as dos <?= $janela ?> dias antes
                do primeiro contato (efeito do conjunto) e antes do último (efeito só do último).
            </p>
            <?php if ((int)$varios['alunos'] === 0): ?>
                <p class="text-secondary mb-0">Nenhum aluno com dois ou mais contatos neste recorte.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th rowspan="2" class="align-bottom">Canal do último contato</th>
                                <th rowspan="2" class="text-end align-bottom">Alunos</th>
                                <th rowspan="2" class="text-end align-bottom">Contatos</th>
                                <th rowspan="2" class="align-bottom border-start">Comparação</th>
                                <th rowspan="2" class="text-end align-bottom">Analisados</th>
                                <th rowspan="2" class="text-end align-bottom">Melhoraram</th>
                                <th colspan="3" class="text-center border-start">Faltas dos que melhoraram</th>
                            </tr>
                            <tr>
                                <th class="text-end border-start">Antes</th>
                                <th class="text-end">Depois</th>
                                <th class="text-end">Queda</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($linhasGrupo($varios) as [$rotulo, $linha, $todos]): ?>
                                <?php $primeiraLinha = true; ?>
                                <?php foreach ($comparacoesVarios as $chave => $rotuloComparacao): ?>
                                    <?php $c = $linha[$chave]; ?>
                                    <tr<?= $todos ? ' class="fw-semibold"' : '' ?>>
                                        <?php if ($primeiraLinha): ?>
                                            <td rowspan="2"<?= $todos ? '' : ' class="ps-4"' ?>><?= $e((string)$rotulo) ?></td>
                                            <td rowspan="2" class="text-end"><?= $num((int)$linha['alunos']) ?></td>
                                            <td rowspan="2" class="text-end"><?= $num((int)$linha['contatos']) ?></td>
                                        <?php endif; ?>
                                        <td class="small border-start fw-normal"><?= $e($rotuloComparacao) ?></td>
                                        <td class="text-end"><?= $num((int)$c['analisados']) ?></td>
                                        <td class="text-end"><?= $parcela($c) ?></td>
                                        <td class="text-end border-start"><?= $pct($c['taxa_antes']) ?></td>
                                        <td class="text-end"><?= $pct($c['taxa_depois']) ?></td>
                                        <td class="text-end"><?= $queda($c) ?></td>
                                    </tr>
                                    <?php $primeiraLinha = false; ?>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body small text-secondary">
            <h2 class="h6 text-body mb-2">Como ler</h2>
            <ul class="mb-0 ps-3">
                <li>
                    Um contato = um dia em que o aluno recebeu algum contato (e-mail automático ou contato registrado
                    na tela de alarmes). Mais de um canal no mesmo dia conta como um contato.
                </li>
                <li>
                    Taxa de faltas = faltas ÷ aulas da grade nos <?= $janela ?> dias da janela (o dia do contato não entra).
                    Dias sem chamada registrada pelo professor não contam.
                </li>
                <li>
                    "Melhoraram" = a taxa de faltas depois do último contato ficou menor que a de antes. "Antes" e
                    "Depois" são a média das taxas desses alunos; "Queda" é a diferença, em pontos percentuais
                    (ex.: de 40% para 15% = 25 p.p.).
                </li>
                <li>
                    Com dois ou mais contatos, a janela antes do último contato pode incluir contatos anteriores.
                </li>
                <li>
                    O canal é o do dia do último contato. Quem recebeu mais de um canal nesse dia aparece em cada um,
                    por isso a soma dos canais pode passar do total.
                </li>
                <li>
                    "Analisados" = alunos com pelo menos <?= $minAulas ?> aulas em cada janela comparada; contatos
                    recentes ainda não têm o período "depois" completo.
                </li>
                <li>Mostra associação, não prova que o contato causou a mudança.</li>
            </ul>
        </div>
    </div>
<?php endif; ?>
